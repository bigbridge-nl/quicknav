<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Console\Command;

use BigBridge\QuickNav\Model\Index\MenuIndexStorage;
use BigBridge\QuickNav\Model\Search\AdminActivityLogProvider;
use BigBridge\QuickNav\Model\Search\AllowAllAuthorization;
use BigBridge\QuickNav\Model\Search\CategoryProvider;
use BigBridge\QuickNav\Model\Search\CmsBlockProvider;
use BigBridge\QuickNav\Model\Search\CmsPageProvider;
use BigBridge\QuickNav\Model\Search\ConfigSectionProvider;
use BigBridge\QuickNav\Model\Search\CouponCodeProvider;
use BigBridge\QuickNav\Model\Search\CreditMemoProvider;
use BigBridge\QuickNav\Model\Search\CustomerProvider;
use BigBridge\QuickNav\Model\Search\InvoiceProvider;
use BigBridge\QuickNav\Model\Search\MatchTier;
use BigBridge\QuickNav\Model\Search\OpenSearch\OpenSearchAvailability;
use BigBridge\QuickNav\Model\Search\OpenSearch\OpenSearchClientFactory;
use BigBridge\QuickNav\Model\Search\OpenSearch\OpenSearchConfig;
use BigBridge\QuickNav\Model\Search\OrderProvider;
use BigBridge\QuickNav\Model\Search\ProductProvider;
use BigBridge\QuickNav\Model\Search\SearchOutcome;
use BigBridge\QuickNav\Model\Search\SearchResult;
use BigBridge\QuickNav\Model\Search\SearchService;
use BigBridge\QuickNav\Model\Search\SearchTermProvider;
use BigBridge\QuickNav\Model\Search\ShipmentProvider;
use BigBridge\QuickNav\Model\Search\StoreScopeProvider;
use BigBridge\QuickNav\Model\Search\UrlPasteResolver;
use BigBridge\QuickNav\Model\Search\UrlRewriteProvider;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks search coverage against this install's real data, catching an index that reports
 * "Ready" while a record type is silently missing. Runs each provider's real search() with an
 * always-allow AuthorizationInterface, since a CLI process has no logged-in admin.
 */
class DiagnoseSearchCommand extends Command
{
    /**
     * One case per provider key, using labels from this install's sample data. `requiresRowsIn`
     * names a table whose rows only exist in some sample data sets — without it, a failing case
     * can't distinguish "provider is broken" from "this install never had that data".
     *
     * @var array<int, array{query: string, expectLabel: string, description: string, requiresRowsIn?: string}>
     */
    private const CASES = [
        ['query' => 'mail sending settings', 'expectLabel' => 'Mail Sending Settings', 'description' => 'Config group (Stores > Configuration > Advanced > System)'],
        ['query' => 'disable email communications', 'expectLabel' => 'Disable Email Communications', 'description' => 'Config field, one level below the group above'],
        ['query' => 'single-store mode', 'expectLabel' => 'Single-Store Mode', 'description' => 'Config group (Stores > Configuration > General)'],
        ['query' => 'disable modules output', 'expectLabel' => 'Disable Modules Output', 'description' => 'Config group (Stores > Configuration > Advanced > Advanced)'],
        ['query' => 'tax', 'expectLabel' => 'Tax', 'description' => 'Config section/menu item'],
        ['query' => '24-mb01', 'expectLabel' => 'Joust Duffle Bag', 'description' => 'Product by SKU'],
        ['query' => 'bags', 'expectLabel' => 'Bags', 'description' => 'Category by name'],
        ['query' => 'veronica costello', 'expectLabel' => 'Veronica Costello', 'description' => 'Customer by name'],
        ['query' => '000000001', 'expectLabel' => '000000001', 'description' => 'Order by increment ID'],
        ['query' => 'footer links block', 'expectLabel' => 'Footer Links Block', 'description' => 'CMS block by title'],
        ['query' => 'home page', 'expectLabel' => 'Home Page', 'description' => 'CMS page by title'],
        ['query' => 'joust-duffle-bag.html', 'expectLabel' => 'joust-duffle-bag.html', 'description' => 'URL rewrite by request path'],
        ['query' => 'foobar', 'expectLabel' => 'foobar', 'description' => 'Search term', 'requiresRowsIn' => 'search_query'],
        ['query' => 'main website', 'expectLabel' => 'Main Website', 'description' => 'Store scope (website)'],
    ];

    /** @var array<string, class-string> provider key => class, mirroring etc/di.xml's SearchService wiring */
    private const PROVIDER_CLASSES = [
        'config_section' => ConfigSectionProvider::class,
        'product' => ProductProvider::class,
        'category' => CategoryProvider::class,
        'store_scope' => StoreScopeProvider::class,
        'customer' => CustomerProvider::class,
        'order' => OrderProvider::class,
        'cms_block' => CmsBlockProvider::class,
        'cms_page' => CmsPageProvider::class,
        'url_rewrite' => UrlRewriteProvider::class,
        'search_term' => SearchTermProvider::class,
        'invoice' => InvoiceProvider::class,
        'shipment' => ShipmentProvider::class,
        'credit_memo' => CreditMemoProvider::class,
        'coupon_code' => CouponCodeProvider::class,
        'admin_activity_log' => AdminActivityLogProvider::class,
    ];

    private const INDEX_HEALTH_RECORD_TYPES = ['config_section', 'config_group', 'config_field', 'menu_item'];

    /** entity_type values large-dataset providers narrow via the shared OpenSearch index (step 04/06). */
    private const OPENSEARCH_ENTITY_TYPES = ['product', 'customer', 'order', 'coupon_code'];

    /**
     * One query per keyword and synonym in QueryLimiter's keywordMap, so a typo that breaks a
     * single alias while its sibling still works is caught.
     *
     * @var array<int, array{query: string, expectProviderKey: string, description: string}>
     */
    private const LIMITER_KEYWORD_CASES = [
        ['query' => 'cat bags', 'expectProviderKey' => 'category', 'description' => 'category keyword "cat"'],
        ['query' => 'product 24-mb01', 'expectProviderKey' => 'product', 'description' => 'product keyword "product"'],
        ['query' => 'products 24-mb01', 'expectProviderKey' => 'product', 'description' => 'product synonym "products"'],
        ['query' => 'customer veronica', 'expectProviderKey' => 'customer', 'description' => 'customer keyword'],
        ['query' => 'order 000000001', 'expectProviderKey' => 'order', 'description' => 'order keyword'],
        ['query' => 'block footer', 'expectProviderKey' => 'cms_block', 'description' => 'cms_block keyword'],
        ['query' => 'page home', 'expectProviderKey' => 'cms_page', 'description' => 'cms_page keyword'],
        ['query' => 'pages home', 'expectProviderKey' => 'cms_page', 'description' => 'cms_page synonym "pages"'],
        ['query' => 'rewrite joust-duffle-bag.html', 'expectProviderKey' => 'url_rewrite', 'description' => 'url_rewrite keyword'],
        ['query' => 'search foobar', 'expectProviderKey' => 'search_term', 'description' => 'search_term keyword'],
        ['query' => 'store main website', 'expectProviderKey' => 'store_scope', 'description' => 'store_scope keyword'],
    ];

    /**
     * The native, directly LIKE-matched column per provider. Category is handled separately: its
     * name and url_path are EAV attributes with no native column to sample.
     *
     * @var array<string, array{table: string, idColumn: string, searchColumn: string}>
     */
    private const AGGREGATE_COVERAGE_TABLES = [
        'product' => ['table' => 'catalog_product_entity', 'idColumn' => 'entity_id', 'searchColumn' => 'sku'],
        'customer' => ['table' => 'customer_grid_flat', 'idColumn' => 'entity_id', 'searchColumn' => 'name'],
        'order' => ['table' => 'sales_order_grid', 'idColumn' => 'entity_id', 'searchColumn' => 'increment_id'],
        'cms_block' => ['table' => 'cms_block', 'idColumn' => 'block_id', 'searchColumn' => 'identifier'],
        'cms_page' => ['table' => 'cms_page', 'idColumn' => 'page_id', 'searchColumn' => 'identifier'],
        'url_rewrite' => ['table' => 'url_rewrite', 'idColumn' => 'url_rewrite_id', 'searchColumn' => 'request_path'],
    ];

    /** Rows to sample from each end of each table (id ASC and id DESC) for aggregate coverage. */
    private const AGGREGATE_SAMPLE_SIZE = 5;

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly OpenSearchAvailability $openSearchAvailability,
        private readonly OpenSearchClientFactory $openSearchClientFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bigbridge:quicknav:diagnose')
            ->setDescription(
                'Checks the QuickNav search index is populated and that a representative query '
                .'for every provider (config, product, category, customer, order, CMS, URL '
                .'rewrite, search term, store scope) actually finds its real sample-data target.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $searchService = $this->buildDiagnosticSearchService();

        $results = [
            $this->checkIndexHealth($output),
            $this->checkSearchCoverage($searchService, $output),
            $this->checkLimiterKeywords($searchService, $output),
            $this->checkUrlPasteResolution($searchService, $output),
            $this->checkScopeAwareness($searchService, $output),
            $this->checkCategoryDrilldown($searchService, $output),
            $this->checkFuzzyTolerance($searchService, $output),
            $this->checkTypeFilterOverride($searchService, $output),
            $this->checkAggregateCoverage($searchService, $output),
            $this->checkNoDuplicateResultIds($searchService, $output),
            $this->checkOpenSearchHealth($output),
        ];

        return in_array(false, $results, true) ? Command::FAILURE : Command::SUCCESS;
    }

    private function checkIndexHealth(OutputInterface $output): bool
    {
        $output->writeln('<info>Index health (bigbridge_quicknav_menu_index row counts):</info>');

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(MenuIndexStorage::TABLE);
        $counts = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['record_type', 'count' => new \Zend_Db_Expr('COUNT(*)')])
                ->group('record_type')
        );

        $healthy = true;
        foreach (self::INDEX_HEALTH_RECORD_TYPES as $recordType) {
            $count = (int) ($counts[$recordType] ?? 0);
            $isEmpty = $count === 0;
            $healthy = $healthy && ! $isEmpty;
            $output->writeln(sprintf(
                '  %s %-14s %d row(s)',
                $isEmpty ? '<error>[EMPTY]</error>' : '<info>[OK]</info>   ',
                $recordType,
                $count
            ));
        }

        if (! $healthy) {
            $output->writeln(
                '  <comment>A zero count above means the index was never (re)compiled for that '
                .'record type — run `bin/magento indexer:reindex bigbridge_quicknav_search` and '
                .'check the daily cron is actually running (`bin/magento cron:run` scheduled via '
                .'OS crontab), since that\'s what keeps this fresh otherwise.</comment>'
            );
        }

        return $healthy;
    }

    private function checkSearchCoverage(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Search coverage (one query per provider, ACL bypassed):</info>');

        $allPassed = true;

        foreach (self::CASES as $case) {
            $requiredTable = $case['requiresRowsIn'] ?? null;
            if ($requiredTable !== null && ! $this->hasRows($requiredTable)) {
                $output->writeln(sprintf(
                    '  <comment>[EMPTY]</comment> "%s" (%s) — no rows in %s, nothing this check can verify either way',
                    $case['query'],
                    $case['description'],
                    $requiredTable
                ));

                continue;
            }

            $outcome = $searchService->search($case['query'], bypassLimiter: true);
            $found = false;
            foreach ($outcome->results as $result) {
                if (stripos($result->label, $case['expectLabel']) !== false) {
                    $found = true;
                    break;
                }
            }

            $allPassed = $allPassed && $found;
            $output->writeln(sprintf(
                '  %s "%s" -> expected "%s" (%s)',
                $found ? '<info>[PASS]</info>' : '<error>[FAIL]</error>',
                $case['query'],
                $case['expectLabel'],
                $case['description']
            ));
        }

        return $allPassed;
    }

    /**
     * Whether an optional data source has anything to find at all. A missing table counts as no
     * rows: the owning module may not be installed.
     */
    private function hasRows(string $table): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        if (! $connection->isTableExists($tableName)) {
            return false;
        }

        return (int) $connection->fetchOne(
            $connection->select()->from($tableName, new \Zend_Db_Expr('COUNT(*)'))
        ) > 0;
    }

    /**
     * The only check running with bypassLimiter false, so it is the only one that exercises
     * keyword detection rather than what the providers find.
     */
    private function checkLimiterKeywords(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Limiter keywords (one query per keyword/synonym):</info>');

        $allPassed = true;
        foreach (self::LIMITER_KEYWORD_CASES as $case) {
            $outcome = $searchService->search($case['query'], bypassLimiter: false);
            $matched = $outcome->limiter !== null && $outcome->limiter->providerKey === $case['expectProviderKey'];

            $allPassed = $allPassed && $matched;
            $output->writeln(sprintf(
                '  %s "%s" -> provider "%s" (%s)',
                $matched ? '<info>[PASS]</info>' : '<error>[FAIL]</error>',
                $case['query'],
                $case['expectProviderKey'],
                $case['description']
            ));
        }

        return $allPassed;
    }

    /**
     * Builds the URL from this install's configured base URL, not a hardcoded domain, so the
     * check cannot silently no-op on a differently configured install.
     */
    private function checkUrlPasteResolution(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>URL-paste resolution:</info>');

        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');

        $resolvedOutcome = $searchService->search($baseUrl.'/joust-duffle-bag.html', bypassLimiter: true);
        $resolved = count($resolvedOutcome->results) === 1 && $resolvedOutcome->results[0]->matchedFromPastedLink === true;

        $gracefulOutcome = $searchService->search(
            $baseUrl.'/this-path-does-not-exist-quicknav-diagnose.html',
            bypassLimiter: true
        );
        $graceful = $gracefulOutcome->urlPasteAttempted === true && $gracefulOutcome->results === [];

        $output->writeln(sprintf(
            '  %s pasted known-good product URL resolves to a single matched result',
            $resolved ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));
        $output->writeln(sprintf(
            '  %s pasted known-domain, unresolvable path fails gracefully (no fallback text search)',
            $graceful ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));

        return $resolved && $graceful;
    }

    /**
     * Losing scopeChoices removes the widget's scope picker without breaking any label match, so
     * no other check here would notice.
     */
    private function checkScopeAwareness(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Scope awareness (scopeChoices present where expected):</info>');

        $outcome = $searchService->search('24-mb01', bypassLimiter: true);
        $product = $this->firstResultOfType($outcome, 'product');
        $hasScopeChoices = $product !== null && ! empty($product->scopeChoices);

        $output->writeln(sprintf(
            '  %s product result carries non-empty scopeChoices (store-view editable)',
            $hasScopeChoices ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));

        return $hasScopeChoices;
    }

    /**
     * The "bags" case above asserts only on the label, so it would not notice these two fields
     * disappearing from the contract.
     */
    private function checkCategoryDrilldown(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Category drill-down (productCount + secondaryActionUrl present):</info>');

        $outcome = $searchService->search('bags', bypassLimiter: true);
        $category = $this->firstResultOfType($outcome, 'category');
        $hasCount = $category !== null && $category->productCount !== null;
        $hasSecondaryUrl = $category !== null
            && $category->secondaryActionUrl !== null
            && str_contains($category->secondaryActionUrl, 'category_id');

        $output->writeln(sprintf(
            '  %s "Bags" category result has a non-null productCount',
            $hasCount ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));
        $output->writeln(sprintf(
            '  %s "Bags" category result has a secondaryActionUrl (View products)',
            $hasSecondaryUrl ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));

        return $hasCount && $hasSecondaryUrl;
    }

    /**
     * Scoped to ConfigSectionProvider because it is the only provider implementing typo tolerance.
     */
    private function checkFuzzyTolerance(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Fuzzy/typo tolerance (config search only — no other provider implements it):</info>');

        $outcome = $searchService->search('taxx', bypassLimiter: true);
        $fuzzyMatch = null;
        foreach ($outcome->results as $result) {
            if (stripos($result->label, 'Tax') !== false) {
                $fuzzyMatch = $result;
                break;
            }
        }
        $found = $fuzzyMatch !== null;
        $isFuzzyTier = $fuzzyMatch !== null && $fuzzyMatch->matchTier === MatchTier::FUZZY;

        $output->writeln(sprintf(
            '  %s "taxx" (typo for "tax") still finds "Tax"',
            $found ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));
        $output->writeln(sprintf(
            '  %s match tier is FUZZY, not silently upgraded to exact/prefix/contains',
            $isFuzzyTier ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));
        $output->writeln(
            '  <comment>Note: only ConfigSectionProvider implements typo tolerance today — '
            .'product/customer/order/etc. searches are exact/prefix/contains only.</comment>'
        );

        return $found && $isFuzzyTier;
    }

    /**
     * An explicit type filter must restrict a bare multi-type query and override a keyword
     * limiter's implied scope without surfacing its banner.
     */
    private function checkTypeFilterOverride(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Type-filter override (explicit filter restricts/overrides limiter scoping):</info>');

        $filteredOutcome = $searchService->search('bag', bypassLimiter: true, typeFilter: ['product']);
        $onlyProducts = $filteredOutcome->results !== [] && $this->allResultsOfType($filteredOutcome, 'product');

        $conflictOutcome = $searchService->search('product bag', bypassLimiter: false, typeFilter: ['category']);
        $onlyCategoryNoBanner = $this->allResultsOfType($conflictOutcome, 'category') && $conflictOutcome->limiter === null;

        $output->writeln(sprintf(
            '  %s typeFilter=[product] on "bag" returns only product results',
            $onlyProducts ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));
        $output->writeln(sprintf(
            '  %s typeFilter=[category] overrides "product" limiter keyword, no banner shown',
            $onlyCategoryNoBanner ? '<info>[PASS]</info>' : '<error>[FAIL]</error>'
        ));

        return $onlyProducts && $onlyCategoryNoBanner;
    }

    /**
     * Duplicate result ids crash Alpine's `x-for :key` silently, with no error anywhere. "countr"
     * hits at least 8 groups of config fields sharing a URL through plain matching, with no
     * fuzzy-match internals involved.
     */
    private function checkNoDuplicateResultIds(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>Result id uniqueness (regression: config fields sharing a group URL):</info>');

        $outcome = $searchService->search('countr', bypassLimiter: true);
        $ids = array_map(static fn (SearchResult $result) => $result->toArray()['id'], $outcome->results);
        $unique = count($ids) === count(array_unique($ids));

        $output->writeln(sprintf(
            '  %s "countr" -> %d result(s), all ids unique',
            $unique ? '<info>[PASS]</info>' : '<error>[FAIL]</error>',
            count($ids)
        ));

        return $unique;
    }

    /**
     * Catches "findable for the one hardcoded sample, broken for everything else" by searching
     * real rows from each end of every source table. Ordered by id rather than RAND() so a
     * failure is reproducible.
     */
    private function checkAggregateCoverage(SearchService $searchService, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln(
            '<info>Aggregate coverage (a handful of real rows per provider, not just one '
            .'hardcoded sample):</info>'
        );

        $connection = $this->resourceConnection->getConnection();
        $allPassed = true;

        foreach (self::AGGREGATE_COVERAGE_TABLES as $providerKey => $spec) {
            $allPassed = $this->checkAggregateCoverageForTable($connection, $searchService, $output, $providerKey, $spec)
                && $allPassed;
        }

        $allPassed = $this->checkAggregateCoverageForCategory($connection, $searchService, $output) && $allPassed;

        return $allPassed;
    }

    /**
     * @param  array{table: string, idColumn: string, searchColumn: string}  $spec
     */
    private function checkAggregateCoverageForTable(
        AdapterInterface $connection,
        SearchService $searchService,
        OutputInterface $output,
        string $providerKey,
        array $spec
    ): bool {
        $table = $this->resourceConnection->getTableName($spec['table']);
        $totalEligible = (int) $connection->fetchOne(
            $connection->select()->from($table, [new \Zend_Db_Expr('COUNT(*)')])
        );

        if ($totalEligible === 0) {
            $output->writeln(sprintf(
                '  <comment>[EMPTY]</comment> %-12s no rows to sample — nothing this check can verify either way',
                $providerKey
            ));

            return true;
        }

        $sampleIds = array_unique(array_merge(
            $connection->fetchCol(
                $connection->select()->from($table, [$spec['idColumn']])->order($spec['idColumn'].' ASC')
                    ->limit(self::AGGREGATE_SAMPLE_SIZE)
            ),
            $connection->fetchCol(
                $connection->select()->from($table, [$spec['idColumn']])->order($spec['idColumn'].' DESC')
                    ->limit(self::AGGREGATE_SAMPLE_SIZE)
            )
        ));

        $searchTexts = $connection->fetchCol(
            $connection->select()->from($table, [$spec['searchColumn']])->where($spec['idColumn'].' IN (?)', $sampleIds)
        );

        return $this->reportAggregateCoverageResult(
            $output,
            $providerKey,
            $totalEligible,
            $this->countFindable($searchService, $providerKey, $searchTexts)
        );
    }

    private function checkAggregateCoverageForCategory(
        AdapterInterface $connection,
        SearchService $searchService,
        OutputInterface $output
    ): bool {
        $categoryTable = $this->resourceConnection->getTableName('catalog_category_entity');
        $varcharTable = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_category', 'name')->getAttributeId();

        $totalEligible = (int) $connection->fetchOne(
            $connection->select()->from($categoryTable, [new \Zend_Db_Expr('COUNT(*)')])
        );

        if ($totalEligible === 0) {
            $output->writeln('  <comment>[EMPTY]</comment> category     no rows to sample — nothing this check can verify either way');

            return true;
        }

        $sampleIds = array_unique(array_merge(
            $connection->fetchCol(
                $connection->select()->from($categoryTable, ['entity_id'])->order('entity_id ASC')
                    ->limit(self::AGGREGATE_SAMPLE_SIZE)
            ),
            $connection->fetchCol(
                $connection->select()->from($categoryTable, ['entity_id'])->order('entity_id DESC')
                    ->limit(self::AGGREGATE_SAMPLE_SIZE)
            )
        ));

        $names = $connection->fetchPairs(
            $connection->select()
                ->from($varcharTable, ['entity_id', 'value'])
                ->where('entity_id IN (?)', $sampleIds)
                ->where('attribute_id = ?', $nameAttributeId)
                ->where('store_id = ?', 0)
        );

        return $this->reportAggregateCoverageResult(
            $output,
            'category',
            $totalEligible,
            $this->countFindable($searchService, 'category', array_values($names))
        );
    }

    /**
     * @param  array<int, mixed>  $searchTexts
     * @return array{0: int, 1: int} [findable count, sampled count]
     */
    private function countFindable(SearchService $searchService, string $providerKey, array $searchTexts): array
    {
        $foundCount = 0;
        $sampledCount = 0;
        foreach ($searchTexts as $searchText) {
            $searchText = (string) $searchText;
            if ($searchText === '') {
                continue;
            }
            $sampledCount++;

            $outcome = $searchService->search($searchText, bypassLimiter: true);
            if ($this->anyResultMatchesText($outcome, $providerKey, $searchText)) {
                $foundCount++;
            }
        }

        return [$foundCount, $sampledCount];
    }

    /**
     * @param  array{0: int, 1: int}  $foundAndSampled
     */
    private function reportAggregateCoverageResult(
        OutputInterface $output,
        string $providerKey,
        int $totalEligible,
        array $foundAndSampled
    ): bool {
        [$foundCount, $sampledCount] = $foundAndSampled;

        if ($sampledCount === 0) {
            $output->writeln(sprintf(
                '  <comment>[EMPTY]</comment> %-12s sampled rows had no usable search text — nothing this check can verify either way',
                $providerKey
            ));

            return true;
        }

        $passed = $foundCount === $sampledCount;
        $output->writeln(sprintf(
            '  %s %-12s %d/%d sampled rows findable (%d total eligible)',
            $passed ? '<info>[PASS]</info>' : '<error>[FAIL]</error>',
            $providerKey,
            $foundCount,
            $sampledCount,
            $totalEligible
        ));

        return $passed;
    }

    private function anyResultMatchesText(SearchOutcome $outcome, string $expectedEntityType, string $text): bool
    {
        foreach ($outcome->results as $result) {
            if ($result->entityType !== $expectedEntityType) {
                continue;
            }
            if (stripos($result->label, $text) !== false || stripos($result->context, $text) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only a reachable-but-empty index counts as a failure. Disabled or unreachable is reported
     * but passes, because narrowing is fail-soft and providers fall back to a full scan.
     */
    private function checkOpenSearchHealth(OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>OpenSearch health (large-dataset candidate-narrowing index):</info>');

        if (! $this->openSearchConfig->isEnabled()) {
            $output->writeln(
                '  <comment>[DISABLED]</comment> quicknav_search/opensearch/enabled=0 — nothing to '
                .'check, product/customer/order/coupon_code providers fall back to the full scan.'
            );

            return true;
        }

        $reachable = $this->openSearchAvailability->isAvailable();
        $output->writeln(sprintf(
            '  %s reachable',
            $reachable ? '<info>[OK]</info>   ' : '<error>[DOWN]</error>'
        ));

        if (! $reachable) {
            $output->writeln(
                '  <comment>Enabled but unreachable — providers fall back to the unmodified full '
                .'scan (fail-soft by design), not treated as a hard failure here.</comment>'
            );

            return true;
        }

        $client = $this->openSearchClientFactory->create();
        $indexName = $this->openSearchConfig->getIndexPrefix().'_search';
        $exists = $client !== null && $client->indices()->exists(['index' => $indexName]);
        $output->writeln(sprintf(
            '  %s index "%s" exists',
            $exists ? '<info>[OK]</info>   ' : '<error>[MISSING]</error>',
            $indexName
        ));

        if (! $exists) {
            $output->writeln(
                '  <comment>Run `bin/magento indexer:reindex bigbridge_quicknav_product_search` '
                .'(and the customer/order/coupon siblings) to create and populate it.</comment>'
            );

            return false;
        }

        $healthy = true;
        foreach (self::OPENSEARCH_ENTITY_TYPES as $entityType) {
            $count = (int) ($client->count([
                'index' => $indexName,
                'body' => ['query' => ['term' => ['entity_type' => $entityType]]],
            ])['count'] ?? 0);
            $isEmpty = $count === 0;
            $healthy = $healthy && ! $isEmpty;
            $output->writeln(sprintf(
                '  %s %-14s %d document(s)',
                $isEmpty ? '<error>[EMPTY]</error>' : '<info>[OK]</info>   ',
                $entityType,
                $count
            ));
        }

        return $healthy;
    }

    private function firstResultOfType(SearchOutcome $outcome, string $type): ?SearchResult
    {
        foreach ($outcome->results as $result) {
            if ($result->entityType === $type) {
                return $result;
            }
        }

        return null;
    }

    private function allResultsOfType(SearchOutcome $outcome, string $type): bool
    {
        foreach ($outcome->results as $result) {
            if ($result->entityType !== $type) {
                return false;
            }
        }

        return true;
    }

    private function buildDiagnosticSearchService(): SearchService
    {
        $allowAll = $this->objectManager->create(AllowAllAuthorization::class);

        $providers = [];
        foreach (self::PROVIDER_CLASSES as $key => $class) {
            $providers[$key] = $this->objectManager->create($class, ['authorization' => $allowAll]);
        }

        // UrlPasteResolver runs its own ACL check, so it needs the same override the providers
        // get, or every pasted-link case reports access-denied in this session-less process.
        $urlPasteResolver = $this->objectManager->create(UrlPasteResolver::class, ['authorization' => $allowAll]);

        return $this->objectManager->create(SearchService::class, [
            'providers' => $providers,
            'urlPasteResolver' => $urlPasteResolver,
        ]);
    }
}

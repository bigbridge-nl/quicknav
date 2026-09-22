<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Storefront\StorefrontLinkBuilder;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Matching itself joins only the name and url_path EAV varchar tables. The parent chain is
 * walked afterwards, for the returned rows only, to build breadcrumbs.
 */
class CategoryProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'category';

    private const ICON_KEY = 'folder-tree';

    /** Default/admin scope, per Magento EAV convention. */
    private const DEFAULT_STORE_ID = 0;

    /** Root Catalog (1) and Default Category (2) are not meaningful breadcrumb context. */
    private const BREADCRUMB_SKIP_IDS = [1, 2];

    /** Access control: the same resource Magento's own category controller requires. */
    private const ACL_RESOURCE = 'Magento_Catalog::categories';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuthorizationInterface $authorization,
        private readonly StorefrontLinkBuilder $storefrontLinkBuilder,
    ) {}

    public function search(string $query, int $limit): array
    {
        $normalizedQuery = mb_strtolower(trim($query));
        if ($normalizedQuery === '') {
            return [];
        }

        if (! $this->authorization->isAllowed(self::ACL_RESOURCE)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $categoryTable = $this->resourceConnection->getTableName('catalog_category_entity');
        $varcharTable = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_category', 'name')->getAttributeId();
        $urlPathAttributeId = (int) $this->eavConfig->getAttribute('catalog_category', 'url_path')->getAttributeId();

        $select = $connection->select()
            ->from(['e' => $categoryTable], ['entity_id', 'path'])
            ->joinLeft(
                ['name_attr' => $varcharTable],
                $connection->quoteInto(
                    'name_attr.entity_id = e.entity_id AND name_attr.attribute_id = ? AND name_attr.store_id = '
                    .self::DEFAULT_STORE_ID,
                    $nameAttributeId
                ),
                ['name' => 'name_attr.value']
            )
            ->joinLeft(
                ['url_path_attr' => $varcharTable],
                $connection->quoteInto(
                    'url_path_attr.entity_id = e.entity_id AND url_path_attr.attribute_id = ? '
                    .'AND url_path_attr.store_id = '.self::DEFAULT_STORE_ID,
                    $urlPathAttributeId
                ),
                ['url_path' => 'url_path_attr.value']
            )
            // Over-fetch so exact/prefix matches aren't crowded out by contains matches
            // before sorting; the shared SearchService cap still applies on top of this.
            ->limit($limit * 3);

        // Each word must match name or url_path on its own, rather than the query matching as
        // one substring: url_path encodes the ancestor chain as slug segments, so a breadcrumb
        // query like "tops jackets" never appears verbatim in "men/tops-men/jackets-men".
        foreach (explode(' ', $normalizedQuery) as $word) {
            if ($word === '') {
                continue;
            }
            $likeParam = '%'.$word.'%';
            $select->where(
                '('
                .$connection->quoteInto('name_attr.value LIKE ?', $likeParam)
                .' OR '
                .$connection->quoteInto('url_path_attr.value LIKE ?', $likeParam)
                .')'
            );
        }

        $rows = $connection->fetchAll($select);

        if ($rows === []) {
            return [];
        }

        $ancestorNames = $this->fetchAncestorNames($connection, $varcharTable, $nameAttributeId, $rows);
        $productCounts = $this->fetchProductCounts($connection, $rows);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->buildResult($row, $normalizedQuery, $ancestorNames, $productCounts);
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    /**
     * @param  array[]  $rows
     * @return array<int, string> ancestor category entity_id => name
     */
    private function fetchAncestorNames(
        AdapterInterface $connection,
        string $varcharTable,
        int $nameAttributeId,
        array $rows
    ): array {
        $ancestorIds = [];
        foreach ($rows as $row) {
            foreach ($this->ancestorIdsFromPath((string) $row['path'], (int) $row['entity_id']) as $id) {
                $ancestorIds[$id] = true;
            }
        }

        if ($ancestorIds === []) {
            return [];
        }

        $nameRows = $connection->fetchPairs(
            $connection->select()
                ->from($varcharTable, ['entity_id', 'value'])
                ->where('entity_id IN (?)', array_keys($ancestorIds))
                ->where('attribute_id = ?', $nameAttributeId)
                ->where('store_id = ?', self::DEFAULT_STORE_ID)
        );

        return $nameRows;
    }

    /**
     * @return int[]
     */
    private function ancestorIdsFromPath(string $path, int $ownId): array
    {
        $ids = array_map('intval', array_filter(explode('/', $path)));

        return array_values(array_filter(
            $ids,
            static fn (int $id) => $id !== $ownId && ! in_array($id, self::BREADCRUMB_SKIP_IDS, true)
        ));
    }

    /**
     * Counts come from `catalog_category_product_index_store{N}` rather than a live join, in one
     * query for every matched category.
     *
     * @param  array[]  $rows
     * @return array<int, int> category entity_id => product count
     */
    private function fetchProductCounts(AdapterInterface $connection, array $rows): array
    {
        $categoryIds = array_map(static fn (array $row) => (int) $row['entity_id'], $rows);
        $storeId = (int) $this->storeManager->getStore()->getId();
        $indexTable = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$storeId);

        return $connection->fetchPairs(
            $connection->select()
                ->from($indexTable, ['category_id', 'count' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('category_id IN (?)', $categoryIds)
                ->where('store_id = ?', $storeId)
                ->group('category_id')
        );
    }

    private function buildResult(array $row, string $normalizedQuery, array $ancestorNames, array $productCounts): SearchResult
    {
        $name = (string) ($row['name'] ?? '');
        $urlPath = (string) ($row['url_path'] ?? '');
        $categoryId = (int) $row['entity_id'];
        $breadcrumb = implode(' > ', array_map(
            static fn (int $id) => $ancestorNames[$id] ?? '',
            $this->ancestorIdsFromPath((string) $row['path'], $categoryId)
        ));

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name,
            context: $breadcrumb,
            url: $this->backendUrl->getUrl('catalog/category/edit', ['id' => $categoryId]),
            matchTier: $this->matchTier(mb_strtolower($name), mb_strtolower($urlPath), $normalizedQuery),
            // fetchPairs() returns COUNT(*) as a numeric string, not a native int.
            productCount: (int) ($productCounts[$categoryId] ?? 0),
            secondaryActionUrl: $this->backendUrl->getUrl('quicknav/category/products', ['category_id' => $categoryId]),
            secondaryActionLabel: (string) __('View products'),
            // Storefront view links (014): computed per matched row, same as ProductProvider.
            storefrontChoices: $this->storefrontLinkBuilder->build(self::ENTITY_TYPE, $categoryId),
        );
    }

    private function matchTier(string $name, string $urlPath, string $normalizedQuery): int
    {
        if ($name === $normalizedQuery || $urlPath === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($name, $normalizedQuery) || str_starts_with($urlPath, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

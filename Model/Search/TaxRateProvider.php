<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Matches a query against tax rate code.
 */
class TaxRateProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'tax_rate';

    private const ICON_KEY = 'percent';

    /** Same ACL resource as TaxRuleProvider — both controllers share this one resource. */
    private const ACL_RESOURCE = 'Magento_Tax::manage_tax';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
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
        $table = $this->resourceConnection->getTableName('tax_calculation_rate');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['tax_calculation_rate_id', 'code', 'tax_country_id', 'rate'])
                ->where('code LIKE ?', '%'.$normalizedQuery.'%')
                // Over-fetch so exact/prefix matches aren't crowded out by contains matches
                // before sorting; the shared SearchService cap still applies on top of this.
                ->limit($limit * 3)
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->buildResult($row, $normalizedQuery);
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    private function buildResult(array $row, string $normalizedQuery): SearchResult
    {
        $code = (string) ($row['code'] ?? '');
        $countryId = (string) ($row['tax_country_id'] ?? '');
        $rate = (string) ($row['rate'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $code,
            context: $countryId.' · '.$rate.'%',
            // The controller's param is confusingly named "rate" but takes the rate's own id.
            url: $this->backendUrl->getUrl('tax/rate/edit', ['rate' => (int) $row['tax_calculation_rate_id']]),
            matchTier: $this->matchTier(mb_strtolower($code), $normalizedQuery),
        );
    }

    private function matchTier(string $code, string $normalizedQuery): int
    {
        if ($code === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($code, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

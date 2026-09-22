<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Matches a query against tax rule code.
 */
class TaxRuleProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'tax_rule';

    private const ICON_KEY = 'calculator';

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
        $table = $this->resourceConnection->getTableName('tax_calculation_rule');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['tax_calculation_rule_id', 'code'])
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

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $code,
            context: '',
            // The controller's param is literally named "rule" (not "id"/"rule_id").
            url: $this->backendUrl->getUrl('tax/rule/edit', ['rule' => (int) $row['tax_calculation_rule_id']]),
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

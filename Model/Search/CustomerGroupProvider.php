<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Matches a query against customer group code.
 */
class CustomerGroupProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'customer_group';

    private const ICON_KEY = 'users';

    private const ACL_RESOURCE = 'Magento_Customer::group';

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
        $table = $this->resourceConnection->getTableName('customer_group');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['customer_group_id', 'customer_group_code'])
                ->where('customer_group_code LIKE ?', '%'.$normalizedQuery.'%')
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
        $code = (string) ($row['customer_group_code'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $code,
            context: '',
            url: $this->backendUrl->getUrl('customer/group/edit', ['id' => (int) $row['customer_group_id']]),
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

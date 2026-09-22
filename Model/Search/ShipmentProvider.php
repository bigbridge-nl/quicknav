<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Reads the maintained `sales_shipment_grid` table rather than joining the entity tables.
 */
class ShipmentProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'shipment';

    private const ICON_KEY = 'truck';

    private const ACL_RESOURCE = 'Magento_Sales::shipment';

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
        $table = $this->resourceConnection->getTableName('sales_shipment_grid');
        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['entity_id', 'increment_id', 'customer_name'])
                ->where('increment_id LIKE ?', $likeParam)
                ->orWhere('customer_name LIKE ?', $likeParam)
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
        $incrementId = (string) ($row['increment_id'] ?? '');
        $customerName = (string) ($row['customer_name'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: '#'.$incrementId,
            context: $customerName,
            url: $this->backendUrl->getUrl('sales/shipment/view', ['shipment_id' => (int) $row['entity_id']]),
            matchTier: $this->matchTier(mb_strtolower($incrementId), mb_strtolower($customerName), $normalizedQuery),
        );
    }

    private function matchTier(string $incrementId, string $customerName, string $normalizedQuery): int
    {
        if ($incrementId === $normalizedQuery || $customerName === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($incrementId, $normalizedQuery) || str_starts_with($customerName, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

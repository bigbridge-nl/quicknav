<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Search\OpenSearch\CandidateIdNarrower;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Reads the maintained `sales_order_grid` table rather than live-joining `sales_order` with the
 * customer and address tables.
 */
class OrderProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'order';

    private const ICON_KEY = 'receipt';

    /** Access control (010 security audit): the same resource Magento's own sales order grid requires. */
    private const ACL_RESOURCE = 'Magento_Sales::sales_order';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
        private readonly ?CandidateIdNarrower $candidateIdNarrower = null,
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

        $candidateIds = $this->candidateIdNarrower?->narrow(self::ENTITY_TYPE, $normalizedQuery, $limit);
        if ($candidateIds === []) {
            return []; // OpenSearch reachable, genuinely zero matches — trust it
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order_grid');
        $likeParam = '%'.$normalizedQuery.'%';

        // Must stay one where() call so the OR is a single parenthesized unit. Split across two,
        // AND binds tighter than OR and the entity_id IN() clause below would apply to only one
        // branch of it.
        $textMatch = $connection->quoteInto('increment_id LIKE ?', $likeParam)
            .' OR '.$connection->quoteInto('customer_name LIKE ?', $likeParam);

        $select = $connection->select()
            ->from($table, ['entity_id', 'increment_id', 'customer_name'])
            ->where($textMatch)
            ->limit($limit * 3);

        if ($candidateIds !== null) {
            $select->where('entity_id IN (?)', $candidateIds);
        }

        $rows = $connection->fetchAll($select);

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
            url: $this->backendUrl->getUrl('sales/order/view', ['order_id' => (int) $row['entity_id']]),
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

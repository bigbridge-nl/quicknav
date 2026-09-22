<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Search\OpenSearch\CandidateIdNarrower;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Reads the maintained `customer_grid_flat` table rather than live-joining `customer_entity`
 * with its EAV attribute tables.
 */
class CustomerProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'customer';

    private const ICON_KEY = 'user';

    /** Access control (010 security audit): the same resource Magento's own customer grid/edit controllers require. */
    private const ACL_RESOURCE = 'Magento_Customer::manage';

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
        $table = $this->resourceConnection->getTableName('customer_grid_flat');
        $likeParam = '%'.$normalizedQuery.'%';

        // Must stay one where() call so the OR is a single parenthesized unit. Split across two,
        // AND binds tighter than OR and the entity_id IN() clause below would apply to only one
        // branch of it.
        $textMatch = $connection->quoteInto('name LIKE ?', $likeParam)
            .' OR '.$connection->quoteInto('email LIKE ?', $likeParam);

        $select = $connection->select()
            ->from($table, ['entity_id', 'name', 'email'])
            ->where($textMatch)
            // Over-fetch so exact/prefix matches aren't crowded out by contains matches
            // before sorting; the shared SearchService cap still applies on top of this.
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
        $name = (string) ($row['name'] ?? '');
        $email = (string) ($row['email'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name !== '' ? $name : $email,
            context: $email,
            url: $this->backendUrl->getUrl('customer/index/edit', ['id' => (int) $row['entity_id']]),
            matchTier: $this->matchTier(mb_strtolower($name), mb_strtolower($email), $normalizedQuery),
        );
    }

    private function matchTier(string $name, string $email, string $normalizedQuery): int
    {
        if ($name === $normalizedQuery || $email === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($name, $normalizedQuery) || str_starts_with($email, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Matches `search_query`, the storefront phrases customers have typed, and links to the term's
 * own edit row.
 */
class SearchTermProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'search_term';

    private const ICON_KEY = 'search-check';

    /** Access control (010 security audit): the same resource Magento's own Search Terms grid requires. */
    private const ACL_RESOURCE = 'Magento_Search::search';

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

        // Access control (010 security audit): this provider previously had no ACL check at all.
        if (! $this->authorization->isAllowed(self::ACL_RESOURCE)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('search_query');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['query_id', 'query_text', 'num_results', 'popularity'])
                ->where('query_text LIKE ?', '%'.$normalizedQuery.'%')
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
        $queryText = (string) ($row['query_text'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $queryText,
            context: $row['num_results'].' results · popularity '.$row['popularity'],
            url: $this->backendUrl->getUrl('search/term/edit', ['id' => (int) $row['query_id']]),
            matchTier: $this->matchTier(mb_strtolower($queryText), $normalizedQuery),
        );
    }

    private function matchTier(string $queryText, string $normalizedQuery): int
    {
        if ($queryText === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($queryText, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

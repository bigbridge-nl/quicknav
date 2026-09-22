<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Matches against the stored phrase as-is ("shoe,shoes,sneaker"), not against split synonyms.
 */
class SearchSynonymProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'search_synonym';

    private const ICON_KEY = 'arrow-left-right';

    private const ACL_RESOURCE = 'Magento_Search::synonyms';

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
        $table = $this->resourceConnection->getTableName('search_synonyms');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['group_id', 'synonyms'])
                ->where('synonyms LIKE ?', '%'.$normalizedQuery.'%')
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
        $synonyms = (string) ($row['synonyms'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $synonyms,
            context: '',
            url: $this->backendUrl->getUrl('search/synonyms/edit', ['group_id' => (int) $row['group_id']]),
            matchTier: $this->matchTier(mb_strtolower($synonyms), $normalizedQuery),
        );
    }

    private function matchTier(string $synonyms, string $normalizedQuery): int
    {
        if ($synonyms === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($synonyms, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Index\MenuIndexStorage;
use BigBridge\QuickNav\Model\Store\Scopable;
use BigBridge\QuickNav\Model\Store\ScopeChoiceBuilder;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads the compiled index table only, never re-parsing system.xml/menu.xml at request time.
 */
class ConfigSectionProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = [
        'config_section' => 'config_section',
        'config_group' => 'config_group',
        'config_field' => 'config_field',
        'menu_item' => 'menu_item',
    ];

    /** config_group, config_field, and menu_item each get their own icon, distinct from config_section's gear. */
    private const ICON_KEY = [
        'config_section' => 'settings',
        'config_group' => 'sliders-horizontal',
        'config_field' => 'tag',
        'menu_item' => 'menu',
    ];

    /** Fuzzy fallback only kicks in below this row count; keeps it cheap on a larger table. */
    private const FUZZY_CANDIDATE_LIMIT = 2000;

    /** config_section/config_group/config_field rows are scope-aware; menu_item rows never are. */
    private const SCOPABLE_RECORD_TYPES = ['config_section', 'config_group', 'config_field'];

    /** config_group and config_field both link into their parent group's anchor on the section-edit page. */
    private const GROUP_ANCHORED_RECORD_TYPES = ['config_group', 'config_field'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly ScopeChoiceBuilder $scopeChoiceBuilder,
        private readonly AuthorizationInterface $authorization,
    ) {}

    public function search(string $query, int $limit): array
    {
        $normalizedQuery = mb_strtolower(trim($query));
        if ($normalizedQuery === '') {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(MenuIndexStorage::TABLE);

        // The `?` placeholder quotes the value but does not escape literal "%" and "_" wildcards
        // in the query, so those are still treated as wildcards. Accepted tradeoff.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('search_label LIKE ?', '%'.$normalizedQuery.'%')
                ->limit($limit * 3) // over-fetch so exact/prefix matches aren't crowded out before sorting
        );

        $results = [];
        $seenLabels = [];
        foreach ($rows as $row) {
            $seenLabels[$row['search_label']] = true;
            if (! $this->isAllowed($row)) {
                continue;
            }
            $results[] = $this->buildResult($row, $normalizedQuery, $this->matchTier($row['search_label'], $normalizedQuery));
        }

        if (count($results) < $limit) {
            $results = array_merge(
                $results,
                $this->fuzzyMatches($connection, $table, $normalizedQuery, $seenLabels, $limit - count($results))
            );
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    private function isAllowed(array $row): bool
    {
        return $this->authorization->isAllowed($row['acl_resource']);
    }

    private function matchTier(string $searchLabel, string $normalizedQuery): int
    {
        if ($searchLabel === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($searchLabel, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }

    /**
     * LIKE misses near-misses such as "taxx" or "tx" for "tax", since neither is a substring of
     * the other. This table holds a few thousand rows, so scoring the remainder by edit distance
     * is cheap enough to do per query.
     */
    private function fuzzyMatches(
        AdapterInterface $connection,
        string $table,
        string $normalizedQuery,
        array $seenLabels,
        int $limit
    ): array {
        if ($limit <= 0 || mb_strlen($normalizedQuery) < 2) {
            return [];
        }

        $candidates = $connection->fetchAll(
            $connection->select()->from($table)->limit(self::FUZZY_CANDIDATE_LIMIT)
        );

        $maxDistance = mb_strlen($normalizedQuery) <= 4 ? 1 : 2;
        $scored = [];

        foreach ($candidates as $row) {
            if (isset($seenLabels[$row['search_label']])) {
                continue;
            }
            if (! $this->isAllowed($row)) {
                continue;
            }

            $distance = levenshtein($normalizedQuery, $row['search_label']);
            // Also compare against the closest-length substring so a short query like "tx"
            // can fuzzy-match a much longer label ("tax") without being penalized for length.
            $distance = min($distance, $this->bestSubstringDistance($normalizedQuery, $row['search_label']));

            if ($distance <= $maxDistance) {
                $scored[] = [$distance, $row];
            }
        }

        usort($scored, static fn ($a, $b) => $a[0] <=> $b[0]);

        $results = [];
        foreach (array_slice($scored, 0, $limit) as [, $row]) {
            $results[] = $this->buildResult($row, $normalizedQuery, MatchTier::FUZZY);
        }

        return $results;
    }

    private function bestSubstringDistance(string $needle, string $haystack): int
    {
        $needleLength = mb_strlen($needle);
        $haystackLength = mb_strlen($haystack);
        if ($needleLength >= $haystackLength) {
            return levenshtein($needle, $haystack);
        }

        $best = PHP_INT_MAX;
        for ($offset = 0; $offset <= $haystackLength - $needleLength; $offset++) {
            $best = min($best, levenshtein($needle, mb_substr($haystack, $offset, $needleLength)));
        }

        return $best;
    }

    private function buildResult(array $row, string $normalizedQuery, int $matchTier): SearchResult
    {
        $params = $row['section_id'] ? ['section' => $row['section_id']] : [];
        $url = $this->backendUrl->getUrl($row['admin_path'], $params);
        $anchor = '';

        if (in_array($row['record_type'], self::GROUP_ANCHORED_RECORD_TYPES, true) && $row['group_id']) {
            $anchor = '#'.$row['group_id'].'-head';
            $url .= $anchor;
        }

        $isScopable = in_array($row['record_type'], self::SCOPABLE_RECORD_TYPES, true);
        $scopable = $isScopable ? ($row['scopable'] ?? Scopable::DEFAULT_ONLY) : null;
        $scopeChoices = $scopable !== null
            ? $this->appendAnchor($this->scopeChoiceBuilder->build($row['admin_path'], $params, $scopable), $anchor)
            : [];

        return new SearchResult(
            entityType: self::ENTITY_TYPE[$row['record_type']] ?? $row['record_type'],
            iconKey: self::ICON_KEY[$row['record_type']] ?? 'link',
            label: $row['label'],
            context: (string) $row['breadcrumb'],
            url: $url,
            matchTier: $matchTier,
            scopable: $scopable,
            scopeChoices: $scopeChoices !== [] ? $scopeChoices : null,
        );
    }

    /**
     * @param  array<int, array{type: string, id: ?int, websiteId: ?int, label: string, url: string}>  $choices
     * @return array<int, array{type: string, id: ?int, websiteId: ?int, label: string, url: string}>
     */
    private function appendAnchor(array $choices, string $anchor): array
    {
        if ($anchor === '') {
            return $choices;
        }

        foreach ($choices as &$choice) {
            $choice['url'] .= $anchor;
        }

        return $choices;
    }
}

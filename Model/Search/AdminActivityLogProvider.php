<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Reads `admin_activity`, not `admin_activity_log` — the latter is a field-diff detail table
 * keyed by activity_id, not a record worth matching on. MageOS_AdminActivityLog has no
 * per-record page, so results link to the grid.
 */
class AdminActivityLogProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'admin_activity_log';

    private const ICON_KEY = 'history';

    private const ACL_RESOURCE = 'MageOS_AdminActivityLog::activity';

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
        $table = $this->resourceConnection->getTableName('admin_activity');

        // MageOS_AdminActivityLog is optional, and nothing else in this module depends on it, so
        // its absence has to read as "no results" rather than a failed query that takes the whole
        // search down with it.
        if (! $connection->isTableExists($table)) {
            return [];
        }

        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['entity_id', 'username', 'module', 'fullaction', 'item_name', 'created_at'])
                ->where('username LIKE ?', $likeParam)
                ->orWhere('module LIKE ?', $likeParam)
                ->orWhere('fullaction LIKE ?', $likeParam)
                ->orWhere('item_name LIKE ?', $likeParam)
                ->order('entity_id DESC')
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
        $username = (string) ($row['username'] ?? '');
        $module = (string) ($row['module'] ?? '');
        $fullaction = (string) ($row['fullaction'] ?? '');
        $itemName = (string) ($row['item_name'] ?? '');
        $label = $itemName !== '' ? $itemName : $fullaction;
        $createdAt = (string) ($row['created_at'] ?? '');

        // created_at must stay in the context: repeated identical actions share username,
        // module, fullaction and item_name, and there is no per-record `url` to tell them
        // apart either, so without it they all hash to the same result id.
        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $label,
            context: $username.' · '.$module.' · '.$createdAt,
            url: $this->backendUrl->getUrl('adminactivity/activity/index'),
            matchTier: $this->matchTier(
                mb_strtolower($username),
                mb_strtolower($module),
                mb_strtolower($fullaction),
                mb_strtolower($itemName),
                $normalizedQuery
            ),
        );
    }

    private function matchTier(string $username, string $module, string $fullaction, string $itemName, string $normalizedQuery): int
    {
        if ($username === $normalizedQuery || $module === $normalizedQuery || $fullaction === $normalizedQuery || $itemName === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($username, $normalizedQuery) || str_starts_with($module, $normalizedQuery) || str_starts_with($fullaction, $normalizedQuery) || str_starts_with($itemName, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

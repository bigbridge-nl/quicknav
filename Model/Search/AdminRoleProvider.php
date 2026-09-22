<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * `authorization_role` also holds one row per admin user (role_type 'U'), so only role_type 'G'
 * rows — actual named roles — are eligible.
 */
class AdminRoleProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'admin_role';

    private const ICON_KEY = 'shield-check';

    private const ACL_RESOURCE = 'Magento_User::acl_roles';

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
        $table = $this->resourceConnection->getTableName('authorization_role');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['role_id', 'role_name'])
                ->where('role_name LIKE ?', '%'.$normalizedQuery.'%')
                ->where('role_type = ?', 'G')
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
        $name = (string) ($row['role_name'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name,
            context: '',
            url: $this->backendUrl->getUrl('adminhtml/user_role/editrole', ['rid' => (int) $row['role_id']]),
            matchTier: $this->matchTier(mb_strtolower($name), $normalizedQuery),
        );
    }

    private function matchTier(string $name, string $normalizedQuery): int
    {
        if ($name === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($name, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

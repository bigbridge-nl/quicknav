<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

class AdminUserProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'admin_user';

    private const ICON_KEY = 'user-cog';

    private const ACL_RESOURCE = 'Magento_User::acl_users';

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
        $table = $this->resourceConnection->getTableName('admin_user');
        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['user_id', 'username', 'email', 'firstname', 'lastname'])
                ->where('username LIKE ?', $likeParam)
                ->orWhere('email LIKE ?', $likeParam)
                ->orWhere("CONCAT(firstname, ' ', lastname) LIKE ?", $likeParam)
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
        $username = (string) ($row['username'] ?? '');
        $email = (string) ($row['email'] ?? '');
        $fullName = trim(($row['firstname'] ?? '').' '.($row['lastname'] ?? ''));

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $fullName !== '' ? $fullName : $username,
            context: $username.' · '.$email,
            url: $this->backendUrl->getUrl('adminhtml/user/edit', ['user_id' => (int) $row['user_id']]),
            matchTier: $this->matchTier(mb_strtolower($username), mb_strtolower($fullName), mb_strtolower($email), $normalizedQuery),
        );
    }

    private function matchTier(string $username, string $fullName, string $email, string $normalizedQuery): int
    {
        if ($username === $normalizedQuery || $fullName === $normalizedQuery || $email === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (
            str_starts_with($username, $normalizedQuery)
            || str_starts_with($fullName, $normalizedQuery)
            || str_starts_with($email, $normalizedQuery)
        ) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

class IntegrationProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'integration';

    private const ICON_KEY = 'plug';

    private const ACL_RESOURCE = 'Magento_Integration::integrations';

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
        $table = $this->resourceConnection->getTableName('integration');
        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['integration_id', 'name', 'email'])
                ->where('name LIKE ?', $likeParam)
                ->orWhere('email LIKE ?', $likeParam)
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
        $name = (string) ($row['name'] ?? '');
        $email = (string) ($row['email'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name,
            context: $email,
            url: $this->backendUrl->getUrl('adminhtml/integration/edit', ['id' => (int) $row['integration_id']]),
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

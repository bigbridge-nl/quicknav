<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Text search over the rewrite rules themselves, to find a row to edit or delete. UrlPasteResolver
 * reads the same table for the opposite purpose: resolving a pasted URL to its entity.
 */
class UrlRewriteProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'url_rewrite';

    private const ICON_KEY = 'route';

    /** Access control (010 security audit): the same resource Magento's own URL Rewrites grid requires. */
    private const ACL_RESOURCE = 'Magento_UrlRewrite::urlrewrite';

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
        $table = $this->resourceConnection->getTableName('url_rewrite');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['url_rewrite_id', 'request_path', 'target_path'])
                ->where('request_path LIKE ?', '%'.$normalizedQuery.'%')
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
        $requestPath = (string) ($row['request_path'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $requestPath,
            context: (string) ($row['target_path'] ?? ''),
            url: $this->backendUrl->getUrl('adminhtml/url_rewrite/edit', ['id' => (int) $row['url_rewrite_id']]),
            matchTier: $this->matchTier(mb_strtolower($requestPath), $normalizedQuery),
        );
    }

    private function matchTier(string $requestPath, string $normalizedQuery): int
    {
        if ($requestPath === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($requestPath, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

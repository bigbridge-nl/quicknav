<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Storefront\StorefrontLinkBuilder;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * `cms_page` is not EAV: title and identifier are native columns, so no join is needed.
 */
class CmsPageProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'cms_page';

    private const ICON_KEY = 'file-text';

    /** Access control (010 security audit): the same resource Magento's own CMS page grid requires. */
    private const ACL_RESOURCE = 'Magento_Cms::page';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
        private readonly StorefrontLinkBuilder $storefrontLinkBuilder,
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
        $table = $this->resourceConnection->getTableName('cms_page');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['page_id', 'title', 'identifier'])
                ->where('title LIKE ?', '%'.$normalizedQuery.'%')
                ->orWhere('identifier LIKE ?', '%'.$normalizedQuery.'%')
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
        $title = (string) ($row['title'] ?? '');
        $identifier = (string) ($row['identifier'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $title,
            context: $identifier,
            url: $this->backendUrl->getUrl('cms/page/edit', ['page_id' => (int) $row['page_id']]),
            matchTier: $this->matchTier(mb_strtolower($title), mb_strtolower($identifier), $normalizedQuery),
            // Storefront view links (014): computed per matched row, same as ProductProvider.
            storefrontChoices: $this->storefrontLinkBuilder->build(self::ENTITY_TYPE, (int) $row['page_id']),
        );
    }

    private function matchTier(string $title, string $identifier, string $normalizedQuery): int
    {
        if ($title === $normalizedQuery || $identifier === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($title, $normalizedQuery) || str_starts_with($identifier, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

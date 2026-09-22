<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Title only. `instance_type` is a PHP class string, selected to build a readable context label
 * rather than to be matched against.
 */
class WidgetInstanceProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'widget_instance';

    private const ICON_KEY = 'puzzle';

    private const ACL_RESOURCE = 'Magento_Widget::widget_instance';

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
        $table = $this->resourceConnection->getTableName('widget_instance');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['instance_id', 'title', 'instance_type'])
                ->where('title LIKE ?', '%'.$normalizedQuery.'%')
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
        $title = (string) ($row['title'] ?? '');
        $instanceType = (string) ($row['instance_type'] ?? '');
        $shortType = strrchr($instanceType, '\\');
        $context = $shortType !== false ? substr($shortType, 1) : $instanceType;

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $title,
            context: $context,
            url: $this->backendUrl->getUrl('adminhtml/widget_instance/edit', ['instance_id' => (int) $row['instance_id']]),
            matchTier: $this->matchTier(mb_strtolower($title), $normalizedQuery),
        );
    }

    private function matchTier(string $title, string $normalizedQuery): int
    {
        if ($title === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($title, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

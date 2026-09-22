<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Magento has no per-subscriber edit screen, so `url` points at the grid with no id param
 * rather than deep-linking a row.
 */
class NewsletterSubscriberProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'newsletter_subscriber';

    private const ICON_KEY = 'inbox';

    private const ACL_RESOURCE = 'Magento_Newsletter::subscriber';

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
        $table = $this->resourceConnection->getTableName('newsletter_subscriber');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['subscriber_id', 'subscriber_email'])
                ->where('subscriber_email LIKE ?', '%'.$normalizedQuery.'%')
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
        $email = (string) ($row['subscriber_email'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $email,
            context: '',
            url: $this->backendUrl->getUrl('newsletter/subscriber/index'),
            matchTier: $this->matchTier(mb_strtolower($email), $normalizedQuery),
        );
    }

    private function matchTier(string $email, string $normalizedQuery): int
    {
        if ($email === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($email, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

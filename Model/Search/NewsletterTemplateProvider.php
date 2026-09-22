<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

class NewsletterTemplateProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'newsletter_template';

    private const ICON_KEY = 'megaphone';

    private const ACL_RESOURCE = 'Magento_Newsletter::template';

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
        $table = $this->resourceConnection->getTableName('newsletter_template');
        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['template_id', 'template_code', 'template_subject'])
                ->where('template_code LIKE ?', $likeParam)
                ->orWhere('template_subject LIKE ?', $likeParam)
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
        $code = (string) ($row['template_code'] ?? '');
        $subject = (string) ($row['template_subject'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $code,
            context: $subject,
            url: $this->backendUrl->getUrl('newsletter/template/edit', ['id' => (int) $row['template_id']]),
            matchTier: $this->matchTier(mb_strtolower($code), mb_strtolower($subject), $normalizedQuery),
        );
    }

    private function matchTier(string $code, string $subject, string $normalizedQuery): int
    {
        if ($code === $normalizedQuery || $subject === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($code, $normalizedQuery) || str_starts_with($subject, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

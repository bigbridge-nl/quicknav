<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * The reviewed product's name is joined from `catalog_product_entity_varchar` at default scope.
 */
class ReviewProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'review';

    private const ICON_KEY = 'star';

    /** Default/admin scope, per Magento EAV convention. */
    private const DEFAULT_STORE_ID = 0;

    private const ACL_RESOURCE = 'Magento_Review::reviews_all';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly EavConfig $eavConfig,
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
        $reviewTable = $this->resourceConnection->getTableName('review');
        $reviewDetailTable = $this->resourceConnection->getTableName('review_detail');
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'name')->getAttributeId();
        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['r' => $reviewTable], ['review_id', 'entity_pk_value'])
                ->join(['rd' => $reviewDetailTable], 'rd.review_id = r.review_id', ['title', 'nickname'])
                ->joinLeft(
                    ['name_attr' => $varcharTable],
                    $connection->quoteInto(
                        'name_attr.entity_id = r.entity_pk_value AND name_attr.attribute_id = ? AND name_attr.store_id = '
                        .self::DEFAULT_STORE_ID,
                        $nameAttributeId
                    ),
                    ['product_name' => 'name_attr.value']
                )
                ->where('rd.title LIKE ?', $likeParam)
                ->orWhere('rd.nickname LIKE ?', $likeParam)
                ->orWhere('name_attr.value LIKE ?', $likeParam)
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
        $nickname = (string) ($row['nickname'] ?? '');
        $productName = (string) ($row['product_name'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $title,
            context: 'by '.$nickname.($productName !== '' ? ' · '.$productName : ''),
            url: $this->backendUrl->getUrl('review/product/edit', ['id' => (int) $row['review_id']]),
            matchTier: $this->matchTier(mb_strtolower($title), mb_strtolower($nickname), $normalizedQuery),
        );
    }

    // Product name is a WHERE-only match signal, excluded from the tier: matching a review's own
    // title or nickname is a stronger hit than matching the product it reviews.
    private function matchTier(string $title, string $nickname, string $normalizedQuery): int
    {
        if ($title === $normalizedQuery || $nickname === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($title, $normalizedQuery) || str_starts_with($nickname, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

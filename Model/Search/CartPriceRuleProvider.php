<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Rule name only. Coupon codes live in `salesrule_coupon`, one-to-many against a rule, and are
 * matched by CouponCodeProvider instead.
 */
class CartPriceRuleProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'cart_price_rule';

    private const ICON_KEY = 'ticket-percent';

    private const ACL_RESOURCE = 'Magento_SalesRule::quote';

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
        $table = $this->resourceConnection->getTableName('salesrule');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['rule_id', 'name'])
                ->where('name LIKE ?', '%'.$normalizedQuery.'%')
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

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name,
            context: '',
            url: $this->backendUrl->getUrl('sales_rule/promo_quote/edit', ['id' => (int) $row['rule_id']]),
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

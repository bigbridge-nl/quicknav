<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Search\OpenSearch\CandidateIdNarrower;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Bulk auto-generated campaigns reach tens of thousands of codes per rule, so the un-narrowed
 * LIKE scan is genuinely expensive here. Results link to the parent rule's edit page, since there
 * is no standalone coupon edit screen — also why this shares CartPriceRuleProvider's ACL resource.
 */
class CouponCodeProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'coupon_code';

    private const ICON_KEY = 'qr-code';

    private const ACL_RESOURCE = 'Magento_SalesRule::quote';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
        private readonly CandidateIdNarrower $candidateIdNarrower,
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

        $candidateIds = $this->candidateIdNarrower->narrow(self::ENTITY_TYPE, $normalizedQuery, $limit);
        if ($candidateIds === []) {
            return []; // OpenSearch reachable, genuinely zero matches — trust it
        }

        $connection = $this->resourceConnection->getConnection();
        $couponTable = $this->resourceConnection->getTableName('salesrule_coupon');
        $ruleTable = $this->resourceConnection->getTableName('salesrule');
        $likeParam = '%'.$normalizedQuery.'%';

        // Must stay one where() call so the OR is a single parenthesized unit. Split across two,
        // AND binds tighter than OR and the entity_id IN() clause below would apply to only one
        // branch of it.
        $textMatch = $connection->quoteInto('sc.code LIKE ?', $likeParam)
            .' OR '.$connection->quoteInto('sr.name LIKE ?', $likeParam);

        $select = $connection->select()
            ->from(['sc' => $couponTable], ['coupon_id', 'code', 'rule_id'])
            ->joinLeft(['sr' => $ruleTable], 'sr.rule_id = sc.rule_id', ['name'])
            ->where($textMatch)
            ->limit($limit * 3);

        if ($candidateIds !== null) {
            $select->where('sc.coupon_id IN (?)', $candidateIds);
        }

        $rows = $connection->fetchAll($select);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->buildResult($row, $normalizedQuery);
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    private function buildResult(array $row, string $normalizedQuery): SearchResult
    {
        $code = (string) ($row['code'] ?? '');
        $ruleName = (string) ($row['name'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $code,
            context: $ruleName,
            url: $this->backendUrl->getUrl('sales_rule/promo_quote/edit', ['id' => (int) $row['rule_id']]),
            matchTier: $this->matchTier(mb_strtolower($code), mb_strtolower($ruleName), $normalizedQuery),
        );
    }

    private function matchTier(string $code, string $ruleName, string $normalizedQuery): int
    {
        if ($code === $normalizedQuery || $ruleName === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($code, $normalizedQuery) || str_starts_with($ruleName, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

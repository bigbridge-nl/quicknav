<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * Fans a query out to every registered provider and ranks the merged results by match tier
 * first, then per-entity-type weight as a tie-breaker within a tier. The overall cap is applied
 * after ranking, independently of each provider's own $perProviderLimit.
 */
class SearchService
{
    /**
     * Bounds worst-case cost: without a cap, an arbitrarily long `q` flows into every provider's
     * `LIKE '%...%'` on every keystroke. A pasted storefront URL is the longest legitimate input
     * and fits well under this.
     */
    private const MAX_QUERY_LENGTH = 300;

    /**
     * Entity type => the di.xml provider key that produces it. The mapping is many-to-one, so
     * scoping by provider key is coarser than the requested type and a post-fetch filter on the
     * results is still required.
     */
    private const PROVIDER_KEY_BY_TYPE = [
        'product' => 'product',
        'category' => 'category',
        'config_section' => 'config_section',
        'config_group' => 'config_section',
        'config_field' => 'config_section',
        'menu_item' => 'config_section',
        'customer' => 'customer',
        'order' => 'order',
        'cms_block' => 'cms_block',
        'cms_page' => 'cms_page',
        'url_rewrite' => 'url_rewrite',
        'search_term' => 'search_term',
        'store_view' => 'store_scope',
        'product_attribute' => 'product_attribute',
        'attribute_set' => 'attribute_set',
        'admin_user' => 'admin_user',
        'admin_role' => 'admin_role',
        'customer_group' => 'customer_group',
        'review' => 'review',
        'cart_price_rule' => 'cart_price_rule',
        'catalog_price_rule' => 'catalog_price_rule',
        'widget_instance' => 'widget_instance',
        'email_template' => 'email_template',
        'newsletter_template' => 'newsletter_template',
        'newsletter_subscriber' => 'newsletter_subscriber',
        'custom_variable' => 'custom_variable',
        'search_synonym' => 'search_synonym',
        'tax_rule' => 'tax_rule',
        'tax_rate' => 'tax_rate',
        'integration' => 'integration',
        'invoice' => 'invoice',
        'shipment' => 'shipment',
        'credit_memo' => 'credit_memo',
        'admin_activity_log' => 'admin_activity_log',
        'coupon_code' => 'coupon_code',
    ];

    /**
     * @param  SearchProviderInterface[]  $providers
     * @param  array<string, int>  $typeWeights  Lower sorts first; unlisted types default to 0.
     *                                           Configured via di.xml, not hardcoded here, so
     *                                           it can be tuned without a code change.
     */
    public function __construct(
        private readonly array $providers,
        private readonly UrlPasteResolver $urlPasteResolver,
        private readonly QueryLimiter $queryLimiter,
        private readonly array $typeWeights = [],
        private readonly int $perProviderLimit = 8,
        private readonly int $overallLimit = 20,
    ) {}

    /**
     * @param  bool  $bypassLimiter  Set when the Trigger & UI feature's "Expand search" action
     *                               re-runs a limiter's remainder text unscoped — skips limiter detection
     *                               entirely so the expanded search can never re-scope itself, even if the remainder
     *                               happens to itself look like a different keyword.
     * @param  string[]  $typeFilter  explicit entity types to restrict results to, set by the
     *                                UI's type-filter chips — independent of (and always takes
     *                                precedence over) a matched keyword limiter's implied type.
     */
    public function search(string $query, bool $bypassLimiter = false, array $typeFilter = []): SearchOutcome
    {
        $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);

        if (trim($query) === '') {
            return new SearchOutcome([], false);
        }

        $urlPasteOutcome = $this->urlPasteResolver->resolve($query);

        if ($urlPasteOutcome->result !== null) {
            // A resolved pasted link is an unambiguous, single-target match — running the raw
            // URL string through the ordinary text providers too would only add noise.
            return new SearchOutcome([$urlPasteOutcome->result], false);
        }

        if ($urlPasteOutcome->accessDenied) {
            // Security audit: the link resolved to a real entity, but the role lacks the ACL
            // resource for it — a distinct state from "nothing matched" (see SearchOutcome).
            return new SearchOutcome([], true, null, urlPasteAccessDenied: true);
        }

        if ($urlPasteOutcome->looksLikeUrl && $urlPasteOutcome->domainMatched) {
            // A link into a known store that matched no url_rewrite row. Returning empty beats
            // falling through to a text search over the raw URL string, which cannot match.
            return new SearchOutcome([], true);
        }

        $limiterMatch = $bypassLimiter ? null : $this->queryLimiter->match($query);
        $providers = $this->providers;
        $searchText = $query;

        if ($limiterMatch !== null && isset($this->providers[$limiterMatch->providerKey])) {
            $providers = [$limiterMatch->providerKey => $this->providers[$limiterMatch->providerKey]];
            $searchText = $limiterMatch->remainder;
        } else {
            $limiterMatch = null;
        }

        if ($typeFilter !== []) {
            // An explicit filter overrides a keyword limiter's implied scope, but keeps its
            // remainder as the search text. No limiter banner shows while a filter is in force.
            $providerKeys = array_unique(array_filter(array_map(
                static fn (string $type): ?string => self::PROVIDER_KEY_BY_TYPE[$type] ?? null,
                $typeFilter
            )));
            $providers = array_intersect_key($this->providers, array_flip($providerKeys));
            $limiterMatch = null;
        }

        $results = [];
        foreach ($providers as $provider) {
            foreach ($provider->search($searchText, $this->perProviderLimit) as $result) {
                if ($typeFilter !== [] && ! in_array($result->entityType, $typeFilter, true)) {
                    // Provider-key scoping above cannot separate the several entity types a
                    // single provider emits, so filter them apart here.
                    continue;
                }
                $results[] = $result;
            }
        }

        usort($results, function (SearchResult $a, SearchResult $b): int {
            if ($a->matchTier !== $b->matchTier) {
                return $a->matchTier <=> $b->matchTier;
            }

            $weightA = $this->typeWeights[$a->entityType] ?? 0;
            $weightB = $this->typeWeights[$b->entityType] ?? 0;
            if ($weightA !== $weightB) {
                return $weightA <=> $weightB;
            }

            return strcasecmp($a->label, $b->label);
        });

        return new SearchOutcome(array_slice($results, 0, $this->overallLimit), false, $limiterMatch);
    }
}

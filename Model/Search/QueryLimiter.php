<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * Detects a leading limiter keyword ("product", "customer", "cat") and splits it off the query.
 * Only the first word is checked, and a keyword with nothing after it does not activate a
 * limiter — it falls through to an ordinary unscoped search of the literal text.
 */
class QueryLimiter
{
    /**
     * @param  array<string, string>  $keywordMap  lowercase keyword => provider key (the same
     *                                             keys used in SearchService's `providers` argument)
     * @param  array<string, string>  $providerLabels  provider key => human-readable label
     * @param  array<string, string>  $entityTypeMap  keyword => entity type, for a keyword whose
     *                                                provider emits more than one entity type
     *                                                (see LimiterMatch::$entityType)
     * @param  array<string, string>  $entityTypeLabels  keyword => human-readable label, overriding
     *                                                   $providerLabels for a keyword narrowed by
     *                                                   $entityTypeMap — the provider's own label
     *                                                   ("Settings") would otherwise show in the
     *                                                   banner even though the results are scoped
     *                                                   to just one of its entity types
     */
    public function __construct(
        private readonly array $keywordMap,
        private readonly array $providerLabels,
        private readonly array $entityTypeMap = [],
        private readonly array $entityTypeLabels = [],
    ) {}

    public function match(string $query): ?LimiterMatch
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return null;
        }

        [$firstWord, $remainder] = array_pad(preg_split('/\s+/', $trimmed, 2), 2, '');
        $keyword = mb_strtolower($firstWord);
        $remainder = trim($remainder);

        if ($remainder === '' || ! isset($this->keywordMap[$keyword])) {
            return null;
        }

        $providerKey = $this->keywordMap[$keyword];

        return new LimiterMatch(
            keyword: $keyword,
            providerKey: $providerKey,
            label: $this->entityTypeLabels[$keyword] ?? $this->providerLabels[$providerKey] ?? $providerKey,
            remainder: $remainder,
            entityType: $this->entityTypeMap[$keyword] ?? null,
        );
    }

    /**
     * One keyword to show as a hint per provider key: the first one declared for it, which
     * `keywordMap`'s existing convention (singular before plural, e.g. product/products) already
     * orders as the one worth suggesting.
     *
     * @return array<string, string> provider key => keyword
     */
    public function primaryKeywordsByProviderKey(): array
    {
        $primary = [];
        foreach ($this->keywordMap as $keyword => $providerKey) {
            $primary[$providerKey] ??= $keyword;
        }

        return $primary;
    }
}

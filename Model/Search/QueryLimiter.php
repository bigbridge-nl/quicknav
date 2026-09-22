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
     */
    public function __construct(
        private readonly array $keywordMap,
        private readonly array $providerLabels,
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
            label: $this->providerLabels[$providerKey] ?? $providerKey,
            remainder: $remainder,
        );
    }
}

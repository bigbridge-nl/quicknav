<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * A recognized leading limiter keyword. `remainder` is the query with the keyword stripped, and
 * is what actually gets searched.
 */
class LimiterMatch
{
    public function __construct(
        public readonly string $keyword,
        public readonly string $providerKey,
        public readonly string $label,
        public readonly string $remainder,
    ) {}
}

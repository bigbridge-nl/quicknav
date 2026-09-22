<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * Ranking tiers, sorted ascending: lower is a better match.
 */
class MatchTier
{
    public const EXACT = 0;

    public const PREFIX = 1;

    public const CONTAINS = 2;

    public const FUZZY = 3;

    private function __construct() {}
}

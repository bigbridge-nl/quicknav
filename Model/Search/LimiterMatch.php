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
        /**
         * Set only for a keyword whose provider emits more than one entity type
         * (config_section's section/group/field/menu_item) — narrows the provider's own results
         * to just this one, the same post-fetch filter SearchService already applies for the
         * type-filter chips. Null for every other keyword: the provider's own results are
         * already exactly the implied type.
         */
        public readonly ?string $entityType = null,
    ) {}
}

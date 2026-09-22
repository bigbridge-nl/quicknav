<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * SearchService's response. `urlPasteAttempted` marks a query recognized as a link to a known
 * store that resolved to nothing, which the widget reports differently from "no matches".
 */
class SearchOutcome
{
    /**
     * @param  SearchResult[]  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly bool $urlPasteAttempted,
        public readonly ?LimiterMatch $limiter = null,
        /**
         * The link matched a real entity the current role may not see. Separate from
         * $urlPasteAttempted so the widget does not claim the link is broken.
         */
        public readonly bool $urlPasteAccessDenied = false,
    ) {}
}

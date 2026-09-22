<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * Tells SearchService which of four things happened: the query was not a URL or named an unknown
 * domain (fall back to text search), it named a known store but matched no url_rewrite row, it
 * resolved, or it resolved to something this role may not see.
 */
class UrlPasteOutcome
{
    public function __construct(
        public readonly bool $looksLikeUrl,
        public readonly bool $domainMatched,
        public readonly ?SearchResult $result,
        public readonly bool $accessDenied = false,
    ) {}

    public static function notAUrl(): self
    {
        return new self(looksLikeUrl: false, domainMatched: false, result: null);
    }

    public static function domainNotConfigured(): self
    {
        return new self(looksLikeUrl: true, domainMatched: false, result: null);
    }

    public static function unresolved(): self
    {
        return new self(looksLikeUrl: true, domainMatched: true, result: null);
    }

    public static function resolved(SearchResult $result): self
    {
        return new self(looksLikeUrl: true, domainMatched: true, result: $result);
    }

    /**
     * Distinct from unresolved(): the link did resolve, so the UI must not report it as broken.
     */
    public static function accessDenied(): self
    {
        return new self(looksLikeUrl: true, domainMatched: true, result: null, accessDenied: true);
    }
}

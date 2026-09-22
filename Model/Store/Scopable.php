<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Store;

/**
 * How far a config section or group can be scoped, derived from its own `showInStore` and
 * `showInWebsite` attributes rather than hardcoded per section. Structure's attribute-inheritance
 * mapper is a no-op here, so nothing is inherited from a parent section.
 */
class Scopable
{
    public const DEFAULT_ONLY = 'default';

    public const WEBSITE = 'website';

    public const STORE_VIEW = 'store';

    private function __construct() {}

    /**
     * @param  string|int|null  $showInWebsite  raw system.xml attribute value (e.g. "1", 1, null)
     * @param  string|int|null  $showInStore  raw system.xml attribute value (e.g. "1", 1, null)
     */
    public static function classify($showInWebsite, $showInStore): string
    {
        if ((int) ($showInStore ?? 0) === 1) {
            return self::STORE_VIEW;
        }
        if ((int) ($showInWebsite ?? 0) === 1) {
            return self::WEBSITE;
        }

        return self::DEFAULT_ONLY;
    }
}

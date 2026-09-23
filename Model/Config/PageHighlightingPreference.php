<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Config;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Resolves the effective page-highlighting behaviour: the admin-configured default, overridden
 * per admin on their own Account Information page. Both the form field and the Alpine flag read
 * this one class, so it is the only place that knows the admin_user `extra` key's encoding.
 */
class PageHighlightingPreference
{
    private const XML_PATH_DEFAULT = 'quicknav_search/general/page_highlighting';

    /**
     * Key inside admin_user.extra. Absent, or an empty string, means "use the admin default".
     */
    public const EXTRA_KEY = 'quicknav_page_highlighting';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AdminSession $adminSession,
    ) {}

    public function isEnabled(): bool
    {
        return $this->getUserOverride() ?? $this->scopeConfig->isSetFlag(self::XML_PATH_DEFAULT);
    }

    /**
     * The logged-in admin's own override, or null when they have none set.
     */
    public function getUserOverride(): ?bool
    {
        $user = $this->adminSession->getUser();
        if (! $user) {
            return null;
        }

        // Magento's own @method docblock says string; ResourceModel\User::_afterLoad()
        // unserializes it into an array before any caller sees it.
        /** @var array<string, string>|null $extra */
        $extra = $user->getExtra();

        return self::decode($extra[self::EXTRA_KEY] ?? null);
    }

    /**
     * @param  mixed  $stored  raw admin_user.extra value for this key
     */
    public static function decode(mixed $stored): ?bool
    {
        return $stored === null || $stored === '' ? null : (bool) $stored;
    }

    /**
     * Maps a submitted form value ('', '0' or '1') to what belongs in `extra`: null removes the
     * key so the admin default applies again, otherwise a '0'/'1' string.
     */
    public static function encode(string $submitted): ?string
    {
        return $submitted === '' ? null : ((int) $submitted === 1 ? '1' : '0');
    }
}

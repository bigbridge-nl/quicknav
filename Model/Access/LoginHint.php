<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Access;

use Magento\Backend\Model\Auth\Session;

/**
 * Reads and clears a one-shot session flag: the hint shows on the first page view after a login
 * and never again within the same session.
 */
class LoginHint
{
    public const SESSION_KEY = 'quicknav_show_login_hint';

    public function __construct(private readonly Session $session) {}

    public function shouldShow(): bool
    {
        return (bool) $this->session->getData(self::SESSION_KEY, true);
    }
}

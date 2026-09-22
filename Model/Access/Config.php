<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Access;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Gates the global overlay (header icon + Ctrl+K/Cmd+K) only. The dashboard widget is a separate
 * entry point and is not affected by this flag.
 */
class Config
{
    private const XML_PATH_ENABLED = 'quicknav_access/general/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {}

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\ViewModel;

use BigBridge\QuickNav\Model\Config\PageHighlightingPreference;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class QuickNavConfig implements ArgumentInterface
{
    public function __construct(
        private readonly PageHighlightingPreference $pageHighlightingPreference,
    ) {}

    public function isPageHighlightingEnabled(): bool
    {
        return $this->pageHighlightingPreference->isEnabled();
    }
}

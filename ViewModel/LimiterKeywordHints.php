<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\ViewModel;

use BigBridge\QuickNav\Model\Search\QueryLimiter;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Surfaces each type-filter chip's typed shortcut (e.g. "product") so the admin can search
 * directly next time instead of opening the chip row. `QueryLimiter`'s provider keys are
 * SearchService's, not the frontend's own type strings, so the one place they diverge
 * (`store_scope` vs `store_view`) is translated here rather than in the widget's JS.
 */
class LimiterKeywordHints implements ArgumentInterface
{
    private const PROVIDER_KEY_TO_TYPE = [
        'store_scope' => 'store_view',
    ];

    /**
     * config_group/config_field/menu_item share config_section's own provider
     * (SearchService::PROVIDER_KEY_BY_TYPE), so `primaryKeywordsByProviderKey()` never
     * surfaces them on its own — it returns one keyword per provider key, and "config_section"
     * already claims that slot. Each entry here must exist as its own keyword in di.xml's
     * `keywordMap`, mapped to `config_section`.
     */
    private const ADDITIONAL_TYPE_KEYWORDS = [
        'config_group' => 'subgroup',
        'config_field' => 'settingsfield',
        'menu_item' => 'menu',
    ];

    public function __construct(
        private readonly QueryLimiter $queryLimiter,
    ) {}

    /**
     * @return array<string, string> frontend type => keyword
     */
    public function toArray(): array
    {
        $byType = [];
        foreach ($this->queryLimiter->primaryKeywordsByProviderKey() as $providerKey => $keyword) {
            $type = self::PROVIDER_KEY_TO_TYPE[$providerKey] ?? $providerKey;
            $byType[$type] = $keyword;
        }

        return $byType + self::ADDITIONAL_TYPE_KEYWORDS;
    }
}

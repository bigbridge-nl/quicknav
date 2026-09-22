<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\ViewModel;

use Hyva\Theme\ViewModel\LucideIcons;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Personalization tiles render client-side from localStorage with no server round-trip, so a
 * stored result's `icon` key must be resolvable to SVG markup in the browser. This emits the
 * whole lookup table once at page-render time.
 */
class IconDictionary implements ArgumentInterface
{
    /**
     * One entry per provider `ICON_KEY`, never shared between two entity types, plus 'link' as
     * the fallback both PHP and the widget's `result.icon || 'link'` rely on.
     */
    private const ICON_KEY_TO_METHOD = [
        'package' => 'packageHtml',
        'folder-tree' => 'folderTreeHtml',
        'settings' => 'settingsHtml',
        'sliders-horizontal' => 'slidersHorizontalHtml',
        'tag' => 'tagHtml',
        'menu' => 'menuHtml',
        'user' => 'userHtml',
        'receipt' => 'receiptHtml',
        'layout-panel-top' => 'layoutPanelTopHtml',
        'file-text' => 'fileTextHtml',
        'route' => 'routeHtml',
        'search-check' => 'searchCheckHtml',
        'store' => 'storeHtml',
        'list' => 'listHtml',
        'layers' => 'layersHtml',
        'user-cog' => 'userCogHtml',
        'shield-check' => 'shieldCheckHtml',
        'users' => 'usersHtml',
        'star' => 'starHtml',
        'ticket-percent' => 'ticketPercentHtml',
        'badge-percent' => 'badgePercentHtml',
        'qr-code' => 'qrCodeHtml',
        'puzzle' => 'puzzleHtml',
        'mail' => 'mailHtml',
        'megaphone' => 'megaphoneHtml',
        'inbox' => 'inboxHtml',
        'braces' => 'bracesHtml',
        'arrow-left-right' => 'arrowLeftRightHtml',
        'calculator' => 'calculatorHtml',
        'percent' => 'percentHtml',
        'plug' => 'plugHtml',
        'receipt-text' => 'receiptTextHtml',
        'truck' => 'truckHtml',
        'undo' => 'undoHtml',
        'history' => 'historyHtml',
        'link' => 'linkHtml',
    ];

    public function __construct(
        private readonly LucideIcons $icons,
    ) {}

    /**
     * @return array<string, string> icon key => rendered SVG markup
     */
    public function toArray(): array
    {
        $dictionary = [];
        foreach (self::ICON_KEY_TO_METHOD as $key => $method) {
            $dictionary[$key] = $this->icons->$method('', 20, 20, ['aria-hidden' => 'true']);
        }

        return $dictionary;
    }
}

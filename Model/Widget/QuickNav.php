<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Widget;

use BigBridge\QuickNav\Model\Search\AccessibleTypeResolver;
use Hyva\AdminDashboardApi\Api\ConfigurationKeys;
use Hyva\AdminDashboardApi\Api\V1\WidgetContextInterface;
use Hyva\AdminDashboardApi\Api\V1\WidgetInstanceInterface;
use Hyva\AdminDashboardApi\Api\V1\WidgetTypeInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;

class QuickNav implements WidgetTypeInterface
{
    private const PROPERTY_SHOW_BRANDING = 'show_branding';

    public function __construct(
        private readonly UrlInterface $url,
        private readonly UserContextInterface $userContext,
        private readonly AccessibleTypeResolver $accessibleTypeResolver,
    ) {}

    public function getDisplayData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): mixed
    {
        $showBranding = $widgetInstance->getPropertyValue(
            ConfigurationKeys::DISPLAY_PROPERTIES,
            self::PROPERTY_SHOW_BRANDING,
        );

        return [
            // Safe to cache: the endpoint is POST + form-key validated, and no secret key is
            // baked into this URL.
            'search_url' => $this->url->getUrl('quicknav/search/index'),
            // Namespaces the client-side localStorage recents and pins, so two admins sharing a
            // browser profile do not see each other's lists.
            'user_id' => $this->userContext->getUserId(),
            // Computed once per render rather than per keystroke.
            'accessible_types' => $this->accessibleTypeResolver->resolve(),
            // Unsaved instances (still being configured) have no stored value yet.
            'show_branding' => $showBranding ?? true,
        ];
    }

    public function getTitle(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): Phrase
    {
        return $ctx->getTitle();
    }

    public function getConfigurableProperties(WidgetContextInterface $ctx): array
    {
        return $ctx->getConfigurableProperties();
    }

    public function getDisplayProperties(WidgetContextInterface $ctx): array
    {
        // QuickNav's search box needs no per-instance setup: it always searches within the
        // current admin's own ACL scope. Without at least one real field here, "Configure New
        // Widget" opens on a dialog that is entirely blank apart from its Save button.
        return array_merge(
            $ctx->getDisplayProperties(),
            [
                self::PROPERTY_SHOW_BRANDING => [
                    'label' => __('Show "Powered by BigBridge"'),
                    'note' => __(
                        'QuickNav needs no other setup — results are limited automatically to '
                        .'what the viewing admin\'s role can access.',
                    ),
                    'input' => [
                        'type' => 'toggle',
                        'attributes' => [
                            'checked' => true,
                        ],
                    ],
                ],
            ],
        );
    }

    public function getTrailingAction(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): array
    {
        return $ctx->getTrailingAction();
    }

    public function isAllowed(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): bool
    {
        return $ctx->isAllowed($widgetInstance);
    }

    public function beforeSave(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): WidgetInstanceInterface
    {
        return $widgetInstance;
    }

    public function afterSave(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): WidgetInstanceInterface
    {
        return $widgetInstance;
    }
}

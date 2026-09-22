<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Store;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds every scope choice through the same `BackendUrl::getUrl()` call that produced the
 * result's own `url`, so "Default" stays byte-for-byte identical to it and website and store
 * choices match the store switcher instead of hand-rolled URL encoding.
 */
class ScopeChoiceBuilder
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
    ) {}

    /**
     * @param  array<string, int|string>  $baseParams  params already used for the base/default URL
     * @return array<int, array{type: string, id: ?int, websiteId: ?int, label: string, url: string}>
     */
    public function build(string $adminPath, array $baseParams, string $scopable, bool $includeWebsiteLevel = true): array
    {
        if ($scopable === Scopable::DEFAULT_ONLY) {
            return [];
        }

        $choices = [$this->choice('default', null, null, (string) __('Default Config'), $adminPath, $baseParams)];

        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int) $website->getId();

            if ($includeWebsiteLevel) {
                $choices[] = $this->choice(
                    'website',
                    $websiteId,
                    $websiteId,
                    $website->getName(),
                    $adminPath,
                    $baseParams + ['website' => $websiteId]
                );
            }

            if ($scopable !== Scopable::STORE_VIEW) {
                continue;
            }

            foreach ($this->storeManager->getStores() as $store) {
                if ((int) $store->getWebsiteId() !== $websiteId) {
                    continue;
                }

                $storeId = (int) $store->getId();
                $choices[] = $this->choice(
                    'store',
                    $storeId,
                    $websiteId,
                    $store->getName(),
                    $adminPath,
                    $baseParams + ['store' => $storeId]
                );
            }
        }

        return $choices;
    }

    /**
     * @param  array<string, int|string>  $params
     * @return array{type: string, id: ?int, websiteId: ?int, label: string, url: string}
     */
    private function choice(string $type, ?int $id, ?int $websiteId, string $label, string $adminPath, array $params): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'websiteId' => $websiteId,
            'label' => $label,
            'url' => $this->backendUrl->getUrl($adminPath, $params),
        ];
    }
}

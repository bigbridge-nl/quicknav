<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\ViewModel;

use BigBridge\QuickNav\Model\Search\OpenSearch\OpenSearchConfig;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Supplies the two admin-facing notices about OpenSearch narrowing, each with the link that acts on
 * it. Both URLs are empty for an admin without the target page's own ACL resource, so the template
 * degrades to the plain text rather than offering a link that leads to "Access denied".
 */
class OpenSearchNotice implements ArgumentInterface
{
    /** Every indexer that populates the QuickNav OpenSearch index. */
    private const OPENSEARCH_INDEXERS = [
        'bigbridge_quicknav_product_search',
        'bigbridge_quicknav_customer_search',
        'bigbridge_quicknav_order_search',
        'bigbridge_quicknav_coupon_search',
    ];

    private const SETTINGS_SECTION = 'quicknav_search';

    private const SETTINGS_ACL = 'BigBridge_QuickNav::config_quicknav_search';

    private const INDEX_MANAGEMENT_ROUTE = 'indexer/indexer/list';

    private const INDEX_MANAGEMENT_ACL = 'Magento_Indexer::index';

    public function __construct(
        private readonly OpenSearchConfig $config,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $backendUrl,
    ) {}

    /**
     * Empty unless Magento itself runs on OpenSearch: with no service configured, narrowing cannot
     * be turned on, so the settings page has nothing to offer.
     */
    public function getSettingsUrl(): string
    {
        if (! $this->config->isPlatformEngineOpenSearch()) {
            return '';
        }
        if (! $this->authorization->isAllowed(self::SETTINGS_ACL)) {
            return '';
        }

        return $this->backendUrl->getUrl(
            'adminhtml/system_config/edit',
            ['section' => self::SETTINGS_SECTION],
        );
    }

    /**
     * Indexer ids that hold no usable index yet, so a search silently falls back to the full scan
     * while narrowing reports itself as on. Empty while narrowing is disabled.
     *
     * @return string[]
     */
    public function getUnbuiltIndexers(): array
    {
        if (! $this->config->isEnabled()) {
            return [];
        }

        $unbuilt = [];
        foreach (self::OPENSEARCH_INDEXERS as $indexerId) {
            if ($this->indexerRegistry->get($indexerId)->getState()->getStatus() !== StateInterface::STATUS_VALID) {
                $unbuilt[] = $indexerId;
            }
        }

        return $unbuilt;
    }

    public function getIndexManagementUrl(): string
    {
        if (! $this->authorization->isAllowed(self::INDEX_MANAGEMENT_ACL)) {
            return '';
        }

        return $this->backendUrl->getUrl(self::INDEX_MANAGEMENT_ROUTE);
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search\OpenSearch;

use BigBridge\QuickNav\Model\Config\Source\OpenSearchConnectionSource;
use Magento\Framework\App\Config\ScopeConfigInterface;

class OpenSearchConfig
{
    private const XML_PATH_ENABLED = 'quicknav_search/opensearch/enabled';

    private const XML_PATH_CONNECTION_SOURCE = 'quicknav_search/opensearch/connection_source';

    private const XML_PATH_HOST = 'quicknav_search/opensearch/host';

    private const XML_PATH_PORT = 'quicknav_search/opensearch/port';

    private const XML_PATH_SCHEME = 'quicknav_search/opensearch/scheme';

    private const XML_PATH_INDEX_PREFIX = 'quicknav_search/opensearch/index_prefix';

    private const XML_PATH_PLATFORM_ENGINE = 'catalog/search/engine';

    /**
     * Magento's own OpenSearch connection, from module-open-search's `catalog/search/opensearch_*`
     * fields. QuickNav's own index (see getIndexPrefix()) is unrelated and always named separately.
     */
    private const XML_PATH_PLATFORM_HOST = 'catalog/search/opensearch_server_hostname';

    private const XML_PATH_PLATFORM_PORT = 'catalog/search/opensearch_server_port';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig) {}

    /**
     * Magento's own search engine, not QuickNav's setting. There is only a service worth pointing an
     * admin at when the platform is already configured for one; `env.php` overrides are covered
     * because ScopeConfigInterface reads them ahead of `core_config_data`.
     */
    public function isPlatformEngineOpenSearch(): bool
    {
        $engine = (string) $this->scopeConfig->getValue(self::XML_PATH_PLATFORM_ENGINE);

        return str_starts_with($engine, 'opensearch');
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_ENABLED);
    }

    /**
     * Whether the admin picked "Use Magento's configured OpenSearch/Elasticsearch" over entering
     * QuickNav's own host and port.
     */
    public function usesPlatformConnection(): bool
    {
        $source = (string) $this->scopeConfig->getValue(self::XML_PATH_CONNECTION_SOURCE);

        return $source === OpenSearchConnectionSource::PLATFORM;
    }

    public function getHost(): string
    {
        if ($this->usesPlatformConnection()) {
            $platform = (string) $this->scopeConfig->getValue(self::XML_PATH_PLATFORM_HOST);

            return $platform !== '' ? $platform : '127.0.0.1';
        }

        $own = (string) $this->scopeConfig->getValue(self::XML_PATH_HOST);

        return $own !== '' ? $own : '127.0.0.1';
    }

    public function getPort(): int
    {
        if ($this->usesPlatformConnection()) {
            $platform = (string) $this->scopeConfig->getValue(self::XML_PATH_PLATFORM_PORT);

            return $platform !== '' ? (int) $platform : 9200;
        }

        $own = (string) $this->scopeConfig->getValue(self::XML_PATH_PORT);

        return $own !== '' ? (int) $own : 9200;
    }

    /**
     * Magento's own OpenSearch/Elasticsearch driver has no scheme setting of its own — it always
     * connects over plain HTTP — so the platform connection has nothing to read here.
     */
    public function getScheme(): string
    {
        if ($this->usesPlatformConnection()) {
            return 'http';
        }

        return (string) ($this->scopeConfig->getValue(self::XML_PATH_SCHEME) ?: 'http');
    }

    public function getIndexPrefix(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PATH_INDEX_PREFIX) ?: 'bigbridge_quicknav');
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search\OpenSearch;

use OpenSearch\Client;
use OpenSearch\GuzzleClientFactory;

/**
 * Builds the client straight from opensearch-php against {@see OpenSearchConfig} — never through
 * Magento's own connection-building service classes, which assume the storefront's catalog
 * search. Never throws: returns null when disabled or unbuildable, one unavailable state.
 */
class OpenSearchClientFactory
{
    private const CONNECT_TIMEOUT_SECONDS = 0.3;

    private const REQUEST_TIMEOUT_SECONDS = 0.8;

    public function __construct(private readonly OpenSearchConfig $config) {}

    public function create(): ?Client
    {
        if (! $this->config->isEnabled()) {
            return null;
        }

        try {
            return (new GuzzleClientFactory)->create([
                'base_uri' => sprintf(
                    '%s://%s:%d',
                    $this->config->getScheme(),
                    $this->config->getHost(),
                    $this->config->getPort()
                ),
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

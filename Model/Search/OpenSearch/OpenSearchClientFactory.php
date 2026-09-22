<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search\OpenSearch;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Builds the client straight from opensearch-php against QuickNav's own config — never through
 * Magento's own connection-building service classes, which assume the storefront's catalog
 * search. The host and port themselves default to that same platform connection when QuickNav's
 * own is left blank (see {@see OpenSearchConfig}); the client built here is still QuickNav's own.
 *
 * Never throws: returns null when disabled or unbuildable, so callers have one unavailable state.
 */
class OpenSearchClientFactory
{
    private const CONNECT_TIMEOUT_MS = 300;

    private const REQUEST_TIMEOUT_MS = 800;

    public function __construct(private readonly OpenSearchConfig $config) {}

    public function create(): ?Client
    {
        if (! $this->config->isEnabled()) {
            return null;
        }

        try {
            return ClientBuilder::create()
                ->setHosts([sprintf(
                    '%s://%s:%d',
                    $this->config->getScheme(),
                    $this->config->getHost(),
                    $this->config->getPort()
                )])
                ->setConnectionParams([
                    'client' => [
                        'curl' => [
                            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
                            CURLOPT_TIMEOUT_MS => self::REQUEST_TIMEOUT_MS,
                        ],
                    ],
                ])
                ->build();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

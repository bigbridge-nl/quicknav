<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search\OpenSearch;

use Magento\Framework\App\CacheInterface;

/**
 * Caching the answer means only the first keystroke after an outage starts pays the connect
 * timeout; the rest get an instant "no". The TTL is also the recovery lag once OpenSearch
 * returns, so nothing needs to invalidate this explicitly.
 */
class OpenSearchAvailability
{
    private const CACHE_KEY = 'bigbridge_quicknav_opensearch_available';

    private const CACHE_LIFETIME = 30;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_UNREACHABLE = 'unreachable';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly OpenSearchClientFactory $clientFactory,
        private readonly OpenSearchConfig $config,
    ) {}

    /**
     * Which of the two unavailable cases applies, so the admin is told what to do rather than being
     * offered both possibilities. Turned off is a setting to change; unreachable is a host to fix.
     */
    public function getStatus(): string
    {
        if (! $this->config->isEnabled()) {
            return self::STATUS_DISABLED;
        }

        return $this->isAvailable() ? self::STATUS_AVAILABLE : self::STATUS_UNREACHABLE;
    }

    public function isAvailable(): bool
    {
        if (! $this->config->isEnabled()) {
            return false;
        }

        // CacheInterface::load() declares no return type and returns false on a miss, which
        // static analysis cannot infer from the interface alone.
        /** @var string|false $cached */
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached === '1';
        }

        $client = $this->clientFactory->create();
        $available = false;
        if ($client !== null) {
            try {
                $available = (bool) $client->ping();
            } catch (\Throwable $e) {
                $available = false;
            }
        }

        $this->cache->save($available ? '1' : '0', self::CACHE_KEY, [], self::CACHE_LIFETIME);

        return $available;
    }
}

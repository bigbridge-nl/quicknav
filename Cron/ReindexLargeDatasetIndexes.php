<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Cron;

use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Recovers from a write-side OpenSearch outage: writes that fail during executeList() are
 * swallowed rather than retried, so a periodic full rebuild is what makes those rows correct
 * again. mview's incremental updates keep running independently of this.
 */
class ReindexLargeDatasetIndexes
{
    private const INDEXER_IDS = [
        'bigbridge_quicknav_product_search',
        'bigbridge_quicknav_customer_search',
        'bigbridge_quicknav_order_search',
        'bigbridge_quicknav_coupon_search',
    ];

    public function __construct(private readonly IndexerRegistry $indexerRegistry) {}

    public function execute(): void
    {
        foreach (self::INDEXER_IDS as $indexerId) {
            $this->indexerRegistry->get($indexerId)->reindexAll();
        }
    }
}

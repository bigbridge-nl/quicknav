<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Setup\Patch\Data;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * mview only captures changes from the moment its triggers are installed, so without this
 * backfill the indexes stay empty until something touches every row. NonTransactionableInterface
 * avoids MySQL error 1412 from customer_grid's own DDL-issuing reindex.
 */
class BackfillLargeDatasetIndexes implements DataPatchInterface, NonTransactionableInterface
{
    private const INDEXER_IDS = [
        'bigbridge_quicknav_product_search',
        'bigbridge_quicknav_customer_search',
        'bigbridge_quicknav_order_search',
        'bigbridge_quicknav_coupon_search',
    ];

    public function __construct(private readonly IndexerRegistry $indexerRegistry) {}

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * PatchInterface declares `@return $this`, so this cannot narrow the return type to void.
     * reindexAll() is deprecated for ActionInterface::executeFull(), but that skips the
     * working-state lock and status update reindexAll() does — indexers would stay "Invalid".
     */
    public function apply(): self
    {
        // No db_schema.xml declares customer_grid_flat: core's customer_grid indexer creates it
        // lazily, so on a fresh install it does not exist until that indexer has run once. The
        // customer backfill below reads from it and fails outright without this.
        $this->indexerRegistry->get('customer_grid')->reindexAll();

        foreach (self::INDEXER_IDS as $indexerId) {
            $this->indexerRegistry->get($indexerId)->reindexAll();
        }

        return $this;
    }
}

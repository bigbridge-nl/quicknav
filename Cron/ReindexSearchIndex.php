<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Cron;

use Magento\Framework\Indexer\IndexerRegistry;

/**
 * The config/menu index has no per-save changelog to subscribe to — system.xml/menu.xml only
 * change on deploy — so a scheduled full rebuild is what keeps it current.
 */
class ReindexSearchIndex
{
    public const INDEXER_ID = 'bigbridge_quicknav_search';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
    ) {}

    public function execute(): void
    {
        $this->indexerRegistry->get(self::INDEXER_ID)->reindexAll();
    }
}

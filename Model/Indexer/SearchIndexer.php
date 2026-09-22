<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Indexer;

use BigBridge\QuickNav\Model\Index\MenuIndexCompiler;
use BigBridge\QuickNav\Model\Index\MenuIndexStorage;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Scoped to the static system.xml/menu.xml dataset, which has no per-entity changelog to
 * subscribe to — every run is a full rebuild, which is cheap for this data set. Product,
 * customer, order and coupon_code are indexed incrementally by LargeDatasetIndexer instead.
 */
class SearchIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly MenuIndexCompiler $compiler,
        private readonly MenuIndexStorage $storage,
    ) {}

    public function executeFull(): void
    {
        $this->storage->replaceAll($this->compiler->compile());
    }

    public function executeList(array $ids): void
    {
        // No per-entity changelog source exists yet (see class docblock) — fall back to a
        // full rebuild rather than silently doing nothing.
        $this->executeFull();
    }

    public function executeRow($id): void
    {
        $this->executeFull();
    }

    public function execute($ids): void
    {
        $this->executeList($ids);
    }
}

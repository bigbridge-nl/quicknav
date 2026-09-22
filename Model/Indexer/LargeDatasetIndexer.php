<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Indexer;

use BigBridge\QuickNav\Model\Index\EntityDocumentCompilerInterface;
use BigBridge\QuickNav\Model\Search\OpenSearch\OpenSearchIndexManager;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * One class, DI-configured as a virtualType per OpenSearch-backed entity type
 * (product/customer/order/coupon_code), each pairing a compiler with an entity type string.
 */
class LargeDatasetIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly EntityDocumentCompilerInterface $compiler,
        private readonly OpenSearchIndexManager $indexManager,
        private readonly string $entityType,
    ) {}

    public function executeFull(): void
    {
        $this->indexManager->ensureIndexExists();
        $this->indexManager->deleteAllOfType($this->entityType);
        foreach (array_chunk($this->compiler->compileAll(), 500) as $chunk) {
            $this->indexManager->bulkUpsert($this->entityType, $chunk);
        }
    }

    public function executeList(array $ids): void
    {
        // Deleting first handles updates and row deletions in one pass: a deleted row does not
        // come back from compileByIds(), so it stays deleted.
        $this->indexManager->deleteByIds($this->entityType, $ids);
        $documents = $this->compiler->compileByIds($ids);
        if ($documents !== []) {
            $this->indexManager->bulkUpsert($this->entityType, $documents);
        }
    }

    public function executeRow($id): void
    {
        $this->executeList([(int) $id]);
    }

    /**
     * Magento declares $ids as an array, but mview calls this with a single id in practice, so
     * the scalar branch is real rather than dead.
     *
     * @param  int[]|int  $ids
     */
    public function execute($ids): void
    {
        $this->executeList(is_array($ids) ? $ids : [$ids]);
    }
}

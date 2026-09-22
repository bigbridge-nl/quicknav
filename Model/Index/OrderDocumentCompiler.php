<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

use Magento\Framework\App\ResourceConnection;

class OrderDocumentCompiler implements EntityDocumentCompilerInterface
{
    private const BATCH_SIZE = 2000;

    public function __construct(private readonly ResourceConnection $resourceConnection) {}

    public function compileAll(): array
    {
        $documents = [];
        $lastId = 0;
        do {
            $batch = $this->fetchBatch(static function ($select) use ($lastId) {
                return $select->where('entity_id > ?', $lastId)->order('entity_id ASC')->limit(self::BATCH_SIZE);
            });
            foreach ($batch as $row) {
                $documents[] = $this->toDocument($row);
                $lastId = max($lastId, (int) $row['entity_id']);
            }
        } while (count($batch) === self::BATCH_SIZE);

        return $documents;
    }

    public function compileByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(fn (array $row) => $this->toDocument($row), $this->fetchBatch(
            static fn ($select) => $select->where('entity_id IN (?)', $ids)
        ));
    }

    private function fetchBatch(callable $configureSelect): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order_grid');
        $select = $connection->select()->from($table, ['entity_id', 'increment_id', 'customer_name']);

        return $connection->fetchAll($configureSelect($select));
    }

    private function toDocument(array $row): array
    {
        return [
            'entity_id' => (int) $row['entity_id'],
            'primary_text' => (string) $row['increment_id'],
            'secondary_text' => (string) ($row['customer_name'] ?? ''),
        ];
    }
}

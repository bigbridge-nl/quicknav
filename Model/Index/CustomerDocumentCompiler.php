<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads `customer_grid_flat`, not raw EAV, so compile-time values match what CustomerProvider
 * reads at query time. The cost is a two-hop staleness chain: this data is only as fresh as
 * core's own `customer_grid` indexer keeps that flat table.
 *
 * What table this reads is separate from what mview subscribes to. The subscription is on
 * `customer_entity` because core's customer_grid indexer periodically DROPs and re-CREATEs
 * `customer_grid_flat`, which would silently destroy a trigger placed on it.
 */
class CustomerDocumentCompiler implements EntityDocumentCompilerInterface
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
        $table = $this->resourceConnection->getTableName('customer_grid_flat');
        $select = $connection->select()->from($table, ['entity_id', 'name', 'email']);

        return $connection->fetchAll($configureSelect($select));
    }

    private function toDocument(array $row): array
    {
        return [
            'entity_id' => (int) $row['entity_id'],
            'primary_text' => (string) ($row['name'] ?? ''),
            'secondary_text' => (string) ($row['email'] ?? ''),
        ];
    }
}

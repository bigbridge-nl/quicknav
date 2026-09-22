<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

class ProductDocumentCompiler implements EntityDocumentCompilerInterface
{
    private const DEFAULT_STORE_ID = 0;

    private const BATCH_SIZE = 2000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
    ) {}

    public function compileAll(): array
    {
        $documents = [];
        $lastId = 0;
        do {
            $batch = $this->fetchBatch(static function ($select) use ($lastId) {
                return $select->where('e.entity_id > ?', $lastId)->order('e.entity_id ASC')->limit(self::BATCH_SIZE);
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
        $rows = $this->fetchBatch(static function ($select) use ($ids) {
            return $select->where('e.entity_id IN (?)', $ids);
        });

        return array_map(fn (array $row) => $this->toDocument($row), $rows);
    }

    private function fetchBatch(callable $configureSelect): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'name')->getAttributeId();

        $select = $connection->select()
            ->from(['e' => $productTable], ['entity_id', 'sku'])
            ->joinLeft(
                ['n' => $varcharTable],
                $connection->quoteInto(
                    'n.entity_id = e.entity_id AND n.attribute_id = ? AND n.store_id = '.self::DEFAULT_STORE_ID,
                    $nameAttributeId
                ),
                ['name' => 'n.value']
            );

        return $connection->fetchAll($configureSelect($select));
    }

    private function toDocument(array $row): array
    {
        return [
            'entity_id' => (int) $row['entity_id'],
            'primary_text' => (string) $row['sku'],
            'secondary_text' => (string) ($row['name'] ?? ''),
        ];
    }
}

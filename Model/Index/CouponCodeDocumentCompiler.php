<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

use Magento\Framework\App\ResourceConnection;

class CouponCodeDocumentCompiler implements EntityDocumentCompilerInterface
{
    private const BATCH_SIZE = 2000;

    public function __construct(private readonly ResourceConnection $resourceConnection) {}

    public function compileAll(): array
    {
        $documents = [];
        $lastId = 0;
        do {
            $batch = $this->fetchBatch(static function ($select) use ($lastId) {
                return $select->where('sc.coupon_id > ?', $lastId)->order('sc.coupon_id ASC')->limit(self::BATCH_SIZE);
            });
            foreach ($batch as $row) {
                $documents[] = $this->toDocument($row);
                $lastId = max($lastId, (int) $row['coupon_id']);
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
            static fn ($select) => $select->where('sc.coupon_id IN (?)', $ids)
        ));
    }

    private function fetchBatch(callable $configureSelect): array
    {
        $connection = $this->resourceConnection->getConnection();
        $couponTable = $this->resourceConnection->getTableName('salesrule_coupon');
        $ruleTable = $this->resourceConnection->getTableName('salesrule');

        $select = $connection->select()
            ->from(['sc' => $couponTable], ['coupon_id', 'code'])
            ->joinLeft(['sr' => $ruleTable], 'sr.rule_id = sc.rule_id', ['name']);

        return $connection->fetchAll($configureSelect($select));
    }

    private function toDocument(array $row): array
    {
        return [
            'entity_id' => (int) $row['coupon_id'],
            'primary_text' => (string) $row['code'],
            'secondary_text' => (string) ($row['name'] ?? ''),
        ];
    }
}

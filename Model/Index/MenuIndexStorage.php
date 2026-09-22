<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

use Magento\Framework\App\ResourceConnection;

/**
 * Replaces the full table contents on every rebuild, so records from an uninstalled module
 * cannot linger.
 */
class MenuIndexStorage
{
    public const TABLE = 'bigbridge_quicknav_menu_index';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {}

    /**
     * @param  array[]  $records
     */
    public function replaceAll(array $records): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->beginTransaction();
        try {
            $connection->delete($table);

            foreach (array_chunk($records, 500) as $chunk) {
                $connection->insertMultiple($table, $chunk);
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return count($records);
    }
}

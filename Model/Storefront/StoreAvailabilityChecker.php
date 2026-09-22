<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Storefront;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Which store views an entity is actually live in. Stricter than `scopable`, which describes only
 * scope granularity: an entity can be scopable to a store view while being disabled or unassigned
 * in it.
 */
class StoreAvailabilityChecker
{
    private const PRODUCT_STATUS_ENABLED = 1;

    private const CATEGORY_ACTIVE = 1;

    private const CMS_PAGE_ACTIVE = 1;

    /** Magento's own convention: a `cms_page_store` row with store_id = 0 means "All Store Views". */
    private const ALL_STORE_VIEWS = 0;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
    ) {}

    /**
     * @return array<int, array{storeId: int, name: string}>
     */
    public function viewableStores(string $entityType, int $entityId): array
    {
        return match ($entityType) {
            'product' => $this->productViewableStores($entityId),
            'category' => $this->categoryViewableStores($entityId),
            'cms_page' => $this->cmsPageViewableStores($entityId),
            default => [],
        };
    }

    /**
     * @return array<int, array{storeId: int, name: string}>
     */
    private function productViewableStores(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $websiteIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_website'), ['website_id'])
                ->where('product_id = ?', $productId)
        );
        if ($websiteIds === []) {
            return [];
        }

        $candidateStores = $this->activeStoresForWebsites(array_map('intval', $websiteIds));
        if ($candidateStores === []) {
            return [];
        }

        $attributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'status')->getAttributeId();
        $effectiveStatus = $this->effectiveEavValue(
            $connection,
            'catalog_product_entity_int',
            $attributeId,
            $productId,
            array_column($candidateStores, 'storeId')
        );

        return array_values(array_filter(
            $candidateStores,
            static fn (array $store) => ($effectiveStatus[$store['storeId']] ?? null) === self::PRODUCT_STATUS_ENABLED
        ));
    }

    /**
     * @return array<int, array{storeId: int, name: string}>
     */
    private function categoryViewableStores(int $categoryId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $path = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity'), ['path'])
                ->where('entity_id = ?', $categoryId)
        );
        if ($path === false) { // @phpstan-ignore identical.alwaysFalse
            return [];
        }
        $pathIds = array_map('intval', array_filter(explode('/', (string) $path)));

        // Categories have no store assignment table the way products do. A category belongs to a
        // store when that store group's root_category_id appears in the category's ancestor path.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['s' => $this->resourceConnection->getTableName('store')], ['store_id', 'name'])
                ->joinLeft(
                    ['g' => $this->resourceConnection->getTableName('store_group')],
                    's.group_id = g.group_id',
                    ['root_category_id']
                )
                ->where('s.store_id != 0')
                ->where('s.is_active = 1')
        );

        $assignedStores = array_values(array_filter(
            $rows,
            static fn (array $row) => in_array((int) $row['root_category_id'], $pathIds, true)
        ));
        if ($assignedStores === []) {
            return [];
        }

        $attributeId = (int) $this->eavConfig->getAttribute('catalog_category', 'is_active')->getAttributeId();
        $storeIds = array_map(static fn (array $store) => (int) $store['store_id'], $assignedStores);
        $effectiveActive = $this->effectiveEavValue(
            $connection,
            'catalog_category_entity_int',
            $attributeId,
            $categoryId,
            $storeIds
        );

        $result = [];
        foreach ($assignedStores as $store) {
            $storeId = (int) $store['store_id'];
            if (($effectiveActive[$storeId] ?? null) === self::CATEGORY_ACTIVE) {
                $result[] = ['storeId' => $storeId, 'name' => (string) $store['name']];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{storeId: int, name: string}>
     */
    private function cmsPageViewableStores(int $pageId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $isActive = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('cms_page'), ['is_active'])
                ->where('page_id = ?', $pageId)
        );
        if ($isActive === false || (int) $isActive !== self::CMS_PAGE_ACTIVE) { // @phpstan-ignore identical.alwaysFalse
            return [];
        }

        $assignedStoreIds = array_map(
            'intval',
            $connection->fetchCol(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('cms_page_store'), ['store_id'])
                    ->where('page_id = ?', $pageId)
            )
        );

        if (in_array(self::ALL_STORE_VIEWS, $assignedStoreIds, true)) {
            return $this->activeStoresForWebsites(null);
        }

        if ($assignedStoreIds === []) {
            return [];
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['store_id', 'name'])
                ->where('store_id IN (?)', $assignedStoreIds)
                ->where('is_active = 1')
        );

        return array_map(
            static fn (array $row) => ['storeId' => (int) $row['store_id'], 'name' => (string) $row['name']],
            $rows
        );
    }

    /**
     * @param  int[]|null  $websiteIds  null = every website
     * @return array<int, array{storeId: int, name: string}>
     */
    private function activeStoresForWebsites(?array $websiteIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('store'), ['store_id', 'name'])
            ->where('store_id != 0')
            ->where('is_active = 1');
        if ($websiteIds !== null) {
            $select->where('website_id IN (?)', $websiteIds);
        }

        return array_map(
            static fn (array $row) => ['storeId' => (int) $row['store_id'], 'name' => (string) $row['name']],
            $connection->fetchAll($select)
        );
    }

    /**
     * Effective EAV int value per store id: the store's override row if present, otherwise the
     * store_id = 0 value. With no default row at all every store resolves to null, which callers
     * treat as not viewable rather than as enabled.
     *
     * @param  int[]  $storeIds
     * @return array<int, int|null>
     */
    private function effectiveEavValue(
        AdapterInterface $connection,
        string $table,
        int $attributeId,
        int $entityId,
        array $storeIds
    ): array {
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName($table), ['store_id', 'value'])
                ->where('attribute_id = ?', $attributeId)
                ->where('entity_id = ?', $entityId)
                ->where('store_id IN (?)', array_unique(array_merge([0], $storeIds)))
        );

        $default = array_key_exists(0, $rows) ? (int) $rows[0] : null;

        $result = [];
        foreach ($storeIds as $storeId) {
            $result[$storeId] = array_key_exists($storeId, $rows) ? (int) $rows[$storeId] : $default;
        }

        return $result;
    }
}

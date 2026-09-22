<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Category;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads product membership from `catalog_category_product_index_store{N}` (the index the
 * storefront category page uses) rather than the raw `catalog_category_product` relation table,
 * so this stays fast regardless of category size.
 */
class ProductsReader
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly BackendUrl $backendUrl,
        private readonly EavConfig $eavConfig,
    ) {}

    public function getCategoryName(int $categoryId): string
    {
        try {
            return (string) $this->categoryRepository->get($categoryId)->getName();
        } catch (NoSuchEntityException) {
            return (string) __('Unknown category');
        }
    }

    public function getCategoryEditUrl(int $categoryId): string
    {
        return $this->backendUrl->getUrl('catalog/category/edit', ['id' => $categoryId]);
    }

    public function getProductEditUrl(int $productId): string
    {
        return $this->backendUrl->getUrl('catalog/product/edit', ['id' => $productId]);
    }

    /**
     * @return array{entity_id: int, sku: string, name: string}[]
     */
    public function getProducts(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $storeId = (int) $this->storeManager->getStore()->getId();
        $indexTable = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$storeId);
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'name')->getAttributeId();

        return $connection->fetchAll(
            $connection->select()
                ->from(['idx' => $indexTable], [])
                ->join(['p' => $productTable], 'p.entity_id = idx.product_id', ['entity_id', 'sku'])
                ->joinLeft(
                    ['name_attr' => $varcharTable],
                    $connection->quoteInto(
                        'name_attr.entity_id = p.entity_id AND name_attr.attribute_id = ? AND name_attr.store_id = 0',
                        $nameAttributeId
                    ),
                    ['name' => 'name_attr.value']
                )
                ->where('idx.category_id = ?', $categoryId)
                ->where('idx.store_id = ?', $storeId)
                ->order('idx.position ASC')
        );
    }
}

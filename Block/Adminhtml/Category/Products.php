<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Block\Adminhtml\Category;

use BigBridge\QuickNav\Model\Category\ProductsReader;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Products extends Template
{
    public function __construct(
        Context $context,
        private readonly ProductsReader $productsReader,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getCategoryId(): int
    {
        return (int) $this->getRequest()->getParam('category_id');
    }

    public function getCategoryName(): string
    {
        return $this->productsReader->getCategoryName($this->getCategoryId());
    }

    public function getCategoryEditUrl(): string
    {
        return $this->productsReader->getCategoryEditUrl($this->getCategoryId());
    }

    public function getProductEditUrl(int $productId): string
    {
        return $this->productsReader->getProductEditUrl($productId);
    }

    /**
     * @return array{entity_id: int, sku: string, name: string}[]
     */
    public function getProducts(): array
    {
        return $this->productsReader->getProducts($this->getCategoryId());
    }
}

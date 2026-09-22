<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Controller\Adminhtml\Category;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Own read-only page rather than Magento's admin product grid: that grid has no category filter
 * and cannot be given one without changing core UI component config.
 */
class Products extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::categories';

    public function execute(): ResultInterface
    {
        $categoryId = (int) $this->getRequest()->getParam('category_id');
        if ($categoryId <= 0) {
            throw new LocalizedException(__('A valid category ID is required.'));
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Magento_Catalog::catalog_categories');
        $resultPage->getConfig()->getTitle()->prepend((string) __('Products in Category'));

        return $resultPage;
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Storefront;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Entity plus store id to live frontend URL, read from `url_rewrite` rather than guessed from a
 * URL key.
 */
class FrontendUrlResolver
{
    /**
     * `SearchResult::entityType` values do not all match `url_rewrite.entity_type`: CMS pages are
     * stored there as `cms-page`, with a hyphen.
     */
    private const ENTITY_TYPE_TO_URL_REWRITE_TYPE = [
        'product' => 'product',
        'category' => 'category',
        'cms_page' => 'cms-page',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    public function resolve(string $entityType, int $entityId, int $storeId): ?string
    {
        $urlRewriteEntityType = self::ENTITY_TYPE_TO_URL_REWRITE_TYPE[$entityType] ?? null;
        if ($urlRewriteEntityType === null) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('url_rewrite');

        // redirect_type = 0 is the current, non-redirecting rewrite for this entity and store.
        // It can be filtered on directly here because the entity is already known.
        $requestPath = $connection->fetchOne(
            $connection->select()
                ->from($table, ['request_path'])
                ->where('entity_type = ?', $urlRewriteEntityType)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->limit(1)
        );
        // AdapterInterface::fetchOne()'s @return is declared `string`, but it actually returns
        // `false` when no row matches — the same real runtime guard UrlPasteResolver relies on.
        if ($requestPath === false) { // @phpstan-ignore identical.alwaysFalse
            return null;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return null;
        }

        // getBaseUrl() is declared on the concrete Store class, not on the StoreInterface that
        // StoreManagerInterface documents as its return type.
        if (! $store instanceof Store) {
            return null;
        }

        // Resolves the target store's own base URL by store id, not the admin session's.
        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/').'/'.$requestPath;
    }
}

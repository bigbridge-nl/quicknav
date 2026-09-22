<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Resolves a pasted storefront URL to its entity through `url_rewrite`. Storefront URLs only —
 * admin deep links are not parsed.
 *
 * The ACL check before building a result is required, not defence in depth: SearchService runs
 * this ahead of every provider and returns its result immediately, and url_rewrite rows persist
 * for disabled and hidden entities, so skipping it would let any role holding only
 * Magento_Backend::admin read names, SKUs and breadcrumbs it has no resource for.
 */
class UrlPasteResolver
{
    private const DEFAULT_STORE_ID = 0;

    /** Root Catalog (1) and Default Category (2) are not meaningful breadcrumb context. */
    private const BREADCRUMB_SKIP_IDS = [1, 2];

    /** Mirrors the ACL_RESOURCE each sibling text-search provider already enforces for this entity_type. */
    private const ACL_RESOURCE_BY_ENTITY_TYPE = [
        'product' => 'Magento_Catalog::products',
        'category' => 'Magento_Catalog::categories',
        'cms-page' => 'Magento_Cms::page',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
        private readonly EavConfig $eavConfig,
        private readonly AuthorizationInterface $authorization,
        private readonly FrontendRouteOwnershipResolver $frontendRouteOwnershipResolver,
        private readonly ?UrlFinderInterface $urlFinder = null,
    ) {}

    public function resolve(string $query): UrlPasteOutcome
    {
        $query = trim($query);
        if (! $this->looksLikeUrl($query)) {
            return UrlPasteOutcome::notAUrl();
        }

        [$host, $requestPath] = $this->splitHostAndPath($query);

        $storeId = $this->matchStoreId($host);
        if ($storeId === null) {
            return UrlPasteOutcome::domainNotConfigured();
        }

        $entity = $this->lookupEntity($requestPath, $storeId);
        if ($entity === null) {
            $routeResult = $this->frontendRouteOwnershipResolver->resolve($requestPath);
            if ($routeResult !== null) {
                return UrlPasteOutcome::resolved($routeResult);
            }

            return UrlPasteOutcome::unresolved();
        }

        $entityType = (string) $entity['entity_type'];
        $aclResource = self::ACL_RESOURCE_BY_ENTITY_TYPE[$entityType] ?? null;
        if ($aclResource !== null && ! $this->authorization->isAllowed($aclResource)) {
            return UrlPasteOutcome::accessDenied();
        }

        $result = $this->buildResult($entityType, (int) $entity['entity_id']);
        if ($result === null) {
            return UrlPasteOutcome::unresolved();
        }

        return UrlPasteOutcome::resolved($result);
    }

    /**
     * A bare "word.word" such as "config.php" is a likelier search term than a pasted link, so it
     * does not count. A scheme, a "www." prefix, or a host followed by a path does — every real
     * storefront link has a path.
     */
    private function looksLikeUrl(string $query): bool
    {
        if ($query === '' || str_contains($query, ' ')) {
            return false;
        }

        return (bool) preg_match('#^https?://#i', $query)
            || (bool) preg_match('#^www\.[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $query)
            || (bool) preg_match('#^([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?/.+$#i', $query);
    }

    /**
     * @return array{0: string, 1: string} [host, request path — no leading/trailing slash,
     *                                     no query string or fragment]
     */
    private function splitHostAndPath(string $query): array
    {
        $urlToParse = preg_match('#^https?://#i', $query) ? $query : ('http://'.$query);
        $parts = parse_url($urlToParse);

        return [
            mb_strtolower($parts['host'] ?? ''),
            trim($parts['path'] ?? '', '/'),
        ];
    }

    private function matchStoreId(string $host): ?int
    {
        if ($host === '') {
            return null;
        }

        foreach ($this->storeManager->getStores() as $store) {
            // getBaseUrl() is only declared on the concrete Store class, not StoreInterface,
            // even though StoreManagerInterface::getStores() is typed to return the interface.
            if (! $store instanceof Store) {
                continue;
            }

            $baseHost = mb_strtolower((string) parse_url(
                (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB),
                PHP_URL_HOST
            ));
            if ($baseHost !== '' && $baseHost === $host) {
                return (int) $store->getId();
            }
        }

        return null;
    }

    /**
     * @return array{entity_type: string, entity_id: string}|null
     */
    private function lookupEntity(string $requestPath, int $storeId): ?array
    {
        if ($requestPath === '') {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('url_rewrite');

        // A storefront request can carry controller and action segments after the rewrite path,
        // so try the full path first, then the progressively shorter candidates routing produces.
        $pathParts = array_values(array_filter(
            explode('/', trim($requestPath, '/')),
            static fn (string $part): bool => $part !== ''
        ));
        for ($length = count($pathParts); $length > 0; $length--) {
            $candidate = implode('/', array_slice($pathParts, 0, $length));

            if ($this->urlFinder !== null) {
                $rewrite = $this->urlFinder->findOneByData([
                    UrlRewrite::REQUEST_PATH => $candidate,
                    UrlRewrite::STORE_ID => $storeId,
                ]);
                if ($rewrite !== null) {
                    return [
                        'entity_type' => (string) $rewrite->getEntityType(),
                        'entity_id' => (string) $rewrite->getEntityId(),
                    ];
                }

                continue;
            }

            $row = $connection->fetchRow(
                $connection->select()
                    ->from($table, ['entity_type', 'entity_id'])
                    ->where('request_path = ?', $candidate)
                    ->where('store_id = ?', $storeId)
                    ->order('redirect_type ASC')
                    ->limit(1)
            );

            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private function buildResult(string $entityType, int $entityId): ?SearchResult
    {
        return match ($entityType) {
            'product' => $this->buildProductResult($entityId),
            'category' => $this->buildCategoryResult($entityId),
            'cms-page' => $this->buildCmsPageResult($entityId),
            default => null,
        };
    }

    private function buildProductResult(int $entityId): ?SearchResult
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'name')->getAttributeId();

        $sku = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['sku'])
                ->where('entity_id = ?', $entityId)
        );
        // AdapterInterface::fetchOne()'s @return is declared as `string`, but it actually
        // returns `false` when no row matches — this is a real runtime guard, not dead code,
        // despite what the imprecise phpdoc implies to PHPStan.
        if ($sku === false) { // @phpstan-ignore identical.alwaysFalse
            return null;
        }

        $name = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity_varchar'), ['value'])
                ->where('entity_id = ?', $entityId)
                ->where('attribute_id = ?', $attributeId)
                ->where('store_id = ?', self::DEFAULT_STORE_ID)
        );

        return new SearchResult(
            entityType: 'product',
            iconKey: 'package',
            label: $name !== false ? (string) $name : (string) $sku, // @phpstan-ignore ternary.elseUnreachable
            context: (string) $sku,
            url: $this->backendUrl->getUrl('catalog/product/edit', ['id' => $entityId]),
            matchTier: MatchTier::EXACT,
            matchedFromPastedLink: true,
        );
    }

    private function buildCategoryResult(int $entityId): ?SearchResult
    {
        $connection = $this->resourceConnection->getConnection();
        $categoryTable = $this->resourceConnection->getTableName('catalog_category_entity');
        $varcharTable = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_category', 'name')->getAttributeId();

        $row = $connection->fetchRow(
            $connection->select()->from($categoryTable, ['path'])->where('entity_id = ?', $entityId)
        );
        if ($row === false) {
            return null;
        }

        $ancestorIds = array_values(array_filter(
            array_map('intval', array_filter(explode('/', (string) $row['path']))),
            static fn (int $id) => $id !== $entityId && ! in_array($id, self::BREADCRUMB_SKIP_IDS, true)
        ));

        $names = $connection->fetchPairs(
            $connection->select()
                ->from($varcharTable, ['entity_id', 'value'])
                ->where('entity_id IN (?)', array_merge($ancestorIds, [$entityId]))
                ->where('attribute_id = ?', $nameAttributeId)
                ->where('store_id = ?', self::DEFAULT_STORE_ID)
        );

        if (! isset($names[$entityId])) {
            return null;
        }

        $breadcrumb = implode(' > ', array_map(
            static fn (int $id) => $names[$id] ?? '',
            $ancestorIds
        ));

        return new SearchResult(
            entityType: 'category',
            iconKey: 'folder-tree',
            label: $names[$entityId],
            context: $breadcrumb,
            url: $this->backendUrl->getUrl('catalog/category/edit', ['id' => $entityId]),
            matchTier: MatchTier::EXACT,
            matchedFromPastedLink: true,
        );
    }

    private function buildCmsPageResult(int $entityId): ?SearchResult
    {
        $connection = $this->resourceConnection->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('cms_page'), ['title', 'identifier'])
                ->where('page_id = ?', $entityId)
        );

        if ($row === false) {
            return null;
        }

        return new SearchResult(
            entityType: 'cms_page',
            iconKey: 'file-text',
            label: (string) $row['title'],
            context: (string) $row['identifier'],
            url: $this->backendUrl->getUrl('cms/page/edit', ['page_id' => $entityId]),
            matchTier: MatchTier::EXACT,
            matchedFromPastedLink: true,
        );
    }
}

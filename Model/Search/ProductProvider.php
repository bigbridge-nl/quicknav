<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Search\OpenSearch\CandidateIdNarrower;
use BigBridge\QuickNav\Model\Store\Scopable;
use BigBridge\QuickNav\Model\Store\ScopeChoiceBuilder;
use BigBridge\QuickNav\Model\Storefront\StorefrontLinkBuilder;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Name is read at the default store view, so results do not change with whichever store view
 * the admin last touched.
 */
class ProductProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'product';

    private const ICON_KEY = 'package';

    /** Default/admin scope, per Magento EAV convention. */
    private const DEFAULT_STORE_ID = 0;

    /** Access control: the same resource Magento's own product controllers require. */
    private const ACL_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly EavConfig $eavConfig,
        private readonly ScopeChoiceBuilder $scopeChoiceBuilder,
        private readonly AuthorizationInterface $authorization,
        private readonly StoreManagerInterface $storeManager,
        private readonly StorefrontLinkBuilder $storefrontLinkBuilder,
        private readonly ?CandidateIdNarrower $candidateIdNarrower = null,
    ) {}

    public function search(string $query, int $limit): array
    {
        $normalizedQuery = mb_strtolower(trim($query));
        if ($normalizedQuery === '') {
            return [];
        }

        if (! $this->authorization->isAllowed(self::ACL_RESOURCE)) {
            return [];
        }

        $candidateIds = $this->candidateIdNarrower?->narrow(self::ENTITY_TYPE, $normalizedQuery, $limit);
        if ($candidateIds === []) {
            return []; // OpenSearch reachable, genuinely zero matches — trust it
        }

        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $nameAttributeTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $nameAttributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'name')->getAttributeId();
        $imageAttributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'small_image')->getAttributeId();
        $likeParam = '%'.$normalizedQuery.'%';

        // Must stay one where() call so the OR is a single parenthesized unit. Split across two,
        // AND binds tighter than OR and the entity_id IN() clause below would apply to only one
        // branch of it.
        $textMatch = $connection->quoteInto('e.sku LIKE ?', $likeParam)
            .' OR '.$connection->quoteInto('name_attr.value LIKE ?', $likeParam);

        $select = $connection->select()
            ->from(['e' => $productTable], ['entity_id', 'sku'])
            ->joinLeft(
                ['name_attr' => $nameAttributeTable],
                $connection->quoteInto(
                    'name_attr.entity_id = e.entity_id AND name_attr.attribute_id = ? AND name_attr.store_id = '.self::DEFAULT_STORE_ID,
                    $nameAttributeId
                ),
                ['name' => 'name_attr.value']
            )
            // small_image is one indexed varchar join, cheap enough to run on every search —
            // unlike the image-resize pipeline, which would need a fully loaded product model.
            ->joinLeft(
                ['image_attr' => $nameAttributeTable],
                $connection->quoteInto(
                    'image_attr.entity_id = e.entity_id AND image_attr.attribute_id = ? AND image_attr.store_id = '.self::DEFAULT_STORE_ID,
                    $imageAttributeId
                ),
                ['image' => 'image_attr.value']
            )
            ->where($textMatch)
            // Over-fetch so exact/prefix matches aren't crowded out by contains matches
            // before sorting; the shared SearchService cap still applies on top of this.
            ->limit($limit * 3);

        if ($candidateIds !== null) {
            $select->where('e.entity_id IN (?)', $candidateIds);
        }

        $rows = $connection->fetchAll($select);

        // getBaseUrl() is declared on the concrete Store class, not on the StoreInterface that
        // StoreManagerInterface documents as its return type.
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $mediaBaseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->buildResult($row, $normalizedQuery, $mediaBaseUrl);
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    private function buildResult(array $row, string $normalizedQuery, string $mediaBaseUrl): SearchResult
    {
        $name = (string) ($row['name'] ?? $row['sku']);
        $sku = (string) $row['sku'];
        $params = ['id' => (int) $row['entity_id']];
        $image = $row['image'] ?? null;

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name,
            context: $sku,
            url: $this->backendUrl->getUrl('catalog/product/edit', $params),
            matchTier: $this->matchTier(mb_strtolower($sku), mb_strtolower($name), $normalizedQuery),
            scopable: Scopable::STORE_VIEW,
            // Product edit reads only a `store` param, never `website`, so a website-level
            // choice would produce a URL Magento silently ignores.
            scopeChoices: $this->scopeChoiceBuilder->build('catalog/product/edit', $params, Scopable::STORE_VIEW, includeWebsiteLevel: false),
            imageUrl: $image && $image !== 'no_selection' ? $mediaBaseUrl.'catalog/product'.$image : null,
            // Eagerly computed per matched row, the same cost already accepted for scopeChoices.
            storefrontChoices: $this->storefrontLinkBuilder->build(self::ENTITY_TYPE, (int) $row['entity_id']),
        );
    }

    private function matchTier(string $sku, string $name, string $normalizedQuery): int
    {
        if ($sku === $normalizedQuery || $name === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($sku, $normalizedQuery) || str_starts_with($name, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

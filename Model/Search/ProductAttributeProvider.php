<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * The entity_type_id filter is required: `eav_attribute` holds attributes for every EAV entity
 * type, so without it a customer attribute sharing a product attribute's code would surface here.
 */
class ProductAttributeProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'product_attribute';

    private const ICON_KEY = 'list';

    private const ACL_RESOURCE = 'Magento_Catalog::attributes_attributes';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly EavConfig $eavConfig,
        private readonly AuthorizationInterface $authorization,
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

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('eav_attribute');
        $entityTypeId = (int) $this->eavConfig->getEntityType('catalog_product')->getId();
        $likeParam = '%'.$normalizedQuery.'%';

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['attribute_id', 'attribute_code', 'frontend_label'])
                ->where('entity_type_id = ?', $entityTypeId)
                // Parenthesized as a single AND-term so it can't be split apart by SQL's
                // AND-before-OR precedence and accidentally match a different entity type.
                ->where(
                    '('
                    .$connection->quoteInto('attribute_code LIKE ?', $likeParam)
                    .' OR '
                    .$connection->quoteInto('frontend_label LIKE ?', $likeParam)
                    .')'
                )
                // Over-fetch so exact/prefix matches aren't crowded out by contains matches
                // before sorting; the shared SearchService cap still applies on top of this.
                ->limit($limit * 3)
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->buildResult($row, $normalizedQuery);
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    private function buildResult(array $row, string $normalizedQuery): SearchResult
    {
        $attributeCode = (string) ($row['attribute_code'] ?? '');
        $frontendLabel = (string) ($row['frontend_label'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $frontendLabel !== '' ? $frontendLabel : $attributeCode,
            context: $attributeCode,
            url: $this->backendUrl->getUrl(
                'catalog/product_attribute/edit',
                ['attribute_id' => (int) $row['attribute_id']]
            ),
            matchTier: $this->matchTier(mb_strtolower($attributeCode), mb_strtolower($frontendLabel), $normalizedQuery),
        );
    }

    private function matchTier(string $attributeCode, string $frontendLabel, string $normalizedQuery): int
    {
        if ($attributeCode === $normalizedQuery || $frontendLabel === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($attributeCode, $normalizedQuery) || str_starts_with($frontendLabel, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

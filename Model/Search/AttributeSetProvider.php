<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Scoped to the `catalog_product` entity type: `eav_attribute_set` also holds attribute sets
 * for every other EAV entity type.
 */
class AttributeSetProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'attribute_set';

    private const ICON_KEY = 'layers';

    private const ACL_RESOURCE = 'Magento_Catalog::sets';

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
        $table = $this->resourceConnection->getTableName('eav_attribute_set');
        $entityTypeId = (int) $this->eavConfig->getEntityType('catalog_product')->getId();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['attribute_set_id', 'attribute_set_name'])
                ->where('entity_type_id = ?', $entityTypeId)
                ->where('attribute_set_name LIKE ?', '%'.$normalizedQuery.'%')
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
        $name = (string) ($row['attribute_set_name'] ?? '');

        return new SearchResult(
            entityType: self::ENTITY_TYPE,
            iconKey: self::ICON_KEY,
            label: $name,
            context: '',
            url: $this->backendUrl->getUrl('catalog/product_set/edit', ['id' => (int) $row['attribute_set_id']]),
            matchTier: $this->matchTier(mb_strtolower($name), $normalizedQuery),
        );
    }

    private function matchTier(string $name, string $normalizedQuery): int
    {
        if ($name === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($name, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }

        return MatchTier::CONTAINS;
    }
}

<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Index\MenuIndexStorage;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Route\ConfigInterface as RouteConfig;
use Magento\Framework\AuthorizationInterface;

/**
 * Fallback for storefront controller routes with no url_rewrite entity: identifies the owning
 * frontend module and points at its first ACL-allowed admin menu or configuration entry.
 */
class FrontendRouteOwnershipResolver
{
    private const ENTITY_TYPE = 'url_route';

    private const ICON_KEY = 'route';

    private const MENU_ITEM_PRIORITY = [
        'menu_item' => 0,
        'config_group' => 1,
        'config_section' => 2,
        'config_field' => 3,
    ];

    public function __construct(
        private readonly RouteConfig $routeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
    ) {}

    public function resolve(string $requestPath): ?SearchResult
    {
        $normalizedPath = $this->normalizeRequestPath($requestPath);
        $frontName = strtolower((string) strtok($normalizedPath, '/'));
        if ($frontName === '') {
            return null;
        }

        // This is the same source Magento's frontend Base router uses for the first path
        // segment; do not maintain a second list of storefront routes in QuickNav.
        $modules = array_values(array_unique(array_filter(array_map(
            static fn (mixed $module): string => (string) $module,
            (array) $this->routeConfig->getModulesByFrontName($frontName, Area::AREA_FRONTEND)
        ))));
        if ($modules === []) {
            return null;
        }

        $firstModuleName = null;
        foreach ($modules as $moduleName) {
            // RouteConfig is declared as returning non-empty strings, so without widening this
            // back to plain string the empty guard below reads as dead code.
            /** @var string $moduleName */
            $moduleName = (string) $moduleName;
            if ($moduleName === '') {
                continue;
            }
            $firstModuleName ??= $moduleName;

            $row = $this->findAllowedAdminEntry($moduleName);
            if ($row === null) {
                continue;
            }

            $moduleLabel = $this->moduleLabel($moduleName);
            $context = 'Storefront route: /'.$normalizedPath.' · Related admin: '.(string) $row['breadcrumb'];

            return new SearchResult(
                entityType: self::ENTITY_TYPE,
                iconKey: self::ICON_KEY,
                label: $moduleLabel,
                context: $context,
                url: $this->buildAdminUrl($row),
                matchTier: MatchTier::EXACT,
                matchedFromPastedLink: true,
            );
        }

        if ($firstModuleName !== null) {
            return new SearchResult(
                entityType: self::ENTITY_TYPE,
                iconKey: self::ICON_KEY,
                label: $this->moduleLabel($firstModuleName),
                context: 'Storefront route: /'.$normalizedPath.' · No direct backend page found',
                url: '',
                matchTier: MatchTier::EXACT,
                matchedFromPastedLink: true,
            );
        }

        return null;
    }

    /**
     * Query and fragment suffixes are not route segments, and repeated or outer slashes do not
     * change the front name.
     */
    private function normalizeRequestPath(string $requestPath): string
    {
        $path = (string) preg_replace('/[?#].*$/', '', $requestPath);
        $path = trim((string) preg_replace('#/{2,}#', '/', $path), '/');

        if (str_starts_with(strtolower($path), 'index.php/')) {
            $path = substr($path, strlen('index.php/'));
        }

        return trim($path, '/');
    }

    /** @return array<string, mixed>|null */
    private function findAllowedAdminEntry(string $moduleName): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(MenuIndexStorage::TABLE);
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('acl_resource LIKE ?', $moduleName.'::%')
                ->limit(50)
        );

        $allowedRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->authorization->isAllowed((string) $row['acl_resource'])
        ));
        usort(
            $allowedRows,
            static fn (array $left, array $right): int => (self::MENU_ITEM_PRIORITY[$left['record_type']] ?? 99)
                <=> (self::MENU_ITEM_PRIORITY[$right['record_type']] ?? 99)
        );

        return $allowedRows[0] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function buildAdminUrl(array $row): string
    {
        $params = $row['section_id'] !== null && $row['section_id'] !== ''
            ? ['section' => $row['section_id']]
            : [];

        $url = $this->backendUrl->getUrl((string) $row['admin_path'], $params);
        if ($row['group_id'] !== null && $row['group_id'] !== '') {
            $url .= '#'.$row['group_id'].'-head';
        }

        return $url;
    }

    private function moduleLabel(string $moduleName): string
    {
        $shortName = str_contains($moduleName, '_') ? (string) substr($moduleName, strpos($moduleName, '_') + 1) : $moduleName;
        $label = preg_replace('/(?<!^)([A-Z])/', ' $1', $shortName) ?? $shortName;

        return trim($label);
    }
}

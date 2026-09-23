<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

use BigBridge\QuickNav\Model\Store\Scopable;
use Magento\Backend\Model\Menu\Config as MenuConfig;
use Magento\Backend\Model\Menu\Item as MenuItem;
use Magento\Config\Model\Config\Structure\Data as StructureData;
use Magento\Framework\App\Area;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\State;

/**
 * Compiles merged system.xml and menu.xml declarations into flat, searchable records. Runs at
 * build/deploy time only, never on the search request path.
 */
class MenuIndexCompiler
{
    public const TYPE_CONFIG_SECTION = 'config_section';

    public const TYPE_CONFIG_GROUP = 'config_group';

    public const TYPE_CONFIG_FIELD = 'config_field';

    public const TYPE_MENU_ITEM = 'menu_item';

    public const CONFIG_EDIT_ADMIN_PATH = 'adminhtml/system_config/edit';

    /**
     * Some system.xml sections (e.g. Magento_GraphQl's `web_api`) declare no `resource`, which
     * core treats as deny-to-all. Every such record gets this root resource instead of a null one
     * that no ACL filter could evaluate.
     */
    private const FALLBACK_ACL_RESOURCE = 'Magento_Backend::admin';

    /**
     * StructureData's own cache key for its adminhtml-scoped merged tree — same
     * wrong-area-cached-empty risk as Menu\Config below, cleared for the same reason.
     */
    private const STRUCTURE_CACHE_ID = Area::AREA_ADMINHTML.'::backend_system_configuration_structure';

    public function __construct(
        private readonly StructureData $structureData,
        private readonly MenuConfig $menuConfig,
        private readonly State $appState,
        private readonly ConfigCache $configCache,
    ) {}

    /**
     * @return array<int, array{
     *     record_type: string,
     *     label: string,
     *     search_label: string,
     *     breadcrumb: string,
     *     admin_path: string,
     *     acl_resource: string,
     *     section_id: ?string,
     *     group_id: ?string,
     *     scopable: string,
     * }>
     */
    public function compile(): array
    {
        // Menu\Config caches its tree under one area-agnostic key on the first getMenu() call.
        // If that call happens outside adminhtml — a real risk from CLI, indexer or cron — the
        // wrong, often empty, tree is cached and reused, so rebuild from source every time.
        $this->configCache->remove(MenuConfig::CACHE_MENU_OBJECT);
        $this->configCache->remove(self::STRUCTURE_CACHE_ID);

        // system.xml and menu.xml are only merged under adminhtml, and CLI, indexer and cron
        // contexts run with whatever area is already set. setAreaCode() throws once an area is
        // set; emulateAreaCode forces adminhtml for this call and restores the previous state.
        return $this->appState->emulateAreaCode(
            Area::AREA_ADMINHTML,
            fn (): array => array_merge($this->compileConfigStructure(), $this->compileMenu())
        );
    }

    /**
     * Walks the raw merged array rather than Structure's Flyweight/Iterator graph: that graph
     * reuses one Section (and Group) instance for every element, so descending into a child
     * iterator while an ancestor iterator is still current mutates shared state under it.
     */
    private function compileConfigStructure(): array
    {
        $records = [];
        $data = $this->structureData->get();
        $tabLabels = [];

        foreach ($data['tabs'] ?? [] as $tabId => $tab) {
            $tabLabels[$tabId] = (string) (__($tab['label'] ?? $tabId));
        }

        foreach ($data['sections'] ?? [] as $sectionId => $section) {
            $sectionId = (string) $sectionId;
            $sectionLabel = (string) (__($section['label'] ?? $sectionId));
            $tabLabel = $tabLabels[$section['tab'] ?? ''] ?? '';
            $sectionBreadcrumb = $tabLabel !== '' ? sprintf('%s > %s', $tabLabel, $sectionLabel) : $sectionLabel;
            $sectionResource = $section['resource'] ?? null;

            $records[] = $this->buildRecord(
                self::TYPE_CONFIG_SECTION,
                $sectionLabel,
                $sectionBreadcrumb,
                self::CONFIG_EDIT_ADMIN_PATH,
                $sectionResource,
                $sectionId,
                null,
                Scopable::classify($section['showInWebsite'] ?? null, $section['showInStore'] ?? null)
            );

            foreach ($section['children'] ?? [] as $groupId => $group) {
                if (($group['_elementType'] ?? 'group') !== 'group') {
                    continue;
                }

                $groupId = (string) $groupId;
                $groupLabel = (string) (__($group['label'] ?? $groupId));
                $groupBreadcrumb = sprintf('%s > %s', $sectionBreadcrumb, $groupLabel);
                $groupResource = $group['resource'] ?? $sectionResource;

                $records[] = $this->buildRecord(
                    self::TYPE_CONFIG_GROUP,
                    $groupLabel,
                    $groupBreadcrumb,
                    self::CONFIG_EDIT_ADMIN_PATH,
                    $groupResource,
                    $sectionId,
                    $groupId,
                    Scopable::classify($group['showInWebsite'] ?? null, $group['showInStore'] ?? null)
                );

                foreach ($group['children'] ?? [] as $fieldId => $field) {
                    if (($field['_elementType'] ?? 'field') !== 'field') {
                        // Subgroups share this `children` key and are not walked: compilation
                        // stops one level deep, as in the group loop above.
                        continue;
                    }

                    $fieldId = (string) $fieldId;
                    $fieldLabel = (string) (__($field['label'] ?? $fieldId));

                    $records[] = $this->buildRecord(
                        self::TYPE_CONFIG_FIELD,
                        $fieldLabel,
                        sprintf('%s > %s', $groupBreadcrumb, $fieldLabel),
                        self::CONFIG_EDIT_ADMIN_PATH,
                        // system.xsd's <field> has no `resource` attribute of its own (unlike
                        // section/group) — always inherits the parent group's.
                        $groupResource,
                        $sectionId,
                        $groupId,
                        Scopable::classify($field['showInWebsite'] ?? null, $field['showInStore'] ?? null)
                    );
                }
            }
        }

        return $records;
    }

    private function compileMenu(): array
    {
        $records = [];

        foreach ($this->menuConfig->getMenu() as $item) {
            $this->compileMenuItem($item, [], $records);
        }

        return $records;
    }

    /**
     * @param  string[]  $parentTitles
     * @param  array[]  $records
     */
    private function compileMenuItem(MenuItem $item, array $parentTitles, array &$records): void
    {
        $title = (string) $item->getTitle();
        $breadcrumbParts = array_filter(array_merge($parentTitles, [$title]), static fn ($part) => $part !== '');
        $action = $item->getAction();

        if ($action !== null && $action !== '') {
            $records[] = $this->buildRecord(
                self::TYPE_MENU_ITEM,
                $title,
                implode(' > ', $breadcrumbParts),
                rtrim($action, '/'),
                $item->toArray()['resource'] ?? null,
                null,
                null,
                // Menu items aren't system.xml config targets, so scope-awareness (005) never
                // applies to them.
                Scopable::DEFAULT_ONLY
            );
        }

        if ($item->hasChildren()) {
            foreach ($item->getChildren() as $child) {
                $this->compileMenuItem($child, $breadcrumbParts, $records);
            }
        }
    }

    private function buildRecord(
        string $recordType,
        string $label,
        string $breadcrumb,
        string $adminPath,
        ?string $aclResource,
        ?string $sectionId,
        ?string $groupId,
        string $scopable
    ): array {
        return [
            'record_type' => $recordType,
            'label' => $label,
            'search_label' => mb_strtolower($label),
            'breadcrumb' => $breadcrumb,
            'admin_path' => $adminPath,
            'acl_resource' => $aclResource ?? self::FALLBACK_ACL_RESOURCE,
            'section_id' => $sectionId,
            'group_id' => $groupId,
            'scopable' => $scopable,
        ];
    }
}

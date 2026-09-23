<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use BigBridge\QuickNav\Model\Index\MenuIndexStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Which entity types the current admin role can see at least one result of, so the type-filter
 * chips never offer an unusable type. Computed once per widget render, not per keystroke, and
 * goes through the same `isAllowed()` checks the providers themselves perform.
 */
class AccessibleTypeResolver
{
    /**
     * Types gated by one fixed ACL resource. `config_section`/`config_group`/`menu_item` are
     * absent because their resource varies per row.
     */
    private const FIXED_RESOURCE_TYPES = [
        'product' => 'Magento_Catalog::products',
        'category' => 'Magento_Catalog::categories',
        'customer' => 'Magento_Customer::manage',
        'order' => 'Magento_Sales::sales_order',
        'cms_block' => 'Magento_Cms::block',
        'cms_page' => 'Magento_Cms::page',
        'url_rewrite' => 'Magento_UrlRewrite::urlrewrite',
        'search_term' => 'Magento_Search::search',
        'store_view' => 'Magento_Backend::store',
        'product_attribute' => 'Magento_Catalog::attributes_attributes',
        'attribute_set' => 'Magento_Catalog::sets',
        'admin_user' => 'Magento_User::acl_users',
        'admin_role' => 'Magento_User::acl_roles',
        'customer_group' => 'Magento_Customer::group',
        'review' => 'Magento_Review::reviews_all',
        'cart_price_rule' => 'Magento_SalesRule::quote',
        'catalog_price_rule' => 'Magento_CatalogRule::promo_catalog',
        'widget_instance' => 'Magento_Widget::widget_instance',
        'email_template' => 'Magento_Email::template',
        'newsletter_template' => 'Magento_Newsletter::template',
        'newsletter_subscriber' => 'Magento_Newsletter::subscriber',
        'custom_variable' => 'Magento_Variable::variable',
        'search_synonym' => 'Magento_Search::synonyms',
        'tax_rule' => 'Magento_Tax::manage_tax',
        'tax_rate' => 'Magento_Tax::manage_tax',
        'integration' => 'Magento_Integration::integrations',
        'invoice' => 'Magento_Sales::sales_invoice',
        'shipment' => 'Magento_Sales::shipment',
        'credit_memo' => 'Magento_Sales::sales_creditmemo',
        'admin_activity_log' => 'MageOS_AdminActivityLog::activity',
        'coupon_code' => 'Magento_SalesRule::quote',
    ];

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ResourceConnection $resourceConnection,
    ) {}

    /**
     * Types owned by an optional module, mapped to the table that module creates. ACL alone
     * cannot rule these out: a full admin is allowed every resource, including ones no installed
     * module defines, so the type would still be offered with nothing behind it.
     */
    private const TABLE_BACKED_TYPES = [
        'admin_activity_log' => 'admin_activity',
    ];

    /** @return string[] entity types the current admin role can access at least one result of */
    public function resolve(): array
    {
        $accessible = [];
        foreach (self::FIXED_RESOURCE_TYPES as $type => $resource) {
            if ($this->authorization->isAllowed($resource) && $this->isTypeInstalled($type)) {
                $accessible[] = $type;
            }
        }

        return array_merge($accessible, $this->accessibleMenuRecordTypes());
    }

    /**
     * True for every type whose module ships with QuickNav's own hard dependencies, and for an
     * optional type only when its table is actually there.
     */
    private function isTypeInstalled(string $type): bool
    {
        $table = self::TABLE_BACKED_TYPES[$type] ?? null;
        if ($table === null) {
            return true;
        }

        return $this->resourceConnection->getConnection()->isTableExists(
            $this->resourceConnection->getTableName($table)
        );
    }

    /**
     * A record type counts as accessible when the role is allowed at least one of its rows, not
     * every row. Selecting distinct (record_type, acl_resource) pairs keeps this a handful of
     * `isAllowed()` calls regardless of table size.
     *
     * @return string[]
     */
    private function accessibleMenuRecordTypes(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(MenuIndexStorage::TABLE);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['record_type', 'acl_resource'])
                ->distinct(true)
        );

        $accessibleTypes = [];
        $checkedResources = [];
        foreach ($rows as $row) {
            $recordType = $row['record_type'];
            if (isset($accessibleTypes[$recordType])) {
                continue;
            }

            $resource = $row['acl_resource'];
            $checkedResources[$resource] ??= $this->authorization->isAllowed($resource);
            if ($checkedResources[$resource]) {
                $accessibleTypes[$recordType] = true;
            }
        }

        return array_keys($accessibleTypes);
    }
}

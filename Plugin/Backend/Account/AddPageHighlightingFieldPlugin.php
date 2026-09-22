<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Plugin\Backend\Account;

use BigBridge\QuickNav\Model\Config\PageHighlightingPreference;
use Magento\Backend\Block\System\Account\Edit\Form as AccountForm;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\Data\Form as DataForm;

/**
 * Adds the QuickNav highlighting preference to every admin's own "Account Information" page, so
 * the admin-wide default from system config can be overridden per user without extra ACL.
 */
class AddPageHighlightingFieldPlugin
{
    public function __construct(private readonly AdminSession $adminSession) {}

    public function afterGetForm(AccountForm $subject, DataForm $result): DataForm
    {
        // getForm() is called several times per request against the same cached Form
        // instance; addField() is not idempotent and throws on a second call.
        if ($result->getElement('quicknav_page_highlighting')) {
            return $result;
        }

        $fieldset = $result->getElement('base_fieldset');
        $user = $this->adminSession->getUser();

        if (! $fieldset || ! $user) {
            return $result;
        }

        // Magento's own @method docblock says string; ResourceModel\User::_afterLoad()
        // unserializes it into an array before any caller sees it.
        /** @var array<string, string>|null $extra */
        $extra = $user->getExtra();
        $current = $extra[PageHighlightingPreference::EXTRA_KEY] ?? '';

        $fieldset->addField(
            'quicknav_page_highlighting',
            'select',
            [
                'name' => 'quicknav_page_highlighting',
                'label' => __('Highlight page items while typing'),
                'title' => __('Highlight page items while typing'),
                'note' => __('Overrides the admin-wide QuickNav setting for your account only.'),
                'values' => [
                    ['value' => '', 'label' => __('Use admin default')],
                    ['value' => '1', 'label' => __('Yes')],
                    ['value' => '0', 'label' => __('No')],
                ],
                'value' => (string) $current,
            ],
        );

        return $result;
    }
}

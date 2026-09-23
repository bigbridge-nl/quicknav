<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Plugin\Backend\Account;

use BigBridge\QuickNav\Model\Config\PageHighlightingPreference;
use Magento\Backend\Controller\Adminhtml\System\Account\Save;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\RequestInterface;
use Magento\User\Model\UserFactory;

/**
 * Persists the highlighting override onto admin_user.extra: the core Save controller only reads
 * its own fixed field set, so this would otherwise be silently dropped. Runs independently of the
 * core save so an identity-check failure there never blocks a preference this harmless.
 */
class SavePageHighlightingFieldPlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly AdminSession $adminSession,
        private readonly UserFactory $userFactory,
    ) {}

    public function afterExecute(Save $subject, Redirect $result): Redirect
    {
        $submitted = $this->request->getParam('quicknav_page_highlighting');
        $userId = $this->adminSession->getUser()?->getId();

        if ($submitted === null || ! $userId) {
            return $result;
        }

        $user = $this->userFactory->create()->load($userId);

        // Magento's own @method docblock says string; ResourceModel\User::_afterLoad()
        // unserializes it into an array before any caller sees it.
        /** @var array<string, string> $extra */
        $extra = $user->getExtra() ?: [];
        $value = PageHighlightingPreference::encode((string) $submitted);

        if ($value === null) {
            unset($extra[PageHighlightingPreference::EXTRA_KEY]);
        } else {
            $extra[PageHighlightingPreference::EXTRA_KEY] = $value;
        }

        // @phpstan-ignore-next-line argument.type (setExtra()'s own docblock says string; User::beforeSave() re-serializes whatever array is set here)
        $user->setExtra($extra)->save();

        return $result;
    }
}

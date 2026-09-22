<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Block\Adminhtml;

use BigBridge\QuickNav\Model\Access\LoginHint;
use BigBridge\QuickNav\Model\Search\AccessibleTypeResolver;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\UrlInterface;

/**
 * Renders as a plain admin header block on every page, not as a Hyva dashboard widget instance.
 */
class AccessTrigger extends Template
{
    public function __construct(
        Context $context,
        private readonly UrlInterface $url,
        private readonly UserContextInterface $userContext,
        private readonly LoginHint $loginHint,
        private readonly AccessibleTypeResolver $accessibleTypeResolver,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getSearchUrl(): string
    {
        return $this->url->getUrl('quicknav/search/index');
    }

    public function getUserId(): int
    {
        return (int) ($this->userContext->getUserId() ?? 0);
    }

    /** @return string[] */
    public function getAccessibleTypes(): array
    {
        return $this->accessibleTypeResolver->resolve();
    }

    public function shouldShowLoginHint(): bool
    {
        return $this->loginHint->shouldShow();
    }
}

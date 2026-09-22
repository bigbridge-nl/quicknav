<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Observer;

use BigBridge\QuickNav\Model\Access\LoginHint;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class FlagAccessLoginHint implements ObserverInterface
{
    public function __construct(private readonly Session $session) {}

    public function execute(Observer $observer): void
    {
        // Dynamic session data bag; this custom key has no @method annotation on Session.
        // @phpstan-ignore method.notFound
        $this->session->setData(LoginHint::SESSION_KEY, true);
    }
}

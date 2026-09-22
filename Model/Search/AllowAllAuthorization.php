<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Framework\AuthorizationInterface;

/**
 * Lets CLI diagnostics exercise provider search logic outside an admin session, where the real
 * AuthorizationInterface denies every resource. Never wired to a provider in di.xml — doing so
 * would disable ACL enforcement on the request path.
 */
class AllowAllAuthorization implements AuthorizationInterface
{
    public function isAllowed($resource, $privilege = null): bool
    {
        return true;
    }
}

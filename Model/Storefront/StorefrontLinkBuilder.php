<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Storefront;

/**
 * A store that passes the availability check but has no resolvable `url_rewrite` row — the index
 * may not have run yet — is dropped rather than offered as a link that resolves to nothing.
 */
class StorefrontLinkBuilder
{
    public function __construct(
        private readonly StoreAvailabilityChecker $availabilityChecker,
        private readonly FrontendUrlResolver $urlResolver,
    ) {}

    /**
     * @return array<int, array{storeId: int, label: string, url: string}>
     */
    public function build(string $entityType, int $entityId): array
    {
        $choices = [];
        foreach ($this->availabilityChecker->viewableStores($entityType, $entityId) as $store) {
            $url = $this->urlResolver->resolve($entityType, $entityId, $store['storeId']);
            if ($url === null) {
                continue;
            }
            $choices[] = [
                'storeId' => $store['storeId'],
                'label' => $store['name'],
                'url' => $url,
            ];
        }

        return $choices;
    }
}

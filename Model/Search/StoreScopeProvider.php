<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store tree is small and static within a request, so it is built once per provider instance
 * instead of being re-read from StoreManager on every keystroke.
 */
class StoreScopeProvider implements SearchProviderInterface
{
    private const ENTITY_TYPE = 'store_view';

    /** Not 'globe', which is reserved for the scope-picker action icon. */
    private const ICON_KEY = 'store';

    /** Guards `adminhtml/system_store/edit{Website,Group,Store}`, the controllers linked to here. */
    private const ACL_RESOURCE = 'Magento_Backend::store';

    /** @var array[]|null */
    private ?array $scopes = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
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

        $results = [];
        foreach ($this->getScopes() as $scope) {
            $matchTier = $this->matchTier(mb_strtolower($scope['name']), $normalizedQuery);
            if ($matchTier === null) {
                continue;
            }

            $results[] = new SearchResult(
                entityType: self::ENTITY_TYPE,
                iconKey: self::ICON_KEY,
                label: $scope['name'],
                context: $scope['context'],
                url: $scope['url'],
                matchTier: $matchTier,
            );
        }

        usort($results, static fn (SearchResult $a, SearchResult $b) => $a->matchTier <=> $b->matchTier);

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array[]
     */
    private function getScopes(): array
    {
        if ($this->scopes !== null) {
            return $this->scopes;
        }

        $websiteNames = [];
        $groupNames = [];
        $scopes = [];

        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteNames[$website->getId()] = $website->getName();
            $scopes[] = [
                'name' => $website->getName(),
                'context' => 'Website',
                'url' => $this->backendUrl->getUrl('adminhtml/system_store/editWebsite', ['website_id' => $website->getId()]),
            ];
        }

        foreach ($this->storeManager->getGroups() as $group) {
            $groupNames[$group->getId()] = $group->getName();
            $websiteName = $websiteNames[$group->getWebsiteId()] ?? null;
            $scopes[] = [
                'name' => $group->getName(),
                'context' => $websiteName !== null ? 'Store · '.$websiteName : 'Store',
                'url' => $this->backendUrl->getUrl('adminhtml/system_store/editGroup', ['group_id' => $group->getId()]),
            ];
        }

        foreach ($this->storeManager->getStores() as $store) {
            $groupName = $groupNames[$store->getStoreGroupId()] ?? null;
            $scopes[] = [
                'name' => $store->getName(),
                'context' => $groupName !== null ? 'Store View · '.$groupName : 'Store View',
                'url' => $this->backendUrl->getUrl('adminhtml/system_store/editStore', ['store_id' => $store->getId()]),
            ];
        }

        return $this->scopes = $scopes;
    }

    private function matchTier(string $name, string $normalizedQuery): ?int
    {
        if ($name === $normalizedQuery) {
            return MatchTier::EXACT;
        }
        if (str_starts_with($name, $normalizedQuery)) {
            return MatchTier::PREFIX;
        }
        if (str_contains($name, $normalizedQuery)) {
            return MatchTier::CONTAINS;
        }

        return null;
    }
}

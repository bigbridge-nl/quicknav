<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

interface SearchProviderInterface
{
    /**
     * @return SearchResult[]
     */
    public function search(string $query, int $limit): array;
}

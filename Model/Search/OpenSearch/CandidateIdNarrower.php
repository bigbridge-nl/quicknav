<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search\OpenSearch;

use Psr\Log\LoggerInterface;

/**
 * The null-versus-empty-array distinction is load-bearing: null means no usable answer (run the
 * original unnarrowed LIKE query), [] means OpenSearch matched nothing (skip the DB). A caller
 * writing `$candidateIds ?? []` is always a bug.
 */
class CandidateIdNarrower
{
    // Matches OpenSearchIndexManager's mapping: min_gram=max_gram=3 means a
    // query shorter than this can never produce a matching n-gram at all.
    private const MIN_QUERY_LENGTH = 3;

    public function __construct(
        private readonly OpenSearchAvailability $availability,
        private readonly OpenSearchClientFactory $clientFactory,
        private readonly OpenSearchConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return int[]|null
     */
    public function narrow(string $entityType, string $normalizedQuery, int $limit): ?array
    {
        if (mb_strlen($normalizedQuery) < self::MIN_QUERY_LENGTH) {
            return null;
        }
        if (! $this->availability->isAvailable()) {
            return null;
        }

        $client = $this->clientFactory->create();
        if ($client === null) {
            return null;
        }

        try {
            $response = $client->search([
                'index' => $this->config->getIndexPrefix().'_search',
                'body' => [
                    'size' => min($limit * 5, 200),
                    '_source' => false,
                    'query' => [
                        'bool' => [
                            'filter' => ['term' => ['entity_type' => $entityType]],
                            'should' => [
                                ['match' => ['primary_text' => ['query' => $normalizedQuery, 'operator' => 'and']]],
                                ['match' => ['secondary_text' => ['query' => $normalizedQuery, 'operator' => 'and']]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('QuickNav OpenSearch search failed, falling back to full scan', [
                'entity_type' => $entityType,
                'exception' => $e,
            ]);

            return null; // an exception is NOT "zero hits" — see class docblock
        }

        return array_map(
            static fn (array $hit): int => (int) explode('#', (string) $hit['_id'], 2)[1],
            $response['hits']['hits'] ?? []
        );
    }
}

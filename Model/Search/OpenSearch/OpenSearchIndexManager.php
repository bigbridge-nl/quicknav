<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search\OpenSearch;

/**
 * One shared index across all entity types, `_id` "{entity_type}#{entity_id}". Every public
 * method is fail-soft, returning silently without a client, since a write must never throw into
 * an entity save; the reindex cron corrects any document a swallowed failure left stale.
 */
class OpenSearchIndexManager
{
    private const BULK_CHUNK_SIZE = 500;

    public function __construct(
        private readonly OpenSearchClientFactory $clientFactory,
        private readonly OpenSearchConfig $config,
    ) {}

    private function indexName(): string
    {
        return $this->config->getIndexPrefix().'_search';
    }

    public function ensureIndexExists(): void
    {
        $client = $this->clientFactory->create();
        if ($client === null) {
            return;
        }
        if ($client->indices()->exists(['index' => $this->indexName()])) {
            return;
        }
        $client->indices()->create(['index' => $this->indexName(), 'body' => $this->mapping()]);
    }

    /**
     * @param  array<int, array{entity_id:int, primary_text:string, secondary_text:string}>  $documents
     */
    public function bulkUpsert(string $entityType, array $documents): void
    {
        $client = $this->clientFactory->create();
        if ($client === null || $documents === []) {
            return;
        }

        foreach (array_chunk($documents, self::BULK_CHUNK_SIZE) as $chunk) {
            $body = [];
            foreach ($chunk as $doc) {
                $body[] = ['index' => ['_index' => $this->indexName(), '_id' => $entityType.'#'.$doc['entity_id']]];
                $body[] = [
                    'entity_type' => $entityType,
                    'entity_id' => $doc['entity_id'],
                    'primary_text' => $doc['primary_text'],
                    'secondary_text' => $doc['secondary_text'],
                ];
            }
            $client->bulk(['body' => $body]);
        }
    }

    public function deleteByIds(string $entityType, array $ids): void
    {
        $client = $this->clientFactory->create();
        if ($client === null || $ids === []) {
            return;
        }

        $body = [];
        foreach ($ids as $id) {
            $body[] = ['delete' => ['_index' => $this->indexName(), '_id' => $entityType.'#'.$id]];
        }
        $client->bulk(['body' => $body]);
    }

    public function deleteAllOfType(string $entityType): void
    {
        $client = $this->clientFactory->create();
        if ($client === null) {
            return;
        }
        $client->deleteByQuery([
            'index' => $this->indexName(),
            'body' => ['query' => ['term' => ['entity_type' => $entityType]]],
        ]);
    }

    private function mapping(): array
    {
        return [
            'settings' => [
                'analysis' => [
                    'filter' => [
                        'quicknav_ngram' => ['type' => 'ngram', 'min_gram' => 3, 'max_gram' => 3],
                    ],
                    'analyzer' => [
                        'quicknav_ngram_analyzer' => [
                            'tokenizer' => 'keyword',
                            'filter' => ['lowercase', 'quicknav_ngram'],
                        ],
                    ],
                ],
                'index' => ['max_ngram_diff' => 0],
            ],
            'mappings' => [
                'properties' => [
                    'entity_type' => ['type' => 'keyword'],
                    'entity_id' => ['type' => 'integer'],
                    'primary_text' => ['type' => 'text', 'analyzer' => 'quicknav_ngram_analyzer'],
                    'secondary_text' => ['type' => 'text', 'analyzer' => 'quicknav_ngram_analyzer'],
                ],
            ],
        ];
    }
}

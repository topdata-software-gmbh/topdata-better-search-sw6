<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

#[AutoconfigureTag('tdbs.search_backend')]
class ElasticsearchBackend implements SearchBackendInterface
{
    private ?array $config = null;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}

    public function getName(): string
    {
        return 'elasticsearch';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        $options = $this->getOptions($criteria);
        if ($options === null) {
            return null;
        }

        $term = $criteria->getTerm();
        if ($term === null || trim($term) === '') {
            return null;
        }

        $indexName = $options['index_name'] ?? 'tdbs_products';
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }

        try {
            $query = [
                'query' => [
                    'multi_match' => [
                        'query' => $term,
                        'fields' => ['name^3', 'productNumber^5', 'description'],
                        'type' => 'best_fields',
                    ]
                ],
                '_source' => false,
                'size' => $options['limit'] ?? 100,
            ];

            $response = $client->request('POST', sprintf('/%s/_search', $indexName), [
                'json' => $query,
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray();
            $hits = $data['hits']['hits'] ?? [];

            $ids = [];
            foreach ($hits as $hit) {
                if (isset($hit['_id'])) {
                    $ids[] = $hit['_id'];
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->error('TDBS Elasticsearch backend search failed: ' . $e->getMessage());
            return null;
        }
    }

    public function index(array $products): void
    {
        $profiles = $this->profileRegistry->getActiveProfiles();

        foreach ($profiles as $profile) {
            $pipeline = $profile['pipeline'] ?? [];
            foreach ($pipeline as $step) {
                if (($step['backend'] ?? '') !== 'elasticsearch') {
                    continue;
                }

                $options = $step['options'] ?? [];
                $indexName = $options['index_name'] ?? 'tdbs_products';

                $this->ensureIndexExists($indexName, $options);
                $this->bulkIndexProducts($indexName, $products);
            }
        }
    }

    private function ensureIndexExists(string $indexName, array $options): void
    {
        $client = $this->getClient();
        if ($client === null) {
            return;
        }

        try {
            $response = $client->request('HEAD', '/' . $indexName);
            if ($response->getStatusCode() === 200) {
                return;
            }

            $settings = $this->generateIndexSettings($options);

            $client->request('PUT', '/' . $indexName, [
                'json' => $settings,
                'headers' => ['Content-Type' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('TDBS failed to ensure Elasticsearch index "%s" exists: %s', $indexName, $e->getMessage()));
        }
    }

    private function generateIndexSettings(array $options): array
    {
        $ngram = $options['ngram'] ?? [];
        $enabled = (bool) ($ngram['enabled'] ?? false);
        $type = $ngram['type'] ?? 'edge_ngram';
        $minGram = (int) ($ngram['min_gram'] ?? 3);
        $maxGram = (int) ($ngram['max_gram'] ?? 6);
        $separateSearchAnalyzer = (bool) ($ngram['use_separate_search_analyzer'] ?? true);

        $settings = [
            'settings' => [
                'analysis' => [
                    'analyzer' => [],
                    'filter' => [],
                    'tokenizer' => [],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'keyword'],
                    'name' => ['type' => 'text'],
                    'productNumber' => ['type' => 'text'],
                    'description' => ['type' => 'text'],
                ],
            ],
        ];

        if ($enabled && $type !== 'none') {
            $tokenizerName = 'tdbs_ngram_tokenizer';
            $indexAnalyzerName = 'tdbs_ngram_index_analyzer';
            $searchAnalyzerName = $separateSearchAnalyzer ? 'tdbs_ngram_search_analyzer' : $indexAnalyzerName;

            $settings['settings']['analysis']['tokenizer'][$tokenizerName] = [
                'type' => $type === 'edge_ngram' ? 'edge_ngram' : 'ngram',
                'min_gram' => $minGram,
                'max_gram' => $maxGram,
                'token_chars' => ['letter', 'digit'],
            ];

            $settings['settings']['analysis']['analyzer'][$indexAnalyzerName] = [
                'tokenizer' => $tokenizerName,
                'filter' => ['lowercase'],
            ];

            if ($separateSearchAnalyzer) {
                $settings['settings']['analysis']['analyzer'][$searchAnalyzerName] = [
                    'tokenizer' => 'standard',
                    'filter' => ['lowercase'],
                ];
            }

            $textFields = ['name', 'productNumber', 'description'];
            foreach ($textFields as $field) {
                $settings['mappings']['properties'][$field] = [
                    'type' => 'text',
                    'analyzer' => $indexAnalyzerName,
                    'search_analyzer' => $searchAnalyzerName,
                ];
            }
        }

        return $settings;
    }

    private function bulkIndexProducts(string $indexName, array $products): void
    {
        $client = $this->getClient();
        if ($client === null) {
            return;
        }

        $payload = '';
        foreach ($products as $product) {
            $payload .= json_encode(['index' => ['_index' => $indexName, '_id' => $product['id']]]) . "\n";
            $payload .= json_encode([
                'id' => $product['id'],
                'name' => $product['name'] ?? '',
                'productNumber' => $product['productNumber'] ?? '',
                'description' => strip_tags($product['description'] ?? ''),
            ]) . "\n";
        }

        try {
            $client->request('POST', '/_bulk', [
                'body' => $payload,
                'headers' => ['Content-Type' => 'application/x-ndjson'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('TDBS bulk indexing failed: ' . $e->getMessage());
        }
    }

    private function getClient(): ?HttpClientInterface
    {
        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $esConfig = $globalConfig['connections']['elasticsearch'] ?? null;

        if (!$esConfig || !isset($esConfig['host'])) {
            return null;
        }

        $options = [
            'base_uri' => rtrim($esConfig['host'], '/'),
            'timeout' => 3,
        ];

        if (isset($esConfig['username']) && isset($esConfig['password'])) {
            $options['auth_basic'] = [$esConfig['username'], $esConfig['password']];
        }

        return $this->httpClient->withOptions($options);
    }

    private function getOptions(Criteria $criteria): ?array
    {
        if (!$criteria->hasExtension('tdbs_options')) {
            return null;
        }

        /** @var ArrayStruct $struct */
        $struct = $criteria->getExtension('tdbs_options');
        return $struct->all();
    }
}

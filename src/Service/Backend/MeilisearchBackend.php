<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AutoconfigureTag('tdbs.search_backend')]
class MeilisearchBackend implements SearchBackendInterface
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly SynonymService $synonymService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}

    public function getName(): string
    {
        return 'meilisearch';
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
            $payload = [
                'q' => $term,
                'limit' => $criteria->getLimit() ?? $options['limit'] ?? 100,
                'offset' => $criteria->getOffset() ?? 0,
            ];

            $filters = $this->buildFilterString($criteria);
            if (!empty($filters)) {
                $payload['filter'] = $filters;
            }

            $sort = $this->buildSortArray($criteria);
            if (!empty($sort)) {
                $payload['sort'] = $sort;
            }

            $response = $client->request('POST', sprintf('/indexes/%s/search', $indexName), [
                'json' => $payload,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray();
            $hits = $data['hits'] ?? [];

            $ids = [];
            foreach ($hits as $hit) {
                if (isset($hit['id'])) {
                    $ids[] = $hit['id'];
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->error('TDBS Meilisearch search pipeline failure: ' . $e->getMessage());
            return null;
        }
    }

    public function index(array $products): void
    {
        $profiles = $this->profileRegistry->getActiveProfiles();

        foreach ($profiles as $profile) {
            $pipeline = $profile['pipeline'] ?? [];
            foreach ($pipeline as $step) {
                if (($step['backend'] ?? '') !== 'meilisearch') {
                    continue;
                }

                $options = $step['options'] ?? [];
                $indexName = $options['index_name'] ?? 'tdbs_products';

                $this->ensureIndexInitialized($indexName);
                $this->bulkIndexProducts($indexName, $products);
            }
        }
    }

    private function ensureIndexInitialized(string $indexName): void
    {
        $client = $this->getClient();
        if ($client === null) {
            return;
        }

        try {
            $response = $client->request('GET', '/indexes/' . $indexName);
            if ($response->getStatusCode() === 404) {
                $client->request('POST', '/indexes', [
                    'json' => ['uid' => $indexName, 'primaryKey' => 'id'],
                ]);
            }

            $synonymsMap = [];
            $databaseSynonyms = $this->synonymService->listSynonyms(null, 1000, 0);
            foreach ($databaseSynonyms as $row) {
                $synonymsArray = array_map('trim', explode(',', $row['synonyms']));
                $synonymsMap[$row['term']] = $synonymsArray;
            }

            $settingsPayload = [
                'searchableAttributes' => ['productNumber', 'name', 'description'],
                'filterableAttributes' => ['categoryTree', 'id'],
                'sortableAttributes' => ['name', 'createdAt'],
                'synonyms' => $synonymsMap,
            ];

            $client->request('PATCH', sprintf('/indexes/%s/settings', $indexName), [
                'json' => $settingsPayload,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('TDBS Meilisearch failed to initialize settings on index "%s": %s', $indexName, $e->getMessage()));
        }
    }

    private function bulkIndexProducts(string $indexName, array $products): void
    {
        $client = $this->getClient();
        if ($client === null) {
            return;
        }

        $documents = [];
        foreach ($products as $product) {
            $documents[] = [
                'id' => $product['id'],
                'name' => $product['name'] ?? '',
                'productNumber' => $product['productNumber'] ?? '',
                'description' => strip_tags($product['description'] ?? ''),
                'categoryTree' => $product['categoryTree'] ?? [],
            ];
        }

        try {
            $client->request('POST', sprintf('/indexes/%s/documents', $indexName), [
                'json' => $documents,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('TDBS Meilisearch bulk document delivery failed on index "%s": %s', $indexName, $e->getMessage()));
        }
    }

    private function buildFilterString(Criteria $criteria): string
    {
        $filters = [];

        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof NotFilter && $filter->getOperator() === NotFilter::CONNECTION_AND) {
                foreach ($filter->getQueries() as $subFilter) {
                    if ($subFilter instanceof EqualsAnyFilter && $subFilter->getField() === 'categoryTree') {
                        $values = array_map(fn($v) => sprintf("'%s'", $v), $subFilter->getValue());
                        if (!empty($values)) {
                            $filters[] = sprintf('categoryTree NOT IN [%s]', implode(', ', $values));
                        }
                    }
                }
            }
        }

        return implode(' AND ', $filters);
    }

    /**
     * @return string[]
     */
    private function buildSortArray(Criteria $criteria): array
    {
        $sorts = [];
        foreach ($criteria->getSorting() as $sorting) {
            $field = $sorting->getField();
            if (in_array($field, ['name', 'createdAt'], true)) {
                $direction = strtolower($sorting->getDirection()) === 'desc' ? 'desc' : 'asc';
                $sorts[] = sprintf('%s:%s', $field, $direction);
            }
        }
        return $sorts;
    }

    private function getClient(): ?HttpClientInterface
    {
        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $meiliConfig = $globalConfig['connections']['meilisearch'] ?? null;

        if (!$meiliConfig || !isset($meiliConfig['host'])) {
            return null;
        }

        $options = [
            'base_uri' => rtrim($meiliConfig['host'], '/'),
            'timeout' => 3,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($meiliConfig['api_key'])) {
            $options['headers']['Authorization'] = sprintf('Bearer %s', $meiliConfig['api_key']);
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

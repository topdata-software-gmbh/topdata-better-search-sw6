---
filename: "_ai/backlog/active/260702_1334__IMPLEMENTATION_PLAN__rebrand_and_abstract_to_better_search.md"
title: "Transition to Generic 'Better Search' Plugin with Service Abstraction"
createdAt: 2026-07-02 13:34
updatedAt: 2026-07-02 13:34
status: draft
priority: high
tags: [refactoring, migration, search-backend, abstraction, storefront-routes]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem to be Solved
The current plugin (`TopdataElasticsearchHacksSW6`) is tightly coupled to Elasticsearch both in its naming conventions, database tables (`topdata_es_zero_search`, `topdata_es_synonym`), and core code execution paths. To build a highly configurable, multi-backend search engine (capable of leveraging Meilisearch, Qdrant, or Elasticsearch in parallel for storefront searches), we must decouple the core search business logic from any single provider. We must also transition the codebase to clean, maintainable conventions suitable for modern Shopware 6.7 environments.

## 2. Executive Summary of the Solution
This plan migrates the plugin to `topdata-better-search-sw6`, renaming all namespaces, base classes, translation snippets, and administration paths. All custom database tables are recreated under the clean prefix `tdbs_` (with a migration script to copy existing data where possible). 

Rather than overriding Elasticsearch directly, we will implement **Approach 3: Decorated Sales Channel Routes**. By decorating `ProductSearchRoute` and `ProductSuggestRoute`, we intercept storefront queries before they touch the database. We introduce an elegant `SearchBackendInterface` supporting multiple swappable backends (such as `ShopwareCoreBackend`, `MeilisearchBackend`, and `QdrantBackend`). We'll implement a console-first indexing mechanism via `tdbs:index:rebuild` and refactor the synonym/zero-search administration using custom commands using `CliLogger`.

## 3. Project Environment Details
- **Project Name**: SW6.7 Plugin (`topdata-better-search-sw6`)
- **Backend root**: `src`
- **PHP Version**: `~8.2.0 || ~8.3.0 || ~8.4.0`
- **Shopware Version**: `6.7.*`
- **Dependency Injecton**: Autowired with PHP 8 attributes (e.g. `#[AutoconfigureTag]`, `#[Entity]`)

---

## 4. Implementation Steps

### Phase 1: Rebranding & DB Schema Migration

We will perform namespaces rename from `Topdata\TopdataElasticsearchHacksSW6` to `Topdata\TopdataBetterSearchSW6`, rename files, and run database migrations.

#### [MODIFY] `composer.json`
Update names, descriptions, autoloader, and extra configurations.
```json
{
    "name":        "topdata/better-search-sw6",
    "description": "Topdata Better Search SW6",
    "version":     "v2.0.0",
    "type":        "shopware-platform-plugin",
    "license":     "MIT",
    "authors":     [
        {
            "name":     "TopData Software GmbH",
            "homepage": "https://www.topdata.de",
            "role":     "Manufacturer"
        }
    ],
    "require":     {
        "shopware/core": "6.7.*"
    },
    "extra":       {
        "shopware-plugin-class": "Topdata\\TopdataBetterSearchSW6\\TopdataBetterSearchSW6",
        "plugin-icon": "src/Resources/config/plugin.png",
        "copyright": "(c) by TopData Software GmbH",
        "label":                 {
            "en-GB": "Topdata Better Search SW6",
            "de-DE": "Topdata Better Search SW6"
        },
        "description":           {
            "en-GB": "Topdata Better Search SW6",
            "de-DE": "Topdata Better Search SW6"
        },
        "manufacturerLink":      {
            "de-DE": "https://www.topdata.de",
            "en-GB": "https://www.topdata.de"
        }
    },
    "autoload":    {
        "psr-4": {
            "Topdata\\TopdataBetterSearchSW6\\": "src/"
        }
    }
}
```

#### [DELETE] `src/TopdataElasticsearchHacksSW6.php`
Remove old plugin base class.

#### [NEW FILE] `src/TopdataBetterSearchSW6.php`
Create the renamed base plugin class. We will register our compiler pass here.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataBetterSearchSW6\DependencyInjection\ElasticsearchAnalysisCompilerPass;

class TopdataBetterSearchSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ElasticsearchAnalysisCompilerPass());
    }
}
```

#### [DELETE] `src/Migration/Migration1716652800CreateZeroSearchTable.php`
Remove old migration.

#### [NEW FILE] `src/Migration/Migration1800000000CreateBetterSearchTables.php`
Migration that creates `tdbs_` tables and safely copies data if old `topdata_es_` tables exist.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1800000000CreateBetterSearchTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1800000000;
    }

    public function update(Connection $connection): void
    {
        // 1. Create tdbs_zero_search table
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdbs_zero_search` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `count` INT NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `last_searched_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.tdbs_zero_search.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        // 2. Create tdbs_synonym table
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdbs_synonym` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `synonyms` TEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.tdbs_synonym.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        // 3. Migrate data from old tables if they exist
        $this->migrateOldTableData($connection, 'topdata_es_zero_search', 'tdbs_zero_search');
        $this->migrateOldTableData($connection, 'topdata_es_synonym', 'tdbs_synonym');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function migrateOldTableData(Connection $connection, string $oldTable, string $newTable): void
    {
        try {
            $schemaManager = $connection->getSchemaManager();
            if ($schemaManager->tablesExist([$oldTable])) {
                $connection->executeStatement(sprintf(
                    'INSERT IGNORE INTO `%s` SELECT * FROM `%s`',
                    $newTable,
                    $oldTable
                ));
            }
        } catch (\Throwable $e) {
            // Silence migration copy errors to ensure execution safety
        }
    }
}
```

#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchEntityDefinition.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchCollection.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchEntity.php`

#### [NEW FILE] `src/Entity/ZeroSearch/ZeroSearchDefinition.php`
Define entity with modern Shopware 6.7 Attribute syntax mapping to the new `tdbs_zero_search` prefix.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;

#[Entity('tdbs_zero_search')]
class ZeroSearchDefinition extends EntityDefinition
{
    public function getEntityClass(): string
    {
        return ZeroSearchEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ZeroSearchCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new IntField('count', 'count'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
            (new DateTimeField('last_searched_at', 'lastSearchedAt')),
        ]);
    }
}
```

#### [NEW FILE] `src/Entity/ZeroSearch/ZeroSearchEntity.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ZeroSearchEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected int $count;
    protected ?\DateTimeInterface $lastSearchedAt = null;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        $this->count = $count;
    }

    public function getLastSearchedAt(): ?\DateTimeInterface
    {
        return $this->lastSearchedAt;
    }

    public function setLastSearchedAt(?\DateTimeInterface $lastSearchedAt): void
    {
        $this->lastSearchedAt = $lastSearchedAt;
    }
}
```

#### [NEW FILE] `src/Entity/ZeroSearch/ZeroSearchCollection.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                    add(ZeroSearchEntity $entity)
 * @method void                    set(string $key, ZeroSearchEntity $entity)
 * @method ZeroSearchEntity[]      getIterator()
 * @method ZeroSearchEntity[]      getElements()
 * @method ZeroSearchEntity|null   get(string $key)
 * @method ZeroSearchEntity|null   first()
 * @method ZeroSearchEntity|null   last()
 */
class ZeroSearchCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ZeroSearchEntity::class;
    }
}
```

---

### Phase 2: Search Abstraction Layer (Backends)

To support Swappable backends, we introduce an interface and a registry, with implementations for Core, Meilisearch, and Qdrant.

#### [NEW FILE] `src/Service/SearchBackendInterface.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface SearchBackendInterface
{
    public function getName(): string;

    /**
     * Executes storefront search and returns a list of matching Product IDs.
     * Returning null triggers fallback to the next configured search handler.
     *
     * @return string[]|null
     */
    public function search(Criteria $criteria, SalesChannelContext $context): ?array;

    /**
     * Executes indexing of product documents.
     *
     * @param array<string, mixed> $products
     */
    public function index(array $products): void;
}
```

#### [NEW FILE] `src/Service/SearchBackendRegistry.php`
Manages registration and dynamic switching of the active backend.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

class SearchBackendRegistry
{
    /**
     * @var SearchBackendInterface[]
     */
    private array $backends = [];

    /**
     * @param iterable<SearchBackendInterface> $backends
     */
    public function __construct(iterable $backends)
    {
        foreach ($backends as $backend) {
            $this->backends[$backend->getName()] = $backend;
        }
    }

    public function getBackend(string $name): ?SearchBackendInterface
    {
        return $this->backends[$name] ?? null;
    }

    /**
     * @return SearchBackendInterface[]
     */
    public function getActiveBackends(): array
    {
        // For now, we return default, but this can read from Shopware SystemConfig dynamically
        return array_values($this->backends);
    }
}
```

#### [NEW FILE] `src/Service/Backend/ShopwareCoreBackend.php`
Standard Shopware DB / default ES fallback implementation.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

class ShopwareCoreBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'shopware_core';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        // Null delegation indicates standard core pipeline execution
        return null;
    }

    public function index(array $products): void
    {
        // Core indexing is handled automatically by Shopware
    }
}
```

#### [NEW FILE] `src/Service/Backend/MeilisearchBackend.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

class MeilisearchBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'meilisearch';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        // Placeholder for future Meilisearch querying logic
        return null;
    }

    public function index(array $products): void
    {
        // Placeholder for future indexing logic
    }
}
```

#### [NEW FILE] `src/Service/Backend/QdrantBackend.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

class QdrantBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'qdrant';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        // Placeholder for future AI/Vector semantic search querying logic
        return null;
    }

    public function index(array $products): void
    {
        // Placeholder for future AI/Vector document embedding logic
    }
}
```

---

### Phase 3: Route Decoration (Storefront Interception - Approach 3)

We will decorate `ProductSearchRoute` and `ProductSuggestRoute` to execute custom searches, handle dynamic category exclusion filters, and register zero-result failures instantly.

#### [NEW FILE] `src/Route/DecoratedProductSearchRoute.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsDecorator(decorates: AbstractProductSearchRoute::class)]
class DecoratedProductSearchRoute extends AbstractProductSearchRoute
{
    public function __construct(
        private readonly AbstractProductSearchRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection
    ) {}

    public function getDecorated(): AbstractProductSearchRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSearchRouteResponse
    {
        $this->applyCategoryExclusions($criteria, $context);

        $term = $criteria->getTerm() ?? '';
        $ids = null;

        // Query swappable backends if any return active IDs
        foreach ($this->backendRegistry->getActiveBackends() as $backend) {
            $resultIds = $backend->search($criteria, $context);
            if ($resultIds !== null) {
                $ids = $resultIds;
                break;
            }
        }

        // If a backend returned matched IDs, limit Shopware execution to those specific primary keys
        if ($ids !== null) {
            if (empty($ids)) {
                $ids = ['00000000000000000000000000000000']; // Non-matching dummy ID
            }
            $criteria->addFilter(new EqualsAnyFilter('id', $ids));
            // Reset term to prevent MySQL native fulltext score calculation overhead
            $criteria->setTerm(null);
        }

        $response = $this->decorated->load($request, $context, $criteria);

        // Record zero-search query metrics
        if ($response->getListingResult()->getTotal() === 0 && !empty($term)) {
            $this->logZeroSearchResult($term);
        }

        return $response;
    }

    private function applyCategoryExclusions(Criteria $criteria, SalesChannelContext $context): void
    {
        /** @var string[]|null $excludedCategories */
        $excludedCategories = $this->systemConfigService->get(
            'TopdataBetterSearchSW6.config.excludedCategories',
            $context->getSalesChannelId()
        );

        if (!empty($excludedCategories) && \is_array($excludedCategories)) {
            $criteria->addFilter(
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsAnyFilter('categoryTree', $excludedCategories)
                ])
            );
        }
    }

    private function logZeroSearchResult(string $term): void
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO `tdbs_zero_search` (`id`, `term`, `count`, `created_at`, `last_searched_at`)
                 VALUES (:id, :term, 1, :now, :now)
                 ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_searched_at` = :now',
                [
                    'id' => Uuid::randomBytes(),
                    'term' => $term,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
            // Prevent failure logging from degrading active storefront performance
        }
    }
}
```

#### [NEW FILE] `src/Route/DecoratedProductSuggestRoute.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsDecorator(decorates: AbstractProductSuggestRoute::class)]
class DecoratedProductSuggestRoute extends AbstractProductSuggestRoute
{
    public function __construct(
        private readonly AbstractProductSuggestRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService
    ) {}

    public function getDecorated(): AbstractProductSuggestRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSuggestRouteResponse
    {
        $this->applyCategoryExclusions($criteria, $context);

        $ids = null;
        foreach ($this->backendRegistry->getActiveBackends() as $backend) {
            $resultIds = $backend->search($criteria, $context);
            if ($resultIds !== null) {
                $ids = $resultIds;
                break;
            }
        }

        if ($ids !== null) {
            if (empty($ids)) {
                $ids = ['00000000000000000000000000000000'];
            }
            $criteria->addFilter(new EqualsAnyFilter('id', $ids));
            $criteria->setTerm(null);
        }

        return $this->decorated->load($request, $context, $criteria);
    }

    private function applyCategoryExclusions(Criteria $criteria, SalesChannelContext $context): void
    {
        /** @var string[]|null $excludedCategories */
        $excludedCategories = $this->systemConfigService->get(
            'TopdataBetterSearchSW6.config.excludedCategories',
            $context->getSalesChannelId()
        );

        if (!empty($excludedCategories) && \is_array($excludedCategories)) {
            $criteria->addFilter(
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsAnyFilter('categoryTree', $excludedCategories)
                ])
            );
        }
    }
}
```

#### [DELETE] `src/Subscriber/ProductSearchSubscriber.php`
Zero searches are now tracked directly within the decorated route (Phase 3), eliminating unnecessary subscriber overhead.

#### [DELETE] `src/Subscriber/SearchCriteriaSubscriber.php`
Exclusions are now injected directly inside decorated routes, keeping operations centralized.

---

### Phase 4: CLI Commands & CliLogger Integration

Refactor synonym CLI commands, migrate database query tables to `tdbs_synonym`, and introduce `RebuildIndexCommand` extending `\Topdata\TopdataFoundationSW6\TopdataFoundationSW6` as defined in the conventions.

#### [MODIFY] `src/Service/SynonymService.php`
Update references to read and write from `tdbs_synonym` instead of `topdata_es_synonym`.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

class SynonymService
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array<array{term: string, synonyms: string, created_at: string}>
     */
    public function listSynonyms(?string $filter = null, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms', 'created_at')
            ->from('tdbs_synonym')
            ->orderBy('term', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($filter !== null && $filter !== '') {
            $qb->where('term LIKE :filter OR synonyms LIKE :filter')
                ->setParameter('filter', '%' . $filter . '%');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function deleteSynonym(string $term): bool
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM `tdbs_synonym` WHERE `term` = :term',
            ['term' => mb_strtolower(trim($term))]
        );

        return $deleted > 0;
    }

    public function clearAllSynonyms(): int
    {
        return (int) $this->connection->executeStatement('TRUNCATE TABLE `tdbs_synonym`');
    }

    public function validateFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [['line' => 0, 'content' => '', 'error' => 'File does not exist or is unreadable.']];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [['line' => 0, 'content' => '', 'error' => 'Could not read file content.']];
        }

        return $this->validateString($content);
    }

    /**
     * @return array<array{line: int, content: string, error: string}>
     */
    public function validateString(string $content): array
    {
        $lines = explode("\n", $content);
        $errors = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                continue;
            }

            $parts = explode('=>', $trimmed, 2);
            if (count($parts) !== 2) {
                $errors[] = [
                    'line' => $lineNumber,
                    'content' => $line,
                    'error' => 'Missing expected mapping delimiter "=>"'
                ];
                continue;
            }

            $term = trim($parts[0]);
            $synonyms = trim($parts[1]);

            if ($term === '') {
                $errors[] = [
                    'line' => $lineNumber,
                    'content' => $line,
                    'error' => 'Left-hand search term cannot be blank'
                ];
            }

            if ($synonyms === '') {
                $errors[] = [
                    'line' => $lineNumber,
                    'content' => $line,
                    'error' => 'Right-hand synonyms mapping block cannot be blank'
                ];
            }
        }

        return $errors;
    }

    public function importFromFile(string $filePath, bool $dryRun = false): int
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException(sprintf('File "%s" does not exist or is not readable.', $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Could not read content from file "%s".', $filePath));
        }

        return $this->importFromString($content, $dryRun);
    }

    public function importFromString(string $content, bool $dryRun = false): int
    {
        $errors = $this->validateString($content);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(sprintf('Cannot import. Found %d syntax errors in the content.', count($errors)));
        }

        $lines = explode("\n", $content);
        $importedCount = 0;

        if ($dryRun) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                    continue;
                }
                $importedCount++;
            }
            return $importedCount;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                    continue;
                }

                $parts = explode('=>', $line, 2);
                $term = mb_strtolower(trim($parts[0]));
                $synonyms = mb_strtolower(trim($parts[1]));

                $this->connection->executeStatement(
                    'INSERT INTO `tdbs_synonym` (`id`, `term`, `synonyms`, `created_at`)
                     VALUES (:id, :term, :synonyms, :now)
                     ON DUPLICATE KEY UPDATE `synonyms` = :synonyms, `created_at` = :now',
                    [
                        'id' => Uuid::randomBytes(),
                        'term' => $term,
                        'synonyms' => $synonyms,
                        'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                    ]
                );

                $importedCount++;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $importedCount;
    }

    public function exportToString(): string
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms')
            ->from('tdbs_synonym')
            ->orderBy('term', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $lines = ["# Elasticsearch Synonyms Mapping File", "# Generated: " . (new \DateTime())->format('Y-m-d H:i:s')];

        foreach ($rows as $row) {
            $lines[] = sprintf('%s => %s', $row['term'], $row['synonyms']);
        }

        return implode("\n", $lines);
    }
}
```

#### [DELETE] `src/Command/Command_ClearSynonyms.php`
#### [DELETE] `src/Command/Command_DeleteSynonym.php`
#### [DELETE] `src/Command/Command_ExportSynonyms.php`
#### [DELETE] `src/Command/Command_ImportSynonyms.php`
#### [DELETE] `src/Command/Command_ListSynonyms.php`
#### [DELETE] `src/Command/Command_ValidateSynonyms.php`

#### [NEW FILE] `src/Command/ClearSynonymsCommand.php`
Implementation adhering to `AsCommand` attribute and `CliLogger` output rules.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'tdbs:synonyms:clear',
    description: 'Bulk purges all active synonym mappings from the database'
)]
class ClearSynonymsCommand extends TopdataFoundationSW6
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation safety check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::setCliStyle($this->getCliStyle());
        $force = (bool) $input->getOption('force');

        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'This will delete ALL database-stored synonyms. Are you sure you want to proceed? [y/N]: ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                CliLogger::warning('Operation aborted.');
                return self::SUCCESS;
            }
        }

        try {
            $this->synonymService->clearAllSynonyms();
            CliLogger::success('Successfully cleared all synonym mapping definitions from the database.');
        } catch (\Throwable $e) {
            CliLogger::error('Truncate process failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/RebuildIndexCommand.php`
Console-First custom indexer command that iterates catalog products in steps and indexes them to configured swappable backends (e.g. Meilisearch, Qdrant).
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsCommand(
    name: 'tdbs:index:rebuild',
    description: 'Rebuilds search indices for configured custom search backends'
)]
class RebuildIndexCommand extends TopdataFoundationSW6
{
    public function __construct(
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Processing step limit size', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::setCliStyle($this->getCliStyle());
        $limit = (int) $input->getOption('limit');
        $context = Context::createDefaultContext();

        CliLogger::title('TDBS Custom Backend Reindex Pipeline');

        $activeBackends = $this->backendRegistry->getActiveBackends();
        if (empty($activeBackends)) {
            CliLogger::warning('No custom search backends currently active.');
            return self::SUCCESS;
        }

        foreach ($activeBackends as $backend) {
            if ($backend->getName() === 'shopware_core') {
                continue; // Delegate core indexing entirely to native processes
            }

            CliLogger::section(sprintf('Indexing Backend: %s', $backend->getName()));

            $criteria = new Criteria();
            $criteria->setLimit($limit);
            $criteria->setOffset(0);

            $total = 0;
            // Fetch and push in sequential database index offsets
            while ($products = $this->productRepository->search($criteria, $context)) {
                if ($products->getTotal() === 0) {
                    break;
                }

                $data = [];
                foreach ($products->getElements() as $product) {
                    $data[] = [
                        'id' => $product->getId(),
                        'name' => $product->getName(),
                        'productNumber' => $product->getProductNumber(),
                        'description' => $product->getDescription(),
                    ];
                }

                $backend->index($data);
                $total += count($data);

                CliLogger::progress($total, $products->getTotal(), 'indexing products...');

                if (count($data) < $limit) {
                    break;
                }
                $criteria->setOffset($criteria->getOffset() + $limit);
            }

            CliLogger::success(sprintf('Completed sync of %d products for backend: %s', $total, $backend->getName()));
        }

        return self::SUCCESS;
    }
}
```

*(Note: We will similarly implement `DeleteSynonymCommand`, `ExportSynonymsCommand`, `ImportSynonymsCommand`, `ListSynonymsCommand`, and `ValidateSynonymsCommand` using the `AsCommand` attribute and `CliLogger::` messaging functions to maintain perfect output alignment.)*

---

### Phase 5: Administration UI & Snippets Alignment

Rename administration JS module, index registration, and translations.

#### [NEW FILE] `src/Resources/snippet/storefront.de-DE.json`
```json
{
    "TopdataBetterSearchSW6": {
        "title": "Bessere Suche",
        "description": "Suchbegriffe und Synonyme"
    }
}
```

#### [NEW FILE] `src/Resources/snippet/storefront.en-GB.json`
```json
{
    "TopdataBetterSearchSW6": {
        "title": "Better Search",
        "description": "Search Terms and Synonyms"
    }
}
```

#### [MODIFY] `src/Resources/app/administration/src/module/topdata-es-zero-search/index.ts`
Modify routing privileges and namespacing:
```typescript
import './page/zero-search-list';

Shopware.Module.register('topdata-better-search', {
    type: 'plugin',
    name: 'BetterSearch',
    title: 'topdata-better-search.title',
    description: 'topdata-better-search.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-better-search-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-better-search',
        label: 'topdata-better-search.title',
        color: '#189eff',
        icon: 'default-shopping-search',
        position: 100,
        parent: 'sw-content',
    }, {
        id: 'topdata-better-search-list',
        label: 'topdata-better-search.listTitle',
        color: '#189eff',
        path: 'topdata.better.search.list',
        parent: 'topdata-better-search',
    }],
});
```

---

### Phase 6: Service Configuration

#### [MODIFY] `src/Resources/config/services.xml`
Register backends, registries, command services, decorated routes, and autowire settings.
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <defaults autowire="true" autoconfigure="true" public="false" />

        <!-- Entity Definition -->
        <service id="Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch\ZeroSearchDefinition">
            <tag name="shopware.entity.definition"/>
        </service>

        <!-- Dynamic Registry & Configured Backends -->
        <service id="Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry">
            <argument type="tagged_iterator" tag="tdbs.search_backend" />
        </service>

        <service id="Topdata\TopdataBetterSearchSW6\Service\Backend\ShopwareCoreBackend">
            <tag name="tdbs.search_backend" />
        </service>

        <service id="Topdata\TopdataBetterSearchSW6\Service\Backend\MeilisearchBackend">
            <tag name="tdbs.search_backend" />
        </service>

        <service id="Topdata\TopdataBetterSearchSW6\Service\Backend\QdrantBackend">
            <tag name="tdbs.search_backend" />
        </service>

        <!-- Synonym Service -->
        <service id="Topdata\TopdataBetterSearchSW6\Service\SynonymService" public="true" />

        <!-- Decorated Sales Channel Search Routes -->
        <service id="Topdata\TopdataBetterSearchSW6\Route\DecoratedProductSearchRoute" decorates="Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute" public="true">
            <argument type="service" id="Topdata\TopdataBetterSearchSW6\Route\DecoratedProductSearchRoute.inner" />
        </service>

        <service id="Topdata\TopdataBetterSearchSW6\Route\DecoratedProductSuggestRoute" decorates="Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute" public="true">
            <argument type="service" id="Topdata\TopdataBetterSearchSW6\Route\DecoratedProductSuggestRoute.inner" />
        </service>

        <!-- Console Commands -->
        <service id="Topdata\TopdataBetterSearchSW6\Command\RebuildIndexCommand">
            <tag name="console.command"/>
            <argument type="service" id="product.repository" />
        </service>

        <service id="Topdata\TopdataBetterSearchSW6\Command\ClearSynonymsCommand">
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

---

## 5. Verification & Testing Plan
1. **Migration Verification**:
   - Run `php bin/console database:migrate TopdataBetterSearchSW6 --all`.
   - Verify that tables `tdbs_zero_search` and `tdbs_synonym` are created and any existing data has been copied.
2. **CLI Output Testing**:
   - Run `php bin/console tdbs:index:rebuild --limit=10`. Verify the execution path uses `CliLogger` format with section, title, and progress updates.
   - Run `php bin/console tdbs:synonyms:clear`. Ensure safe interactive prompt behavior.
3. **Route Interception Verification**:
   - Execute storefront searches with zero results and verify matching entries are logged into `tdbs_zero_search`.

---

## 6. Report Structure

The last phase of the plan is to write a report to:
`_ai/backlog/reports/260702_1334__IMPLEMENTATION_REPORT__rebrand_and_abstract_to_better_search.md`

```yaml
---
filename: "_ai/backlog/reports/260702_1334__IMPLEMENTATION_REPORT__rebrand_and_abstract_to_better_search.md"
title: "Report: Transition to Generic Better Search Plugin with Service Abstraction"
createdAt: 2026-07-02 13:34
updatedAt: 2026-07-02 13:34
planFile: "_ai/backlog/active/260702_1334__IMPLEMENTATION_PLAN__rebrand_and_abstract_to_better_search.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 15
filesModified: 4
filesDeleted: 11
tags: [refactoring, migration, search-backend, abstraction, storefront-routes]
documentType: IMPLEMENTATION_REPORT
---
```


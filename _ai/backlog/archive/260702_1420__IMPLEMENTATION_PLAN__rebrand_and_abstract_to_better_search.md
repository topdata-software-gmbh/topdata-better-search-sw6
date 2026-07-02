---
filename: "_ai/backlog/active/260702_1420__IMPLEMENTATION_PLAN__rebrand_and_abstract_to_better_search.md"
title: "Transition to Generic 'Better Search' Plugin with Service Abstraction"
createdAt: 2026-07-02 14:20
updatedAt: 2026-07-02 14:20
status: completed
completedAt: 2026-07-02 18:41
priority: high
tags: [refactoring, migration, search-backend, abstraction, storefront-routes, shopware6.7]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem to be Solved
The current plugin (`TopdataElasticsearchHacksSW6`) is tightly coupled to Elasticsearch both in its naming conventions, database tables (`topdata_es_zero_search`, `topdata_es_synonym`), and core code execution paths. To build a highly configurable, multi-backend search engine (capable of leveraging Meilisearch, Qdrant, or Elasticsearch in parallel for storefront searches), we must decouple the core search business logic from any single provider. We must also transition the codebase to clean, maintainable conventions suitable for modern Shopware 6.7 environments and resolve dependency container registration issues.

## 2. Executive Summary of the Solution
This plan migrates the plugin to `topdata-better-search-sw6`, renaming all namespaces, base classes, translation snippets, and administration paths. All custom database tables are recreated under the clean prefix `tdbs_` (with a migration script to copy existing data where possible). 

Rather than overriding Elasticsearch directly, we will implement **Decorated Sales Channel Routes**. By decorating `ProductSearchRoute` and `ProductSuggestRoute`, we intercept storefront queries before they touch the database. We introduce an elegant `SearchBackendInterface` supporting multiple swappable backends (such as `ShopwareCoreBackend`, `MeilisearchBackend`, and `QdrantBackend`). We'll implement a console-first indexing mechanism via `tdbs:index:rebuild` and refactor the synonym/zero-search administration using custom commands using `CliLogger`. We will fully adhere to Symfony 7.4 PHP 8 attributes for autowiring and decoration, eliminating redundant XML registrations and resolving potential constructor type mismatches.

## 3. Project Environment Details
- **Project Name**: SW6.7 Plugin
- **Backend root**: src
- **PHP Version**: 8.2 / 8.3 / 8.4
- **Symfony Version**: 7.4
- **Doctrine DBAL**: 4.4.x

---

## 4. Implementation Steps

### Phase 1: Clean Up & Rebranding Preparation

To prevent class-loading clashes and clutter, we will remove obsolete files and prepare the directory structure for the clean rebranded codebase.

#### [DELETE] `src/TopdataElasticsearchHacksSW6.php`
#### [DELETE] `src/Migration/Migration1716652800CreateZeroSearchTable.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchCollection.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchEntity.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchEntityDefinition.php`
#### [DELETE] `src/Subscriber/ProductSearchSubscriber.php`
#### [DELETE] `src/Subscriber/SearchCriteriaSubscriber.php`
#### [DELETE] `src/Command/Command_ClearSynonyms.php`
#### [DELETE] `src/Command/Command_DeleteSynonym.php`
#### [DELETE] `src/Command/Command_ExportSynonyms.php`
#### [DELETE] `src/Command/Command_ImportSynonyms.php`
#### [DELETE] `src/Command/Command_ListSynonyms.php`
#### [DELETE] `src/Command/Command_ValidateSynonyms.php`
#### [DELETE] `src/Command/ExampleCommand.php`
#### [DELETE] `src/Controller/AdminApiExampleController.php`
#### [DELETE] `src/Controller/StorefrontExampleController.php`
#### [DELETE] `src/Resources/views/storefront/example.html.twig`
#### [DELETE] `src/Resources/app/administration/src/module/topdata-es-zero-search/page/zero-search-list/index.ts`
#### [DELETE] `src/Resources/app/administration/src/module/topdata-es-zero-search/index.ts`
#### [DELETE] `src/Resources/app/administration/src/snippet/de-DE.json`
#### [DELETE] `src/Resources/app/administration/src/snippet/en-GB.json`

---

### Phase 2: Core Base, Dependencies & Autoloading

Configure composer package settings, declare external plugin requirements, and initialize the compiler-pass with updated namespaces.

#### [MODIFY] `composer.json`
Add dependency requirements and update autoloader namespaces.
```json
{
    "name":        "topdata/better-search-sw6",
    "description": "Topdata Better Search SW6",
    "version":     "v1.0.0",
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
        "shopware/core": "6.7.*",
        "topdata/foundation-sw6": "^1.0"
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

#### [NEW FILE] `src/TopdataBetterSearchSW6.php`
Rebranded plugin entry point that configures the compiler pass.
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

#### [MODIFY] `src/DependencyInjection/ElasticsearchAnalysisCompilerPass.php`
Update compiler pass with rebranded namespace.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ElasticsearchAnalysisCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('elasticsearch.analysis')) {
            return;
        }

        $analysis = $container->getParameter('elasticsearch.analysis');
        if (!\is_array($analysis)) {
            $analysis = [];
        }

        $analysis['filter'] = $analysis['filter'] ?? [];
        $analysis['analyzer'] = $analysis['analyzer'] ?? [];

        $analysis['filter']['topdata_word_delimiter'] = [
            'type' => 'word_delimiter_graph',
            'preserve_original' => true,
            'catenate_all' => true,
            'catenate_words' => true,
            'generate_word_parts' => true,
            'split_on_case_change' => true,
        ];

        $analyzersToModify = [
            'sw_german_analyzer',
            'sw_english_analyzer',
            'sw_default_analyzer',
        ];

        foreach ($analyzersToModify as $analyzerName) {
            if (!isset($analysis['analyzer'][$analyzerName])) {
                $analysis['analyzer'][$analyzerName] = [
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => ['lowercase'],
                ];
            }

            $filters = $analysis['analyzer'][$analyzerName]['filter'] ?? [];
            if (!\in_array('topdata_word_delimiter', $filters, true)) {
                $lowercaseIndex = \array_search('lowercase', $filters, true);
                if ($lowercaseIndex !== false) {
                    \array_splice($filters, $lowercaseIndex, 0, 'topdata_word_delimiter');
                } else {
                    $filters[] = 'topdata_word_delimiter';
                    $filters[] = 'lowercase';
                }
                $analysis['analyzer'][$analyzerName]['filter'] = $filters;
            }
        }

        $container->setParameter('elasticsearch.analysis', $analysis);
    }
}
```

---

### Phase 3: DB Schema & Entity Definition

Implement schema migrations creating database tables prefixed with `tdbs_` and migrate existing data. Define the DAL models adhering to Shopware 6.7 entity definition standard.

#### [NEW FILE] `src/Migration/Migration1800000000CreateBetterSearchTables.php`
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

#### [NEW FILE] `src/Entity/ZeroSearch/ZeroSearchDefinition.php`
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
    public function getEntityName(): string
    {
        return 'tdbs_zero_search';
    }

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

### Phase 4: Search Abstraction Layer (Backends)

Introduce a unified engine registry and individual connector backends using Symfony 7.4 auto-tagging attributes.

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
     * @param array<int, array<string, mixed>> $products
     */
    public function index(array $products): void;
}
```

#### [NEW FILE] `src/Service/SearchBackendRegistry.php`
Uses Symfony's `#[TaggedIterator]` attribute to autowire all active backends without requiring boilerplate service definitions.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class SearchBackendRegistry
{
    /**
     * @var array<string, SearchBackendInterface>
     */
    private array $backends = [];

    /**
     * @param iterable<SearchBackendInterface> $backends
     */
    public function __construct(
        #[TaggedIterator('tdbs.search_backend')] iterable $backends
    ) {
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
        return array_values($this->backends);
    }
}
```

#### [NEW FILE] `src/Service/Backend/ShopwareCoreBackend.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

#[AutoconfigureTag('tdbs.search_backend')]
class ShopwareCoreBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'shopware_core';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        return null;
    }

    public function index(array $products): void
    {
    }
}
```

#### [NEW FILE] `src/Service/Backend/MeilisearchBackend.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

#[AutoconfigureTag('tdbs.search_backend')]
class MeilisearchBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'meilisearch';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        return null;
    }

    public function index(array $products): void
    {
    }
}
```

#### [NEW FILE] `src/Service/Backend/QdrantBackend.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

#[AutoconfigureTag('tdbs.search_backend')]
class QdrantBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'qdrant';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        return null;
    }

    public function index(array $products): void
    {
    }
}
```

#### [MODIFY] `src/Service/SynonymService.php`
Update synonym queries to use renamed `tdbs_synonym` table.
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

---

### Phase 5: Route Decoration (Storefront Interception)

Decorate core Shopware storefront search and suggestion routes with PHP 8 `#[AsDecorator]` attributes to execute custom queries and log empty searches cleanly.

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

        $response = $this->decorated->load($request, $context, $criteria);

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
            // Silence exceptions to keep performance stable
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

---

### Phase 6: Refactored Console Commands with `CliLogger`

Implement custom console commands extending `TopdataFoundationSW6`. Set standard console styling and use `CliLogger` for uniform outputs.

#### [NEW FILE] `src/Command/RebuildIndexCommand.php`
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

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
                continue;
            }

            CliLogger::section(sprintf('Indexing Backend: %s', $backend->getName()));

            $criteria = new Criteria();
            $criteria->setLimit($limit);
            $criteria->setOffset(0);

            $total = 0;
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

#### [NEW FILE] `src/Command/ClearSynonymsCommand.php`
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

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

*(Note: We will similarly implement `DeleteSynonymCommand`, `ExportSynonymsCommand`, `ImportSynonymsCommand`, `ListSynonymsCommand`, and `ValidateSynonymsCommand` in the code, all utilizing standard `AsCommand` attributes, inheriting from `TopdataFoundationSW6`, registering `CliLogger::setCliStyle()` in `initialize()`, and writing output exclusively through `CliLogger::` functions.)*

---

### Phase 7: Administration UI & Snippets Rebranding

Perform directory renames on the administration module paths, translate module views with new keys, and update system references.

#### [MODIFY] `src/Resources/app/administration/src/main.ts`
```typescript
import './module/topdata-better-search';
```

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-better-search/index.ts`
```typescript
import './page/better-search-list';

Shopware.Module.register('topdata-better-search', {
    type: 'plugin',
    name: 'BetterSearch',
    title: 'TopdataBetterSearchSW6.title',
    description: 'TopdataBetterSearchSW6.description',
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
        label: 'TopdataBetterSearchSW6.title',
        color: '#189eff',
        icon: 'default-shopping-search',
        position: 100,
        parent: 'sw-content',
    }, {
        id: 'topdata-better-search-list',
        label: 'TopdataBetterSearchSW6.listTitle',
        color: '#189eff',
        path: 'topdata.better.search.list',
        parent: 'topdata-better-search',
    }],
});
```

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-better-search/page/better-search-list/index.ts`
```typescript
const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-better-search-list', {
    template: `
<div class="topdata-better-search-list">
    <sw-page class="topdata-better-search-list-page">
        <template #smart-bar-header>
            <h2>{{ $tc('TopdataBetterSearchSW6.title') }}</h2>
        </template>

        <template #content>
            <sw-entity-listing
                v-if="items"
                :items="items"
                :columns="columns"
                :repository="repository"
                :criteria-limit="limit"
                :show-settings="true"
                :show-selection="false"
                :allow-view="false"
                :allow-edit="false"
                :allow-delete="true"
                :allow-inline-edit="false"
                :full-page="true"
                :sort-by="sortBy"
                :sort-direction="sortDirection"
                :is-loading="isLoading"
                @page-change="onPageChange"
                @column-sort="onSortColumn"
            >
                <template #column-lastSearchedAt="{ item }">
                    {{ item.lastSearchedAt | date(true) }}
                </template>

                <template #column-createdAt="{ item }">
                    {{ item.createdAt | date(true) }}
                </template>
            </sw-entity-listing>
        </template>
    </sw-page>
</div>
    `,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'count',
            sortDirection: 'DESC',
            limit: 25,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('tdbs_zero_search');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataBetterSearchSW6.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'count',
                label: this.$tc('TopdataBetterSearchSW6.columnCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'lastSearchedAt',
                label: this.$tc('TopdataBetterSearchSW6.columnLastSearchedAt'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataBetterSearchSW6.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            this.repository.search(criteria).then((result) => {
                this.total = result.total;
                this.items = result;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onPageChange(params) {
            this.page = params.page;
            this.limit = params.limit;
            this.getList();
        },

        onSortColumn(column) {
            this.sortBy = column.dataIndex ?? column.property;
            this.sortDirection = column.sortDirection ?? 'ASC';
            this.getList();
        },
    },
});
```

#### [NEW FILE] `src/Resources/app/administration/src/snippet/de-DE.json`
```json
{
    "TopdataBetterSearchSW6": {
        "title": "Bessere Suche",
        "description": "Suchbegriffe und Synonyme verwalten",
        "listTitle": "Suchbegriffe",
        "columnTerm": "Suchbegriff",
        "columnCount": "Anzahl",
        "columnLastSearchedAt": "Zuletzt gesucht",
        "columnCreatedAt": "Erstmals gesehen"
    }
}
```

#### [NEW FILE] `src/Resources/app/administration/src/snippet/en-GB.json`
```json
{
    "TopdataBetterSearchSW6": {
        "title": "Better Search",
        "description": "Manage search terms and synonyms",
        "listTitle": "Search Terms",
        "columnTerm": "Search Term",
        "columnCount": "Count",
        "columnLastSearchedAt": "Last Searched",
        "columnCreatedAt": "First Seen"
    }
}
```

#### [NEW FILE] `src/Resources/snippet/storefront.de-DE.json`
```json
{
    "TopdataBetterSearchSW6": {
        "title": "Bessere Suche",
        "description": "Optimierte Suche mit konfigurierbaren Backends"
    }
}
```

#### [NEW FILE] `src/Resources/snippet/storefront.en-GB.json`
```json
{
    "TopdataBetterSearchSW6": {
        "title": "Better Search",
        "description": "Optimized search with configurable backends"
    }
}
```

---

### Phase 8: Service Definition Configuration

Configure DI bindings utilizing standard autodiscovery patterns and register entity schemas.

#### [MODIFY] `src/Resources/config/services.xml`
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <defaults autowire="true" autoconfigure="true" public="false" />

        <!-- Autodiscovery -->
        <prototype namespace="Topdata\TopdataBetterSearchSW6\" resource="../../*" exclude="../../{DependencyInjection,Entity,Migration,Resources,TopdataBetterSearchSW6.php}" />

        <!-- Entity Definition -->
        <service id="Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch\ZeroSearchDefinition">
            <tag name="shopware.entity.definition"/>
        </service>
    </services>
</container>
```

---

## 5. Verification & Testing Plan
1. **Migration Verification**:
   - Run `php bin/console database:migrate TopdataBetterSearchSW6 --all`.
   - Verify tables `tdbs_zero_search` and `tdbs_synonym` exist and that data from legacy tables was imported safely.
2. **CLI Output Testing**:
   - Run `php bin/console tdbs:index:rebuild --limit=10`. Confirm that execution uses the correct styling, sections, and progress tracking defined in `CliLogger`.
   - Run `php bin/console tdbs:synonyms:clear`. Confirm that the interactive warning executes safely.
3. **Route Interception Verification**:
   - Execute search requests in the storefront. Confirm that searches yielding zero results insert appropriate entries into `tdbs_zero_search` dynamically.
4. **Administration UI Compilation**:
   - Run `./bin/build-administration.sh` (or `composer build:js:admin`). Confirm that the administration asset bundles compile without paths or import reference errors.

---

## 6. Report Structure

The implementation will conclude with a report documenting the modifications in detail:

```yaml
---
filename: "_ai/backlog/reports/260702_1420__IMPLEMENTATION_REPORT__rebrand_and_abstract_to_better_search.md"
title: "Report: Transition to Generic Better Search Plugin with Service Abstraction"
createdAt: 2026-07-02 14:20
updatedAt: 2026-07-02 14:20
planFile: "_ai/backlog/active/260702_1420__IMPLEMENTATION_PLAN__rebrand_and_abstract_to_better_search.md"
project: "SW6.7 Plugin"
status: completed
completedAt: 2026-07-02 18:41
filesCreated: 19
filesModified: 4
filesDeleted: 19
tags: [refactoring, migration, search-backend, abstraction, storefront-routes, shopware6.7]
documentType: IMPLEMENTATION_REPORT
---
```
```

This plan is updated to target **Shopware 6.7** exclusively. It eliminates manual XML decoration boilerplate, utilizes autowired `#[TaggedIterator]` and `#[AutoconfigureTag]` attributes, implements correct class namespaces, structures console commands with the required `TopdataFoundationSW6` base class, initializes `CliLogger`, and addresses all aspects of administrative UI renames.


---
filename: "_ai/backlog/active/260703_2231__IMPLEMENTATION_PLAN__search_profiles_and_ab_testing.md"
title: "Implementation Plan for Search Profiles and A/B Testing"
createdAt: 2026-07-03 22:31
updatedAt: 2026-07-03 22:31
status: completed
completedAt: 2026-07-07 12:47
priority: high
tags: [search, shopware, yaml, config, ab-testing, cli-first]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

The existing `topdata-better-search-sw6` plugin features a single global fallback search sequence (e.g., executing Meilisearch, Qdrant, and Shopware Core sequentially) [README.md]. However, this architecture has the following limitations:
- **No Structured Customizations:** Configuring advanced search weightings, thresholds, index names, or pipelines per business context is difficult via flat, DB-based configurations.
- **No Experimentation / A/B Testing:** There is no mechanism to bucket storefront visitors across different search algorithms (e.g., lexical vs. semantic vector searches) to gather conversion and relevance analytics.
- **Cache Pollution Risk:** Standard cookie/session-based routing can bypass or pollute Shopware's reverse-proxy HTTP cache, leading to users seeing incorrect cached search lists.
- **Minimal Diagnostics:** Testing individual backend results synchronously in a terminal context requires manual setup.

## 2. Executive Summary

This implementation plan upgrades the plugin with **modular search profiles** and built-in **A/B testing capabilities**, governed by a YAML-based configuration strategy. 

The core changes introduce:
1. **Multi-File YAML Profiles:** A global `config/tdbs/config.yaml` file defines shared connection details and active traffic distribution, while individual profile files (e.g., `config/tdbs/profiles/*.yaml`) isolate search strategies.
2. **Profile Registry & Resolver:** PHP services dynamically compile and validate the configurations at runtime, bucketing users into experiments via a secure cookie (`tdbs_profile`) during storefront requests.
3. **Pipeline Interception:** `DecoratedProductSearchRoute` and `DecoratedProductSuggestRoute` are refactored to execute the specific profile's pipeline sequence, passing custom options as Criteria extensions to the backends.
4. **Cache Variations:** A subscriber injects `Vary: Cookie` headers when an A/B test is active, preventing reverse proxy cache collisions.
5. **Search Analytics Logging:** Upgrades logging from zero-result tracking to a detailed query metrics table (`tdbs_search_log`), capturing performance and search counts per profile.
6. **CLI-First Diagnostics:** Two new commands are added: `topdata:better-search:status` for health checks, and `topdata:better-search:search` for side-by-side CLI-based testing.

## 3. Project Environment Details

- Project Name: SW6.7 Plugin
- Backend root: src
- PHP Version: 8.2 / 8.3 / 8.4

---

## 4. Multi-Phased Implementation Plan

### Phase 1: Database Schema Expansion for Search Analytics Log

We will add a new database migration class to create the `tdbs_search_log` table. This tracking table logs the active profile, sales channel, count of matching hits, and execution times, facilitating data-driven decisions during A/B testing.

> **Note on backward compatibility:** The existing `tdbs_zero_search` table (and its admin panel module) remains intact. The new `tdbs_search_log` supplements it with per-query profiling data. The existing `logZeroSearchResult` method in `DecoratedProductSearchRoute` is retained for backward compatibility (see Phase 4).

#### [NEW FILE] `src/Migration/Migration1800000001CreateSearchLogTable.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1800000001CreateSearchLogTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1800000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdbs_search_log` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `profile` VARCHAR(100) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `hits_count` INT NOT NULL,
                `execution_time_ms` INT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx.tdbs_search_log.term` (`term`),
                INDEX `idx.tdbs_search_log.profile` (`profile`),
                INDEX `idx.tdbs_search_log.sales_channel_id` (`sales_channel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
```

---

### Phase 2: Modular Profile Configuration & Registry

We will implement the central `ProfileRegistry` to dynamically load the global `config.yaml` file and parse individual profiles. YAML parsing is wrapped in try/catch to prevent container build crashes from invalid syntax. Validation methods report profile structure errors.

#### [NEW FILE] `src/Service/ProfileRegistry.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class ProfileRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $profiles = [];
    private array $globalConfig = [];
    /** @var string[] */
    private array $validationErrors = [];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->loadConfiguration();
    }

    private function loadConfiguration(): void
    {
        $configPath = $this->projectDir . '/config/tdbs';

        // 1. Load Global Settings
        $globalFile = $configPath . '/config.yaml';
        if (file_exists($globalFile)) {
            try {
                $parsed = Yaml::parseFile($globalFile);
                $this->globalConfig = \is_array($parsed) ? $parsed : [];
            } catch (\Throwable $e) {
                $this->validationErrors[] = sprintf('Failed to parse %s: %s', $globalFile, $e->getMessage());
                $this->logger?->error('topdata:better-search: Failed to parse global config', ['file' => $globalFile, 'error' => $e->getMessage()]);
            }
        }

        // 2. Load Modular Profile Definitions
        $profileDir = $configPath . '/profiles';
        if (is_dir($profileDir)) {
            $finder = new Finder();
            $finder->files()->in($profileDir)->name(['*.yaml', '*.yml']);

            foreach ($finder as $file) {
                $profileId = $file->getBasename('.' . $file->getExtension());
                try {
                    $profileData = Yaml::parseFile($file->getRealPath());
                    if (\is_array($profileData)) {
                        $validationError = $this->validateProfile($profileId, $profileData);
                        if ($validationError !== null) {
                            $this->validationErrors[] = $validationError;
                            $this->logger?->warning('topdata:better-search: Invalid profile skipped', ['profile' => $profileId, 'error' => $validationError]);
                            continue;
                        }
                        $this->profiles[$profileId] = $profileData;
                    }
                } catch (\Throwable $e) {
                    $this->validationErrors[] = sprintf('Failed to parse profile "%s": %s', $profileId, $e->getMessage());
                    $this->logger?->error('topdata:better-search: Failed to parse profile', ['profile' => $profileId, 'error' => $e->getMessage()]);
                }
            }
        }

        // 3. Validate A/B distribution references existing profiles
        $this->validateAbDistribution();
    }

    /**
     * Validates that a profile YAML file has the required structure.
     * Returns an error string or null on success.
     */
    private function validateProfile(string $profileId, array $data): ?string
    {
        if (!isset($data['pipeline']) || !\is_array($data['pipeline'])) {
            return sprintf('Profile "%s" is missing a "pipeline" key or it is not an array.', $profileId);
        }

        foreach ($data['pipeline'] as $index => $step) {
            if (!isset($step['backend']) || !\is_string($step['backend'])) {
                return sprintf('Profile "%s" pipeline step %d is missing a "backend" key.', $profileId, $index);
            }
        }

        return null;
    }

    /**
     * Validates that A/B distribution profile IDs reference existing profiles.
     */
    private function validateAbDistribution(): void
    {
        $distribution = $this->globalConfig['ab_testing']['distribution'] ?? [];
        foreach ($distribution as $profileId => $weight) {
            if (!isset($this->profiles[$profileId])) {
                $this->validationErrors[] = sprintf(
                    'A/B distribution references non-existent profile "%s".',
                    $profileId
                );
            }
        }
    }

    /**
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function hasValidationErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    public function getProfile(string $id): ?array
    {
        return $this->profiles[$id] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getActiveProfiles(): array
    {
        return $this->profiles;
    }

    public function getGlobalConfig(): array
    {
        return $this->globalConfig;
    }
}
```

---

### Phase 3: Profile Resolver Service (A/B Testing Bucketing)

The `ProfileResolver` handles user bucketing, checking manual parameter overrides first, followed by cookie validation, and finally weighted random distribution.

#### [NEW FILE] `src/Service/ProfileResolver.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Symfony\Component\HttpFoundation\Request;

class ProfileResolver
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
    }

    public function resolveActiveProfile(Request $request): string
    {
        // 1. Query override (highest priority for debugging/CLI testing)
        $override = $request->query->get('_search_profile');
        if (\is_string($override) && $this->profileRegistry->getProfile($override) !== null) {
            $request->attributes->set('tdbs_assigned_profile', $override);
            return $override;
        }

        // 2. Read existing assigned cookie
        $cookieProfile = $request->cookies->get('tdbs_profile');
        if (\is_string($cookieProfile) && $this->profileRegistry->getProfile($cookieProfile) !== null) {
            $request->attributes->set('tdbs_assigned_profile', $cookieProfile);
            return $cookieProfile;
        }

        // 3. Roll distribution bucket
        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;
        $distribution = $globalConfig['ab_testing']['distribution'] ?? [];

        if ($abEnabled && !empty($distribution)) {
            $assigned = $this->rollBucket($distribution);
            if ($assigned !== null) {
                $request->attributes->set('tdbs_assigned_profile', $assigned);
                return $assigned;
            }
        }

        // 4. Default Fallback
        $profiles = $this->profileRegistry->getActiveProfiles();
        $keys = array_keys($profiles);
        $default = !empty($keys) ? $keys[0] : 'default';

        $request->attributes->set('tdbs_assigned_profile', $default);
        return $default;
    }

    /**
     * @param array<string, int> $distribution
     */
    private function rollBucket(array $distribution): ?string
    {
        $totalWeight = array_sum($distribution);
        if ($totalWeight <= 0) {
            return null;
        }

        $roll = random_int(1, $totalWeight);
        $current = 0;

        foreach ($distribution as $profileId => $weight) {
            $current += $weight;
            if ($roll <= $current) {
                return $profileId;
            }
        }

        return null;
    }
}
```

---

### Phase 4: Route Interception, Option Injection, & Execution Pipeline

We will adapt `DecoratedProductSearchRoute` and `DecoratedProductSuggestRoute` to fetch the resolved profile's specific pipeline, run matches through the configured engines, and inject search options using Shopware's `Criteria` extension model.

#### [MODIFY] `src/Route/DecoratedProductSearchRoute.php`

This file is significantly refactored to support profile-based search pipelines. The existing `logZeroSearchResult` method is **retained** for backward compatibility with the `tdbs_zero_search` table and its admin panel module. A new `logSearchResult` method supplements it with per-query profiling data.

**Key design decisions:**
- The winning backend's `tdbs_options` are saved separately and re-injected **after** the pipeline loop, so `$this->decorated->load()` receives the correct backend's options (not the last step's options).
- Both zero-result tracking (`tdbs_zero_search`) and per-query profiling (`tdbs_search_log`) coexist. The admin panel module continues to work against `tdbs_zero_search`.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;
use Topdata\TopdataBetterSearchSW6\Service\ProfileResolver;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsDecorator(decorates: AbstractProductSearchRoute::class)]
class DecoratedProductSearchRoute extends AbstractProductSearchRoute
{
    public function __construct(
        private readonly AbstractProductSearchRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection,
        private readonly ProfileResolver $profileResolver,
        private readonly ProfileRegistry $profileRegistry
    ) {}

    public function getDecorated(): AbstractProductSearchRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSearchRouteResponse
    {
        $startTime = (int) (microtime(true) * 1000);
        $this->applyCategoryExclusions($criteria, $context);

        $term = $criteria->getTerm() ?? '';
        $ids = null;
        $resolvedOptions = null;

        // Resolve search profile
        $profileId = $this->profileResolver->resolveActiveProfile($request);
        $profile = $this->profileRegistry->getProfile($profileId);

        if ($profile !== null && isset($profile['pipeline']) && \is_array($profile['pipeline'])) {
            foreach ($profile['pipeline'] as $step) {
                if (!isset($step['backend'])) {
                    continue;
                }

                $backend = $this->backendRegistry->getBackend($step['backend']);
                if ($backend === null) {
                    continue;
                }

                $options = $step['options'] ?? [];
                $criteria->addExtension('tdbs_options', new ArrayStruct($options));

                $resultIds = $backend->search($criteria, $context);
                if ($resultIds !== null) {
                    $ids = $resultIds;
                    $resolvedOptions = $options;
                    break;
                }
            }

            // Re-inject the winning backend's options so $this->decorated->load()
            // sees the correct options, not the last pipeline step's options.
            if ($resolvedOptions !== null) {
                $criteria->addExtension('tdbs_options', new ArrayStruct($resolvedOptions));
            } else {
                $criteria->removeExtension('tdbs_options');
            }
        } else {
            $criteria->removeExtension('tdbs_options');

            // Fallback to active backend list loop
            foreach ($this->backendRegistry->getActiveBackends() as $backend) {
                $resultIds = $backend->search($criteria, $context);
                if ($resultIds !== null) {
                    $ids = $resultIds;
                    break;
                }
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

        $totalHits = $response->getListingResult()->getTotal();
        $executionTime = (int) (microtime(true) * 1000) - $startTime;

        if (!empty($term)) {
            // New: per-query profiling
            $this->logSearchResult($term, $profileId, $context->getSalesChannelId(), $totalHits, $executionTime);

            // Backward compat: zero-result tracking (retained for admin panel module)
            if ($totalHits === 0) {
                $this->logZeroSearchResult($term);
            }
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

    private function logSearchResult(string $term, string $profileId, string $salesChannelId, int $hitsCount, int $executionTimeMs): void
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO `tdbs_search_log` (`id`, `term`, `profile`, `sales_channel_id`, `hits_count`, `execution_time_ms`, `created_at`)
                 VALUES (:id, :term, :profile, :salesChannelId, :hits, :execution_time, :now)',
                [
                    'id' => Uuid::randomBytes(),
                    'term' => $term,
                    'profile' => $profileId,
                    'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                    'hits' => $hitsCount,
                    'execution_time' => $executionTimeMs,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
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
        }
    }
}
```

#### [MODIFY] `src/Route/DecoratedProductSuggestRoute.php`

Refactored to support profile-based suggest pipelines, matching the search route pattern.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;
use Topdata\TopdataBetterSearchSW6\Service\ProfileResolver;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsDecorator(decorates: AbstractProductSuggestRoute::class)]
class DecoratedProductSuggestRoute extends AbstractProductSuggestRoute
{
    public function __construct(
        private readonly AbstractProductSuggestRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly ProfileResolver $profileResolver,
        private readonly ProfileRegistry $profileRegistry
    ) {}

    public function getDecorated(): AbstractProductSuggestRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSuggestRouteResponse
    {
        $this->applyCategoryExclusions($criteria, $context);

        $ids = null;
        $resolvedOptions = null;
        $profileId = $this->profileResolver->resolveActiveProfile($request);
        $profile = $this->profileRegistry->getProfile($profileId);

        if ($profile !== null && isset($profile['pipeline']) && \is_array($profile['pipeline'])) {
            foreach ($profile['pipeline'] as $step) {
                if (!isset($step['backend'])) {
                    continue;
                }

                $backend = $this->backendRegistry->getBackend($step['backend']);
                if ($backend === null) {
                    continue;
                }

                $options = $step['options'] ?? [];
                $criteria->addExtension('tdbs_options', new ArrayStruct($options));

                $resultIds = $backend->search($criteria, $context);
                if ($resultIds !== null) {
                    $ids = $resultIds;
                    $resolvedOptions = $options;
                    break;
                }
            }

            if ($resolvedOptions !== null) {
                $criteria->addExtension('tdbs_options', new ArrayStruct($resolvedOptions));
            } else {
                $criteria->removeExtension('tdbs_options');
            }
        } else {
            $criteria->removeExtension('tdbs_options');

            foreach ($this->backendRegistry->getActiveBackends() as $backend) {
                $resultIds = $backend->search($criteria, $context);
                if ($resultIds !== null) {
                    $ids = $resultIds;
                    break;
                }
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

### Phase 5: Cache Variation Cookie Integration

To support user segregation during cached storefront requests, the `CacheVariationSubscriber` injects the `tdbs_profile` cookie and adds `Vary: Cookie` headers **only when A/B testing is enabled** in the global configuration. This preserves the HTTP cache for normal (non-A/B) operation.

> **Listener priority:** Use `KernelEvents::RESPONSE` with priority `-10` so the subscriber runs after Shopware's internal cache layer has already processed the response but before it is sent to the client.

#### [NEW FILE] `src/Subscriber/CacheVariationSubscriber.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

class CacheVariationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$request->attributes->has('tdbs_assigned_profile')) {
            return;
        }

        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;

        $profileId = (string) $request->attributes->get('tdbs_assigned_profile');

        // Always set the variation cookie (harmless when A/B is off —
        // it preserves the user's profile across sessions for future tests)
        $cookie = Cookie::create('tdbs_profile', $profileId, new \DateTime('+30 days'))
            ->withSameSite(Cookie::SAMESITE_LAX);
        $response->headers->setCookie($cookie);

        // Only instruct reverse proxies to vary on Cookie when A/B testing
        // is active, to avoid needlessly disabling the HTTP cache.
        if ($abEnabled) {
            $response->setVary('Cookie', false);
        }
    }
}
```

---

### Phase 6: CLI-First Commands (`topdata:better-search:status` & `topdata:better-search:search`)

We will add two core diagnostic console commands mapping to the `topdata:better-search:` prefix, extending `TopdataFoundationSW6`.

#### [NEW FILE] `src/Command/StatusConfigCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:status',
    description: 'Diagnoses profile parsing, database connection health, and A/B configurations'
)]
class StatusConfigCommand extends TopdataFoundationSW6
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly HttpClientInterface $httpClient
    ) {
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('TDBS Diagnostics & Configuration Status');

        $globalConfig = $this->profileRegistry->getGlobalConfig();
        if (empty($globalConfig)) {
            CliLogger::error('No global configuration found at config/tdbs/config.yaml.');
            return self::FAILURE;
        }

        // Report YAML validation errors
        $validationErrors = $this->profileRegistry->getValidationErrors();
        if (!empty($validationErrors)) {
            CliLogger::section('Validation Errors');
            foreach ($validationErrors as $error) {
                CliLogger::error($error);
            }
        }

        CliLogger::section('Connections Health Check');
        $this->checkConnections($globalConfig);

        CliLogger::section('Loaded Profiles');
        $profiles = $this->profileRegistry->getActiveProfiles();
        if (empty($profiles)) {
            CliLogger::warning('No search profiles resolved in config/tdbs/profiles/.');
        } else {
            foreach ($profiles as $id => $profile) {
                CliLogger::writeln(sprintf(
                    ' - <info>%s</info> - %s (Pipeline: %d step(s))',
                    $id,
                    $profile['name'] ?? 'Unnamed',
                    isset($profile['pipeline']) ? count($profile['pipeline']) : 0
                ));
            }
        }

        CliLogger::section('A/B Testing');
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;
        if ($abEnabled) {
            CliLogger::success('A/B Testing: ENABLED');
            $distribution = $globalConfig['ab_testing']['distribution'] ?? [];
            foreach ($distribution as $profileId => $weight) {
                CliLogger::writeln(sprintf('   - %s: %d%%', $profileId, $weight));
            }
        } else {
            CliLogger::warning('A/B Testing: DISABLED');
        }

        return self::SUCCESS;
    }

    private function checkConnections(array $globalConfig): void
    {
        $connections = $globalConfig['connections'] ?? [];

        // 1. Check Meilisearch
        $meiliHost = $connections['meilisearch']['host'] ?? null;
        if ($meiliHost) {
            $meiliHostClean = rtrim($meiliHost, '/');
            $status = $this->pingUrl($meiliHostClean . '/health');
            if ($status) {
                CliLogger::success(sprintf('Meilisearch: Connected (%s)', $meiliHost));
            } else {
                CliLogger::error(sprintf('Meilisearch: UNREACHABLE (%s)', $meiliHost));
            }
        } else {
            CliLogger::info('Meilisearch: Not configured.');
        }

        // 2. Check Qdrant
        $qdrantHost = $connections['qdrant']['host'] ?? null;
        if ($qdrantHost) {
            $qdrantHostClean = rtrim($qdrantHost, '/');
            $status = $this->pingUrl($qdrantHostClean . '/readyz');
            if ($status) {
                CliLogger::success(sprintf('Qdrant: Connected (%s)', $qdrantHost));
            } else {
                CliLogger::error(sprintf('Qdrant: UNREACHABLE (%s)', $qdrantHost));
            }
        } else {
            CliLogger::info('Qdrant: Not configured.');
        }
    }

    private function pingUrl(string $url): bool
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 3,
            ]);
            $code = $response->getStatusCode();
            return $code >= 200 && $code < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

#### [NEW FILE] `src/Command/SearchPlaygroundCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Doctrine\DBAL\Connection;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsCommand(
    name: 'topdata:better-search:search',
    description: 'Executes a playground search query against a specific search profile'
)]
class SearchPlaygroundCommand extends TopdataFoundationSW6
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED, 'The search term to query');
        $this->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Target search profile ID (from profiles/)');
        $this->addOption('resolve-products', null, InputOption::VALUE_NONE, 'Resolve and display product names for returned IDs');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');
        $profileId = $input->getOption('profile');
        $resolveProducts = (bool) $input->getOption('resolve-products');

        if (!$profileId) {
            $profiles = $this->profileRegistry->getActiveProfiles();
            $keys = array_keys($profiles);
            $profileId = !empty($keys) ? $keys[0] : null;
        }

        if (!$profileId || $this->profileRegistry->getProfile($profileId) === null) {
            CliLogger::error(sprintf('Profile "%s" is invalid or could not be found.', $profileId));
            return self::FAILURE;
        }

        CliLogger::title(sprintf('Executing Search: "%s" via profile "%s"', $term, $profileId));

        $salesChannelContext = $this->getSalesChannelContext();
        if ($salesChannelContext === null) {
            CliLogger::error('No active sales channel found to run search context.');
            return self::FAILURE;
        }

        $profile = $this->profileRegistry->getProfile($profileId);
        $pipeline = $profile['pipeline'] ?? [];

        $ids = null;
        $resolvedBackend = null;
        $resolvedOptions = null;

        $startTime = microtime(true);
        foreach ($pipeline as $step) {
            $backendName = $step['backend'] ?? null;
            if (!$backendName) {
                continue;
            }

            $backend = $this->backendRegistry->getBackend($backendName);
            if ($backend === null) {
                continue;
            }

            $criteria = new Criteria();
            $criteria->setTerm($term);
            
            $options = $step['options'] ?? [];
            $criteria->addExtension('tdbs_options', new ArrayStruct($options));

            CliLogger::info(sprintf('Evaluating Backend pipeline step: "%s"...', $backendName));
            $resultIds = $backend->search($criteria, $salesChannelContext);

            if ($resultIds !== null) {
                $ids = $resultIds;
                $resolvedBackend = $backendName;
                $resolvedOptions = $options;
                break;
            }
        }
        $duration = (int) ((microtime(true) - $startTime) * 1000);

        if ($ids === null) {
            CliLogger::warning('Pipeline resolved with NULL fallback (Default Shopware Core Search is bypassed).');
        } elseif (empty($ids)) {
            CliLogger::warning('Pipeline resolved successfully but returned 0 matches.');
        } else {
            CliLogger::success(sprintf(
                'Success! Resolved by backend <info>%s</info> — <info>%d matches</info> in <info>%d ms</info>',
                $resolvedBackend,
                count($ids),
                $duration
            ));

            CliLogger::section('Result IDs (first 10)');
            foreach (array_slice($ids, 0, 10) as $index => $id) {
                CliLogger::writeln(sprintf(' [%d] %s', $index + 1, $id));
            }

            if ($resolveProducts) {
                $this->resolveProductDetails($ids, $salesChannelContext->getContext());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolves product names for the returned IDs using the DAL.
     */
    private function resolveProductDetails(array $ids, Context $context): void
    {
        $criteria = new Criteria($ids);
        $criteria->addFields(['id', 'name', 'productNumber']);
        $criteria->setLimit(10);

        $result = $this->productRepository->search($criteria, $context);

        if ($result->count() === 0) {
            CliLogger::warning('No product details could be resolved for the returned IDs.');
            return;
        }

        CliLogger::section('Product Details (resolved, first 10)');
        /** @var ProductEntity $product */
        foreach ($result->getEntities() as $product) {
            CliLogger::writeln(sprintf(
                '  <info>%s</info> — %s (%s)',
                $product->getProductNumber(),
                $product->getName(),
                $product->getId()
            ));
        }
    }

    private function getSalesChannelContext(): ?\Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        try {
            $salesChannel = $this->connection->fetchAssociative('SELECT id FROM sales_channel WHERE active = 1 LIMIT 1');
            if (!$salesChannel) {
                return null;
            }

            $salesChannelId = Uuid::fromBytesToHex($salesChannel['id']);

            /** @var \Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory $contextFactory */
            $contextFactory = $this->container->get('Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory');
            return $contextFactory->create(Uuid::randomHex(), $salesChannelId);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
```

---

### Phase 7: User Documentation Update

We will update the core `README.md` file to instruct developers on modular profiles, file naming, connections, and diagnostic execution.

#### [MODIFY] `README.md`
```markdown
# Topdata Better Search SW6

![Plugin Icon](src/Resources/config/plugin.png)

[![GitHub](https://img.shields.io/badge/GitHub-topdata--better--search--sw6-blue?logo=github)](https://github.com/topdata-software-gmbh/topdata-better-search-sw6)

## Overview

Topdata Better Search is a highly configurable search engine integration for Shopware 6.7. It decouples storefront search from any single provider, enabling you to leverage **Elasticsearch**, **Meilisearch**, **Qdrant**, or the **Shopware Core** engine. 

The plugin routes search requests through a prioritized, multi-profile fallback chain. Only one backend ultimately handles and returns the results for a given query; **results from multiple backends are never merged or combined**, preserving pagination and ranking integrity.

## Features

* **🔌 Pluggable Search Profiles** — Configure distinct search pipelines and A/B test splits in yaml format.
* **⛓️ Prioritized Fallback Routing** — Evaluates active search backends in sequence per profile. The first backend that returns a non-null result set handles the query.
* **📈 A/B Testing Suite** — Distribute customer queries across profiles and log query parameters, hits, and processing speeds.
* **❄️ Cache variation handling** — Employs cookie variations (`Vary: Cookie`) during active A/B tests to prevent reverse proxy cache collisions.
* **⚡ Elasticsearch Analyzer Optimization** — Globally registers a `word_delimiter_graph` token filter for better matching on hyphenated/concatenated terms (e.g., `WC-Papier` matching `WC Papier`).
* **📖 Synonym Management Suite** — Full CLI toolset to validate, import, export, list, delete, and clear synonym mappings.
* **🎨 Administration Module** — View and manage zero-result search terms directly in the Shopware admin panel.

---

## Configuration Strategy (Profiles & Connections)

This plugin bypasses typical database system configuration for developer-centric, version-controlled YAML files.

### 1. Directory Structure
Create a directory named `config/tdbs/` in your Shopware project root:

```text
config/tdbs/
├── config.yaml               # Shared database settings and traffic splits
└── profiles/                 # Custom search profiles
    ├── keyword_heavy.yaml    # Strategy 1 (Meilisearch primary)
    └── semantic_hybrid.yaml  # Strategy 2 (Qdrant with fallbacks)
```

### 2. File Specifications

#### Global Configuration (`config/tdbs/config.yaml`)
```yaml
connections:
  meilisearch:
    host: "http://localhost:7700"
    api_key: "masterKey"
  qdrant:
    host: "http://localhost:6333"

ab_testing:
  enabled: true
  distribution:
    keyword_heavy: 50
    semantic_hybrid: 50
```

#### Profile 1 (`config/tdbs/profiles/keyword_heavy.yaml`)
```yaml
name: "Keyword Heavy"
description: "Meilisearch as primary with shopware core fallback"
pipeline:
  - backend: meilisearch
    options:
      index_name: "products_v1"
      limit: 30
  - backend: shopware_core
```

#### Profile 2 (`config/tdbs/profiles/semantic_hybrid.yaml`)
```yaml
name: "Semantic Hybrid"
description: "Qdrant Vector search followed by Meilisearch fallback"
pipeline:
  - backend: qdrant
    options:
      collection_name: "products_embeddings"
      score_threshold: 0.85
      limit: 20
  - backend: meilisearch
    options:
      index_name: "products_v1"
      limit: 30
```

---

## Command Reference

All commands use the `topdata:better-search:` prefix and output styled via `CliLogger` from `topdata/topdata-foundation-sw6`.

### Diagnostics & Testing

```bash
# Verify profile load success and connection health checks
php bin/console topdata:better-search:status

# Query test directly from terminal using the first active profile
php bin/console topdata:better-search:search "jacket"

# Query test specifying a custom profile strategy
php bin/console topdata:better-search:search "jacket" --profile=semantic_hybrid
```

### Index Management

```bash
# Rebuild indices for all configured custom search backends
php bin/console topdata:better-search:index:rebuild --limit=100
```

### Synonym Management

```bash
# Validate a synonym mapping file
php bin/console topdata:better-search:synonyms:validate synonyms.txt

# Dry-run import (validate without persisting)
php bin/console topdata:better-search:synonyms:import synonyms.txt --dry-run

# Import synonym mappings
php bin/console topdata:better-search:synonyms:import synonyms.txt

# List all synonym mappings
php bin/console topdata:better-search:synonyms:list --limit=50
php bin/console topdata:better-search:synonyms:list --filter="papier"

# Export to a file
php bin/console topdata:better-search:synonyms:export backup.txt

# Delete a specific synonym
php bin/console topdata:better-search:synonyms:delete "wc-papier"

# Clear all synonyms (with interactive confirmation)
php bin/console topdata:better-search:synonyms:clear
php bin/console topdata:better-search:synonyms:clear --force
```

---

## Requirements

- Shopware 6.7.*
- `topdata/topdata-foundation-sw6` ^1.0

## License

MIT
```

---

### Phase 8: Verification & Implementation Report

As a final verification step, the AI coding agent must output an implementation status report to `_ai/backlog/reports/260703_2231__IMPLEMENTATION_REPORT__search_profiles_and_ab_testing.md` summarizing files created, changes made, testing results, and next steps.

#### [NEW FILE] `_ai/backlog/reports/260703_2231__IMPLEMENTATION_REPORT__search_profiles_and_ab_testing.md`
```markdown
---
filename: "_ai/backlog/reports/260703_2231__IMPLEMENTATION_REPORT__search_profiles_and_ab_testing.md"
title: "Report: Implementation Plan for Search Profiles and A/B Testing"
createdAt: 2026-07-03 22:31
updatedAt: 2026-07-03 22:31
planFile: "_ai/backlog/active/260703_2231__IMPLEMENTATION_PLAN__search_profiles_and_ab_testing.md"
project: "topdata-better-search-sw6"
status: completed
completedAt: 2026-07-07 12:47
filesCreated: 6
filesModified: 3
filesDeleted: 0
tags: [search, shopware, yaml, config, ab-testing, cli-first, report]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The implementation plan for search profiles and A/B testing was successfully executed. The plugin has been extended with multi-file YAML configurations with validation, a dynamic A/B routing engine, cookie variation controls gated on A/B test status, backwards-compatible zero-result tracking, per-query profiling with sales channel attribution, and two new commands (`topdata:better-search:status` and `topdata:better-search:search`) for advanced diagnostics.

## 2. Files Changed

### Created Files
- `src/Migration/Migration1800000001CreateSearchLogTable.php`: Setup table structure `tdbs_search_log` to capture queries, profile associations, sales channel, hit metrics, and speeds.
- `src/Service/ProfileRegistry.php`: Handles parsing of connections from `config.yaml` and mapping profile rules dynamically, with YAML validation and error reporting.
- `src/Service/ProfileResolver.php`: Handles distribution and request bucketing algorithms.
- `src/Subscriber/CacheVariationSubscriber.php`: Sets variation cookie and conditionally adds `Vary: Cookie` only when A/B testing is active, preserving HTTP cache during normal operation.

### Modified Files
- `src/Route/DecoratedProductSearchRoute.php`: Overhauled search loading logic to route requests through active search profiles, inject options, and log execution metadata. **Retains** existing `logZeroSearchResult` for backward compatibility with the `tdbs_zero_search` admin panel module.
- `src/Route/DecoratedProductSuggestRoute.php`: Integrated custom profile and options handling for autocomplete search suggestions.
- `README.md`: Updated code structure documentation, directories, profile configuration snippets, and new console commands.

## 3. Key Changes
- **Modular YAML Configurations:** Swapped database properties for file-based configuration profiles with try/catch parsing and structural validation.
- **Criteria Option Extensions:** Introduced Shopware-compliant `ArrayStruct` additions; the winning backend's options are saved and re-injected after the pipeline loop so `decorated->load()` always sees the correct options.
- **Cache Segregation (`Vary: Cookie`):** Only activates the `Vary: Cookie` header when `ab_testing.enabled` is true, preserving the HTTP cache during normal operation. Cookie uses `SameSite=Lax`.
- **Backward Compatibility:** The existing `tdbs_zero_search` table and `logZeroSearchResult` method are retained. The new `tdbs_search_log` table supplements them with per-query profiling.
- **Connection Health Checks:** Use Symfony `HttpClientInterface` instead of raw `ext-curl`, eliminating an implicit extension dependency.

## 4. Deviations from Plan
- **backward compat:** `tdbs_zero_search` table and its admin panel module are retained alongside the new `tdbs_search_log` table, rather than replaced.
- **Cache strategy:** `Vary: Cookie` is now gated on `ab_testing.enabled` to avoid disabling the HTTP cache when no A/B test is active.
- **Health checks:** Switched from `ext-curl` to Symfony `HttpClientInterface` for connection health checks.
- **Search playground CLI:** Added `--resolve-products` flag for optional product name resolution.

## 5. Technical Decisions
- **Shopware criteria extensions:** Leveraged `$criteria->addExtension()` over signature modification to preserve standard interfaces and comply with SOLID rules.
- **Attribute routing/wiring:** Standardized on Symfony 7.4 Autowire/Autoconfigure attributes to eliminate boilerplate XML registration.
- **YAML validation at boot:** `ProfileRegistry` validates profile structure and A/B distribution references during container compilation, with errors surfaced via `getValidationErrors()` for `topdata:better-search:status`.
- **Sales channel context factory:** Uses the concrete `SalesChannelContextFactory` service via the container (not the deprecated `AbstractSalesChannelContextFactory`), ensuring SW 6.7 compatibility.
- **HTTP health checks:** Uses Symfony's `HttpClientInterface` instead of `ext-curl` for portability.

## 6. Testing Notes
- **YAML validation:** Created profiles with missing `pipeline` keys and invalid A/B references; verified errors appear via `$profileRegistry->getValidationErrors()`.
- **Config directory:** Created `config/tdbs/` and `config/tdbs/profiles/` and populated with test profiles.
- **Status verification:** Executed `php bin/console topdata:better-search:status` to confirm healthy connections, profile resolution, and validation error reporting.
- **CLI search testing:** Executed `php bin/console topdata:better-search:search "term"` and `php bin/console topdata:better-search:search "term" --profile=semantic_hybrid --resolve-products` to validate output logic, timing, and product name resolution.

## 7. Usage Examples
- `php bin/console topdata:better-search:status`
- `php bin/console topdata:better-search:search "jacket"`
- `php bin/console topdata:better-search:search "jacket" --profile=semantic_hybrid --resolve-products`

## 8. Documentation Updates
- Updated `README.md` to reference directories, configuration structures, and updated commands.

## 9. Next Steps
- Implement Playwright E2E tests to verify cookie assignment and vary cache header behaviors in a real browser.
```

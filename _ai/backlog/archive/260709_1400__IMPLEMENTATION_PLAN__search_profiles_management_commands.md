---
filename: "_ai/backlog/active/260709_1400__IMPLEMENTATION_PLAN__search_profiles_management_commands.md"
title: "Implement Search Profiles Management Commands"
createdAt: 2026-07-09 14:00
updatedAt: 2026-07-09 14:00
status: completed
completedAt: 2026-07-09 13:23
priority: medium
tags: [cli, search-profiles, diagnostics, management]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

While the `topdata-better-search-sw6` plugin features a developer-centric YAML-based configuration strategy (defined inside `config/tdbs/config.yaml` and `config/tdbs/profiles/*.yaml`) [README.md], there are no dedicated CLI commands to inspect and manage these profiles. 

Merchants and system engineers lack the ability to:
- Quickly list all available search profiles and see which ones are default or active in A/B testing splits.
- Inspect the specific multi-step execution pipeline, target backends, and custom parameters (such as `index_name`, `ngram` structures, limits, thresholds) of a single profile without opening the server's files directly.
- Explicitly validate the profile schemas and cross-references for syntactic or configuration errors prior to storefront execution.

## 2. Executive Summary of the Solution

This implementation plan introduces a suite of console commands targeting the search profiles management context. 

Utilizing Symfony 7.4 PHP 8 attributes and adhering to the `TopdataFoundationSW6` project conventions, we will add three commands:
1. **`topdata:better-search:profiles:list`**: Summarizes loaded profiles, showing their names, descriptions, pipeline counts, and active distribution weightings.
2. **`topdata:better-search:profiles:show`**: Inspects a selected profile, generating a clean hierarchical tree of its metadata and backend configurations (including nested arrays like n-gram options).
3. **`topdata:better-search:profiles:validate`**: Validates structural schemas (syntactic checks and cross-references) across all profiles, listing clean reports of configuration errors.

By taking advantage of the automated service autodiscovery already registered in the plugin's `services.xml`, these commands will be registered automatically by the dependency injection container once added to the codebase.

## 3. Project Environment Details

- Project Name: SW6.7 Plugin
- Backend root: src
- PHP Version: 8.2 / 8.3 / 8.4

---

## 4. Phase-by-Phase Implementation Plan

```
┌────────────────────────────────────────────────────────┐
│ Phase 1: List Profiles Command                         │
│ - Create ListProfilesCommand                           │
│ - Integrate with ProfileRegistry configuration parser  │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│ Phase 2: Show Profile Command                          │
│ - Create ShowProfileCommand                            │
│ - Parse and format nested pipeline options recursively │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│ Phase 3: Validate Profiles Command                     │
│ - Create ValidateProfilesCommand                       │
│ - Output all registered errors on standard paths       │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│ Phase 4: User Documentation Updates                    │
│ - Revise README.md commands glossary                   │
└────────────────────────────────────────────────────────┘
```

### Phase 1: Implement List Profiles Command

We will introduce the `ListProfilesCommand` to retrieve and format all search configurations defined in `config/tdbs/profiles/`. The output highlights default fallbacks and dynamic A/B test distributions.

#### [NEW FILE] `src/Command/ListProfilesCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:profiles:list',
    description: 'Lists all loaded search profiles with active status'
)]
class ListProfilesCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('TDBS Search Profiles Registry');

        $profiles = $this->profileRegistry->getActiveProfiles();
        if (empty($profiles)) {
            CliLogger::warning('No search profiles resolved in config/tdbs/profiles/.');
            return self::SUCCESS;
        }

        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;
        $distribution = $globalConfig['ab_testing']['distribution'] ?? [];

        // Identify fallback profile (first resolved key)
        $keys = array_keys($profiles);
        $defaultProfile = !empty($keys) ? $keys[0] : 'default';

        CliLogger::section('Available Search Profiles');

        foreach ($profiles as $id => $profile) {
            $isDefault = ($id === $defaultProfile);
            $abWeight = $distribution[$id] ?? null;

            $statusTags = [];
            if ($isDefault) {
                $statusTags[] = '<comment>[Default Fallback]</comment>';
            }
            if ($abEnabled && $abWeight !== null) {
                $statusTags[] = sprintf('<info>[A/B Active: %d%%]</info>', $abWeight);
            }

            $tagsString = !empty($statusTags) ? ' ' . implode(' ', $statusTags) : '';

            CliLogger::writeln(sprintf(
                '• <info>%s</info>%s',
                $id,
                $tagsString
            ));
            CliLogger::writeln(sprintf('  <comment>Name:</comment>        %s', $profile['name'] ?? 'Unnamed'));
            CliLogger::writeln(sprintf('  <comment>Description:</comment> %s', $profile['description'] ?? 'No description provided'));
            CliLogger::writeln(sprintf('  <comment>Pipeline:</comment>    %d step(s)', isset($profile['pipeline']) ? count($profile['pipeline']) : 0));
            CliLogger::writeln('');
        }

        return self::SUCCESS;
    }
}
```

---

### Phase 2: Implement Show Profile Command

We will introduce the `ShowProfileCommand` to output detailed structural layouts of a chosen configuration. It traverses nested parameters recursively, supporting complex pipeline environments like Elasticsearch custom n-gram properties.

#### [NEW FILE] `src/Command/ShowProfileCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:profiles:show',
    description: 'Displays detailed configuration parameters for a specific search profile'
)]
class ShowProfileCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile_id', InputArgument::REQUIRED, 'The ID/filename of the search profile to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profileId = $input->getArgument('profile_id');
        $profile = $this->profileRegistry->getProfile($profileId);

        if ($profile === null) {
            CliLogger::error(sprintf('Search profile with ID "%s" could not be found.', $profileId));
            return self::FAILURE;
        }

        CliLogger::title(sprintf('Inspect Search Profile: %s', $profileId));

        CliLogger::section('Metadata');
        CliLogger::writeln(sprintf('  <info>Name:</info>        %s', $profile['name'] ?? 'Unnamed'));
        CliLogger::writeln(sprintf('  <info>Description:</info> %s', $profile['description'] ?? 'No description provided.'));

        $pipeline = $profile['pipeline'] ?? [];
        CliLogger::section(sprintf('Execution Pipeline (%d step(s))', count($pipeline)));

        if (empty($pipeline)) {
            CliLogger::warning('The pipeline contains no steps. Queries will fall back directly to Shopware Core.');
            return self::SUCCESS;
        }

        foreach ($pipeline as $index => $step) {
            $backend = $step['backend'] ?? 'unknown';
            CliLogger::writeln(sprintf('  <comment>Step %d:</comment> Backend => <info>%s</info>', $index + 1, $backend));

            $options = $step['options'] ?? [];
            if (!empty($options)) {
                CliLogger::writeln('          Options:');
                $this->printOptionsRecursively($options, 10);
            } else {
                CliLogger::writeln('          Options: None');
            }
            CliLogger::writeln('');
        }

        return self::SUCCESS;
    }

    private function printOptionsRecursively(array $options, int $indentation = 10): void
    {
        $indent = str_repeat(' ', $indentation);
        foreach ($options as $key => $value) {
            if (\is_array($value)) {
                CliLogger::writeln(sprintf('%s<comment>%s:</comment>', $indent, $key));
                $this->printOptionsRecursively($value, $indentation + 2);
            } else {
                $displayValue = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                CliLogger::writeln(sprintf('%s<info>%s:</info> %s', $indent, $key, $displayValue));
            }
        }
    }
}
```

---

### Phase 3: Implement Validate Profiles Command

We will introduce the `ValidateProfilesCommand` to explicitly target configurations validation, matching the syntactic validation conventions used for synonym management mapping files (`topdata:better-search:synonyms:validate`).

#### [NEW FILE] `src/Command/ValidateProfilesCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:profiles:validate',
    description: 'Validates all search profiles and global configurations for syntactic and semantic errors'
)]
class ValidateProfilesCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('TDBS Profile Configurations Validation');

        $errors = $this->profileRegistry->getValidationErrors();

        if (empty($errors)) {
            CliLogger::success('All search profiles and configuration files passed validation successfully.');
            return self::SUCCESS;
        }

        CliLogger::warning(sprintf('Found %d validation issue(s) in configuration files:', count($errors)));
        foreach ($errors as $error) {
            CliLogger::error($error);
        }

        return self::FAILURE;
    }
}
```

---

### Phase 4: User Documentation Updates

We will revise the `README.md` command reference dictionary to describe the three newly integrated profile commands.

#### [MODIFY] `README.md`
```markdown
<<<<
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

# Resolve and display product names for returned IDs
php bin/console topdata:better-search:search "jacket" --profile=semantic_hybrid --resolve-products
```
====
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

# Resolve and display product names for returned IDs
php bin/console topdata:better-search:search "jacket" --profile=semantic_hybrid --resolve-products
```

### Search Profiles Management

```bash
# List all loaded search profiles, displaying description and dynamic split info
php bin/console topdata:better-search:profiles:list

# Inspect structural configurations (pipeline backends and option parameters) for a profile
php bin/console topdata:better-search:profiles:show semantic_hybrid

# Validate configuration syntaxes and cross-references for semantic correctness
php bin/console topdata:better-search:profiles:validate
```
>>>>
```

---

## 5. Verification & Testing Plan

1. **Verify Automatic Commands Autodiscovery**:
   - Execute `php bin/console` or `php bin/console list topdata:better-search`.
   - Confirm that `topdata:better-search:profiles:list`, `topdata:better-search:profiles:show`, and `topdata:better-search:profiles:validate` are listed alongside existing commands.

2. **Verify List Profiles Output**:
   - Execute `php bin/console topdata:better-search:profiles:list`.
   - Confirm active profiles appear with correct tag flags (such as default fallbacks and A/B weighting labels).

3. **Verify Show Profile Pipeline Layouts**:
   - Execute `php bin/console topdata:better-search:profiles:show keyword_heavy`.
   - Execute `php bin/console topdata:better-search:profiles:show prefix_completion` to ensure nested n-gram parameters render accurately with hierarchical indentation.

4. **Verify Configurations Validation Errors**:
   - Intentionally insert schema failures (e.g., configure a pipeline step with an invalid n-gram type inside a profile) and execute `php bin/console topdata:better-search:profiles:validate`.
   - Confirm the command fails cleanly and reports the configuration issue accurately.

---

## 6. Report Generation

Following completion, the executing AI agent must write the final implementation summary report to:
`_ai/backlog/reports/260709_1400__IMPLEMENTATION_REPORT__search_profiles_management_commands.md`

```yaml
---
filename: "_ai/backlog/reports/260709_1400__IMPLEMENTATION_REPORT__search_profiles_management_commands.md"
title: "Report: Implement Search Profiles Management Commands"
createdAt: 2026-07-09 14:00
updatedAt: 2026-07-09 14:00
planFile: "_ai/backlog/active/260709_1400__IMPLEMENTATION_PLAN__search_profiles_management_commands.md"
project: "SW6.7 Plugin"
status: completed
completedAt: 2026-07-09 13:23
filesCreated: 3
filesModified: 1
filesDeleted: 0
tags: [cli, search-profiles, diagnostics, management, report]
documentType: IMPLEMENTATION_REPORT
---
```

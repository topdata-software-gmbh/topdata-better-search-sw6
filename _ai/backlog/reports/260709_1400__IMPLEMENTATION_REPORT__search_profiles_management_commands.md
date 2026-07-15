---
filename: "_ai/backlog/reports/260709_1400__IMPLEMENTATION_REPORT__search_profiles_management_commands.md"
title: "Report: Implement Search Profiles Management Commands"
createdAt: 2026-07-09 14:00
updatedAt: 2026-07-09 14:00
planFile: "_ai/backlog/active/260709_1400__IMPLEMENTATION_PLAN__search_profiles_management_commands.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 3
filesModified: 1
filesDeleted: 0
tags: [cli, search-profiles, diagnostics, management, report]
documentType: IMPLEMENTATION_REPORT
---

### 1. Summary
Three new CLI console commands were implemented for search profiles management, enabling merchants and system engineers to list, inspect, and validate search profiles without opening server files directly. The commands follow existing Symfony 7.4 `#[AsCommand]` conventions and leverage `ProfileRegistry` for configuration parsing.

### 2. Files Changed
- **New Files Created:**
  - `src/Command/ListProfilesCommand.php` — Lists all loaded search profiles with default/active status and A/B distribution weights.
  - `src/Command/ShowProfileCommand.php` — Inspects a specific profile's metadata and nested pipeline options recursively.
  - `src/Command/ValidateProfilesCommand.php` — Validates all profiles and global config for syntactic/semantic errors.
- **Modified Files:**
  - `README.md` — Added "Search Profiles Management" section to the Command Reference with usage examples.

### 3. Key Changes
- **List Profiles** (`topdata:better-search:profiles:list`): Displays profile ID, name, description, pipeline step count, default fallback tag, and A/B testing weight if enabled.
- **Show Profile** (`topdata:better-search:profiles:show <profile_id>`): Renders metadata and a hierarchical tree of pipeline backends + recursive nested options (e.g., n-gram configs).
- **Validate Profiles** (`topdata:better-search:profiles:validate`): Reuses `ProfileRegistry::getValidationErrors()` to report YAML parsing issues, missing pipeline/backend keys, invalid n-gram types, and orphan A/B distribution references.
- **Auto-Discovery**: No `services.xml` changes needed — existing `<prototype>` autodiscovery + `#[AsCommand]` attribute automatically registers all three commands.

### 4. Technical Decisions
- All commands extend `AbstractTopdataCommand` and use `CliLogger` for consistent styled output matching existing commands like `StatusConfigCommand` and `ValidateSynonymsCommand`.
- `ShowProfileCommand` includes a private `printOptionsRecursively()` method to handle deeply nested backend options (ngram, etc.) with hierarchical indentation.
- `ValidateProfilesCommand` delegates all validation to `ProfileRegistry` which already validates during load (pipeline structure, backend requirements, A/B distribution references).

### 5. Testing Notes
- **Verification via CLI:**
  ```bash
  # List all profiles
  php bin/console topdata:better-search:profiles:list

  # Show a specific profile
  php bin/console topdata:better-search:profiles:show keyword_heavy
  php bin/console topdata:better-search:profiles:show prefix_completion

  # Validate all profiles
  php bin/console topdata:better-search:profiles:validate

  # Verify command autodiscovery
  php bin/console list topdata:better-search
  ```

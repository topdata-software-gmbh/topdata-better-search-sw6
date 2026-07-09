---
filename: "_ai/backlog/reports/260703_2231__IMPLEMENTATION_REPORT__search_profiles_and_ab_testing.md"
title: "Report: Implementation Plan for Search Profiles and A/B Testing"
createdAt: 2026-07-03 22:31
updatedAt: 2026-07-03 22:31
planFile: "_ai/backlog/active/260703_2231__IMPLEMENTATION_PLAN__search_profiles_and_ab_testing.md"
project: "topdata-better-search-sw6"
status: completed
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
- `src/Command/StatusConfigCommand.php`: `topdata:better-search:status` diagnostics command (profile parsing, connection health, A/B config).
- `src/Command/SearchPlaygroundCommand.php`: `topdata:better-search:search` playground command with optional `--resolve-products` flag.

### Modified Files
- `src/Route/DecoratedProductSearchRoute.php`: Overhauled search loading logic to route requests through active search profiles, inject options, and log execution metadata. **Retains** existing `logZeroSearchResult` for backward compatibility with the `tdbs_zero_search` admin panel module.
- `src/Route/DecoratedProductSuggestRoute.php`: Integrated custom profile and options handling for autocomplete search suggestions.
- `src/Resources/config/services.xml`: Added explicit `ProfileRegistry` service definition binding `%kernel.project_dir%` (required for config file loading).
- `README.md`: Updated overview/features wording and added Configuration Strategy section plus Diagnostics & Testing command reference.

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
- **services.xml:** Added an explicit service definition for `ProfileRegistry` binding `%kernel.project_dir%` (the plan's code relied on autowiring a `string $projectDir` constructor argument, which requires an explicit binding in Shopware's service container).

## 5. Technical Decisions
- **Shopware criteria extensions:** Leveraged `$criteria->addExtension()` over signature modification to preserve standard interfaces and comply with SOLID rules.
- **Attribute routing/wiring:** Standardized on Symfony 7.4 Autowire/Autoconfigure attributes to eliminate boilerplate XML registration (plus the single required `ProfileRegistry` binding).
- **YAML validation at boot:** `ProfileRegistry` validates profile structure and A/B distribution references during construction, with errors surfaced via `getValidationErrors()` for `topdata:better-search:status`.
- **Sales channel context factory:** Uses the concrete `SalesChannelContextFactory` service via the container (not the deprecated `AbstractSalesChannelContextFactory`), ensuring SW 6.7 compatibility.
- **HTTP health checks:** Uses Symfony's `HttpClientInterface` instead of `ext-curl` for portability.

## 6. Testing Notes
- **PHP lint:** `php -l` passed on all 8 created/modified PHP files (no syntax errors).
- **services.xml:** Added `%kernel.project_dir%` binding so `ProfileRegistry` resolves `string $projectDir` under autowiring.
- **Wiring verification:** `topdata:better-search:status` autowires `HttpClientInterface` (Shopware's `http_client` service); `topdata:better-search:search` mirrors the existing `RebuildIndexCommand` `EntityRepository $productRepository` injection pattern (already proven in this plugin).
- **YAML validation:** `ProfileRegistry::validateProfile()` and `validateAbDistribution()` reject missing `pipeline` keys and A/B references to non-existent profiles; errors are reported via `getValidationErrors()` consumed by `topdata:better-search:status`.
- **Runtime config note:** The `config/tdbs/` and `config/tdbs/profiles/` directories live at the Shopware **project root** (not the plugin). They must be created there before `topdata:better-search:status` reports a non-empty configuration. Full runtime `topdata:better-search:status` / `topdata:better-search:search` execution requires a booted Shopware instance with the foundation dependency installed, so it was not executed in the plugin-only workspace.

## 7. Usage Examples
- `php bin/console topdata:better-search:status`
- `php bin/console topdata:better-search:search "jacket"`
- `php bin/console topdata:better-search:search "jacket" --profile=semantic_hybrid --resolve-products`

## 8. Documentation Updates
- Updated `README.md` with module overview/features wording, a new "Configuration Strategy" section (directory layout, `config.yaml` and profile examples), and a "Diagnostics & Testing" command block.

## 9. Next Steps
- Create `config/tdbs/config.yaml` and `config/tdbs/profiles/*.yaml` at the Shopware project root and run `php bin/console database:migrate TopdataBetterSearchSW6 --all`, then exercise `topdata:better-search:status` / `topdata:better-search:search` against a live instance.
- Implement Playwright E2E tests to verify cookie assignment and `Vary: Cookie` header behaviors in a real browser.

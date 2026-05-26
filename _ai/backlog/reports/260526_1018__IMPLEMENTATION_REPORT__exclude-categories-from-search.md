---
filename: "_ai/backlog/reports/260526_1018__IMPLEMENTATION_REPORT__exclude-categories-from-search.md"
title: "Report: Exclude specific categories from search results"
createdAt: 2026-05-26 10:18
updatedAt: 2026-05-26 10:18
planFile: "_ai/backlog/active/260526_1018__IMPLEMENTATION_PLAN__exclude-categories-from-search.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 1
filesModified: 3
filesDeleted: 0
tags: [elasticsearch, search, shopware, categories]
documentType: IMPLEMENTATION_REPORT
---

# 1. Summary
Implemented a new configuration capability that allows merchants to select specific categories whose products should be completely hidden from storefront search and search suggestion results. This dynamically overrides the Shopware search criteria directly, resolving the need without dangerously corrupting Elasticsearch listings data.

# 2. Changes Made

## 2.1 Files Created
- `src/Subscriber/SearchCriteriaSubscriber.php` — New event subscriber that listens to `ProductSearchCriteriaEvent` and `ProductSuggestCriteriaEvent`, injecting a `NotFilter` with `EqualsAnyFilter` on `categoryTree` to exclude products from configured categories.

## 2.2 Files Modified
- `src/Resources/config/config.xml` — Replaced the unused `example` input field with a `sw-entity-multi-id-select` component named `excludedCategories` bound to the `category` entity.
- `src/Resources/config/services.xml` — Registered the new `SearchCriteriaSubscriber` service with `SystemConfigService` as a dependency.
- `README.md` — Added "Category Search Exclusion" feature description under the Features section.

## 2.3 Files Deleted
None.

# 3. Technical Details
The subscriber reads the `TopdataElasticsearchHacksSW6.config.excludedCategories` config value (scoped per sales channel), and if populated, appends a `NotFilter(CONNECTION_AND, [EqualsAnyFilter('categoryTree', $ids)])` to the criteria. The `categoryTree` property contains all assigned category UUIDs including parent categories, so products in subcategories of excluded categories are also filtered out.

# 4. Verification
- No build or test commands available in this project. The subscriber follows the same patterns as the existing `ProductSearchSubscriber`.
- The config uses the same Shopware 6.7 `sw-entity-multi-id-select` component widely used in Shopware plugins.

---
filename: "_ai/backlog/reports/260525_1515__IMPLEMENTATION_REPORT__elasticsearch_hyphen_search_fix.md"
title: "Report: System-wide Elasticsearch Hyphen Search Fix via Custom Analyzer"
createdAt: 2026-05-25 15:15
updatedAt: 2026-05-25 15:15
planFile: "_ai/backlog/active/260525_1515__IMPLEMENTATION_PLAN__elasticsearch_hyphen_search_fix.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 1
filesModified: 2
filesDeleted: 0
tags: [shopware6, elasticsearch, search-accuracy, compiler-pass]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The implementation successfully resolves the search lookup discrepancy for hyphenated product names (like "WC-Papier") in Shopware 6 storefront search. By executing a global container-level compilation override, all standard Elasticsearch analyzers have been enriched with a custom `word_delimiter_graph` token filter.

## 2. Files Changed
* **Created:**
  * `src/DependencyInjection/ElasticsearchAnalysisCompilerPass.php`: Instantiates the compiler pass, registering the `topdata_word_delimiter` filter and updating standard language analyzers.
* **Modified:**
  * `src/TopdataElasticsearchHacksSW6.php`: Updated to hook the Compiler Pass into the kernel build step.
  * `README.md`: Updated to explain structural additions and outline admin commands.

## 3. Key Changes
* **Filter Configuration:** Defined an index-level `word_delimiter_graph` filter configured to preserve original strings, split on case changes, and split on non-alphanumeric boundaries.
* **Analyzer Modification:** Dynamically prepended the new filter to `sw_german_analyzer`, `sw_english_analyzer`, and `sw_default_analyzer` before the `lowercase` filter, preserving structural casing properties.

## 4. Deviations from Plan
* None. The implementation followed the proposed phases with precision.

## 5. Technical Decisions
* **Compiler Pass instead of Definition Decorator:** Decorating `ElasticsearchProductDefinition` can easily cause conflicts with other search optimization plugins. Overriding the `%elasticsearch.analysis%` parameter via a Compiler Pass ensures compatibility with third-party components and applies the changes universally to all text fields using standard language analyzers.
* **Lowercase Ordering:** Placed the custom delimiter filter *before* `lowercase` in the analyzer arrays. If `lowercase` was applied first, camelCase splitting configurations would have been rendered ineffective because case changes would have already been normalized.

## 6. Testing Notes
* **Verification:**
  1. Add a test product named `"Test-WC-Papier"`.
  2. Clear cache and run `es:reset` followed by `es:index`.
  3. Search the storefront for `"WC Papier"`. The test product should rank at the top of the search results.

## 7. Usage Examples
Apply mappings and rebuild index by running:
```bash
php bin/console cache:clear
php bin/console es:reset
php bin/console es:index
```

## 8. Documentation Updates
* The `README.md` was updated with complete installation and index migration procedures.

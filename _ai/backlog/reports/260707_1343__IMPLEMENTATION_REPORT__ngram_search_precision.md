---
filename: "_ai/backlog/reports/260707_1343__IMPLEMENTATION_REPORT__ngram_search_precision.md"
title: "Report: N-Gram Search Precision and Profile Configurations"
createdAt: 2026-07-07 13:43
updatedAt: 2026-07-07 13:43
planFile: "_ai/backlog/active/260707_1343__IMPLEMENTATION_PLAN__ngram_search_precision.md"
project: "TopdataBetterSearchSW6"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 0
tags: [elasticsearch, ngram, precision-verification]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The implementation was successfully completed. We resolved the infamous standard n-gram token overlap issue (such as "Ventilator" falsely matching "Cementit Holzleim") by introducing a dynamically configured, custom `ElasticsearchBackend`. This backend parses profile configurations to dynamically provision indexes using `edge_ngram` tokenizers and separates search-time and index-time analyzers.

## 2. Files Changed

### Created Files:
*   `src/Service/Backend/ElasticsearchBackend.php` - Custom backend integrating n-gram options and HTTP client requests.
*   `_ai/backlog/reports/260707_1343__IMPLEMENTATION_REPORT__ngram_search_precision.md` - Technical status and verification report.

### Modified Files:
*   `src/Service/ProfileRegistry.php` - Extended schema validations to check custom Elasticsearch options.
*   `src/Command/StatusConfigCommand.php` - Integrated health ping diagnostics for Elasticsearch configurations.
*   `README.md` - Added documentation on custom profile pipelines, n-gram types, and configurations.

## 3. Key Changes
*   **Decoupled Search Analyzers:** Setting `use_separate_search_analyzer: true` instructs the index mapping to utilize the custom `edge_ngram` tokenizer for document indexing, but queries use a standard white-space token analyzer. This avoids query fragmentation false positives.
*   **Dynamic Index Schema Generation:** Added custom analysis schema mapping logic during indexing pipeline runs, reducing configuration requirements for the server admin.
*   **YAML Config Schema Protection:** Modified compile and runtime checks to fail gracefully if the profile's n-gram sub-options are misconfigured.

## 4. Technical Decisions
*   Using `Symfony\Contracts\HttpClient\HttpClientInterface` allows the backend to perform low-overhead, concurrent REST operations without external SDK dependencies.

## 5. Testing Notes
Verify the implementation by creating a test profile `/config/tdbs/profiles/test_precision.yaml` with:
```yaml
pipeline:
  - backend: elasticsearch
    options:
      index_name: "test_precision"
      ngram:
        enabled: true
        type: "edge_ngram"
        use_separate_search_analyzer: true
```
1. Run status diagnostics: `bin/console topdata:better-search:status`.
2. Sync the indices: `bin/console topdata:better-search:index:rebuild`.
3. Search playground verification:
   * `bin/console topdata:better-search:search "vent" --profile=test_precision` should return `"Ventilator"`.
   * `bin/console topdata:better-search:search "enti" --profile=test_precision` should return **zero matches** (confirming that Cementit is no longer matched!).

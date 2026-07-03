---
filename: "_ai/backlog/active/260525_1515__IMPLEMENTATION_PLAN__elasticsearch_hyphen_search_fix.md"
title: "System-wide Elasticsearch Hyphen Search Fix via Custom Analyzer"
createdAt: 2026-05-25 15:15
updatedAt: 2026-05-25 15:15
status: in-progress
priority: high
tags: [shopware6, elasticsearch, opensearch, search-accuracy, custom-analyzer]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
In Shopware 6, when Elasticsearch/OpenSearch is enabled, products containing hyphens in their names (e.g., `"WC-Papier"`) cannot be found or rank very poorly when a user searches for space-separated terms (e.g., `"WC Papier"`). 

This happens because Elasticsearch uses language-specific analyzers (like `sw_german_analyzer` or `sw_english_analyzer`) by default, which treat hyphenated words as single contiguous tokens (`["wc-papier"]`). Since the query is split on spaces (`["wc", "papier"]`), prefix-matching fails to resolve the separate word parts (the token does not start with `"papier"`). Modifying Shopware's PHP `preserved_chars` only affects database-based searches and does not influence the Elasticsearch indexing pipeline.

## 2. Executive Summary
This plan provides a clean, automated, system-wide solution utilizing a custom Symfony **Compiler Pass** within the `TopdataElasticsearchHacksSW6` plugin. 

Instead of writing complex and fragile entity mapping decorations, the Compiler Pass intercepts the container compilation process to modify the global `%elasticsearch.analysis%` configuration. It defines a custom `word_delimiter_graph` filter and dynamically injects it into all built-in language analyzers (such as `sw_german_analyzer` and `sw_english_analyzer`). 

This configures Elasticsearch to index hyphenated terms as multiple overlapping tokens simultaneously (`["wc-papier", "wc", "papier"]`). As a result, searching for either `"wc"`, `"papier"`, `"WC-Papier"`, or `"WC Papier"` will successfully match the product name.

## 3. Project Environment Details
* **Platform:** Shopware 6.7.*
* **Search Infrastructure:** Elasticsearch 7.8+ / OpenSearch 2.x / OpenSearch 3.x
* **PHP Version:** ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0
* **Plugin Directory Structure:**
  * `src/DependencyInjection/` (New)
  * `src/TopdataElasticsearchHacksSW6.php` (To modify)

---

## 4. Implementation Phases

### Phase 1: Create the Elasticsearch Compiler Pass
We will create a custom Compiler Pass class that injects a `word_delimiter_graph` filter and safely prepends it to existing language-specific filters (such as `lowercase`) to preserve letter casing for delimiter matching (e.g., camelCase splitting).

[NEW FILE] `src/DependencyInjection/ElasticsearchAnalysisCompilerPass.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\DependencyInjection;

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

        // 1. Define custom word delimiter filter to split on hyphens, slashes, and camelCase
        $analysis['filter']['topdata_word_delimiter'] = [
            'type' => 'word_delimiter_graph',
            'preserve_original' => true,
            'catenate_all' => true,
            'catenate_words' => true,
            'generate_word_parts' => true,
            'split_on_case_change' => true,
        ];

        // 2. Identify the default language analyzers used by Shopware
        $analyzersToModify = [
            'sw_german_analyzer',
            'sw_english_analyzer',
            'sw_default_analyzer',
        ];

        foreach ($analyzersToModify as $analyzerName) {
            // Initialize the analyzer structure if it hasn't been defined yet
            if (!isset($analysis['analyzer'][$analyzerName])) {
                $analysis['analyzer'][$analyzerName] = [
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => ['lowercase'],
                ];
            }

            $filters = $analysis['analyzer'][$analyzerName]['filter'] ?? [];
            if (!\in_array('topdata_word_delimiter', $filters, true)) {
                // The 'lowercase' filter must occur AFTER 'word_delimiter_graph'
                // to allow split_on_case_change (e.g. CamelCase) to match correctly.
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

### Phase 2: Register the Compiler Pass in the Plugin Bootstrap
We will update the main plugin class to register our newly created Compiler Pass during Symfony's container build process.

[MODIFY] `src/TopdataElasticsearchHacksSW6.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataElasticsearchHacksSW6\DependencyInjection\ElasticsearchAnalysisCompilerPass;

class TopdataElasticsearchHacksSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ElasticsearchAnalysisCompilerPass());
    }
}
```

### Phase 3: System Verification & Reindexing
Since index-level analyzer definitions cannot be updated on a live index, a complete index recreation is required.

1. Clear the system cache to compile the new container definitions:
   ```bash
   php bin/console cache:clear
   ```
2. Reset the current Elasticsearch indices to remove outdated schemas:
   ```bash
   php bin/console es:reset
   ```
3. Rebuild the indices with the updated mappings and custom analyzer configurations:
   ```bash
   php bin/console es:index
   ```

### Phase 4: Documentation Update
We will update the plugin README to describe the technical adjustments and the reindexing procedures required for administrators.

[MODIFY] `README.md`
```markdown
# Topdata Elasticsearch Hacks SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview
This plugin optimizes Elasticsearch tokenization on Shopware 6.7 to allow better matching on hyphenated or concatenated terms (such as `WC-Papier` matching `WC Papier`).

## Features
* Globally registers a `word_delimiter_graph` token filter in Elasticsearch settings.
* Overrides default language analyzers (`sw_german_analyzer`, `sw_english_analyzer`, `sw_default_analyzer`) to split terms dynamically without breaking default stemmers.

## Installation
1. Install and activate the plugin.
2. Clear the Symfony cache:
   ```bash
   php bin/console cache:clear
   ```
3. Reset and rebuild the Elasticsearch search indices to apply the updated mappings:
   ```bash
   php bin/console es:reset
   php bin/console es:index
   ```
```

---

## 5. Phase 5: Post-Implementation Report Generation
Upon completion, the executing agent must output the final summary of changes to `_ai/backlog/reports/260525_1515__IMPLEMENTATION_REPORT__elasticsearch_hyphen_search_fix.md`.
```

---

### Implementation Report

```markdown
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


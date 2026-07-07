# Topdata Better Search SW6

![Plugin Icon](src/Resources/config/plugin.png)

[![GitHub](https://img.shields.io/badge/GitHub-topdata--better--search--sw6-blue?logo=github)](https://github.com/topdata-software-gmbh/topdata-better-search-sw6)

## Overview

Topdata Better Search is a highly configurable search engine integration for Shopware 6.7. It decouples storefront search from any single provider, enabling you to leverage **Elasticsearch**, **Meilisearch**, **Qdrant**, or the **Shopware Core** engine.

The plugin routes search requests through a prioritized, multi-profile fallback chain. Only one backend ultimately handles and returns the results for a given query; **results from multiple backends are never merged or combined**, preserving pagination and ranking integrity.

Built on a clean service abstraction layer, the plugin decorates the native `ProductSearchRoute` and `ProductSuggestRoute` to intercept queries and execute them against the active backend chain.

## Features

* **🔌 Pluggable Search Profiles** — Configure distinct search pipelines and A/B test splits in yaml format.
* **⛓️ Prioritized Fallback Routing** — Evaluates active search backends in sequence per profile. The first backend that returns a non-null result set handles the query, with others acting as fallbacks.
* **📈 A/B Testing Suite** — Distribute customer queries across profiles and log query parameters, hits, and processing speeds.
* **❄️ Cache Variation Handling** — Employs cookie variations (`Vary: Cookie`) during active A/B tests to prevent reverse proxy cache collisions.
* **⚡ Elasticsearch Analyzer Optimization** — Globally registers a `word_delimiter_graph` token filter for better matching on hyphenated/concatenated terms (e.g., `WC-Papier` matching `WC Papier`).
* **📖 Synonym Management Suite** — Full CLI toolset to validate, import, export, list, delete, and clear synonym mappings.
* **🔍 Zero-Result Tracking** — Automatically logs storefront searches that return no results to a dedicated database table for analysis.
* **🚫 Category Search Exclusion** — Select categories in the plugin configuration to dynamically hide assigned products from search and suggestion results.
* **🧩 Symfony 7.4 Native Attributes** — Uses `#[AsDecorator]`, `#[TaggedIterator]`, `#[AutoconfigureTag]`, and `#[AsCommand]` throughout for service declaration.
* **🎨 Administration Module** — View and manage zero-result search terms directly in the Shopware admin panel.

## Fallback Execution Flow

The plugin evaluates backends sequentially using a chain-of-responsibility pattern:

1. The registry iterates through active backends based on compiler pass priority.
2. Each backend's `search()` method is called:
   * If a backend returns `null`, the query falls through to the next engine in the chain.
   * If a backend returns an array of matching product IDs (including an empty array `[]`), execution stops, and those IDs are used to filter the Shopware product collection.
3. If no custom backend handles the query, the native Shopware search mechanism is used.

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
    ├── semantic_hybrid.yaml  # Strategy 2 (Qdrant with fallbacks)
    └── prefix_completion.yaml # Strategy 3 (Elasticsearch with precise edge-ngram)
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
  elasticsearch:
    host: "http://localhost:9200"
    username: "elastic"     # Optional basic authentication
    password: "password"    # Optional basic authentication

ab_testing:
  enabled: true
  distribution:
    keyword_heavy: 30
    semantic_hybrid: 30
    prefix_completion: 40
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

#### Profile 3 (`config/tdbs/profiles/prefix_completion.yaml`)
```yaml
name: "Prefix Edge N-Gram Completion"
description: "Precision prefix autocomplete using standalone index-time edge-ngrams"
pipeline:
  - backend: elasticsearch
    options:
      index_name: "tdbs_products_prefix"
      limit: 25
      ngram:
        enabled: true
        type: "edge_ngram" # Options: edge_ngram | standard | none
        min_gram: 3
        max_gram: 6
        use_separate_search_analyzer: true # Decouples indexing and search analyzers to avoid false matches
  - backend: shopware_core
```

---

## Installation

1. Install and activate the plugin.
2. Run database migrations:
   ```bash
   php bin/console database:migrate TopdataBetterSearchSW6 --all
   ```
3. Clear the Symfony cache:
   ```bash
   php bin/console cache:clear
   ```

---

## Administration Module: Zero Search Results

**Navigation:** Content → Better Search → Search Terms  
**Route:** `topdata.better.search.list`  
**Access:** Requires privilege `system.zero_search.viewer`

The listing page shows all search terms that returned no products, with columns for Term, Count, Last Searched, and First Seen. Entries can be sorted, paginated, and deleted.

---

## Command Reference

All commands use the `tdbs:` prefix and output styled via `CliLogger` from `topdata/foundation-sw6`.

### Diagnostics & Testing

```bash
# Verify profile load success and connection health checks
php bin/console tdbs:status

# Query test directly from terminal using the first active profile
php bin/console tdbs:search "jacket"

# Query test specifying a custom profile strategy
php bin/console tdbs:search "jacket" --profile=semantic_hybrid

# Resolve and display product names for returned IDs
php bin/console tdbs:search "jacket" --profile=semantic_hybrid --resolve-products
```

### Index Management

```bash
# Rebuild indices for all configured custom search backends
php bin/console tdbs:index:rebuild --limit=100
```

### Synonym Management

```bash
# Validate a synonym mapping file
php bin/console tdbs:synonyms:validate synonyms.txt

# Dry-run import (validate without persisting)
php bin/console tdbs:synonyms:import synonyms.txt --dry-run

# Import synonym mappings
php bin/console tdbs:synonyms:import synonyms.txt

# List all synonym mappings
php bin/console tdbs:synonyms:list --limit=50
php bin/console tdbs:synonyms:list --filter="papier"

# Export to a file
php bin/console tdbs:synonyms:export backup.txt

# Delete a specific synonym
php bin/console tdbs:synonyms:delete "wc-papier"

# Clear all synonyms (with interactive confirmation)
php bin/console tdbs:synonyms:clear
php bin/console tdbs:synonyms:clear --force
```

---

## Search Abstraction Layer

The plugin introduces three core abstractions:

| Interface / Class | Purpose |
|---|---|
| `SearchBackendInterface` | Defines `search()` and `index()` contract |
| `SearchBackendRegistry` | Autowires all tagged backends via `#[TaggedIterator]` |
| `DecoratedProductSearchRoute` / `DecoratedProductSuggestRoute` | Intercepts storefront routes with `#[AsDecorator]` |

### Adding a Custom Backend

Create a class implementing `SearchBackendInterface` and tag it:

```php
#[AutoconfigureTag('tdbs.search_backend')]
class MyBackend implements SearchBackendInterface
{
    public function getName(): string { return 'my_backend'; }
    public function search(Criteria $c, SalesChannelContext $ctx): ?array { /* ... */ }
    public function index(array $products): void { /* ... */ }
}
```

The registry picks it up automatically — no service XML configuration needed.

---

## Requirements

- Shopware 6.7.*
- `topdata/foundation-sw6` ^1.0

## License

MIT
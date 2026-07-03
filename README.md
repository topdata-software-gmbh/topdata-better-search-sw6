# Topdata Better Search SW6

![Plugin Icon](src/Resources/config/plugin.png)

[![GitHub](https://img.shields.io/badge/GitHub-topdata--better--search--sw6-blue?logo=github)](https://github.com/topdata-software-gmbh/topdata-better-search-sw6)

## Overview

Topdata Better Search is a highly configurable search engine integration for Shopware 6.7. It decouples storefront search from any single provider, enabling you to leverage **Elasticsearch**, **Meilisearch**, **Qdrant**, or the **Shopware Core** engine.

The plugin routes search requests through a prioritized fallback chain. Only one backend ultimately handles and returns the results for a given query; **results from multiple backends are never merged or combined**, preserving pagination and ranking integrity.

Built on a clean service abstraction layer, the plugin decorates the native `ProductSearchRoute` and `ProductSuggestRoute` to intercept queries and execute them against the active backend chain.

## Features

* **🔌 Pluggable Search Backends** — Swap search engines via a clean `SearchBackendInterface`. Ships with stubs for Shopware Core, Meilisearch, and Qdrant.
* **⛓️ Prioritized Fallback Routing** — Evaluates active search backends in sequence. The first backend that returns a non-null result set handles the query, with others acting as fallbacks.
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
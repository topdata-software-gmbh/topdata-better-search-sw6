# Topdata Better Search SW6

![Plugin Icon](src/Resources/config/plugin.png)

[![GitHub](https://img.shields.io/badge/GitHub-topdata--better--search--sw6-blue?logo=github)](https://github.com/topdata-software-gmbh/topdata-better-search-sw6)

## Overview

Topdata Better Search is a highly configurable, multi-backend search engine for Shopware 6.7. It decouples storefront search from any single provider, enabling you to leverage **Elasticsearch**, **Meilisearch**, **Qdrant**, or the **Shopware Core** engine interchangeably — or in parallel.

Built on a clean service abstraction layer, the plugin decorates the native `ProductSearchRoute` and `ProductSuggestRoute`, intercepting queries before they reach the database and routing them through your configured search backends.

## Features

* **🔌 Pluggable Search Backends** — Swap search engines via a clean `SearchBackendInterface`. Ships with stubs for Shopware Core, Meilisearch, and Qdrant.
* **⚡ Elasticsearch Analyzer Optimization** — Globally registers a `word_delimiter_graph` token filter for better matching on hyphenated/concatenated terms (e.g., `WC-Papier` matching `WC Papier`).
* **📖 Synonym Management Suite** — Full CLI toolset to validate, import, export, list, delete, and clear synonym mappings.
* **🔍 Zero-Result Tracking** — Automatically logs storefront searches that return no results for analysis and optimization.
* **🚫 Category Search Exclusion** — Select categories in plugin configuration to dynamically hide assigned products from search and suggestion results.
* **🧩 Symfony 7.4 Native Attributes** — Uses `#[AsDecorator]`, `#[TaggedIterator]`, `#[AutoconfigureTag]`, and `#[AsCommand]` throughout — no boilerplate XML.
* **🎨 Administration Module** — View and manage zero-result search terms directly in the Shopware admin panel.

## Future Vision

This plugin is designed to grow into a unified search hub:

- **Multi-Backend Fallback** — Query backends in priority order; if one returns no results, fall through to the next.
- **Console-First Indexing** — `tdbs:index:rebuild` pushes product data to all configured custom backends.
- **Extensible** — Add new backends by implementing `SearchBackendInterface` and tagging with `#[AutoconfigureTag('tdbs.search_backend')]`.

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

The registry picks it up automatically — no service configuration needed.

---

## Requirements

- Shopware 6.7.*
- `topdata/foundation-sw6` ^1.0

## License

MIT

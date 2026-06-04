# Topdata Elasticsearch Hacks SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview
This plugin optimizes Elasticsearch tokenization on Shopware 6.7 to allow better matching on hyphenated or concatenated terms (such as `WC-Papier` matching `WC Papier`).

## Features
* Globally registers a `word_delimiter_graph` token filter in Elasticsearch settings.
* Overrides default language analyzers (`sw_german_analyzer`, `sw_english_analyzer`, `sw_default_analyzer`) to split terms dynamically without breaking default stemmers.
* **Synonym Suite**: Dynamically tracks failed storefront searches and offers a full suite of administrative CLI utilities to manage search synonym mappings.
* **Category Search Exclusion**: Select categories (e.g., "Gratisartikel") directly in the plugin configuration to dynamically hide all assigned products from Storefront search and suggestion results, without breaking their layout on regular category pages.

## Installation
1. Install and activate the plugin.
2. Run database migrations to construct tables:
   ```bash
   php bin/console database:migrate TopdataElasticsearchHacksSW6 --all
   ```
3. Clear the Symfony cache:
   ```bash
   php bin/console cache:clear
   ```
4. Reset and rebuild the Elasticsearch search indices to apply the updated mappings:
   ```bash
   php bin/console es:reset
   php bin/console es:index --no-queue
   php bin/console es:create:alias
   ```

---

## Administration Module: Zero Search Results

The plugin ships with an admin panel module to view and manage zero-result search terms directly in the Shopware administration.

* **Navigation:** Content → Zero Search Results
* **Route:** `topdata.es.zero.search.list`
* **Access:** Requires privilege `system.zero_search.viewer`

The listing page shows all search terms that returned no products, displaying:
* **Term** — the customer's search query
* **Count** — how many times the term failed
* **Last Searched / First Seen** — timestamps

Entries can be sorted, paginated, and deleted directly from the admin grid.

---

## Command Reference Guide: Synonym & Zero-Result Analytics

This plugin contains a comprehensive suite of console commands to help merchants audit, optimize, and organize search synonyms.

### 1. Identify Failed Searches (Zero-Result Terms)
Extract terms entered by customers that returned no matches, formatted directly for an LLM prompt:
```bash
# Print standard console table view of failures
php bin/console topdata:es-hacks:export-zero-results --limit=50 --min-count=2

# Export directly into a pre-formatted LLM copy-paste prompt file
php bin/console topdata:es-hacks:export-zero-results --format=llm-prompt --output=var/log/prompt.txt
```

### 2. Validate Synonym Mapping Files
Test a local synonyms text file for syntax, missing elements, or structural errors before committing changes to the database:
```bash
php bin/console topdata:es-hacks:validate-synonyms var/log/synonyms.txt
```

### 3. Dry-Run and Import Mappings
Import generated synonyms text files using the explicit mapping format (`term => synonym1, synonym2`):
```bash
# Perform validation checks without writing to the database
php bin/console topdata:es-hacks:import-synonyms var/log/synonyms.txt --dry-run

# Execute database import
php bin/console topdata:es-hacks:import-synonyms var/log/synonyms.txt
```

### 4. Search and List Registered Mappings
Inspect synonym entries currently configured in the database using filters and pagination:
```bash
# List all active mappings in a structured table
php bin/console topdata:es-hacks:list-synonyms --limit=50

# Filter active mappings by search criteria
php bin/console topdata:es-hacks:list-synonyms --filter="papier"
```

### 5. Export Mappings (Backups/Manual Audits)
Dump currently stored synonym mappings to a file for backup or local editing:
```bash
php bin/console topdata:es-hacks:export-synonyms --output=var/log/synonym_backup.txt
```

### 6. Delete a Specific Mapping
Remove a unique synonym configuration using its left-hand key search term:
```bash
php bin/console topdata:es-hacks:delete-synonym "wc-papier"
```

### 7. Clear All Synonym Definitions
Completely wipe all stored synonym records. Requires interactive confirmation unless forced:
```bash
php bin/console topdata:es-hacks:clear-synonyms
# Or bypass the confirmation prompt:
php bin/console topdata:es-hacks:clear-synonyms --force
```

---

## API Integration

Automated management scripts can retrieve zero-result queries via Shopware Admin API authentication:
* **Route:** `GET /api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export`
* **Query Parameters:**
  * `limit` (default: 100)
  * `minCount` (default: 1)
  * `format` (`json`, `csv`, `markdown`)

## Requirements

- Shopware 6.7.*

## License

MIT

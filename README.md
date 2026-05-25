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

## Requirements

- Shopware 6.7.*

## License

MIT

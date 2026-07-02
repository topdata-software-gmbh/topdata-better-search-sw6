---
filename: "_ai/backlog/reports/260702_1420__IMPLEMENTATION_REPORT__rebrand_and_abstract_to_better_search.md"
title: "Report: Transition to Generic Better Search Plugin with Service Abstraction"
createdAt: 2026-07-02 14:20
updatedAt: 2026-07-02 14:20
planFile: "_ai/backlog/active/260702_1420__IMPLEMENTATION_PLAN__rebrand_and_abstract_to_better_search.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 19
filesModified: 4
filesDeleted: 19
tags: [refactoring, migration, search-backend, abstraction, storefront-routes, shopware6.7]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Transition to Generic Better Search Plugin

## Summary

Successfully completed all 8 phases of the plan to rebrand and abstract the plugin from `TopdataElasticsearchHacksSW6` to `TopdataBetterSearchSW6`.

## Files Deleted (19)

- `src/TopdataElasticsearchHacksSW6.php` — old plugin entry point
- `src/Migration/Migration1716652800CreateZeroSearchTable.php` — old migration
- `src/Entity/ZeroSearch/ZeroSearchEntityDefinition.php` — old entity definition
- `src/Entity/ZeroSearch/ZeroSearchCollection.php` — old collection (replaced)
- `src/Entity/ZeroSearch/ZeroSearchEntity.php` — old entity (replaced)
- `src/Subscriber/ProductSearchSubscriber.php` — replaced by route decoration
- `src/Subscriber/SearchCriteriaSubscriber.php` — replaced by route decoration
- `src/Command/Command_ClearSynonyms.php`, `Command_DeleteSynonym.php`, `Command_ExportSynonyms.php`, `Command_ImportSynonyms.php`, `Command_ListSynonyms.php`, `Command_ValidateSynonyms.php` — old commands
- `src/Command/ExampleCommand.php` — example code
- `src/Controller/AdminApiExampleController.php`, `StorefrontExampleController.php` — example controllers
- `src/Resources/views/storefront/example.html.twig` — example template
- `src/Resources/app/administration/src/module/topdata-es-zero-search/page/zero-search-list/index.ts`, `index.ts` — old admin module
- `src/Resources/app/administration/src/snippet/de-DE.json`, `en-GB.json` — old snippets (replaced)

## Files Created (19)

### Phase 2: Core Base
- `composer.json` — updated with new name, deps (`topdata/foundation-sw6`), autoload
- `src/TopdataBetterSearchSW6.php` — new rebranded plugin entry point with compiler pass
- `src/DependencyInjection/ElasticsearchAnalysisCompilerPass.php` — updated namespace

### Phase 3: DB Schema & Entities
- `src/Migration/Migration1800000000CreateBetterSearchTables.php` — creates `tdbs_zero_search` and `tdbs_synonym` with data migration
- `src/Entity/ZeroSearch/ZeroSearchDefinition.php` — DAL definition with `#[Entity]` attribute
- `src/Entity/ZeroSearch/ZeroSearchEntity.php` — entity class
- `src/Entity/ZeroSearch/ZeroSearchCollection.php` — collection class

### Phase 4: Search Abstraction Layer
- `src/Service/SearchBackendInterface.php` — interface with `search()` and `index()`
- `src/Service/SearchBackendRegistry.php` — backend registry using `#[TaggedIterator]`
- `src/Service/Backend/ShopwareCoreBackend.php` — stub backend
- `src/Service/Backend/MeilisearchBackend.php` — stub backend
- `src/Service/Backend/QdrantBackend.php` — stub backend

### Phase 5: Route Decoration
- `src/Route/DecoratedProductSearchRoute.php` — decorates `AbstractProductSearchRoute` with `#[AsDecorator]`
- `src/Route/DecoratedProductSuggestRoute.php` — decorates `AbstractProductSuggestRoute`

### Phase 6: Console Commands
- `src/Command/RebuildIndexCommand.php` — `tdbs:index:rebuild`
- `src/Command/ClearSynonymsCommand.php` — `tdbs:synonyms:clear`
- `src/Command/DeleteSynonymCommand.php` — `tdbs:synonyms:delete`
- `src/Command/ExportSynonymsCommand.php` — `tdbs:synonyms:export`
- `src/Command/ImportSynonymsCommand.php` — `tdbs:synonyms:import`
- `src/Command/ListSynonymsCommand.php` — `tdbs:synonyms:list`
- `src/Command/ValidateSynonymsCommand.php` — `tdbs:synonyms:validate`

### Phase 7: Administration UI
- `src/Resources/app/administration/src/module/topdata-better-search/index.ts` — new module
- `src/Resources/app/administration/src/module/topdata-better-search/page/better-search-list/index.ts` — list component
- `src/Resources/app/administration/src/snippet/de-DE.json` — German snippets
- `src/Resources/app/administration/src/snippet/en-GB.json` — English snippets
- `src/Resources/snippet/storefront.de-DE.json` — storefront German snippets
- `src/Resources/snippet/storefront.en-GB.json` — storefront English snippets

## Files Modified (4)

- `src/Service/SynonymService.php` — updated namespace and table name to `tdbs_synonym`
- `src/Resources/app/administration/src/main.ts` — updated import path
- `src/Resources/config/services.xml` — replaced with autodiscovery prototype + entity definition
- `src/DependencyInjection/ElasticsearchAnalysisCompilerPass.php` — updated namespace

## Key Technical Decisions

1. **Route Decoration over Subscribers**: Used `#[AsDecorator]` on `AbstractProductSearchRoute` and `AbstractProductSuggestRoute` instead of event subscribers, providing cleaner interception of storefront searches.
2. **Tagged Iterators**: `SearchBackendRegistry` uses `#[TaggedIterator('tdbs.search_backend')]` for zero-boilerplate DI registration of backends.
3. **Autoconfigure Tags**: All backend implementations use `#[AutoconfigureTag('tdbs.search_backend')]` rather than XML service definitions.
4. **Foundation Base Class**: All console commands extend `TopdataFoundationSW6` and use `CliLogger` for consistent output styling.
5. **Data Migration**: The migration silently copies data from old `topdata_es_*` tables to new `tdbs_*` tables with `INSERT IGNORE`.

---
filename: "_ai/backlog/active/260526_1018__IMPLEMENTATION_PLAN__exclude-categories-from-search.md"
title: "Exclude specific categories from search results"
createdAt: 2026-05-26 10:18
updatedAt: 2026-05-26 10:18
status: draft
priority: medium
tags: [elasticsearch, search, shopware, categories]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Statement
A new functionality is required to hide products belonging to specific categories (e.g., "Gratisartikel" / Free items) from Storefront search results. The request suggests using a list of categories in the plugin configuration to define these exclusions easily.

## 2. Executive Summary
While the request mentions excluding categories from "ES indexing", forcefully dropping entities from the Shopware Elasticsearch index process is highly discouraged and destructive. Shopware 6 relies on the same Elasticsearch index to render standard category listing pages. If products are excluded from indexing completely, they will be broken and disappear entirely from their own valid "Gratisartikel" category pages.

To cleanly and safely achieve the core business requirement ("products from this category should not be found with the search"), we will implement **query exclusion**:
1. We will add a `sw-entity-multi-id-select` component to the plugin's `config.xml`, allowing merchants to seamlessly pick multiple categories to exclude from search.
2. We will register a `SearchCriteriaSubscriber` that intercepts Storefront search and suggestion events (`ProductSearchCriteriaEvent`, `ProductSuggestCriteriaEvent`).
3. The subscriber will inject a `NotFilter` against the product's `categoryTree` into the search criteria. 

This guarantees the products will not surface via searches, while remaining perfectly functional and visible on category navigation pages.

## 3. Project Environment
- Shopware 6.7.*
- Plugin: `TopdataElasticsearchHacksSW6`
- Core architecture relies on Symfony Event Subscribers and Shopware DAL Search Criteria.

## 4. Implementation Steps

### Step 1: Update Plugin Configuration
We will add a multi-select field for categories in the plugin configuration, replacing the unused `example` input field.

**File:** `src/Resources/config/config.xml`
**Action:** [MODIFY]
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Basic Configuration</title>
        <title lang="de-DE">Grundeinstellungen</title>
        
        <component name="sw-entity-multi-id-select">
            <name>excludedCategories</name>
            <entity>category</entity>
            <label>Excluded Categories from Search</label>
            <label lang="de-DE">Von der Suche ausgeschlossene Kategorien</label>
            <helpText>Products assigned to these categories will not be found via the storefront search.</helpText>
            <helpText lang="de-DE">Produkte, die diesen Kategorien zugeordnet sind, werden nicht über die Storefront-Suche gefunden.</helpText>
        </component>
    </card>
</config>
```

### Step 2: Create the Search Event Subscriber
This subscriber intercepts standard search events and applies a `NotFilter` using the configurations we set up.

**File:** `src/Subscriber/SearchCriteriaSubscriber.php`
**Action:** [NEW FILE]
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SearchCriteriaSubscriber implements EventSubscriberInterface
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchCriteriaEvent::class => 'onSearch',
            ProductSuggestCriteriaEvent::class => 'onSearch',
        ];
    }

    /**
     * @param ProductSearchCriteriaEvent|ProductSuggestCriteriaEvent $event
     */
    public function onSearch($event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        
        // Retrieve the configured excluded category IDs
        $excludedCategories = $this->systemConfigService->get('TopdataElasticsearchHacksSW6.config.excludedCategories', $salesChannelId);

        if (empty($excludedCategories) || !\is_array($excludedCategories)) {
            return;
        }

        // Apply a NotFilter matching the 'categoryTree' property.
        // The categoryTree contains the UUIDs of all assigned categories and their parent categories.
        $criteria = $event->getCriteria();
        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [
                    new EqualsAnyFilter('categoryTree', $excludedCategories)
                ]
            )
        );
    }
}
```

### Step 3: Register Subscriber in Services
Register the new subscriber into the Symfony Dependency Injection container.

**File:** `src/Resources/config/services.xml`
**Action:** [MODIFY]
Add the new service under the other subscribers in the `<services>` block.

```xml
        <!-- Core Business Logic Services -->
        <!-- ... existing definitions ... -->

        <!-- Storefront Search Fail Aggregation Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\ProductSearchSubscriber">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Dynamic Storefront Search Exclusion Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\SearchCriteriaSubscriber">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <tag name="kernel.event_subscriber"/>
        </service>
```

### Step 4: Update Documentation
Reflect the new capability in the plugin's `README.md`.

**File:** `README.md`
**Action:** [MODIFY]
Under the `## Features` section, add a new bullet point:
```markdown
* **Category Search Exclusion**: Select categories (e.g., "Gratisartikel") directly in the plugin configuration to dynamically hide all assigned products from Storefront search and suggestion results, without breaking their layout on regular category pages.
```

---

## 5. Report Writing
Once the implementation is finalized, an execution report will be compiled.

```yaml
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

...


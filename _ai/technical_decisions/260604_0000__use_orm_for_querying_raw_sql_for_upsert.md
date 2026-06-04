# ADR: Use ORM for Querying, Raw SQL for Upsert

**Date:** 2026-06-04
**Status:** Accepted
**Context:** topdata-elasticsearch-hacks-sw6

## Decision

The `topdata_es_zero_search` table uses a **hybrid approach**:
- **Entity definition (ORM)** for all **read paths** — admin API, admin module listing, future statistics
- **Raw SQL upsert** (`ON DUPLICATE KEY UPDATE`) for **writes** in `ProductSearchSubscriber`

## Rationale

### Writes (storefront hot path)
- The `ProductSearchSubscriber` fires on **every storefront search with zero results**
- The MySQL `ON DUPLICATE KEY UPDATE` is a **single round-trip** — no read-before-write
- The ORM alternative (search → create/update) would be **2 queries** on every zero-result search
- The raw SQL is encapsulated in one subscriber; if the schema or write logic changes, it's a single file to update

### Reads (admin panel)
- The entity definition auto-exposes the data via Shopware's admin API (`POST /api/search/topdata-es-zero-search`) with pagination, filtering, sorting, and aggregations
- The admin module uses `sw-entity-listing` directly, no custom controller needed
- Future "most searched" statistics benefit from ORM criteria/aggregations without any custom SQL

### Trade-offs
- **Write path bypasses DAL events** — no entity written events, no change log. Acceptable for a simple counter table with no relations.
- **Schema must stay in sync** — the migration and entity definition must agree on columns. Verified at migration time.

## Alternatives Considered

| Approach | Pros | Cons |
|----------|------|------|
| Full ORM (entity repo for writes too) | Consistent, event-driven | 2 queries on storefront hot path |
| Full raw SQL everywhere | Simple, fast | No admin API, no admin module, manual pagination |
| **Hybrid (chosen)** | Fast writes + free admin API | Two access patterns to maintain |

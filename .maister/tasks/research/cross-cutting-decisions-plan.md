# Cross-Cutting Architectural Decisions — Implementation Plan

**Date**: 2026-04-20
**Source**: Cross-cutting assessment §3 (partially divergent patterns)
**Status**: ✅ All 8 decisions made — ready for implementation

---

## Overview

This plan resolves all ⚠️ partially divergent patterns from the cross-cutting assessment.
Each decision becomes either a **standard file** (for patterns affecting all services) or an **ADR update** (for service-specific changes).

---

## Decision 1: Cache Integration — Centralized, No Exceptions

**Decision**: All services MUST use the new `phpbb\cache` service via `TagAwareCacheInterface`. No per-service custom caching. No exceptions.

### Changes Required

| File / Service | Change |
|----------------|--------|
| `.maister/docs/standards/backend/STANDARDS.md` | Add "Cache Integration" section mandating `TagAwareCacheInterface` |
| Auth HLD | Replace "own file cache for role cache" → use Cache Service pool `cache.auth` |
| Hierarchy HLD | Add cache pool `cache.hierarchy` for nested-set tree |
| Threads HLD | Add cache pool `cache.threads` for rendered content |
| Messaging HLD | Add cache pool `cache.messaging` for conversation metadata |
| Notifications HLD | Already compliant ✅ |
| Storage HLD | Add cache pool `cache.storage` for quota lookups |

### Standard Pattern

```php
// Every service receives TagAwareCacheInterface via DI
public function __construct(
    private readonly TagAwareCacheInterface $cache,
) {}

// Pool isolation via service YAML config
services:
    phpbb.hierarchy.service:
        arguments:
            $cache: '@cache.hierarchy'
```

### Pool Naming Convention

`cache.{service_name}` — e.g., `cache.auth`, `cache.hierarchy`, `cache.threads`

---

## Decision 2: ID Strategy — Autoincrement + UUID Transition

**Decision**: Keep integer autoincrement PKs for backward compatibility. Introduce UUID v7 columns alongside PKs. Transition to UUID as external identifiers over time.

### Changes Required

| File / Service | Change |
|----------------|--------|
| `.maister/docs/standards/backend/STANDARDS.md` | Add "ID Strategy" section |
| Storage HLD | Already uses UUID v7 ✅ — remains the pioneer |
| All other service HLDs | Add `uuid BINARY(16) NOT NULL` column to primary entities |

### Standard Pattern

```sql
-- Existing tables: ADD column, backfill later
ALTER TABLE phpbb_topics ADD COLUMN uuid BINARY(16) NOT NULL DEFAULT (UUID_TO_BIN(UUID(), 1));
CREATE UNIQUE INDEX idx_topics_uuid ON phpbb_topics(uuid);

-- New tables (Messaging, Storage): UUID v7 as PK or secondary unique
```

### Migration Path

1. **Phase A**: Add `uuid` columns to existing tables (non-breaking)
2. **Phase B**: REST API returns UUID as resource identifier (integer remains internal)
3. **Phase C**: New services use UUID for cross-service references
4. **No Phase D** — integer PKs remain for DB joins permanently

---

## Decision 3: Schema Strategy — Reuse Existing Tables, Backward Compatibility

**Decision**: Reuse existing `phpbb_*` tables wherever possible. Forums MUST work on old schema. New tables only for genuinely new features (messaging conversations, stored_files).

### Changes Required

| File / Service | Change |
|----------------|--------|
| `.maister/docs/standards/backend/STANDARDS.md` | Add "Schema Strategy" section |
| Messaging HLD | New tables OK (redesigned feature) — document migration path from `phpbb_privmsgs` |
| Storage HLD | New `phpbb_stored_files` OK — document migration from `phpbb_attachments` |
| All reuse-table services | Explicitly document which legacy columns are kept/ignored |

### Rules

1. **Existing tables**: Add columns, never drop columns. Unused columns get ignored in PHP code.
2. **New tables**: Only when the feature is fundamentally redesigned (e.g., messaging conversations).
3. **Table prefix**: All services must respect the `$table_prefix` config (default `phpbb_`).
4. **Zero-downtime**: Schema changes must be additive (ALTER TABLE ADD COLUMN, CREATE INDEX).

---

## Decision 4: Shared Package — `phpbb\common`

**Decision**: Create `phpbb\common` package for shared cross-cutting code: exceptions, configurations, value objects, and contracts.

### Package Structure

```
src/phpbb/common/
├── Exception/
│   ├── PhpbbException.php              # Base exception (all services extend)
│   ├── NotFoundException.php           # 404 — entity not found
│   ├── AccessDeniedException.php       # 403 — permission denied
│   ├── ValidationException.php         # 422 — input validation failed
│   ├── ConflictException.php           # 409 — state conflict
│   └── RateLimitException.php          # 429 — rate limited
├── Config/
│   ├── ConfigProviderInterface.php     # Read config values
│   └── ConfigKeys.php                  # Shared config key constants
├── Event/
│   ├── DomainEvent.php                 # Base domain event class
│   └── DomainEventCollection.php       # Collection returned by mutations
├── ValueObject/
│   ├── UserId.php                      # Typed user ID wrapper
│   └── Pagination.php                  # Shared pagination VO
└── Contract/
    ├── CacheableInterface.php          # Marks entities as cacheable
    └── AuditableInterface.php          # Marks entities for audit logging
```

### HTTP Error Mapping Convention

| Exception | HTTP Status | JSON Error Code |
|-----------|-------------|-----------------|
| `NotFoundException` | 404 | `not_found` |
| `AccessDeniedException` | 403 | `access_denied` |
| `ValidationException` | 422 | `validation_error` |
| `ConflictException` | 409 | `conflict` |
| `RateLimitException` | 429 | `rate_limited` |
| `PhpbbException` (catchall) | 500 | `internal_error` |

### Changes Required

| File / Service | Change |
|----------------|--------|
| Create `src/phpbb/common/` package | New files (see structure above) |
| `.maister/docs/standards/backend/STANDARDS.md` | Add "Exceptions" and "Configuration" sections |
| All service HLDs | Replace per-service exceptions with `phpbb\common\Exception\*` |
| REST API | Add `ExceptionSubscriber` mapping `phpbb\common\Exception\*` → HTTP responses |

---

## Decision 5: Counter Management — Normalized Standard

**Decision**: Threads "hybrid tiered" and Messaging "tiered hot+cold" are the same pattern. Normalize into a single named standard: **Tiered Counter Pattern**.

### Standard: Tiered Counter Pattern

```
┌─────────────┐     write-through     ┌───────────────┐     periodic flush     ┌──────────────┐
│  Hot Counter │  ←─────────────────── │  Cache Layer   │  ──────────────────►  │  Cold Counter │
│  (in-memory) │                       │  (per-request) │                       │  (DB column)  │
└─────────────┘                        └───────────────┘                        └──────────────┘
```

**Levels:**
1. **Hot**: In-cache counter, incremented on every mutation. Source of truth for reads.
2. **Cold**: DB column, flushed periodically (configurable interval, default: every N requests or M seconds).
3. **Recalculation**: Background job rebuilds cold counters from source data for self-healing.

### Standard File to Create

`.maister/docs/standards/backend/COUNTER_PATTERN.md` — with the above definition plus:
- When to use (any denormalized count column)
- Flush strategies (request-count threshold vs time-based vs hybrid)
- Recalculation job contract
- Cache key naming: `counter.{entity}.{id}.{field}` (e.g., `counter.forum.42.topic_count`)

### Changes Required

| File / Service | Change |
|----------------|--------|
| Create `.maister/docs/standards/backend/COUNTER_PATTERN.md` | New standard file |
| `.maister/docs/INDEX.md` | Add counter pattern to index |
| Threads HLD | Reference counter pattern standard instead of inline "hybrid tiered" |
| Messaging HLD | Reference counter pattern standard instead of inline "tiered hot+cold" |
| Hierarchy HLD | Reference counter pattern for forum stats counters |

---

## Decision 6: Domain Events as Returns — Standard

**Decision**: ALL service mutations MUST return domain events. This is a project-wide standard.

### Standard: Domain Event Returns

```php
// Every mutation method returns DomainEventCollection
public function createTopic(int $forumId, string $title, string $body): DomainEventCollection
{
    // ... create topic ...
    
    return new DomainEventCollection([
        new TopicCreatedEvent($topicId, $forumId, $userId),
    ]);
}
```

### Rules

1. **All mutations** (create, update, delete) return `DomainEventCollection` from `phpbb\common\Event\`
2. **Queries** (get, list, search) return entities/DTOs as before — no events
3. **Controller dispatches events** after successful response — service layer does NOT dispatch
4. **Event naming**: `{Entity}{Action}Event` — e.g., `TopicCreatedEvent`, `PostDeletedEvent`, `MessageSentEvent`
5. **Event base class**: `phpbb\common\Event\DomainEvent` with `occurredAt`, `actorId`, `entityId`

### Standard File to Create

`.maister/docs/standards/backend/DOMAIN_EVENTS.md` — with:
- Return convention (DomainEventCollection)
- Event class structure
- Dispatch responsibility (controller, not service)
- Naming convention
- Payload contract (minimum: entityId, actorId, occurredAt)

### Changes Required

| File / Service | Change |
|----------------|--------|
| Create `.maister/docs/standards/backend/DOMAIN_EVENTS.md` | New standard file |
| Create `src/phpbb/common/Event/DomainEvent.php` | Base event class |
| Create `src/phpbb/common/Event/DomainEventCollection.php` | Collection class |
| `.maister/docs/INDEX.md` | Add domain events to index |
| Messaging HLD | Update return types to `DomainEventCollection` |
| Notifications HLD | Update return types to `DomainEventCollection` |
| Hierarchy HLD | Already returns events ✅ — verify consistency |
| Threads HLD | Already returns events ✅ — verify consistency |

---

## Decision 7: Content Storage — s9e XML Default + Encoding Engine Field

**Decision**: Keep s9e XML as the default storage format. Add `encoding_engine` column to `phpbb_posts` for future migration to alternative formats.

### Schema Change

```sql
ALTER TABLE phpbb_posts ADD COLUMN encoding_engine VARCHAR(16) NOT NULL DEFAULT 's9e';
-- Possible future values: 's9e', 'raw', 'markdown', 'html'
```

### ContentPipeline Behavior

```php
// ContentPipeline reads encoding_engine to determine processing:
match ($post->encodingEngine) {
    's9e' => $this->s9eRenderer->render($post->postText),
    'raw' => $this->bbcodeParser->parse($post->postText),
    // Future formats added here
};
```

### Changes Required

| File / Service | Change |
|----------------|--------|
| Threads HLD ADR-001 | **Amend**: raw text storage → s9e XML default with encoding_engine field. Update ContentPipeline design. |
| `TODO-content-storage-migration.md` | Mark as ✅ RESOLVED |
| Cross-cutting assessment §7.4 | Mark as ✅ RESOLVED |

### Impact

- **No bulk data migration needed** — existing s9e XML posts remain valid
- **New posts** default to `s9e` encoding engine
- **Future migration** possible per-post (change encoding_engine + rewrite post_text)
- **ContentPipeline** becomes format-aware via encoding_engine column

---

## Decision 8: Forum Counter Contract — Event-Driven

**Decision**: Threads emits events. Hierarchy consumes them. Threads is completely unaware of Hierarchy. Event-driven, eventual consistency.

### Contract

```
Threads Service                          Hierarchy Service
─────────────────                        ─────────────────
createTopic() →                          
  returns TopicCreatedEvent  ──event──►  onTopicCreated():
                                            forum.topic_count++
                                            forum.last_post_*

createPost() →
  returns PostCreatedEvent   ──event──►  onPostCreated():
                                            forum.post_count++
                                            forum.last_post_*

deleteTopic() →
  returns TopicDeletedEvent  ──event──►  onTopicDeleted():
                                            forum.topic_count--
                                            recalculate last_post
```

### Rules

1. **Threads never imports Hierarchy** — zero coupling
2. **Hierarchy registers event listeners** for `TopicCreatedEvent`, `PostCreatedEvent`, `TopicDeletedEvent`, `PostDeletedEvent`
3. **Eventual consistency** accepted — counters may be stale for a request cycle
4. **Self-healing**: `recalculateForumStats()` cron job queries Threads data directly (SQL COUNT)
5. **Transaction boundary**: Each service owns its own transaction. No distributed transactions.

### Changes Required

| File / Service | Change |
|----------------|--------|
| Threads HLD | Remove `$hierarchyService->updateForumStats()` calls. Emit events only. |
| Hierarchy HLD | Add `ForumStatsSubscriber` listening for Thread events. Add `recalculateForumStats()` method. |
| `TODO-forum-counter-contract.md` | Mark as ✅ RESOLVED |
| Cross-cutting assessment §7.5 | Mark as ✅ RESOLVED |

---

## Implementation Priority

| Order | Decision | Effort | Blocking |
|-------|----------|--------|----------|
| **1** | D4: `phpbb\common` package | Medium | All services (base exceptions, events) |
| **2** | D6: Domain events standard | Small | Standard file + base classes in D4 |
| **3** | D5: Counter pattern standard | Small | Standard file only |
| **4** | D1: Cache integration | Medium | Update all HLDs |
| **5** | D2: ID strategy | Medium | Schema changes |
| **6** | D3: Schema strategy | Small | Standard doc only |
| **7** | D7: Content storage | Medium | Threads HLD amendment |
| **8** | D8: Forum counters | Medium | Threads + Hierarchy HLD updates |

D1–D6 are **standard definitions** (documentation). D7–D8 require **HLD amendments**.

---

## Applicable Standards

- [Global Standards](../../docs/standards/global/STANDARDS.md) — naming, formatting, file structure
- [Backend Standards](../../docs/standards/backend/STANDARDS.md) — DI, types, namespacing, SQL safety
- [Services Architecture](../../docs/project/services-architecture.md) — shared patterns, implementation order

## Standards Compliance Checklist

- [x] All new code under `phpbb\` namespace (PSR-4)
- [x] Symfony DI via constructor injection
- [x] PDO prepared statements for all DB access
- [x] PHP 8.2+ features (readonly, enums, match)
- [x] Tabs for indentation, UNIX LF
- [x] `declare(strict_types=1)` in all new files
- [x] No closing `?>` tag
- [x] Domain events via Symfony EventDispatcher

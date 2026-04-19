# Cache Service Integration Needs — Cross-Service Analysis

## Sources Investigated

| Service HLD | File | Status |
|---|---|---|
| Threads | `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` | ✅ Full read |
| Messaging | `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md` | ✅ Full read |
| Storage | `.maister/tasks/research/2026-04-19-storage-service/outputs/high-level-design.md` | ✅ Full read |
| Auth | `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` | ✅ Full read |
| Hierarchy | `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` | ✅ Full read |

---

## 1. Per-Service Cache Requirements

### 1.1 Threads Service (`phpbb\threads`)

**Source**: threads HLD, ADR-001, ADR-004, Content Pipeline section, Counter Management section

#### Data to cache

| Data | Nature | Evidence |
|---|---|---|
| **Rendered HTML from ContentPipeline** | Computed output — raw text → HTML via ordered plugin chain | "Content storage is **raw text only** — a single `post_text` column with no cached HTML or metadata; the content pipeline runs full parse+render on every display (future Redis/file cache will optimize)" (HLD overview). "Executes on EVERY display — no persistent cache in core. Future: Redis/file cache wraps this method externally." (ContentPipelineInterface::render docblock) |
| **Denormalized counters** (topic_posts, forum_posts, num_posts, num_topics) | Hot counters — synchronous in-transaction updates | ADR-004: "Hybrid tiered counters — topic/forum counters synchronous in-transaction, user counters via events" |
| **Topic/forum listing query results** | Query result cache — short-lived | TopicEditedEvent, TopicMovedEvent, TopicTypeChangedEvent all list "CacheInvalidation" as a consumer |

#### TTL ranges

| Data | Suggested TTL | Rationale |
|---|---|---|
| Rendered HTML | 5–60 min | Content changes only on edit (rare); pipeline is expensive; "future Redis/file cache" — medium TTL, invalidate on PostEditedEvent |
| Forum topic listings | 10–60 sec | High frequency reads, frequent writes (new posts) |
| Topic view (posts) | 30–120 sec | Read-heavy, invalidated on new reply/edit |

#### Invalidation strategy

- **Content pipeline cache**: Event-driven invalidation on `PostEditedEvent`, `PostHardDeletedEvent`. Key: `(post_id, viewing_user_id?)` or simpler `(post_id)` if per-user rendering differences are minimal.
- **Counter caches**: NOT cached in external cache — counters are denormalized in DB rows synchronously. Safety net via periodic reconciliation cron. No external cache involvement.
- **Query result cache**: Tag-based. Tag `forum:{forum_id}` → invalidate on any write in the forum. Tag `topic:{topic_id}` → invalidate on any post change.

#### Namespace / isolation need

- `threads:content:{post_id}` — rendered content cache
- `threads:query:forum_topics:{forum_id}:{page}` — query result cache
- `threads:query:topic_posts:{topic_id}:{page}` — query result cache

#### Key observations

1. **Threads counters do NOT need external cache** — they are materialized in DB via synchronous in-transaction increment/decrement. The "tiered" part is about user counters going via events to `phpbb\user`, not about a cache layer.
2. **ContentPipeline rendering IS the major cache opportunity** — currently explicitly designed as "no persistent cache in core, future Redis/file cache wraps externally." This is the primary consumer pattern for a cache service from threads.
3. Domain events already list "CacheInvalidation" as a consumer for `TopicEditedEvent`, `TopicMovedEvent`, `TopicTypeChangedEvent` — confirming the threads team expects an external cache listener.

---

### 1.2 Messaging Service (`phpbb\messaging`)

**Source**: messaging HLD sections 4–11

#### Data to cache

| Data | Nature | Evidence |
|---|---|---|
| **Unread counters** (`unread_conversations`, `unread_messages`) | Hot counters with tiered strategy | `messaging_counters` table + "Hot counters: Updated atomically via event listeners. Reconciled by cron." (table definition comment). ADR-007: "Tiered hot+cold (event-driven hot, cron-reconciled cold)" |
| **Conversation list** | Query result — user's inbox | `getConversations()` is "Very high" frequency, target <10ms (Performance §11.1) |
| **Unread conversation indicators** | Per-user computed state | Derived from cursors + sparse overrides (query in §11.4) |
| **Participant previews** | Small associated data loaded per conversation | "Load participant preview (first 3-5 participants per conversation)" (flow §5.3) |
| **Rule sets** | Per-user configuration | Loaded on every `MessageDelivered` event for rule evaluation |

#### TTL ranges

| Data | Suggested TTL | Rationale |
|---|---|---|
| Unread counters | Real-time (no TTL, event-driven updates) | These live in `messaging_counters` DB table, updated atomically via events. Could be cached in-memory/Redis with event-driven invalidation for latency. |
| Conversation list | 5–30 sec | Very high frequency reads, moderate writes |
| User rules | 5–60 min | Changes are rare (user explicitly updates rules) |
| Participant previews | 1–5 min | Changes rare (add/remove participant) |

#### Invalidation strategy

- **Counters**: Event-driven. `MessageDelivered` → increment, `MessageRead` → decrement. These are already atomic DB operations; caching would only reduce read latency.
- **Conversation list**: Event-driven invalidation per-user. `MessageDelivered`, `ConversationCreated`, `ConversationStateChanged` → invalidate `messaging:conversations:{user_id}`.
- **Rules**: Invalidate on `RuleAdded`, `RuleUpdated`, `RuleDeleted`, `RulesReordered`.

#### Namespace / isolation need

- `messaging:counters:{user_id}` — unread counts
- `messaging:conversations:{user_id}:{state}:{cursor}` — conversation list pages
- `messaging:rules:{user_id}` — user rule set
- `messaging:participants:{conversation_id}` — participant list

#### Key observations

1. **Messaging counters use a dedicated DB table** (`messaging_counters`), NOT a generic cache service. The counter values are the source of truth. A cache service could provide a **read-through cache** on top of this table to avoid DB roundtrips for hot paths (navbar unread badge).
2. **Counter reconciliation** is already built in via cron at `messaging_counter_reconcile_interval = 86400` seconds.
3. The messaging service's "hot counter" pattern is structurally identical to the threads service's counter pattern — both use DB-materialized counters with event-driven atomic updates and periodic reconciliation. Neither currently imagines a Redis counter.

---

### 1.3 Storage Service (`phpbb\storage`)

**Source**: storage HLD, QuotaService, OrphanService sections

#### Data to cache

| Data | Nature | Evidence |
|---|---|---|
| **Quota state** (`used_bytes`, `max_bytes`) | Counter-like — atomic operations | `QuotaService` with `checkQuota()`, `incrementUsage()`, `decrementUsage()`, `reconcile()`. Atomic `UPDATE WHERE used_bytes + size <= max_bytes` pattern. |
| **File metadata** (StoredFile entity) | Read-heavy lookup by ID | `retrieve()` returns `StoredFile` entity from DB; called on every download/URL generation |
| **URL generation results** | Computed per file | `getUrl()` constructs URL from file metadata + config; deterministic |
| **Allowed extensions / config** | Static configuration | Loaded from config on every `store()` call |

#### TTL ranges

| Data | Suggested TTL | Rationale |
|---|---|---|
| Quota state | No TTL — DB is source of truth; cache for read optimization only | Atomic DB operations ensure correctness; cache reduces read latency for `checkQuota()` pre-flight |
| File metadata | 5–60 min | Rarely changes after creation; deleted files invalidate |
| URL results | 1–24 hours | URLs are deterministic from metadata + config; only change if config changes |
| Extension whitelist | Until config change | Static config, invalidate on admin settings change |

#### Invalidation strategy

- **Quota**: Event-driven. `FileStoredEvent` → increment, `FileDeletedEvent` → decrement. `QuotaReconciledEvent` → flush cached value.
- **File metadata**: Invalidate on `FileDeletedEvent`, `FileClaimedEvent`.
- **Config/extensions**: Invalidate on admin config change event.

#### Namespace / isolation need

- `storage:file:{file_id}` — file metadata
- `storage:quota:{scope}:{scope_id}` — quota state
- `storage:url:{file_id}:{variant?}` — generated URLs
- `storage:config:allowed_extensions` — config cache

#### Key observations

1. **Quota is DB-materialized** (like messaging/threads counters) — the `phpbb_storage_quotas` table uses atomic UPDATE-with-condition. Cache service adds read-through optimization only.
2. **File metadata caching is straightforward** — immutable after creation, deleted on removal. Classic get-or-compute + event-driven invalidation.
3. Storage is a **lower-volume** cache consumer compared to threads/messaging.

---

### 1.4 Auth Service (`phpbb\auth`)

**Source**: auth HLD, Cache Strategy section, AclCacheService

#### Data to cache

| Data | Nature | Evidence |
|---|---|---|
| **Permission bitstring** (per user) | Pre-computed binary, expensive to rebuild | Stored in `phpbb_users.user_permissions` column. Built by `PermissionResolver::resolve()` which queries 5 ACL tables + group memberships. |
| **Role cache** | Global — roleId → permissions map | Stored in file cache as `_role_cache`. Built from `acl_roles_data` table. |
| **Option registry** | Global — permission option names → IDs | Stored in file cache as `_acl_options`. Built from `acl_options` table. |
| **Decoded ACL** (in-memory) | Per-request decoded from bitstring | PHP array `acl[forum_id][option_index]`. Memoized in AuthorizationService per request. |

#### Existing cache architecture (3+1 layers)

The auth service **already has its own baked-in caching system** with 3 persistence layers:

| Layer | Storage | Key | Lifetime | Invalidation |
|---|---|---|---|---|
| File cache — option registry | File system (phpBB cache driver) | `_acl_options` | Until extension enable/disable | Options added/removed |
| File cache — role cache | File system (phpBB cache driver) | `_role_cache` | Until `clearPrefetch()` | Every `clearPrefetch()` rebuilds |
| DB cache — user bitstring | `phpbb_users.user_permissions` | Per-user row | Until user's permissions change | `clearPrefetch(?userId)` sets to `''` |
| Memory — decoded ACL | PHP array (AuthorizationService) | Per-request | Single request | Reset on new request |

#### TTL ranges

- No TTL-based expiry — all invalidation is **explicit event-driven**.
- Bitstring survives until permissions change.
- Role/option caches survive until admin changes roles/extensions.

#### Invalidation strategy

All via `AclCacheService::clearPrefetch()`:

| Trigger | Scope |
|---|---|
| Set user permissions | Single user |
| Set group permissions | All users (or group members) |
| Create/update/delete role | All users |
| Copy forum permissions | All users |
| Forum create/modify/delete | All users |
| User joins/leaves group | Single user |

**Event dispatched**: `PermissionsClearedEvent` after invalidation.

#### Integration with cache service

The auth service **does NOT need a generic cache service** — it has a deeply specialized caching mechanism:
- The bitstring format is custom (base-36/31-bit encoding).
- The decode/encode is hand-optimized for O(1) read-time lookup.
- The file cache uses phpBB's native `cache.driver`.
- DB storage is a deliberate choice for the bitstring (survives file cache purges).

**However**, the auth service could benefit from cache service in two ways:
1. **If migrating from file cache** to Redis — the option registry (`_acl_options`) and role cache (`_role_cache`) could use the cache service's key-value store instead of file cache.
2. **Cache invalidation events** — other services listen to `PermissionsClearedEvent` to know when to flush permission-dependent caches (e.g., hierarchy's forum display lists filtered by ACL).

#### Namespace / isolation need

If integrated:
- `auth:options` — option registry
- `auth:roles` — role cache
- `auth:user_perms:{user_id}` — bitstring (would duplicate DB storage — unlikely to migrate)

#### Key observations

1. **Auth is the most sophisticated cache consumer already** — its caching is deeply specialized and self-contained.
2. **No need to replace** the existing mechanism with a generic cache service.
3. Auth's `PermissionsClearedEvent` is a **signal for OTHER services** to invalidate their permission-dependent caches.
4. If Redis is introduced, auth's file caches (`_acl_options`, `_role_cache`) are natural candidates to migrate to Redis via the cache service's pool factory.

---

### 1.5 Hierarchy Service (`phpbb\hierarchy`)

**Source**: hierarchy HLD, CacheInvalidationListener, ForumRepository, TreeService

#### Data to cache

| Data | Nature | Evidence |
|---|---|---|
| **Forum tree** (full tree or subtree) | Structural — nested set with all forum entities | `getTree()`, `getSubtree()`, `getPath()` — read-heavy, rarely modified |
| **Forum parent chains** (`forum_parents`) | Pre-computed parent path per forum | Stored in `phpbb_forums.forum_parents` column. `ForumRepository::invalidateParentCache()` and `TreeService::invalidateParentCache()` both clear it. |
| **Forum SQL query cache** | Generic query results | `CacheInvalidationListener::onForumChanged()` calls `$this->cache->destroy('sql', FORUMS_TABLE)` — destroys ALL SQL cache entries tagged with forums table |
| **Forum entity by ID** | Single entity lookup | `ForumRepository::findById()` — common lookup |
| **Denormalized stats** (`ForumStats`, `ForumLastPost`) | Counter data on forum entity | Updated synchronously by `phpbb\threads` via `updateForumStats()` |

#### TTL ranges

| Data | Suggested TTL | Rationale |
|---|---|---|
| Full forum tree | 5–60 min | Rarely changes (admin action only); expensive query (full nested set) |
| Forum entity | 1–5 min | Changes on thread activity (last post, counters) |
| Parent chains | 24 hours or event-driven | Only change on tree structure modification (move/reorder/create/delete) |
| SQL query cache | 30–120 sec | General-purpose, moderate churn |

#### Invalidation strategy

Already defined in `CacheInvalidationListener`:
```php
ForumCreatedEvent   → destroy SQL cache for FORUMS_TABLE
ForumUpdatedEvent   → destroy SQL cache for FORUMS_TABLE
ForumDeletedEvent   → destroy SQL cache for FORUMS_TABLE
ForumMovedEvent     → destroy SQL cache for FORUMS_TABLE
ForumReorderedEvent → destroy SQL cache for FORUMS_TABLE
```

Plus `invalidateParentCache()` on all tree mutations: sets `forum_parents = ''` in DB.

**Cross-service invalidation**: Threads dispatches `ForumCountersChangedEvent` → hierarchy updates forum stats → triggers SQL cache invalidation.

#### Namespace / isolation need

- `hierarchy:tree` or `hierarchy:tree:{root_id}` — full/partial tree
- `hierarchy:forum:{forum_id}` — single forum entity
- `hierarchy:path:{forum_id}` — parent chain
- `hierarchy:sql:*` — SQL query results (tagged with table)

#### Key observations

1. **Hierarchy's forum tree is the prime candidate for long-lived caching** — it changes only on admin actions but is read on nearly every page load.
2. **Parent chains are already DB-cached** in `forum_parents` column — a classic materialized cache pattern.
3. The `CacheInvalidationListener` already uses phpBB's `cache.driver` via `$this->cache->destroy('sql', TABLE)` — this is the existing SQL query cache.
4. **Forum stats are ephemeral** — updated on every post creation, so caching the full entity has limited value unless stats are separated from structure.
5. Hierarchy is a **passive cache consumer** — it uses whatever cache driver is injected, doesn't manage its own sophisticated caching like auth does.

---

## 2. Cross-Cutting Cache Patterns

### 2.1 Counter Caching (Threads + Messaging + Storage)

**Pattern observed**: All three services use **DB-materialized counters** with **atomic in-transaction operations** and **periodic reconciliation**.

| Service | Counter Table/Column | Update Strategy | Reconciliation |
|---|---|---|---|
| Threads | `phpbb_topics.topic_posts_*`, `phpbb_forums.forum_posts_*`, `num_posts`, `num_topics` | Synchronous `UPDATE ... SET counter = counter + 1` in transaction | `syncTopicCounters()`, `syncForumCounters()` — periodic cron |
| Messaging | `messaging_counters (user_id, counter_type, counter_value)` | Atomic `UPDATE SET counter_value = counter_value + 1` via event listeners | Cron at `messaging_counter_reconcile_interval` (daily) |
| Storage | `phpbb_storage_quotas (scope, scope_id, used_bytes, max_bytes)` | Atomic `UPDATE WHERE used_bytes + size <= max_bytes` | `QuotaReconciliationJob` (daily cron) |

**Cache service role for counters**: **Read-through cache ONLY**. The DB is always the source of truth. The cache service could provide:
- `getOrLoad(key, loader, ttl)` pattern — load counter from cache, fall back to DB query.
- Event-driven cache update — on counter increment event, also update cached value (write-through).
- This avoids a DB query for every navbar "unread" badge check or quota pre-flight.

**Recommendation**: Counters stay in DB. Cache service provides optional **read-through caching** for hot counter reads (e.g., unread badge). Not a requirement for correctness — only for latency.

---

### 2.2 Configuration / Metadata Caching (Auth + Hierarchy + Storage)

**Pattern observed**: Long-lived, rarely-changing data that is expensive to compute or query.

| Service | Data | Current Storage | Change Frequency |
|---|---|---|---|
| Auth | Option registry (`_acl_options`) | File cache | Extension enable/disable |
| Auth | Role cache (`_role_cache`) | File cache | Admin role management |
| Auth | User permission bitstring | `phpbb_users.user_permissions` | Permission changes |
| Hierarchy | Forum tree (full nested set) | Live query | Admin forum management |
| Hierarchy | Parent chains (`forum_parents`) | `phpbb_forums.forum_parents` column | Tree mutations |
| Storage | Allowed extensions config | PHP config | Admin setting changes |

**Cache service role**: Provide a **unified key-value store** (Redis/file) that these services can use instead of ad-hoc file caches or DB column caches. Benefits:
- Single TTL/eviction policy management
- Consistent invalidation API
- Future Redis migration path (currently file-based)

---

### 2.3 Query Result Caching (Threads + Hierarchy)

**Pattern observed**: Short-lived cache of SQL query results, invalidated on any write to the relevant table.

| Service | Query | Current Cache | Invalidation |
|---|---|---|---|
| Hierarchy | Forum listing (full tree) | `$this->cache->destroy('sql', FORUMS_TABLE)` — phpBB SQL cache | Any forum event |
| Threads | Forum topic listing | Not yet cached, "CacheInvalidation" listed as event consumer | Topic/post events |
| Threads | Topic post listing | Not yet cached | Post events |

**Cache service role**: Provide **tag-based query result caching**:
- Store query results with tags: `['forum:5', 'table:topics']`
- Invalidate by tag: `cache->invalidateTag('forum:5')` — clears all cached queries for forum 5.
- This replaces phpBB's legacy `$cache->destroy('sql', TABLE)` with a more granular mechanism.

---

### 2.4 Computed Cache (Threads ContentPipeline)

**Pattern observed**: Expensive computation whose result is deterministic from input.

| Service | Computation | Input → Output | Cost |
|---|---|---|---|
| Threads | `ContentPipeline::render()` | raw post text + ContentContext → HTML | Runs BBCode parser, markdown, smilies, autolinks, censoring — per post, per display |

**Cache service role**: **Get-or-compute** pattern:
```php
$html = $cache->getOrCompute(
    key: "threads:content:{$postId}",
    compute: fn() => $pipeline->render($rawText, $context),
    ttl: 3600,
    tags: ["post:{$postId}", "forum:{$forumId}"]
);
```

This is the **single biggest performance win** from a cache service. Every page view currently runs the full content pipeline. With caching, only the first view (or post-edit) triggers the pipeline.

**Complications**:
- Per-user rendering differences: `viewSmilies`, `viewImages`, `viewCensored`, `highlightWords` from `ContentContext`.
- Cache key must include user-dependent flags OR the pipeline must separate user-independent rendering from user-specific post-processing.
- Recommended: Cache the **user-independent base render** (`post_id` → base HTML), apply user-specific transforms (smilie toggle, censoring toggle) as a lightweight post-processing step.

---

## 3. Integration API Requirements

### 3.1 Operations Needed by Consumers

Based on all five HLDs, the cache service must support:

| Operation | Consumers | Use Case |
|---|---|---|
| `get(key): ?mixed` | All services | Basic key-value lookup |
| `set(key, value, ttl, tags[]): void` | All services | Store with TTL and tag associations |
| `delete(key): void` | All services | Explicit single-key removal |
| `invalidateTag(tag): void` | Threads, Hierarchy | Bulk invalidation of all entries sharing a tag (e.g., `forum:5`) |
| `getOrCompute(key, callable, ttl, tags[]): mixed` | Threads (ContentPipeline), Storage (file metadata), Hierarchy (tree) | Lazy load pattern — return cached value or compute + cache |
| `has(key): bool` | Storage (exists check) | Lightweight check without deserialization |
| `clear(): void` | Admin actions | Full cache flush |
| `invalidatePrefix(prefix): void` | Optional — service-level flush | Clear all keys in a namespace (e.g., `threads:*`) |

### 3.2 Injection Pattern

**Recommended**: **Pool factory** injected via constructor, each service requests its own pool/namespace.

```php
// Cache pool factory creates isolated pools
interface CachePoolFactoryInterface {
    public function getPool(string $namespace): CachePoolInterface;
}

// Each service gets its own pool at construction
class ThreadsService {
    private CachePoolInterface $cache;

    public function __construct(
        CachePoolFactoryInterface $cacheFactory,
        // ...other deps
    ) {
        $this->cache = $cacheFactory->getPool('threads');
    }
}
```

**Why pool factory, not direct injection**:
1. **Namespace isolation** — `threads:content:123` can't collide with `messaging:conversations:456`.
2. **Per-pool configuration** — threads pool might use Redis, auth pool might stay file-based.
3. **Independent flush** — can clear all threads cache without affecting auth cache.
4. **Consistent with Symfony Cache component** — `cache.app`, `cache.system`, tagged pools.

**Evidence from HLDs**:
- Auth already uses `@cache.driver` (file cache) — needs its own pool.
- Hierarchy uses `$this->cache->destroy('sql', TABLE)` — uses the global SQL cache pool.
- Threads expects "future Redis/file cache" — needs a separate pool.
- Messaging has no cache dependency yet — clean injection.

### 3.3 Namespace Isolation

| Service | namespace | Purpose |
|---|---|---|
| `threads` | `threads` | Content HTML, query results |
| `messaging` | `messaging` | Conversation lists, counters, rules |
| `storage` | `storage` | File metadata, URLs, quota reads |
| `auth` | `auth` | Option registry, role cache (if migrated from file) |
| `hierarchy` | `hierarchy` | Forum tree, entity cache, SQL results |

---

## 4. Summary Table

| Service | Data Cached | TTL Range | Invalidation Style | Volume (reads/sec estimate) |
|---|---|---|---|---|
| **Threads** | Rendered HTML (ContentPipeline output) | 5–60 min | Event-driven (`PostEditedEvent`) + tag-based (`post:{id}`, `forum:{id}`) | Very High — every page view |
| **Threads** | Query results (topic/post listings) | 10–120 sec | Tag-based (`forum:{id}`, `topic:{id}`) | High — listing pages |
| **Messaging** | Unread counters (read-through) | Event-driven (no TTL) | Event: `MessageDelivered`, `MessageRead` | High — navbar badge |
| **Messaging** | Conversation list | 5–30 sec | Event: per-user invalidation on message activity | Medium-High |
| **Messaging** | User rules | 5–60 min | Event: rule CRUD operations | Low |
| **Storage** | File metadata (StoredFile) | 5–60 min | Event: `FileDeletedEvent`, `FileClaimedEvent` | Medium — downloads |
| **Storage** | Quota state (read-through) | Event-driven | Event: store/delete operations | Low — upload pre-flight |
| **Auth** | Option registry | Indefinite | Explicit: extension changes | Low — once per cache miss |
| **Auth** | Role cache | Indefinite | Explicit: `clearPrefetch()` | Low — once per cache miss |
| **Auth** | User permission bitstring | Indefinite | Explicit: `clearPrefetch(userId)` | Medium — once per user per session |
| **Hierarchy** | Forum tree (full/subtree) | 5–60 min | Event: all forum mutation events | High — every page load |
| **Hierarchy** | Forum parent chains | 24h or event | Event: tree mutations only | Medium |
| **Hierarchy** | SQL query results | 30–120 sec | Event: forum mutation + thread activity | High |

---

## 5. Key Design Implications for Cache Service

### 5.1 Must-Have Features

1. **Tag-based invalidation** — threads and hierarchy need it for `forum:{id}`, `topic:{id}`, `post:{id}` tags.
2. **Get-or-compute (lazy load)** — ContentPipeline rendering is the primary use case; storage file metadata is secondary.
3. **Namespace isolation via pools** — every service needs its own keyspace.
4. **Event-driven invalidation** — all services use domain events; cache service must integrate with Symfony EventDispatcher.
5. **TTL support** — different data has different lifetimes (10 sec → 24 hours).

### 5.2 Nice-to-Have Features

1. **Prefix-based flush** — clear all keys in a namespace (admin "clear cache" action).
2. **Multi-get** — batch key retrieval (conversation list loading multiple participant previews).
3. **Increment/decrement** — native atomic operations for counter read-through (avoids get→modify→set races).
4. **Statistics/monitoring** — hit rate, miss rate per pool.

### 5.3 NOT Needed

1. **Distributed cache coordination** — single-server PHP deployment, no multi-node consistency needed.
2. **Cache warming** — all services assume lazy population on miss.
3. **Complex eviction policies** — TTL + explicit invalidation is sufficient; no LRU/LFU required (Redis handles this internally).
4. **Counter management** — all services use DB-materialized counters. Cache provides read-through only. Do NOT move counters to cache-only storage.

### 5.4 Compatibility with Existing phpBB Cache

The current `cache.driver` (file-based, `cache/production/`) is used by:
- Legacy SQL query cache (`$cache->destroy('sql', TABLE)`)
- Auth file caches (`_acl_options`, `_role_cache`)
- Config cache (`data_global.php`, etc.)

The new cache service should:
- **Wrap or replace** `cache.driver` with a more capable implementation
- **Preserve backward compatibility** — existing `$cache->destroy('sql', TABLE)` must keep working
- **Add** tag-based invalidation, get-or-compute, and pool factory on top
- Allow **gradual migration** — services can opt into the new cache API at their own pace

### 5.5 Storage Backend Recommendations

| Backend | When | Consumers |
|---|---|---|
| **File system** (existing) | Default, single-server, low traffic | Auth (option registry, role cache), config |
| **Redis** | Production, any cache-worthy traffic | ContentPipeline renders, query results, conversation lists, forum tree |
| **APCu** | Single-server, high-throughput reads | Per-request decoded ACL (auth), hot counters (read-through) |

The cache service should support **multiple backends per pool** — a pool factory that creates a Redis-backed pool for `threads` and an APCu-backed pool for `auth` in-memory data.

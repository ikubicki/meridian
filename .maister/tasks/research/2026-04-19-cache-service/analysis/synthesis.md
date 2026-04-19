# Cache Service — Research Synthesis

## 1. Cross-Cutting Patterns

### 1.1 Legacy System Duality

The legacy cache system (`phpbb\forums\cache`) conflates two fundamentally different concerns into one interface:

| Concern | Methods | Nature |
|---|---|---|
| **Key-Value Cache** | `_read()`, `_write()`, `_exists()`, `put()`, `get()`, `destroy()` | Generic get/set by string key |
| **SQL Result Cache** | `sql_load()`, `sql_save()`, `sql_exists()`, `sql_fetchrow()`, `sql_freeresult()`, `sql_rowseek()` | Cursor-based iteration over cached query results |

The new service should cleanly separate these. SQL result caching is a **consumer pattern** (a specialized use of KV cache), not a first-class cache primitive. It belongs in a repository/query layer that uses the generic cache underneath.

### 1.2 All Counter Systems Are DB-Materialized

A consistent pattern across threads, messaging, and storage: **counters live in the database**, not in cache.

- Threads: `topic_posts_*`, `forum_posts_*` — synchronous in-transaction INCREMENT
- Messaging: `messaging_counters` — atomic UPDATE via event listeners
- Storage: `phpbb_storage_quotas` — atomic UPDATE-with-condition

**Implication**: The cache service does NOT own counters. It provides optional **read-through caching** for hot counter reads (navbar badges, quota pre-flight). Correctness comes from the DB; cache is latency optimization only.

### 1.3 ContentPipeline Is The #1 Cache Opportunity

Every page view currently runs the full BBCode→HTML pipeline for every displayed post. This is:
- CPU-expensive (parse + transform + render)
- Deterministic (same input → same output)
- Read-heavy, write-rare (only invalidated on post edit)

The `getOrCompute(key, callable, ttl, tags)` pattern is designed specifically for this. Tag-based invalidation on `PostEditedEvent` provides precision.

### 1.4 Two Invalidation Regimes

| Regime | Data | Strategy |
|---|---|---|
| **Event-driven** (immediate) | Content renders, counters, ACL | Domain event → `CacheInvalidationSubscriber` → tag invalidation |
| **TTL-based** (eventual) | Query results, listings, stats | Short TTL (10-120s) as safety net; events accelerate invalidation |

Recommended hybrid: TTL as maximum staleness guarantee + events for immediate invalidation where available.

### 1.5 Pool Factory = Namespace Isolation

Every service needs its own cache namespace. A `CachePoolFactory` creates isolated pools (`threads`, `messaging`, `storage`, `auth`, `hierarchy`). Benefits:
- Key collision prevention
- Per-pool backend configuration (Redis for threads, file for auth)
- Independent flush capability
- Clean DI injection

---

## 2. Design Tensions

### 2.1 PSR Compliance vs Feature Richness

| PSR-16 (SimpleCache) | Needed Beyond PSR |
|---|---|
| `get`, `set`, `delete`, `clear`, `has` | **Tag-based invalidation** — not in PSR |
| `getMultiple`, `setMultiple`, `deleteMultiple` | **Get-or-compute** — not in PSR |
| TTL support | **Stampede prevention** (XFetch/locking) |

**Tension**: Implementing PSR-16 gives interop but lacks tags and compute patterns. Symfony solves this by extending PSR-6 with `TagAwareCacheInterface`.

**Resolution**: Expose PSR-16 as the minimal interface. Add `TagAwareCachePoolInterface` extending it with `invalidateTags()` and `getOrCompute()`. Services that need tags use the extended interface; those that don't can depend on the PSR-16 contract.

### 2.2 File System Default vs Redis Recommended

- **File system**: Zero dependencies, works everywhere (shared hosting), current phpBB default, opcache-optimized reads
- **Redis**: Faster for high-throughput, tag invalidation is trivial (version counters in Redis), distributed-ready

**Tension**: Default should "just work" on shared hosting, but the architecture must be Redis-first in design (so tag invalidation works efficiently on file system too).

**Resolution**: File system as default backend. Implementation must support tag invalidation on file system (version-counter approach, tag versions stored in a single file or individual files). Redis as recommended production backend.

### 2.3 Backward Compatibility vs Clean API

Legacy code uses `$cache->destroy('sql', FORUMS_TABLE)` — table-level SQL cache flush. New code wants `$cache->invalidateTags(['forum:3'])` — semantic tag invalidation.

**Tension**: Can't break existing consumers immediately but don't want the new API polluted by legacy patterns.

**Resolution**: Legacy bridge adapter that translates `destroy('sql', TABLE)` → `invalidateTags(['table:' . TABLE])`. New code uses the clean API directly. Legacy bridge is deprecated and removed once migration is complete.

### 2.4 Simplicity vs Multi-Tier

APCu L1 + Redis L2 gives best latency. But adds complexity, consistency challenges (stale L1 on other servers), and APCu isn't always available.

**Tension**: Multi-tier is valuable for high-traffic production but overengineered for typical phpBB installations.

**Resolution**: Multi-tier as optional configuration (ChainAdapter pattern), not the default. Default is single-backend. Documentation guides admins on when to enable L1.

### 2.5 Per-Backend Serialization vs Unified

- File: `var_export()` + `include` (opcache-optimized, zero deserialization cost)
- Redis: `igbinary` (compact, fast) with `serialize()` fallback
- APCu: No serialization (native PHP values in shared memory)
- Database: `serialize()` (full type fidelity)

**Tension**: Each backend has an optimal serialization strategy. A unified serializer wastes performance.

**Resolution**: Marshaller abstraction per backend. Each backend adapter uses its optimal format. The marshaller is an implementation detail, not exposed to consumers.

---

## 3. Functional Decomposition

### Layer 1: Interface Contracts

```
CacheInterface (PSR-16)                  — basic get/set/delete/has
TagAwareCacheInterface extends CacheInterface  — adds invalidateTags(), getOrCompute()
CachePoolFactoryInterface                — creates named pools
MarshallerInterface                      — serialize/deserialize abstraction
```

### Layer 2: Core Implementation

```
CachePool                  — main implementation of TagAwareCacheInterface
CachePoolFactory           — creates CachePool instances with backend + config
StampedeProtection         — XFetch + optional locking
TagVersionStore            — version-counter tag invalidation logic
```

### Layer 3: Backend Adapters

```
FilesystemAdapter          — var_export + include, tag versions in file
RedisAdapter               — phpredis, igbinary serialization
MemcachedAdapter           — ext-memcached
ApcuAdapter                — APCu shared memory
DatabaseAdapter            — SQL table, version-counter tags
NullAdapter                — no-op (testing/disabled cache)
ChainAdapter               — multi-tier L1→L2
```

### Layer 4: Integration

```
CacheInvalidationSubscriber (per service)  — listens to domain events, calls invalidateTags()
LegacyCacheBridge                          — backward-compatible wrapper for legacy code
CacheTidyTask                              — cron task for cleanup/GC
CacheWarmupCommand                         — CLI command for optional pre-warming
```

---

## 4. Key Insights

1. **Legacy SQL caching is a pattern, not a primitive** — don't replicate `sql_load()`/`sql_fetchrow()` in new API. Let repositories cache query results using `getOrCompute()`.

2. **Auth doesn't need generic cache** — its 3-layer system (file, DB column, in-memory decode) is purpose-built. Only option/role caches might migrate to generic cache.

3. **Tag version-counter is THE pattern** — Symfony-proven, O(tags) invalidation, lazy cleanup, works on all backends including filesystem.

4. **ContentPipeline cache key must be user-independent** — cache base render per post, apply user-specific toggles (smilies, images, censoring) as lightweight post-processing. Otherwise cache hit rate plummets.

5. **Stampede prevention should be opt-in** — XFetch (beta parameter) on `getOrCompute()` by default. Locking only for explicitly-marked high-cost computations.

6. **Database backend is essential** — many phpBB installs run on shared hosting without Redis. DB cache (with version-counter tags) is the "better than file" option that works everywhere.

7. **Key design convention** — `{service}:{entity}:{id}:{variant}` with `:` separator (Redis convention). Max 200 chars, hash overflow keys.

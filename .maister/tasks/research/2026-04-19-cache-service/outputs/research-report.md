# Cache Service — Research Report

## Executive Summary

The `phpbb\cache` utility service provides a reusable, configurable caching layer for all phpBB services. It defaults to filesystem-backed storage (matching legacy behavior) while enabling external backends (Redis, Memcached, APCu, database). The service introduces three capabilities absent from legacy: **tag-based invalidation**, **get-or-compute with stampede prevention**, and **namespace-isolated cache pools**.

The primary performance opportunity is caching ContentPipeline rendered HTML (threads service), which currently re-renders BBCode→HTML on every page view. Secondary consumers include forum tree lookups (hierarchy), conversation lists (messaging), and file metadata (storage).

---

## 1. Current State (Legacy)

### Architecture

The current system (`phpbb\forums\cache`) consists of:
- **`driver_interface`** — 17-method monolithic interface combining KV cache and SQL result caching
- **Inheritance chain**: `base` → `file` (filesystem); `base` → `memory` → `redis`/`memcached`/`apcu`/`wincache`
- **`service.php`** wrapper — 7 `obtain_*()` methods + `__call()` magic delegation + deferred purge

### Storage Format (File Driver)

```php
<?php exit; // PHP exit guard — prevents direct execution
// Expiry timestamp
1234567890
// Serialized data
a:2:{s:4:"key1";s:5:"value";s:4:"key2";i:42;}
```

Opcache-optimized files (`data_*.php`) use `var_export()` + `include` for zero-deserialization reads.

### Limitations

1. **No tag-based invalidation** — `destroy('sql', TABLE)` brute-force deletes all SQL cache entries for a table
2. **No compute pattern** — consumers must implement get-check-compute-set manually
3. **No stampede prevention** — all concurrent requests recompute simultaneously on miss
4. **Monolithic interface** — KV and SQL caching conflated; services can't depend on just what they need
5. **No namespace isolation** — all services share one key space via single `cache.driver` service
6. **Inconsistent TTL** — file driver defaults 1 year, memory drivers default 30 days

### DI Configuration

```
config.php → $acm_type → convert_30_acm_type() → %cache.driver.class% → cache.driver service
```

~10 services depend on `@cache` (service wrapper), ~15+ depend on `@cache.driver` directly.

---

## 2. Industry Standards & Patterns

### PSR Standards

| Standard | Nature | Methods | Best For |
|---|---|---|---|
| **PSR-6** (Cache Item) | Object Pool model (Item + Pool) | 9 pool + 6 item methods | Framework internals, deferred writes |
| **PSR-16** (Simple Cache) | Direct get/set model | 8 methods | Application code, simple API |

**Recommendation**: PSR-16 as the consumer-facing contract. Extend with tag + compute capabilities. PSR-6 as optional internal bridge for Symfony adapter compatibility.

### Tag-Based Invalidation (Version Counter)

The Symfony-proven pattern:
- Each tag has a monotonically increasing version number
- On `invalidateTags(['forum:3'])` → increment version for `forum:3`
- On read → compare stored tag versions against current versions; mismatch = miss
- **O(N)** where N = number of tags invalidated (NOT number of items)
- Stale items are lazily cleaned on next access

### Stampede Prevention

| Pattern | PHP Fit | Complexity | Recommended |
|---|---|---|---|
| **XFetch (probabilistic early expiry)** | Excellent | Low | ✅ Primary |
| **Locking (mutex)** | Good (flock/Redis SETNX) | Medium | ✅ Secondary for high-cost |
| **Stale-while-revalidate** | Poor (no async in PHP) | High | ❌ Skip |

### Multi-Tier (L1/L2)

- **L1**: APCu — sub-microsecond, per-server, limited memory
- **L2**: Redis — ~0.1-1ms, shared across servers
- **Consistency**: Short L1 TTL (10-30s) limits staleness window
- **Recommendation**: Optional configuration, not default

### Serialization Per Backend

| Backend | Optimal Serializer | Read Speed |
|---|---|---|
| Filesystem | `var_export()` + `include` | ~0.01ms (opcache) |
| Redis | igbinary (fallback: serialize) | ~0.1-0.5ms |
| APCu | Native (no serialization) | ~0.001ms |
| Database | serialize() | ~0.5-2ms |
| Memcached | igbinary (fallback: serialize) | ~0.1-0.5ms |

---

## 3. Consumer Analysis (From Service HLDs)

### Primary Consumers

| Service | Cache Use | Volume | TTL | Invalidation |
|---|---|---|---|---|
| **Threads** (ContentPipeline) | Rendered post HTML | Very High (every pageview) | 5-60 min | Event: `PostEditedEvent` + tags |
| **Threads** (query results) | Topic/post listings | High | 10-120 sec | Tags: `forum:{id}`, `topic:{id}` |
| **Hierarchy** (forum tree) | Full/partial tree structure | High (every page) | 5-60 min | Event: forum mutations |
| **Messaging** (conversations) | User inbox pages | Medium-High | 5-30 sec | Event: per-user on message activity |

### Secondary Consumers

| Service | Cache Use | Volume | TTL | Invalidation |
|---|---|---|---|---|
| **Messaging** (counters) | Unread badge read-through | High | Event-driven | Event: `MessageDelivered/Read` |
| **Storage** (file metadata) | StoredFile entity | Medium | 5-60 min | Event: delete/claim |
| **Auth** (option/role caches) | ACL config (if migrated) | Low | Indefinite | Explicit: `clearPrefetch()` |
| **Hierarchy** (SQL results) | Forum queries | High | 30-120 sec | Event: forum changes |

### Not Cache Consumers

- **Counters** (all services) — DB-materialized, atomic operations, reconciliation cron
- **Auth bitstring** — stored in `phpbb_users.user_permissions` column (DB cache)
- **Storage quotas** — atomic DB operations with condition checks

---

## 4. Required API Surface

### Must-Have Operations

| Operation | Signature | Use Case |
|---|---|---|
| `get` | `get(string $key, mixed $default = null): mixed` | Basic lookup |
| `set` | `set(string $key, mixed $value, int $ttl = null, array $tags = []): bool` | Store with TTL + tags |
| `delete` | `delete(string $key): bool` | Remove single key |
| `has` | `has(string $key): bool` | Existence check |
| `clear` | `clear(): bool` | Flush entire pool |
| `invalidateTags` | `invalidateTags(array $tags): bool` | Bulk tag-based invalidation |
| `getOrCompute` | `getOrCompute(string $key, callable $compute, int $ttl, array $tags = [], float $beta = 1.0): mixed` | Lazy load with stampede prevention |

### Must-Have Infrastructure

| Component | Purpose |
|---|---|
| `CachePoolFactory` | Creates namespace-isolated pools; per-pool backend config |
| `MarshallerInterface` | Per-backend serialization strategy |
| `TagVersionStore` | Version-counter tag management |
| `CacheInvalidationSubscriber` | Per-service event→tag invalidation mapping |

### Nice-to-Have

- `getMultiple()` / `setMultiple()` — batch operations
- `increment()` / `decrement()` — atomic counter operations (for read-through counters)
- `invalidatePrefix()` — flush all keys in a namespace
- Cache statistics (hit/miss rate per pool)
- CLI clear/warmup commands

---

## 5. Backend Requirements

| Backend | When To Use | Tag Support | Notes |
|---|---|---|---|
| **Filesystem** | Default, shared hosting, single-server | Version-counter in file | Opcache-optimized reads; current phpBB approach |
| **Redis** | Production with any meaningful traffic | Native (version counter in Redis keys) | Recommended for anything beyond hobbyist |
| **Memcached** | Legacy Redis alternative | Version-counter via Memcached keys | Less capable than Redis (no SCAN, no pub/sub) |
| **APCu** | L1 tier or single-server high-throughput | Limited (per-server only) | Not suitable as primary backend in multi-server |
| **Database** | Shared hosting without filesystem cache or with multiple web heads | Version-counter via `cache_tag_versions` table | Better concurrency than file; adds DB load |
| **Null** | Testing, cache-disabled mode | N/A | No-op implementation |

---

## 6. Key Design Decisions Required

| # | Decision | Options | Recommendation |
|---|---|---|---|
| 1 | **Interface standard** | A: PSR-16 only, B: PSR-6 only, C: PSR-16 + custom extensions, D: Custom only | C — PSR-16 base + tags/compute |
| 2 | **Tag invalidation mechanism** | A: Version counter, B: Direct delete mapping, C: Key-prefix versioning | A — proven, O(tags), lazy cleanup |
| 3 | **Stampede prevention** | A: XFetch only, B: Locking only, C: XFetch + optional locking, D: None | C — XFetch default, locking opt-in |
| 4 | **Pool factory model** | A: Single pool (namespaced keys), B: Multiple pools (separate backends), C: Hybrid | B — per-pool config, separate backends possible |
| 5 | **Serialization** | A: Unified serialize(), B: Per-backend optimal, C: Configurable marshaller | C — marshaller abstraction, per-backend defaults |
| 6 | **Multi-tier support** | A: Built-in always, B: Optional chain adapter, C: Not supported | B — ChainAdapter when configured |
| 7 | **Legacy compatibility** | A: Clean break, B: Bridge adapter, C: Dual API forever | B — bridge for migration, deprecated |
| 8 | **Default backend** | A: Filesystem, B: Redis, C: Auto-detect best available | A — filesystem (matches legacy, zero deps) |

---

## 7. Risk Assessment

| Risk | Impact | Mitigation |
|---|---|---|
| Tag invalidation on filesystem is slow (file I/O for version reads) | Medium — file reads add latency per cache access | Store all tag versions in a single file (batch read); opcache helps |
| ContentPipeline cache key per-user explosion | High — low hit rate if keys include user preferences | Separate base render (post-level) from user-specific post-processing |
| Migration disruption to existing services | Medium — ~25 services use `@cache`/`@cache.driver` | Bridge adapter + gradual opt-in migration |
| Redis not available on shared hosting | Low — file system default covers this | Multiple backends, graceful degradation |
| Over-engineering for typical phpBB traffic | Medium — hobby forums don't need advanced caching | Layered: basic works without config; advanced features opt-in |

---

## 8. Open Questions for Design Phase

1. Should the service implement PSR-6 internally for Symfony Cache adapter compatibility, or is a clean custom implementation preferred?
2. Should SQL result caching (legacy `sql_load/sql_save` pattern) be preserved as a first-class feature or relegated to a separate query-cache layer?
3. What is the migration path for the ~25 existing `@cache`/`@cache.driver` consumers? Big-bang or service-by-service?
4. Should tag versions be shared across pools (global tag namespace) or per-pool (tags are pool-scoped)?
5. Is cache warming (pre-populate on deploy/cache-clear) needed, or is lazy-fill sufficient?

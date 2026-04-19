# Solution Exploration: `phpbb\cache` Utility Caching Service

## Problem Reframing

### Research Question

How should we design a reusable caching service for this phpBB rewrite that replaces the monolithic legacy `\phpbb\cache\service` (17-method facade + tightly coupled SQL caching + per-driver serialization) with a modern, pool-based architecture supporting tag invalidation, stampede prevention, and multiple backends — while preserving zero-dependency filesystem caching for shared hosting?

### How Might We Questions

1. **HMW provide a clean, standard caching interface** that ~5 services can consume without learning a bespoke API?
2. **HMW support tag-based invalidation** so that forum/topic mutations surgically invalidate related cache entries across services?
3. **HMW prevent cache stampedes** on expensive computations (ContentPipeline renders, hierarchy tree builds) without mandating external lock infrastructure?
4. **HMW isolate cache pools per service** (threads, messaging, storage, auth, hierarchy) while sharing backend connections efficiently?
5. **HMW support per-backend optimal serialization** (var_export+opcache for files, igbinary for Redis, native for APCu) without leaking backend concerns into service code?
6. **HMW enable multi-tier caching** (APCu L1 + Redis L2) for high-traffic installs without complicating the default single-server path?
7. **HMW migrate from the legacy 17-method interface** without breaking all ~25 existing service consumers at once?
8. **HMW default to filesystem caching** (shared hosting, zero deps) while making Redis/Memcached trivial to enable?

---

## Decision Area 1: Interface Standard

### Context

The caching interface is the primary API surface ~5 new services and ~25 legacy consumers will depend on. The choice determines interoperability with the PHP ecosystem, learning curve for contributors, and how much custom code we maintain. The legacy `driver_interface` has 17 methods including SQL cursor operations (`sql_load`, `sql_fetchrow`, `sql_rowseek`) that are unique to phpBB and tightly couple cache to database concerns.

### Alternative A: PSR-16 SimpleCache Only

**Description**: Implement `Psr\SimpleCache\CacheInterface` with its 8 methods (`get`, `set`, `delete`, `clear`, `getMultiple`, `setMultiple`, `deleteMultiple`, `has`). All caching needs are served through this minimal interface.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Trivial to learn (8 methods); any PSR-16 library is a drop-in; minimal maintenance surface; composer packages that accept PSR-16 work out of the box |
| **Cons** | No tag support — must be bolted on externally or via wrapper; no `getOrCompute` pattern — every consumer writes boilerplate; no deferred write batching |
| **Best for** | Projects with simple caching needs and no tag invalidation requirements |

### Alternative B: PSR-6 CacheItemPool

**Description**: Implement `Psr\Cache\CacheItemPoolInterface` with its object model (`CacheItem` objects, deferred saves, explicit `save()`/`commit()` lifecycle).

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Full Symfony Cache ecosystem compatible; deferred saves enable write batching; `CacheItem` can carry metadata (tags, expiry); PSR-6–aware packages work directly |
| **Cons** | Verbose for simple get/set (3 calls instead of 1); heavier cognitive load for contributors; CacheItem allocation overhead on hot paths; still no native tag support in PSR-6 itself |
| **Best for** | Projects deeply integrated with Symfony ecosystem needing deferred write batching |

### Alternative C: PSR-16 Base + Custom `TagAwareCacheInterface`

**Description**: Define `phpbb\cache\TagAwareCacheInterface extends Psr\SimpleCache\CacheInterface` adding `invalidateTags(array $tags)` and `getOrCompute(string $key, callable $callback, int $ttl, array $tags)`. Consumer code uses the extended interface; any PSR-16-only consumer still works via the base type.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Gets PSR-16 interoperability *and* tag/stampede features; simple for consumers (getOrCompute is 1 call); type-compatible with any PSR-16 consumer; custom surface is exactly 2 methods |
| **Cons** | Custom interface = custom documentation; third-party PSR-16 adapters won't natively support tags; `getOrCompute` conflates caching with computation (some argue separation of concerns) |
| **Best for** | Projects that need tag invalidation and stampede prevention while keeping PSR interoperability |

### Alternative D: Fully Custom Interface

**Description**: Design a bespoke `phpbb\cache\CacheInterface` with no PSR alignment — methods tailored exactly to phpBB's needs (tags, stampede, pools, SQL caching).

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Maximum flexibility; can include SQL-cache methods for legacy compat; no PSR constraints; interface shaped exactly for phpBB patterns |
| **Cons** | Zero ecosystem interoperability; every external library needs a wrapper; contributors must learn bespoke API; higher long-term maintenance; vendor lock-in to our own abstraction |
| **Best for** | Projects with unique caching semantics that PSR interfaces fundamentally cannot represent |

### Trade-Off Comparison

| Criterion | A: PSR-16 | B: PSR-6 | C: PSR-16 + Custom | D: Full Custom |
|-----------|-----------|----------|-------------------|----------------|
| Learning curve | ★★★★★ | ★★★☆☆ | ★★★★☆ | ★★☆☆☆ |
| Ecosystem interop | ★★★★★ | ★★★★☆ | ★★★★☆ | ★☆☆☆☆ |
| Tag support | ✗ native | ✗ native | ✓ native | ✓ native |
| Stampede support | ✗ | ✗ | ✓ getOrCompute | ✓ anything |
| Maintenance burden | ★★★★★ | ★★★☆☆ | ★★★★☆ | ★★☆☆☆ |
| Legacy SQL compat | ✗ | ✗ | ✗ (separate adapter) | ✓ can include |

### ➤ Recommended: C — PSR-16 Base + Custom `TagAwareCacheInterface`

**Rationale**: This gives the best balance. The 8 PSR-16 methods cover 80% of use cases and ensure ecosystem compatibility. The 2 custom methods (`invalidateTags`, `getOrCompute`) address the exact gaps phpBB needs. Legacy SQL caching is a separate concern (Decision 7) and should not pollute the general cache interface. The custom surface is small enough that documentation is trivial.

---

## Decision Area 2: Tag Invalidation Mechanism

### Context

phpBB needs multi-tag per item — a cached post belongs to both `forum:{id}` and `topic:{id}`. When a topic is moved between forums, both old and new forum caches must be invalidated. The hierarchy service caches forum trees tagged with `forum:{id}` for subtree invalidation. The mechanism must work across all backends (file, Redis, APCu, Memcached) without backend-specific tag storage APIs.

### Alternative A: Version Counter per Tag (Symfony Approach)

**Description**: Each tag has a version counter stored in the cache backend itself. When an item is cached, the current versions of its tags are stored alongside. On read, the stored tag versions are compared to current versions — if any tag version has incremented, the item is considered stale and recomputed. Invalidation = increment the tag's version counter (O(1) per tag).

| Aspect | Assessment |
|--------|-----------|
| **Pros** | O(tags) invalidation regardless of how many items share a tag; battle-tested in Symfony Cache; no crash-consistency risk (stale items just get recomputed); works identically across all backends; lazy cleanup (stale items evicted on next read or GC) |
| **Cons** | Extra read on every cache hit (fetch tag versions); stale items waste storage until read; tag version storage must be in same backend or a shared one; adds ~1 extra key lookup per tag per read |
| **Best for** | Systems with many items per tag and infrequent invalidation (forums, topic trees) |

### Alternative B: Direct Tag-to-Key Mapping (Reverse Index)

**Description**: Maintain a reverse index mapping each tag to the set of cache keys with that tag. On invalidation, iterate the set and delete each key. Storage: `tag:forum:5 → [key1, key2, key3, ...]`.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Immediate physical deletion (no stale items); cache hit is simple (no version check); storage-efficient (deleted items don't linger) |
| **Cons** | O(items-per-tag) invalidation — can be thousands for popular forums; crash between deleting some keys and updating the index = inconsistent state; reverse index itself must be cached (chicken-and-egg); filesystem backend: deleting 1000 files is I/O-expensive; Memcached has no native set type |
| **Best for** | Systems with few items per tag and frequent invalidation; Redis with native SET type |

### Alternative C: Key-Prefix Versioning

**Description**: Encode the tag version directly in the cache key namespace: `v3:forum:5:tree`. Invalidating tag `forum:5` increments its version to `v4`, making all `v3:*` keys invisible (orphaned). New writes use `v4:forum:5:*`.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | O(1) invalidation (just increment prefix version); zero overhead on cache hits (no version check — version is in the key); conceptually simple |
| **Cons** | Only supports single-tag-per-item (can't encode two tag versions in one key); orphaned keys waste storage indefinitely; key length grows with version encoding; requires GC for orphaned keys; fundamentally incompatible with multi-tag requirement |
| **Best for** | Single-tag scenarios like per-tenant isolation or simple namespace versioning |

### Alternative D: No Tags (TTL-Only Invalidation)

**Description**: No tag mechanism at all. Cache items expire only via TTL. Mutations either wait for TTL expiry or call explicit `delete($key)` for known keys.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Simplest implementation; zero overhead; no metadata storage; any cache backend works; perfectly predictable behavior |
| **Cons** | Cannot invalidate "all caches for forum X" without knowing every key; stale data visible for full TTL after mutations; forces consumers to track their own keys; useless for interconnected data (forum→topics→posts) |
| **Best for** | Independent, isolated cache entries with acceptable staleness windows |

### Trade-Off Comparison

| Criterion | A: Version Counter | B: Reverse Index | C: Key-Prefix | D: No Tags |
|-----------|-------------------|-------------------|---------------|------------|
| Multi-tag per item | ✓ | ✓ | ✗ | N/A |
| Invalidation cost | O(tags) | O(items-per-tag) | O(1) | N/A |
| Cache hit overhead | +1 lookup per tag | None | None | None |
| Crash consistency | ✓ safe | ✗ risk | ✓ safe | ✓ safe |
| Storage waste | Medium (lazy) | Low (immediate) | High (orphaned) | None |
| Backend portability | ✓ all | ✗ needs SET type | ✓ all | ✓ all |
| Implementation effort | Medium | High | Low | None |

### ➤ Recommended: A — Version Counter per Tag

**Rationale**: Multi-tag per item is a hard requirement (posts tagged with both `forum:{id}` and `topic:{id}`). This eliminates C and D. Between A and B, the version counter approach has crash-consistency guarantees, O(tags) invalidation cost (vs O(items) for reverse index), and works identically across file/Redis/APCu/Memcached backends. The extra tag-version lookup per read is acceptable — it's one array comparison from a single cached value. This is the same approach used by Symfony's `TagAwareAdapter`, proven at scale.

---

## Decision Area 3: Stampede Prevention Strategy

### Context

Cache stampedes occur when a popular cache entry expires and many concurrent requests all attempt to recompute it simultaneously. In phpBB, expensive computations include: ContentPipeline BBCode rendering (50-200ms), hierarchy tree materialization (DB-heavy), and forum statistics aggregation. Most cache items are cheap (config values, ACL lookups) and don't need stampede protection. The solution must work on filesystem (shared hosting, no external coordination) and Redis (production).

### Alternative A: XFetch Probabilistic Early Expiry Only

**Description**: Implement the XFetch algorithm — each cached item stores its compute time (`delta`). As TTL approaches expiry, a probabilistically chosen request recomputes early (before actual expiry), preventing the thundering herd. Probability: `delta * beta * log(random()) + now > expiry`. The `beta` parameter (default 1.0) controls how early recomputation starts.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Zero infrastructure (works on file, APCu, anywhere); no locking needed; mathematically proven optimal (Vattani et al., 2015); configurable via beta parameter; single request recomputes while others still get old value |
| **Cons** | Non-deterministic — hard to debug "why did it recompute now?"; requires storing delta (compute time) with each entry; very short TTLs have insufficient probabilistic window; doesn't protect against cold-start stampede (first fill) |
| **Best for** | Hot keys with moderate compute cost where occasional duplicate computation is acceptable |

### Alternative B: Mutex Locking Only

**Description**: When a cache miss occurs, acquire a lock (flock for file, SETNX for Redis, APCu CAS). First request to acquire lock recomputes; others wait (with short timeout) or return stale value if available.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Deterministic — exactly one request recomputes; protects cold-start; debuggable (lock state is observable); stale-while-revalidate pattern possible |
| **Cons** | Requires lock infrastructure per backend (flock, SETNX, etc.); lock contention on hot keys; deadlock risk if recomputation fails; wait/timeout logic adds complexity; filesystem locking is unreliable on NFS |
| **Best for** | Expensive computations where duplicate work is costly (>100ms) |

### Alternative C: XFetch Default + Opt-In Locking for High-Cost

**Description**: XFetch is the default for all cache entries. High-cost computations (explicitly marked by the consumer) additionally use mutex locking. `getOrCompute($key, $callback, $ttl, $tags, $options)` where `$options['lock'] = true` enables locking.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Best of both worlds — cheap items get zero-infrastructure XFetch; expensive items get deterministic locking; consumer controls the trade-off; no overhead for 90% of cache entries |
| **Cons** | Two code paths to maintain and test; consumers must know which items are "expensive"; lock infrastructure still needed for the locking path; slightly more complex API |
| **Best for** | Mixed workloads with both cheap config-style caching and expensive computation caching |

### Alternative D: No Stampede Prevention

**Description**: No stampede prevention at all. Every cache miss triggers recomputation regardless of concurrent requests.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Simplest implementation; zero overhead; zero complexity; easy to understand and debug |
| **Cons** | Hot key expiry causes N concurrent recomputations; ContentPipeline renders at 200ms × 50 concurrent users = 10 seconds of wasted CPU; DB load spikes on cache expiry; hierarchy tree rebuilds hammer the database |
| **Best for** | Low-traffic sites or items with trivial compute cost (<1ms) |

### Trade-Off Comparison

| Criterion | A: XFetch | B: Mutex | C: XFetch + Mutex | D: None |
|-----------|-----------|----------|-------------------|---------|
| Infrastructure needed | None | Per-backend locks | Per-backend locks (opt-in) | None |
| Cold-start protection | ✗ | ✓ | ✓ (when locked) | ✗ |
| Hot-key protection | ✓ (probabilistic) | ✓ (deterministic) | ✓ (both) | ✗ |
| Overhead on hit | Minimal (delta check) | None | Minimal | None |
| Complexity | Low | Medium | Medium-High | None |
| Works on filesystem | ✓ | ✓ (flock) | ✓ | ✓ |
| Debuggability | ★★★☆☆ | ★★★★☆ | ★★★☆☆ | ★★★★★ |

### ➤ Recommended: C — XFetch Default + Opt-In Locking

**Rationale**: The workload is mixed. ~90% of cached items are cheap (config lookups, ACL options, word censors — <1ms to compute). XFetch handles these with zero infrastructure. The remaining ~10% (ContentPipeline renders at 50-200ms, hierarchy tree builds with multiple DB queries) genuinely need deterministic protection. Opt-in locking via `$options['lock'] = true` lets consumers declare expensive items without imposing locking overhead everywhere. The lock implementation per backend is contained (flock for file, SETNX+TTL for Redis, APCu CAS) and only activated when requested.

---

## Decision Area 4: Pool Architecture

### Context

~5 services will use the cache: threads, messaging, storage, auth, hierarchy. Each has different characteristics — hierarchy data changes rarely (long TTL, tag invalidation), auth sessions change frequently (short TTL), thread caches are large (per-topic rendering). Some installations may want Redis for threads + filesystem for auth. Pools provide namespace isolation so `threads:post:123` doesn't collide with `auth:session:abc`. The question is how pools relate to backends and connections.

### Alternative A: Single Global Pool with Key Prefixing

**Description**: One pool, one backend connection. All services share it. Namespace isolation via key prefixes: `threads:post:123`, `auth:session:abc`. Configuration is global — all services use the same backend, same TTL defaults.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Simplest setup (one connection, one config); no pool management; minimal resource usage; trivial to understand |
| **Cons** | Cannot per-service backend selection (all or nothing); one service's `clear()` affects all; tag namespace collisions possible without careful naming; single failure point; cannot set different TTL defaults per service |
| **Best for** | Small installations where all services have similar caching needs |

### Alternative B: Independent Pools per Service

**Description**: Each pool is a completely independent instance with its own backend connection, configuration, and lifecycle. `threads_cache` might be Redis, `auth_cache` might be APCu, `hierarchy_cache` might be file.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Maximum isolation — one pool's issues don't affect others; per-service backend selection; per-service TTL defaults; independent clear/purge; easiest to reason about |
| **Cons** | 5 Redis connections instead of 1 (resource waste); configuration complexity (5 backend configs); harder to monitor (5 separate cache states); connection pooling not shared |
| **Best for** | Systems where services have fundamentally different backend needs and resources are abundant |

### Alternative C: Shared Backend Connection, Logical Namespace Isolation

**Description**: Pools share a backend connection (one Redis connection, one filesystem directory) but have logical namespace isolation via key prefixes. Each pool has its own TTL defaults, tags, and configuration — but the underlying adapter instance is shared. DI container wires pools from a shared `CacheAdapterFactory`.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | One connection per backend type (efficient); per-pool TTL defaults and tag isolation; can still override backend per pool when needed; pool `clear()` only clears its namespace; factory pattern keeps DI clean |
| **Cons** | Shared connection = shared failure; namespace prefixing adds key length; slightly more complex than global pool; factory abstraction to maintain |
| **Best for** | Systems with multiple logical pools but shared infrastructure — the typical web application pattern |

### Alternative D: Hierarchical Pools (Parent with Child Overrides)

**Description**: A parent pool defines defaults (backend, TTL, serializer). Child pools inherit and override specific settings. E.g., parent = Redis/300s TTL; `hierarchy_pool` overrides TTL to 3600s; `auth_pool` overrides backend to APCu.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | DRY configuration (only override what differs); natural for settings that are "mostly the same with exceptions"; handles the common case well |
| **Cons** | Inheritance chain adds indirection; harder to understand what a pool's actual config is; override resolution logic to implement; debugging "which TTL am I actually using?" is confusing; uncommon pattern (less community knowledge) |
| **Best for** | Systems with many pools that share most configuration but differ in a few parameters |

### Trade-Off Comparison

| Criterion | A: Global | B: Independent | C: Shared + NS | D: Hierarchical |
|-----------|-----------|---------------|----------------|-----------------|
| Resource efficiency | ★★★★★ | ★★☆☆☆ | ★★★★☆ | ★★★★☆ |
| Isolation | ★★☆☆☆ | ★★★★★ | ★★★★☆ | ★★★★☆ |
| Per-service config | ✗ | ✓ full | ✓ logical | ✓ inherited |
| Simplicity | ★★★★★ | ★★★★☆ | ★★★☆☆ | ★★☆☆☆ |
| Per-service backend | ✗ | ✓ | ✓ (override) | ✓ (override) |
| DI complexity | Low | Medium | Medium | High |

### ➤ Recommended: C — Shared Backend Connection, Logical Namespace Isolation

**Rationale**: With ~5 pools, independent connections waste resources (5 Redis connections is excessive for most phpBB installs). The global pool is too limiting — we need per-pool TTL defaults and the ability to override backends for specific services. Shared connection + namespace isolation gives the best balance: one Redis connection serves all pools, each pool has `{service}:` key prefix, and the DI container wires pools via a `CachePoolFactory`. If a specific pool needs a different backend (e.g., APCu for auth hot-path), it can override at the pool level without affecting others.

---

## Decision Area 5: Serialization Strategy

### Context

Different cache backends have fundamentally different optimal serialization strategies. For filesystem: `var_export()` + `include()` with opcache is ~10x faster than `unserialize()` because PHP opcache caches the compiled bytecode. For Redis: `igbinary` is ~50% smaller and ~30% faster than `serialize()`. For APCu: no serialization needed (stores native PHP values in shared memory). A unified strategy leaves significant performance on the table; a per-backend strategy leaks abstraction.

### Alternative A: Unified `serialize()` for All Backends

**Description**: Use PHP's native `serialize()`/`unserialize()` for all backends. Simple, consistent, works everywhere.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Zero complexity; no abstraction needed; consistent behavior across backends; `serialize()` handles all PHP types including objects |
| **Cons** | 10x slower than var_export+include for filesystem; 50% larger than igbinary for Redis; wastes APCu's native storage capability; performance-critical paths suffer unnecessarily |
| **Best for** | Prototyping or when serialization performance is irrelevant |

### Alternative B: Per-Backend Hardcoded Optimal

**Description**: Each backend adapter hardcodes its optimal serialization: file adapter uses `var_export()`+`include()`, Redis adapter uses `igbinary` (fallback `serialize()`), APCu adapter uses native storage, Memcached adapter uses `igbinary`/`serialize()`.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Maximum performance per backend; each adapter knows its optimal strategy; no abstraction overhead; simple per-adapter implementation |
| **Cons** | Serialization logic duplicated across adapters; cannot override (e.g., use JSON for debugging); new backend = new serialization code; testing harder (must test each strategy) |
| **Best for** | Systems where backend set is fixed and performance is critical |

### Alternative C: Configurable Marshaller Abstraction

**Description**: Define `MarshallerInterface` with `marshall(array $values): array` and `unmarshall(string $data): mixed`. Each backend has a default marshaller (`VarExportMarshaller` for file, `IgbinaryMarshaller` for Redis) that can be overridden via DI config. Auto-detect `igbinary` extension availability.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Optimal defaults per backend + override capability; testable in isolation; new backends plug in with their marshaller; igbinary auto-detection; can swap to JSON for debugging |
| **Cons** | Extra abstraction layer; interface to maintain; marshaller selection logic; slight indirection overhead (negligible); more classes |
| **Best for** | Systems that value both performance and flexibility — the "correct abstraction" approach |

### Alternative D: JSON Everywhere

**Description**: Use `json_encode()`/`json_decode()` for all backends. Human-readable, cross-language compatible.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Human-readable cache files (great for debugging); cross-language compatible (Redis values readable from Node.js); no PHP-specific serialization concerns |
| **Cons** | Lossy — cannot serialize PHP objects, closures, or resources; `DateTime` objects flattened; slower than specialized serializers; larger than igbinary; breaks any consumer storing objects |
| **Best for** | Cross-language cache sharing or debugging-heavy development environments |

### Trade-Off Comparison

| Criterion | A: serialize() | B: Hardcoded | C: Marshaller | D: JSON |
|-----------|---------------|-------------|---------------|---------|
| File backend perf | ★★☆☆☆ | ★★★★★ | ★★★★★ | ★★☆☆☆ |
| Redis bandwidth | ★★★☆☆ | ★★★★★ | ★★★★★ | ★★☆☆☆ |
| PHP type fidelity | ★★★★★ | ★★★★★ | ★★★★★ | ★★☆☆☆ |
| Debuggability | ★★☆☆☆ | ★★☆☆☆ | ★★★☆☆ | ★★★★★ |
| Abstraction cost | None | None | Low | None |
| Flexibility | ✗ | ✗ | ✓ override | ✗ |

### ➤ Recommended: C — Configurable Marshaller Abstraction

**Rationale**: The performance difference between var_export+opcache and serialize() on filesystem is too large to ignore (~10x on reads). Similarly, igbinary's 50% size reduction matters for Redis network I/O. But hardcoding per backend (B) prevents overrides and creates duplication. The marshaller abstraction is a single interface with 2 methods — minimal maintenance cost for significant flexibility. Each adapter ships with its optimal default marshaller. The auto-detection of igbinary (falling back to serialize) means zero-config optimal performance.

---

## Decision Area 6: Multi-Tier Support

### Context

Multi-tier caching (L1 local + L2 remote) can dramatically reduce latency for stable data. APCu as L1 (process-local, ~100ns reads) in front of Redis L2 (~0.5ms reads) gives 5000x speedup for hot data. However: most phpBB installations are single-server (no Redis), APCu isn't always available, and L1 caches on different servers can diverge (staleness). Short L1 TTLs (10-30s) limit staleness windows. The hierarchy tree (changes rarely, read on every page) is ideal for L1; active thread caches (change frequently) are not.

### Alternative A: Always-On L1/L2 When APCu Available

**Description**: If APCu extension is detected, automatically add an APCu L1 tier in front of every pool's primary backend. L1 TTL fixed at 30s. No configuration needed.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Zero-config optimization; transparent to consumers; automatic benefit for all pools; simple mental model ("if APCu exists, it's faster") |
| **Cons** | L1 applied to pools where it's harmful (frequently-changing data gets stale); 30s staleness for ALL data; no way to disable per pool; magic behavior hard to debug; APCu memory pressure from all pools |
| **Best for** | Systems where all cached data changes infrequently and consistency tolerance is uniform |

### Alternative B: Optional ChainAdapter Configuration

**Description**: Admin explicitly configures multi-tier per pool via DI/config. E.g., `hierarchy_pool: [apcu(ttl=30), redis]`. Default is single-tier. The `ChainPool` wraps multiple pools in priority order.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Full control — admin decides which pools get L1; per-pool L1 TTL; no surprise behavior; can chain any combination (APCu→Redis, APCu→File); explicit is better than implicit |
| **Cons** | Requires admin knowledge to configure; default installs get no benefit; more YAML config to write; ChainPool implementation needed |
| **Best for** | Production deployments with performance-aware administrators |

### Alternative C: Not Supported (Single Backend per Pool)

**Description**: Each pool has exactly one backend. No multi-tier. If you want APCu, configure the pool to use APCu. If you want Redis, use Redis. No chaining.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Simplest possible architecture; no staleness concerns; easy to debug; less code to maintain; fewer edge cases |
| **Cons** | Cannot get both APCu speed and Redis persistence; must choose one backend per pool; misses significant optimization opportunity for stable data |
| **Best for** | MVP / first release where simplicity is prioritized over optimization |

### Alternative D: Smart Auto-Tier (Detect APCu, Apply to Stable Data)

**Description**: Auto-detect APCu availability. If present, automatically add L1 only to pools explicitly marked as `stable` in their DI config (e.g., hierarchy, config). Pools not marked stable remain single-tier. L1 TTL configurable per pool (default 30s).

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Smart defaults — stable data gets L1, volatile data doesn't; opt-in via pool config attribute (not separate admin step); auto-detection of APCu; bounded staleness risk |
| **Cons** | "Stable" classification is developer judgment (could be wrong); still magic (auto-detect); pool config has another attribute to understand; detection logic to maintain |
| **Best for** | Systems where developers can classify data stability and want automatic optimization |

### Trade-Off Comparison

| Criterion | A: Always-On | B: Explicit Chain | C: Not Supported | D: Smart Auto |
|-----------|-------------|-------------------|-----------------|---------------|
| Zero-config benefit | ✓ | ✗ | N/A | ✓ (for marked pools) |
| Staleness control | ✗ (all pools) | ✓ (per-pool TTL) | N/A | ✓ (marked only) |
| Implementation effort | Medium | Medium-High | None | Medium |
| Admin knowledge needed | None | High | None | Low |
| Correctness risk | High | Low | None | Medium |

### ➤ Recommended: C (initial release) → B (future enhancement)

**Rationale**: For the initial release, single-backend-per-pool is the right call. Most phpBB installs are single-server — the L1/L2 optimization matters for high-traffic sites which are the minority. Adding multi-tier now means maintaining ChainPool, managing staleness, and handling edge cases before we've validated the core cache service works. The pool architecture (Decision 4) already supports per-pool backend override, so configuring a pool to use APCu directly is possible. When demand justifies it, adding ChainPool as an optional adapter (Alternative B) is additive — it doesn't require rearchitecting. Building for this future by keeping the adapter interface clean is sufficient.

---

## Decision Area 7: Legacy Compatibility

### Context

The legacy `cache\service` is a 17-method facade used by ~25 services via `@cache` and `@cache.driver` DI references. The `driver_interface` includes SQL cursor methods (`sql_load`, `sql_save`, `sql_fetchrow`, `sql_rowseek`, `sql_freeresult`) that are tightly coupled to `\phpbb\db\driver\driver_interface`. These SQL methods don't map to any standard cache interface. The new service must coexist with legacy consumers during migration. A big-bang rewrite of all 25 consumers is high-risk.

### Alternative A: Clean Break

**Description**: New `phpbb\cache` namespace, new interface, no backward compatibility. All existing consumers must be rewritten to use the new API. Old `cache\service` and `cache\driver\*` are deleted.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | No legacy baggage; clean codebase from day 1; no maintenance of bridge code; forces complete modernization |
| **Cons** | All ~25 consumers must be rewritten simultaneously; high risk of breakage; blocks other work until migration complete; SQL cache users need complete rewrite; no incremental path |
| **Best for** | Greenfield rewrites or very small legacy surfaces |

### Alternative B: Bridge Adapter (LegacyCacheBridge)

**Description**: Create `LegacyCacheBridge implements driver_interface` that wraps the new `TagAwareCacheInterface`. The bridge translates legacy method calls to new API calls. Legacy consumers continue working unchanged. The bridge is marked `@deprecated` with migration guides. SQL cursor methods delegate to a specialized `SqlResultCache` component.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Zero disruption to existing consumers; gradual migration at each consumer's pace; bridge is a single class; `@deprecated` warnings surface in static analysis; SQL caching isolated to dedicated component; new services use new API directly |
| **Cons** | Bridge code to maintain until all consumers migrated; some legacy methods may have subtly different semantics; bridge testing overhead; two APIs coexist (potential confusion) |
| **Best for** | Large legacy codebases with many consumers and a need for incremental migration |

### Alternative C: Dual API Maintained Indefinitely

**Description**: Both the new `TagAwareCacheInterface` and the legacy `driver_interface` are first-class APIs, backed by the same engine. No deprecation — both coexist permanently.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | No migration pressure; legacy consumers never break; both interfaces are "correct"; no bridge overhead |
| **Cons** | Double the API surface indefinitely; two interfaces to document and maintain; new contributors confused by two cache APIs; no incentive to modernize; technical debt becomes permanent |
| **Best for** | Systems where legacy API consumers will never be rewritten |

### Alternative D: Adapter per Legacy Method Subset

**Description**: Create separate adapters for distinct legacy usage patterns: `SqlResultCacheAdapter` (wraps sql_load/sql_save/sql_fetchrow), `KvCacheAdapter` (wraps get/put/destroy), each delegating to the new service. Legacy consumers choose the adapter matching their usage pattern.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Smaller, focused adapters; SQL caching clearly separated; KV adapter is almost trivial; each adapter can be deprecated/removed independently |
| **Cons** | Legacy consumers must still change their DI reference (from `@cache` to `@cache.kv` or `@cache.sql`); not transparent migration; multiple adapter classes to maintain; doesn't solve the "~25 consumers need updating" problem |
| **Best for** | Systems with clearly separable legacy usage patterns where consumers can update DI references |

### Trade-Off Comparison

| Criterion | A: Clean Break | B: Bridge | C: Dual API | D: Per-Subset |
|-----------|---------------|-----------|------------|---------------|
| Migration disruption | ★★★★★ | ★☆☆☆☆ | None | ★★★☆☆ |
| Long-term maintenance | ★★★★★ | ★★★★☆ | ★★☆☆☆ | ★★★☆☆ |
| Incremental migration | ✗ | ✓ | N/A | Partial |
| API clarity | ★★★★★ | ★★★☆☆ | ★★☆☆☆ | ★★★☆☆ |
| SQL cache handling | Consumer rewrites | SqlResultCache | Both APIs | Dedicated adapter |
| Implementation risk | High | Low | Low | Medium |

### ➤ Recommended: B — Bridge Adapter (LegacyCacheBridge)

**Rationale**: ~25 consumers using the legacy API makes a clean break (A) impractical — it would block all other development. Dual API (C) is a maintenance trap. The bridge approach (B) gives zero-disruption migration: `@cache.driver` points to `LegacyCacheBridge` wrapping the new engine. Legacy consumers work unchanged. New services use `TagAwareCacheInterface` directly. The SQL cursor methods (`sql_load`, `sql_fetchrow`, etc.) delegate to a dedicated `SqlResultCache` component that uses the new cache under the hood but provides the cursor API legacy code expects. As each legacy consumer is modernized, it switches from `@cache` to `@cache.pool.{service}`. When the last consumer migrates, the bridge is deleted. `@deprecated` annotations ensure static analysis tools flag remaining usages.

---

## Decision Area 8: Default Backend

### Context

phpBB's traditional audience includes shared hosting (no Redis, no Memcached, no APCu). The default backend must work everywhere with zero configuration. The legacy default is filesystem-based caching using PHP files in `cache/production/`. Redis is the industry standard for production caching. The backend choice most impacts developer experience (debugging), performance, and deployment requirements.

### Alternative A: Filesystem (Legacy Default)

**Description**: Cache entries stored as PHP files in a configurable directory (default `cache/`). Uses `var_export()` + `include()` for optimal opcache integration. Lock files for concurrency. Matches the current phpBB behavior.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Zero external dependencies; works on every PHP hosting; matches legacy behavior (familiar); opcache makes reads ~10x faster than `unserialize()`; cache entries human-inspectable (PHP files); proven in phpBB for 15+ years |
| **Cons** | Filesystem I/O for every write; directory can grow large (thousands of files); no atomic multi-key operations; NFS unfriendly; disk I/O bottleneck under high concurrency; no native TTL expiry (requires GC cron) |
| **Best for** | Shared hosting; single-server deployments; development environments; the phpBB default audience |

### Alternative B: Redis with Filesystem Fallback

**Description**: Try to connect to Redis (configurable host/port). If connection fails, silently fall back to filesystem. Auto-detection at boot time.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Optimal when Redis available; still works without Redis; "just works" for both shared hosting and production; single configuration covers both paths |
| **Cons** | Silent fallback is magic — admin may not realize they're on filesystem; connection attempt on every boot adds latency when Redis is absent; fallback path is a second code path to test; behavior changes based on infrastructure state (non-deterministic); debugging "why is my cache slow?" is confusing |
| **Best for** | Environments that want Redis when possible but need a safety net |

### Alternative C: Database

**Description**: Cache stored in a `phpbb_cache` database table. Uses the existing database connection — no new dependencies. Supports transactions for consistency.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Works everywhere a database exists (truly universal); better concurrency than file (row-level locking); transactional consistency; no filesystem permissions issues; native TTL via `WHERE expires_at > NOW()` |
| **Cons** | Adds load to already-busy database; cache reads become SQL queries (defeating the purpose of caching); schema migration required; slower than file + opcache for reads; database is often the bottleneck in phpBB |
| **Best for** | Environments where filesystem is unreliable (read-only FS, ephemeral containers without persistent volumes) |

### Alternative D: Auto-Detect Best Available

**Description**: At first boot, detect available backends in priority order: APCu > Redis > Memcached > Filesystem. Use the best one found. Store detection result in config for subsequent boots.

| Aspect | Assessment |
|--------|-----------|
| **Pros** | Zero-config optimal performance; automatically uses the best available backend; config stored after first detection (no repeated probing) |
| **Cons** | Magic behavior — admin doesn't know which backend is active; backend changes if extensions are installed/removed; very hard to debug; test environments differ from production; "works on my machine" problems; implicit dependency on infrastructure state |
| **Best for** | Hypothetically convenient but practically problematic for real-world deployments |

### Trade-Off Comparison

| Criterion | A: Filesystem | B: Redis + Fallback | C: Database | D: Auto-Detect |
|-----------|-------------|--------------------|-----------|--------------| 
| Zero-config works | ✓ | ✓ (with fallback) | ✓ (DB exists) | ✓ |
| Shared hosting | ✓ | ✓ (fallback) | ✓ | ✓ |
| Performance (reads) | ★★★★☆ (opcache) | ★★★★★ (Redis) / ★★★★☆ (fallback) | ★★☆☆☆ | Varies |
| Predictability | ★★★★★ | ★★☆☆☆ | ★★★★★ | ★☆☆☆☆ |
| Debuggability | ★★★★★ | ★★☆☆☆ | ★★★★☆ | ★☆☆☆☆ |
| High concurrency | ★★☆☆☆ | ★★★★★ / ★★☆☆☆ | ★★★☆☆ | Varies |
| DB load impact | None | None | Negative | Varies |

### ➤ Recommended: A — Filesystem

**Rationale**: Filesystem is the only backend that works on every PHP installation with zero configuration — and that's phpBB's audience. With `var_export()` + opcache, read performance is excellent for single-server deployments. Redis should be easy to enable (one config line), but it should be an explicit choice, not a magic fallback. Auto-detection (D) violates the principle of least surprise. Database (C) adds load to the database, which is already phpBB's bottleneck. The configuration path for Redis/Memcached/APCu should be clearly documented and straightforward — but the default must be the lowest-common-denominator that Just Works™.

---

## User Preferences

Based on the stated requirements and phpBB project constraints:

- **Shared hosting compatibility** is non-negotiable (filesystem default)
- **Tag-based invalidation** is a hard requirement (forum/topic cross-invalidation)
- **PSR compliance** is preferred for ecosystem interop
- **Gradual migration** from legacy is preferred over big-bang rewrite
- **~5 consumers** — threads, messaging, storage, auth, hierarchy
- **Simplicity** is valued — avoid over-engineering for V1

---

## Recommended Approach Summary

| # | Decision Area | Recommended | Confidence |
|---|--------------|-------------|------------|
| 1 | Interface Standard | **C**: PSR-16 + Custom `TagAwareCacheInterface` | High |
| 2 | Tag Invalidation | **A**: Version Counter per Tag (Symfony approach) | High |
| 3 | Stampede Prevention | **C**: XFetch default + opt-in locking | High |
| 4 | Pool Architecture | **C**: Shared backend connection, logical namespace isolation | High |
| 5 | Serialization | **C**: Configurable marshaller abstraction | Medium-High |
| 6 | Multi-Tier | **C→B**: Single-tier V1, opt-in ChainPool later | High |
| 7 | Legacy Compatibility | **B**: LegacyCacheBridge + SqlResultCache | High |
| 8 | Default Backend | **A**: Filesystem (zero deps, opcache-optimized) | High |

### Key Assumptions

1. **~5 pools is the steady-state** — if pool count grows to 20+, the factory pattern in Decision 4 should be revisited
2. **Filesystem opcache is available** — the var_export optimization assumes opcache is enabled (it is by default since PHP 5.5)
3. **Multi-server deployments are the minority** — single-tier is acceptable for V1 because most phpBB installs are single-server
4. **Legacy consumers will be migrated within 2-3 major versions** — the bridge (Decision 7) is temporary, not permanent
5. **igbinary extension is optional** — the marshaller auto-detects it but falls back gracefully

### Key Trade-Offs Accepted

- **Extra read per cache hit** (Decision 2) — tag version check adds ~1 key lookup per tagged read; acceptable given forum data is read-heavy and tag count per item is typically 2-3
- **Two code paths for stampede prevention** (Decision 3) — XFetch + opt-in locking is more complex than a single strategy, but the mixed workload justifies it
- **Bridge maintenance cost** (Decision 7) — maintaining `LegacyCacheBridge` until full migration is technical debt, but the alternative (breaking 25 consumers) is worse
- **No multi-tier in V1** (Decision 6) — leaves optimization on the table for high-traffic sites, but simplifies initial delivery

---

## Why Not Others (Non-Selected Alternatives)

| Decision | Rejected | Why Not |
|----------|----------|---------|
| 1 | A (PSR-16 only) | No tag or stampede support — would require external bolting |
| 1 | B (PSR-6) | Object model is verbose for phpBB's simple get/set patterns |
| 1 | D (Full custom) | Zero ecosystem interop for negligible flexibility gain |
| 2 | B (Reverse index) | O(items) invalidation + crash-consistency risk on filesystem |
| 2 | C (Key-prefix) | Cannot support multi-tag per item (hard requirement) |
| 2 | D (No tags) | Cannot invalidate "all caches for forum X" without tracking every key |
| 3 | A (XFetch only) | No cold-start protection for expensive ContentPipeline renders |
| 3 | B (Mutex only) | Unnecessary locking overhead for 90% of cheap cache entries |
| 3 | D (None) | 50 concurrent users × 200ms render = 10s wasted CPU on hot key expiry |
| 4 | A (Global) | Cannot set per-service TTL or backend |
| 4 | B (Independent) | 5 Redis connections is wasteful for typical phpBB installs |
| 4 | D (Hierarchical) | Override resolution adds indirection for marginal config DRYness |
| 5 | A (serialize all) | 10x slower than var_export for filesystem — too large a penalty |
| 5 | B (Hardcoded) | Cannot override per backend; duplicates serialization logic |
| 5 | D (JSON) | Lossy for PHP objects (DateTime, etc.) — type fidelity matters |
| 6 | A (Always-on L1) | Stale L1 for volatile data; no per-pool control |
| 6 | D (Smart auto) | Magic detection + "stable" classification is dev judgment call |
| 7 | A (Clean break) | ~25 consumers must rewrite simultaneously — blocks all other work |
| 7 | C (Dual API) | Two permanent APIs is maintenance trap |
| 7 | D (Per-subset) | Consumers still must change DI references — not transparent |
| 8 | B (Redis+fallback) | Silent fallback is unpredictable; connection probe adds boot latency |
| 8 | C (Database) | Adds load to phpBB's bottleneck (the database) |
| 8 | D (Auto-detect) | Magic backend selection = "works on my machine" problems |

---

## Deferred Ideas

1. **Cache warming CLI command** — `bin/phpbbcli.php cache:warm` that pre-populates critical pools (hierarchy tree, config). Useful after deployments but not needed for V1.
2. **Cache statistics dashboard** — ACP page showing hit/miss rates per pool, memory usage, tag invalidation counts. Valuable for debugging but a separate feature.
3. **Distributed cache invalidation** — Pub/sub (Redis) or webhook-based invalidation for multi-server deployments. Only relevant when multi-tier (Decision 6) is implemented.
4. **Cache preloading via opcache_compile_file** — For filesystem backend, preload hot cache files into opcache on worker boot. PHP 7.4+ preloading feature. Optimization for later.
5. **Write-behind / write-through patterns** — Queue cache writes for batch persistence. Only relevant for high-write scenarios (messaging cache). Not needed for V1.

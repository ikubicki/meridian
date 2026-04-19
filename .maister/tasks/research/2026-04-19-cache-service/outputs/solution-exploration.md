# Cache Service — Solution Exploration

## Overview

Eight design decisions shape the `phpbb\cache` utility service architecture. Each presents 4 alternatives evaluated against phpBB's constraints: shared hosting support, ~5 service consumers, filesystem-first philosophy, and eventual Redis production path.

---

## Decision 1: Interface Standard

**Context**: The cache service needs a consumer-facing API. PSR standards provide interop but lack phpBB-specific features (tags, compute pattern). A purely custom interface gives freedom but isolates from the ecosystem.

### Option A: PSR-16 SimpleCache Only

Implement `Psr\SimpleCache\CacheInterface` (8 methods: get, set, delete, clear, has, getMultiple, setMultiple, deleteMultiple).

- **Pros**: Industry-standard, trivial to swap implementations, any PSR-16 library works as alternative backend, consumers have familiar API
- **Cons**: No tag invalidation, no get-or-compute, no stampede prevention — all advanced features require separate interfaces or wrapper classes
- **Best for**: Projects needing maximum library interop with minimal custom behavior

### Option B: PSR-6 CacheItemPool

Implement `Psr\Cache\CacheItemPoolInterface` (9 pool + 6 item methods). Items are explicit objects with expiry, metadata.

- **Pros**: Rich object model, deferred saves (batch writes), Symfony Cache uses this internally, supports metadata per item
- **Cons**: Verbose for consumers (get item → check isHit → get value pattern), more complex than necessary for most use cases, still lacks tags natively
- **Best for**: Framework-level cache infrastructure where deferred batching and item metadata matter

### Option C: PSR-16 Base + Custom TagAwareCacheInterface

Extend PSR-16 with 2 additional methods: `invalidateTags(array $tags): bool` and `getOrCompute(string $key, callable $compute, ?int $ttl, array $tags, float $beta): mixed`.

- **Pros**: Consumers that need only basic cache use PSR-16 contract (swappable). Consumers needing tags/compute depend on the extended interface. Best of both worlds. Familiar `get`/`set` API.
- **Cons**: Custom extension means no third-party library directly implements the full interface. Two interface levels to document.
- **Best for**: phpBB — simple API for most consumers, extended API for threads/hierarchy that need tags + compute.

### Option D: Fully Custom Interface

Define `phpbb\cache\CachePoolInterface` from scratch with all needed methods, ignoring PSR.

- **Pros**: Total freedom in method signatures, naming, return types. Can optimize for phpBB's exact needs.
- **Cons**: No interop with PSR-compliant libraries. Cannot type-hint PSR-16 in consumers. Learning curve for contributors familiar with PSR.
- **Best for**: Highly specialized systems where PSR constraints are genuinely limiting.

### Trade-off Matrix

| Aspect | A (PSR-16) | B (PSR-6) | C (PSR-16+ext) | D (Custom) |
|--------|-----------|-----------|---------------|-----------|
| API simplicity | ★★★★★ | ★★☆ | ★★★★ | ★★★★ |
| Tag support | ✗ | ✗ | ✓ (extended) | ✓ |
| Compute pattern | ✗ | ✗ | ✓ (extended) | ✓ |
| Library interop | ★★★★★ | ★★★★ | ★★★★ (base) | ★☆ |
| Migration effort | Low | Medium | Low | Medium |

**Recommended: C** — PSR-16 as base gives interop + familiar API. Custom extension adds the two features phpBB actually needs (tags, compute). Consumers not needing tags can depend on just `CacheInterface` (PSR-16).

---

## Decision 2: Tag Invalidation Mechanism

**Context**: Threads and hierarchy need tag-based invalidation. A post cached under tags `['forum:3', 'topic:42']` must be invalidated when either forum 3 or topic 42 changes. The mechanism must support multiple tags per item.

### Option A: Version Counter Per Tag (Symfony Approach)

Each tag has an integer version stored separately. On invalidation, increment the version. On read, compare stored tag versions against current — mismatch means miss.

- **Pros**: O(N) where N = tags invalidated (not items). Lazy cleanup — stale items never explicitly deleted, just treated as misses. Works on all backends. Proven at scale (Symfony production). Supports multi-tag per item naturally.
- **Cons**: Every read requires checking tag versions (1 extra lookup per read, can be batched). Stale items waste storage until overwritten or GC'd. Tag version store must be fast (ideally same or faster backend).
- **Best for**: Systems with many items per tag, where tag invalidation must be instant and cheap.

### Option B: Direct Tag-to-Key Mapping

Maintain a reverse index: `tag → Set<key>`. On invalidation, look up all keys for the tag and physically delete each.

- **Pros**: Immediate physical deletion (no stale data). Simple mental model. Storage cleaned immediately.
- **Cons**: O(M) where M = items carrying the tag (forum with 10K topics = 10K deletes). Reverse index storage overhead. Crash between write+mapping-update = inconsistency. Expensive for popular tags.
- **Best for**: Systems with few items per tag, where immediate physical cleanup is required.

### Option C: Key-Prefix Versioning

Encode a version number in the key namespace: `forum:3:v42:topics`. Invalidation = increment prefix version. Old keys become unreachable.

- **Pros**: O(1) invalidation (just increment a number). No scanning or deletion. Simple implementation.
- **Cons**: Cannot support multiple tags per item (one key has one prefix). Old items waste storage until TTL. Cannot combine with other invalidation schemes.
- **Best for**: Single-tag scenarios where storage waste is acceptable.

### Option D: No Tags (TTL Only)

Rely entirely on time-based expiry. No tag concept. Items expire after their TTL regardless of entity changes.

- **Pros**: Simplest implementation. No tag overhead on reads or writes. No tag storage.
- **Cons**: Stale data within TTL window. Cannot immediately invalidate on entity change. Forces very short TTLs for correctness. Wastes compute (recomputing even when data hasn't changed).
- **Best for**: Static data or systems where eventual consistency within TTL is acceptable.

### Trade-off Matrix

| Aspect | A (Version) | B (Mapping) | C (Prefix) | D (None) |
|--------|------------|-------------|-----------|---------|
| Multi-tag per item | ✓ | ✓ | ✗ | N/A |
| Invalidation speed | O(tags) | O(items/tag) | O(1) | N/A |
| Storage waste | Some (stale) | None | More (orphans) | None |
| Complexity | Medium | High | Low | None |
| Crash consistency | Safe (lazy) | Risky | Safe | Safe |
| Read overhead | +1 lookup | None | None | None |

**Recommended: A** — Version counter. phpBB needs multi-tag (post tagged `forum:X` + `topic:Y`). O(tags) is fast even with many items. Proven by Symfony. Lazy cleanup avoids deletion storms.

---

## Decision 3: Stampede Prevention Strategy

**Context**: When a popular cache item expires, concurrent requests all attempt to recompute simultaneously. ContentPipeline renders are expensive. Forum topic listings are high-traffic. Need to avoid thundering herd.

### Option A: XFetch Only

Probabilistic early expiry — each request has a random chance of recomputing before actual expiry. Probability increases near expiry. Controlled by `beta` parameter.

- **Pros**: Zero infrastructure (no locks needed). Works on all backends. Statistically effective — one request "wins" naturally. Minimal overhead. Simple to implement.
- **Cons**: Non-deterministic — theoretically 2+ requests could both decide to recompute. Less effective for very high traffic (many may recompute simultaneously). Requires storing compute-time metadata.
- **Best for**: General-purpose cache items with moderate traffic and moderate compute cost.

### Option B: Mutex Locking Only

First request acquires a lock, computes, stores result. Others wait or return stale value.

- **Pros**: Deterministic — exactly one process recomputes. Guaranteed no duplicate work. Clear semantics.
- **Cons**: Requires lock infrastructure (flock for file, Redis SETNX for Redis). Lock holder crash = stall until lock TTL. Waiters block (latency spike) or need stale-fallback logic. More complex implementation.
- **Best for**: Expensive computations where duplicate work is genuinely costly (heavy DB queries, external API calls).

### Option C: XFetch Default + Opt-in Locking

XFetch is the standard behavior for `getOrCompute()`. A `$locked: true` parameter activates mutex for specific high-cost items.

- **Pros**: Best of both — most items get lightweight probabilistic protection, expensive items get deterministic protection. Consumers choose per use case. Minimal overhead for common case.
- **Cons**: Two code paths to maintain and test. Lock infrastructure still needed (but only used by opt-in items). Marginally more complex API.
- **Best for**: phpBB — ContentPipeline renders justify locking; forum listing queries are fine with XFetch.

### Option D: No Stampede Prevention

Accept that multiple requests may recompute simultaneously on miss. First write wins, others are wasted.

- **Pros**: Simplest implementation. No metadata overhead. No lock infrastructure. No beta parameter complexity.
- **Cons**: Under high traffic, a popular cache miss causes N simultaneous expensive recomputations. DB/CPU spike on cold cache or after invalidation. ContentPipeline renders multiply.
- **Best for**: Low-traffic sites where stampedes are unlikely, or items where compute cost is negligible.

### Trade-off Matrix

| Aspect | A (XFetch) | B (Locking) | C (Both) | D (None) |
|--------|-----------|------------|----------|---------|
| Duplicate compute risk | Low (probabilistic) | Zero | Minimal | High |
| Infrastructure need | None | Lock mechanism | Lock (optional) | None |
| API complexity | Low (+beta) | Medium (+lock) | Medium | None |
| Latency impact | None (async probability) | Waiters may stall | Depends on mode | None |
| Implementation effort | Low | Medium | Medium | None |

**Recommended: C** — XFetch as default (zero-overhead for most items), opt-in locking for ContentPipeline and other expensive computations. `getOrCompute($key, $fn, $ttl, $tags, beta: 1.0, locked: false)`.

---

## Decision 4: Pool Architecture

**Context**: 5 services need cache. Each wants key namespace isolation. Some may need different backends (Redis for threads, file for auth). How are pools structured?

### Option A: Single Global Pool with Key Prefixing

One cache backend connection. All services share it. Keys are prefixed with service name: `threads:content:42`.

- **Pros**: Simplest setup. One connection to manage. One config. Prefix provides logical separation. Low resource usage.
- **Cons**: Cannot have different backends per service. `clear()` clears everything. One service's cache explosion affects all. Cannot tune TTL/eviction per service.
- **Best for**: Simple deployments where one backend serves all needs and services trust each other.

### Option B: Independent Pools Per Service

Each pool is a fully independent cache instance with its own backend connection and configuration.

- **Pros**: Maximum isolation. Per-pool backend (Redis for threads, file for auth). Independent flush. Independent monitoring. No key collision possible.
- **Cons**: Multiple connections (5 Redis connections instead of 1). More config to manage. Higher resource usage. Cannot share tag versions across pools.
- **Best for**: Large-scale deployments where pools genuinely need different backends and full isolation.

### Option C: Shared Backend Connection + Logical Namespace

Pools share a backend connection but each has a key prefix. Factory creates pools that reuse the same Redis/file connection with different namespaces.

- **Pros**: Connection efficiency (1 Redis connection shared). Logical isolation via prefixes. Per-pool flush via prefix delete. Less resources than full independence. Can still override backend per pool when needed.
- **Cons**: Key prefix overhead on every operation. One backend failure affects all pools. Cannot have truly different backends per pool (without override config).
- **Best for**: phpBB — most pools use the same backend, connection reuse is efficient, prefix isolation is sufficient.

### Option D: Hierarchical Pools

Parent pool defines defaults (backend, TTL, serializer). Child pools inherit and override specific settings.

- **Pros**: DRY configuration. Change parent = change all children. Elegant for homogeneous setups.
- **Cons**: Inheritance complexity. Hard to reason about effective config. Over-designed for 5 pools. Debugging "which TTL applies?" is annoying.
- **Best for**: Very large systems with dozens of pools sharing common configuration patterns.

### Trade-off Matrix

| Aspect | A (Single) | B (Independent) | C (Shared+NS) | D (Hierarchical) |
|--------|-----------|----------------|--------------|-----------------|
| Isolation | Key prefix only | Complete | Logical | Inherited |
| Resource use | Minimal | High (N conns) | Low (1 conn) | Medium |
| Per-pool backend | ✗ | ✓ | ✓ (override) | ✓ (override) |
| Config complexity | ★☆ | ★★★ | ★★ | ★★★★ |
| Flush scope | All or nothing | Per-pool | Per-namespace | Per-pool |

**Recommended: C** — Shared connection + namespace isolation. Most phpBB installs use one backend. Connection reuse is efficient. Per-pool backend override covers the 20% case (e.g., auth staying on file while threads uses Redis). Prefix-based flush supports admin "clear threads cache" action.

---

## Decision 5: Serialization Strategy

**Context**: Different backends have fundamentally different optimal serialization. File uses var_export+include (opcache=zero deserialization). Redis benefits from igbinary (50% smaller). APCu stores native PHP values.

### Option A: Unified serialize() for All

Use PHP's native `serialize()`/`unserialize()` everywhere, regardless of backend.

- **Pros**: Single code path. Full type fidelity. No extension dependencies. Predictable behavior.
- **Cons**: 10x slower file reads (serialize+unserialize vs opcache include). 50% larger Redis values (without igbinary). Suboptimal everywhere.
- **Best for**: Proof of concept or when extensions are unavailable.

### Option B: Per-Backend Hardcoded

Each backend adapter has a fixed serialization strategy: file=var_export, Redis=igbinary, APCu=native, DB=serialize.

- **Pros**: Optimal performance per backend. No runtime detection overhead. Simple per-adapter implementation.
- **Cons**: Inflexible — can't change serializer without changing adapter. igbinary may not be installed (hard failure on Redis adapter?). No fallback.
- **Best for**: Controlled deployments where extension availability is guaranteed.

### Option C: Configurable Marshaller Abstraction

`MarshallerInterface` with implementations per strategy. Each backend adapter uses a marshaller (configurable, with sensible defaults). Auto-detects igbinary at runtime.

- **Pros**: Flexible — swap marshallers without changing adapters. Auto-detection handles extension availability. Testable (mock marshaller). Per-backend defaults are optimal. Override possible for edge cases.
- **Cons**: Extra abstraction layer. Marginal overhead of interface dispatch. More classes to maintain.
- **Best for**: phpBB — heterogeneous hosting environments where extension availability varies. Sensible defaults + override = safe.

### Option D: JSON Everywhere

`json_encode()`/`json_decode()` for all backends.

- **Pros**: Human-readable cache values. Cross-language compatible. Fast for simple types. No extensions needed.
- **Cons**: Lossy — objects become arrays, integer keys become strings, DateTime needs custom handling. Cannot round-trip PHP domain objects. Larger than igbinary.
- **Best for**: Cross-language cache sharing or debugging-priority environments. NOT phpBB (needs object/array fidelity).

### Trade-off Matrix

| Aspect | A (serialize) | B (Hardcoded) | C (Marshaller) | D (JSON) |
|--------|-------------|-------------|--------------|---------|
| Performance | Poor (file) | Optimal | Optimal | Medium |
| Flexibility | None | None | High | None |
| Extension deps | None | Hard (igbinary) | Soft (fallback) | None |
| Type fidelity | Full | Full | Full | Lossy |
| Complexity | ★☆ | ★★ | ★★★ | ★☆ |

**Recommended: C** — Marshaller abstraction. Defaults: file→VarExportMarshaller, Redis→IgbinaryMarshaller (fallback PhpSerializeMarshaller), APCu→NullMarshaller, DB→PhpSerializeMarshaller. Auto-detect igbinary at runtime.

---

## Decision 6: Multi-Tier Support

**Context**: APCu (L1, per-server) + Redis (L2, shared) gives best latency. But adds complexity, staleness (other servers' L1), and APCu isn't always available.

### Option A: Always-On Automatic

If APCu is detected, automatically add it as L1 in front of the configured L2 backend. No admin configuration needed.

- **Pros**: Zero-config performance boost. Transparent to consumers. Admins don't need to know about APCu.
- **Cons**: Staleness surprises (admin clears Redis but APCu still serves stale for 30s). Hard to debug. Magic behavior. Some data shouldn't be in APCu (volatile counters).
- **Best for**: Fully managed platforms where cache consistency isn't critical.

### Option B: Optional ChainAdapter Configuration

Admin explicitly configures multi-tier in pool config: `pools.threads.chain: [apcu, redis]` with per-level TTL.

- **Pros**: Explicit — admin understands what's happening. Per-pool control (threads=chain, auth=file-only). Debugging is clear. Staleness window is configured (L1 TTL).
- **Cons**: Configuration complexity. Admin must understand multi-tier trade-offs. More config options.
- **Best for**: phpBB — explicit configuration matches admin-panel philosophy. Only enabled when admin understands implications.

### Option C: Not Supported (Single Backend Only)

Each pool has exactly one backend. No chaining. If you want APCu, it's the only backend for that pool.

- **Pros**: Simplest implementation. No staleness issues. No multi-tier bugs. Easy to reason about.
- **Cons**: Cannot get APCu speed + Redis durability. Either fast-but-volatile or durable-but-slower. Leaves performance on the table for high-traffic sites.
- **Best for**: MVP/V1 — ship without multi-tier, add later based on demand.

### Option D: Smart Auto-Tier

Detect data characteristics (TTL, tags, update frequency) and automatically route to appropriate tier. Stable data → APCu L1 + Redis L2. Volatile data → Redis only.

- **Pros**: Optimal performance without manual config. Adapts to workload.
- **Cons**: Magic. How does the system know what's "stable"? Metadata overhead. Debugging nightmare. Over-engineered.
- **Best for**: Never in practice — too much implicit behavior.

### Trade-off Matrix

| Aspect | A (Auto) | B (Explicit) | C (None) | D (Smart) |
|--------|---------|------------|---------|----------|
| Performance uplift | Auto | On demand | None | Auto |
| Config burden | None | Medium | None | None |
| Debuggability | ★★ | ★★★★ | ★★★★★ | ★☆ |
| Staleness risk | Hidden | Visible | None | Hidden |
| Implementation effort | Medium | Medium | None | High |

**Recommended: B (with C as V1)** — Ship V1 without multi-tier (single backend per pool). Design the adapter interface to allow ChainAdapter in V2. When added, require explicit configuration per pool.

---

## Decision 7: Legacy Compatibility

**Context**: ~25 existing services use `@cache` (service wrapper with 7 `obtain_*()` methods + `__call`) and `@cache.driver` (17-method driver interface including SQL cursor methods). Need a migration path.

### Option A: Clean Break

New `phpbb\cache` namespace with clean API. Old consumers must be rewritten to use new API. No bridge.

- **Pros**: No legacy code in new service. Clean architecture from day one. No maintenance burden of compatibility layer.
- **Cons**: ~25 services must be rewritten simultaneously. Big-bang migration risk. All-or-nothing deployment. High effort.
- **Best for**: Greenfield rewrites where legacy code is being replaced anyway.

### Option B: Bridge Adapter

`LegacyCacheBridge` implements old `driver_interface`, delegates to new service internally. Deprecated but functional. Services migrate individually at their own pace.

- **Pros**: Gradual migration. No big-bang risk. Legacy code works immediately. New and old code coexist. Bridge can be removed once all consumers migrate.
- **Cons**: Must maintain bridge code (translating 17 old methods to new API). SQL cursor methods are awkward to bridge. Bridge may become permanent if migration stalls.
- **Best for**: phpBB — legacy services can't all be rewritten at once; bridge enables incremental adoption.

### Option C: Dual API Indefinitely

Both old and new interfaces are first-class, maintained forever, backed by same engine.

- **Pros**: No migration pressure. Both interfaces always work. Contributors can use whichever they're comfortable with.
- **Cons**: Double API surface to maintain/document. Confusing for new contributors. Technical debt never resolved.
- **Best for**: Never — this is where bridges go to die.

### Option D: Adapter Per Legacy Method Subset

Split legacy interface into logical sub-adapters: `SqlCacheAdapter` (sql_load/sql_save/sql_fetchrow), `KvCacheAdapter` (put/get/destroy/tidy), each wrapping new service.

- **Pros**: Cleaner separation than one monolithic bridge. Each adapter is focused. SQL adapter can be deprecated independently from KV adapter.
- **Cons**: More classes than option B. Consumers may use methods from both subsets needing both adapters. More refactoring at consumer sites.
- **Best for**: Projects where the legacy interface's sub-concerns map cleanly to different consumers.

### Trade-off Matrix

| Aspect | A (Break) | B (Bridge) | C (Dual) | D (Sub-adapters) |
|--------|----------|-----------|---------|----------------|
| Migration risk | ★★★★★ | ★★ | ★☆ | ★★★ |
| Code cleanliness | ★★★★★ | ★★★ | ★★ | ★★★★ |
| Effort (immediate) | Very High | Medium | Low | Medium-High |
| Legacy debt resolved | Immediately | Eventually | Never | Eventually |
| Consumer disruption | High | None | None | Low |

**Recommended: B** — Bridge adapter. `LegacyCacheBridge` implements old interface, delegates to new pools internally. Mark deprecated immediately. Plan migration per-service over time. SQL cursor methods wrapped with `getOrCompute()` + array iteration.

---

## Decision 8: Default Backend

**Context**: phpBB installs range from shared hosting (no Redis, no APCu, maybe no opcache) to dedicated servers with Redis. Default must "just work" without configuration.

### Option A: Filesystem

File-based cache using `var_export()` + `include` for reads. Tag versions in dedicated file. Current phpBB approach.

- **Pros**: Zero dependencies. Works everywhere. Opcache makes reads near-instant. Proven in phpBB for 15+ years. No configuration needed. Shared hosting friendly.
- **Cons**: File I/O on writes. Directory scanning for cleanup. Concurrent write races (mitigated by tmpfile+rename). Not distributed. Tag version reads add file I/O.
- **Best for**: Default — covers 80% of phpBB installations without any configuration.

### Option B: Redis with Filesystem Fallback

Attempt Redis connection at startup. If available, use Redis. If not, fall back to filesystem.

- **Pros**: Automatic performance upgrade when Redis is present. No manual config for Redis users. Graceful degradation.
- **Cons**: Startup overhead (connection attempt even if Redis isn't running). Silent fallback may confuse admins. Which is it using? Magic behavior.
- **Best for**: PaaS platforms where Redis may or may not be provisioned.

### Option C: Database

SQL table (`phpbb_cache_items`) as cache storage. Uses existing database connection.

- **Pros**: No extra dependencies beyond what phpBB already has (a database). Better concurrency than file (transactions). Works on multi-server setups (shared DB). Supports tag versions natively.
- **Cons**: Adds queries to already-busy DB. Slower than filesystem with opcache. DB bottleneck becomes cache bottleneck. 
- **Best for**: Multi-server deployments without Redis, or shared hosting where filesystem caching is restricted.

### Option D: Auto-Detect Best Available

Check at runtime: APCu → Redis → Memcached → File. Use the best available without configuration.

- **Pros**: Optimal performance without config. Upgrades automatically when extensions are installed.
- **Cons**: Unpredictable behavior. "Which backend is it using?" requires inspection. Extension installation changes behavior. Hard to reproduce issues across environments.
- **Best for**: Managed platforms with known environments. NOT open-source software on diverse hosts.

### Trade-off Matrix

| Aspect | A (File) | B (Redis+fallback) | C (Database) | D (Auto) |
|--------|---------|-------------------|-------------|---------|
| Zero-config | ✓ | ✓ (with magic) | ✓ (needs table) | ✓ (magic) |
| Shared hosting | ✓ | ✗ (Redis rare) | ✓ | Varies |
| Performance | Good (opcache) | Best/Good | Poor | Best available |
| Predictability | ★★★★★ | ★★★ | ★★★★★ | ★★ |
| Distributed | ✗ | ✓/✗ | ✓ | Varies |

**Recommended: A** — Filesystem. phpBB's core audience includes shared hosting. Filesystem is proven, zero-dependency, opcache-optimized. Redis and database are available as explicit configuration choices for admins who need them.

---

## Summary of Recommendations

| # | Decision | Recommended | Rationale |
|---|---|---|---|
| 1 | Interface Standard | **C**: PSR-16 + TagAwareCacheInterface | Interop + needed features |
| 2 | Tag Invalidation | **A**: Version counter per tag | O(tags), multi-tag, proven |
| 3 | Stampede Prevention | **C**: XFetch + opt-in locking | Zero-overhead default, protection for expensive items |
| 4 | Pool Architecture | **C**: Shared connection + namespace | Efficient, isolated, overridable |
| 5 | Serialization | **C**: Configurable marshaller | Per-backend optimal, extension-safe |
| 6 | Multi-Tier | **B** (V2): Optional ChainAdapter | Single tier in V1, explicit opt-in later |
| 7 | Legacy Compatibility | **B**: Bridge adapter | Gradual migration, no disruption |
| 8 | Default Backend | **A**: Filesystem | Zero deps, shared hosting, proven |

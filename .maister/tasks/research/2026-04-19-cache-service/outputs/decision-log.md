# Cache Service — Decision Log

## ADR-001: Interface Standard

**Decision**: C — PSR-16 base + custom `TagAwareCacheInterface`

**Context**: The cache service needs a consumer API that provides basic get/set plus tag invalidation and get-or-compute.

**Choice**: Extend PSR-16 `CacheInterface` with `TagAwareCacheInterface` adding `invalidateTags(array $tags): bool` and `getOrCompute(string $key, callable $compute, ?int $ttl, array $tags): mixed`. Consumers needing only basic cache depend on PSR-16; consumers needing tags use the extended interface.

**Consequences**: Library interop via PSR-16 base. Custom extension is phpBB-specific but minimal (2 methods). Two interface levels to document.

---

## ADR-002: Tag Invalidation Mechanism

**Decision**: A — Version counter per tag (Symfony approach)

**Context**: Threads and hierarchy need multi-tag per item invalidation. A post cached under `['forum:3', 'topic:42']` must invalidate when either entity changes.

**Choice**: Each tag has a monotonically increasing version number. On `invalidateTags()`, increment version. On read, compare stored tag versions against current — mismatch = miss. Lazy cleanup (stale items treated as misses, never explicitly deleted).

**Consequences**: O(N) invalidation where N = tags (not items). Every read requires tag version verification (+1 lookup, batchable). Stale items waste storage until overwritten. Works on all backends including filesystem.

---

## ADR-003: Stampede Prevention

**Decision**: D — No stampede prevention

**Context**: Cache stampede occurs when popular items expire and many requests recompute simultaneously.

**Choice**: No built-in stampede prevention. Accept that concurrent misses may trigger duplicate computations. Simplest implementation with no metadata overhead.

**Consequences**: Under high traffic, popular cache miss causes N simultaneous recomputations. Acceptable trade-off — phpBB forums rarely experience thundering-herd scenarios at typical deployment scale. Can be added later as opt-in if needed.

---

## ADR-004: Pool Architecture

**Decision**: C — Shared backend connection + logical namespace isolation

**Context**: 5 services need cache with key isolation. Most will use the same backend. Connection reuse is efficient.

**Choice**: `CachePoolFactory` creates pools that share a backend connection. Each pool has a key prefix (namespace) for isolation. Per-pool backend override is possible when explicitly configured.

**Consequences**: 1 connection serving all pools (resource efficient). Prefix-based isolation prevents key collision. Per-pool flush via prefix delete. Backend override enables Redis for threads + file for auth if desired.

---

## ADR-005: Serialization Strategy

**Decision**: C — Configurable marshaller abstraction

**Context**: Different backends have fundamentally different optimal serialization (var_export for file, igbinary for Redis, native for APCu).

**Choice**: `MarshallerInterface` with per-backend implementations. Defaults: filesystem→VarExportMarshaller, Redis→IgbinaryMarshaller (fallback PhpSerializeMarshaller), APCu→NullMarshaller, DB→PhpSerializeMarshaller. Auto-detect igbinary at runtime.

**Consequences**: Optimal performance per backend. Extension-safe (graceful fallback). Extra abstraction layer (marginal overhead). Each backend uses its ideal format without consumer awareness.

---

## ADR-006: Multi-Tier Support

**Decision**: B — Optional ChainAdapter configuration (future V2)

**Context**: APCu L1 + Redis L2 gives best latency but adds complexity and staleness risks.

**Choice**: V1 ships single backend per pool. Interface is designed to allow ChainAdapter in V2. When added, admin explicitly configures chain per pool with per-level TTL.

**Consequences**: V1 is simple (no multi-tier bugs). Architecture is future-proof. Admin explicitly opts in to multi-tier when ready. Staleness window is visible and configured.

---

## ADR-007: Legacy Compatibility

**Decision**: A — Clean break

**Context**: ~25 existing services use `@cache`/`@cache.driver` with legacy 17-method interface including SQL cursor methods.

**Choice**: New `phpbb\cache` namespace with clean API. No bridge adapter. Legacy consumers must be rewritten to use new API.

**Consequences**: No legacy code in new service. Clean architecture from day one. All ~25 consumers must be updated — but in this rewrite, all services are being rebuilt anyway (threads, messaging, storage, auth, hierarchy are all new). The "legacy" services are already being redesigned.

---

## ADR-008: Default Backend

**Decision**: A — Filesystem

**Context**: phpBB installs range from shared hosting to dedicated servers with Redis. Default must work without configuration.

**Choice**: File-based cache using `var_export()` + `include` for opcache-optimized reads. Tag versions stored in dedicated file. Zero external dependencies.

**Consequences**: Works everywhere. Opcache makes reads near-instant. Proven approach. Not distributed (single-server only). Redis and database available as explicit configuration choices.

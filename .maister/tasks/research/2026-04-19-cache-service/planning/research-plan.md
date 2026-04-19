# Research Plan: phpbb\cache Utility Service

## Research Overview

**Research question**: How should the `phpbb\cache` utility service be designed to provide a reusable caching layer with filesystem default and configurable external backends (Redis, Memcached, database), usable by all other phpbb services?

**Research type**: Mixed (Technical + Literature)
- Technical: Legacy cache system analysis (drivers, service, data types, DI wiring, SQL caching)
- Literature: PSR-6/PSR-16 contracts, Symfony Cache component, industry cache patterns

**Scope boundaries**:
- IN: Legacy drivers (7), service wrapper, SQL caching, PSR compliance, key namespacing, TTL/invalidation, stampede prevention, multi-tier, serialization, DI integration
- OUT: HTTP/CDN caching, browser caching, Twig template caching, Symfony compiled container caching

---

## Methodology

### Primary approach
Parallel codebase analysis + external standards research. The legacy system is self-contained (2,376 lines across 10 files) and well-suited for complete code reading. PSR standards and cache patterns require web research.

### Fallback strategies
- If PSR interfaces are not directly fetchable, use Symfony Cache component documentation as proxy (it implements both PSR-6 and PSR-16)
- If industry patterns are hard to find, focus on Symfony Cache, Laravel Cache, and Doctrine Cache as representative implementations

### Analysis framework
1. **Interface contract analysis** — What methods exist, what gaps vs PSR, what to keep/drop
2. **Backend abstraction analysis** — How drivers abstract storage, what's common, what differs
3. **Data type taxonomy** — Categorize all cached data (domain, SQL, config, routing) by TTL, size, invalidation pattern
4. **Consumer needs analysis** — What each future phpbb service needs from cache (from HLD documents)
5. **Pattern applicability** — Map industry patterns to phpbb requirements

---

## Research Phases

### Phase 1: Broad Discovery
- Map all legacy cache files and their relationships
- Identify all cache consumers in `services.yml` DI config
- Catalog all `data_*.php` and `sql_*.php` files in `cache/production/`
- Identify cache references in existing service HLDs (threads, messaging, auth, hierarchy, storage)
- Check if PSR cache interfaces exist in vendor (they don't — confirmed)

### Phase 2: Targeted Reading
- Read complete `driver_interface.php` (176 lines) — full method contract
- Read complete `service.php` (420 lines) — service wrapper + domain data caching methods
- Read `base.php` (256 lines) — abstract base with SQL caching, purge, file operations
- Read `memory.php` (280 lines) — abstract for in-memory backends (key prefix, SQL table tracking)
- Read `file.php` (629 lines) — filesystem implementation details (locking, serialization format)
- Read `redis.php` (162 lines), `memcached.php` (148 lines) — connection handling, config patterns
- Read DI config and `di/extension/config.php` — how `acm_type` maps to driver class

### Phase 3: Deep Dive
- Trace SQL caching flow: query → md5 → table tracking → invalidation via `destroy('sql', TABLE)`
- Trace domain data caching: `_` prefix convention, global vars vs prefixed items
- Analyze file driver serialization format (`data_*.php` files use PHP `var_export`)
- Map memory driver key prefix strategy (`substr(md5($dbname . $table_prefix), 0, 8) . '_'`)
- Investigate locking patterns in file driver (`.lock` files exist in cache dir)
- Analyze deferred purge mechanism in service.php (event-driven via `core.garbage_collection`)

### Phase 4: Verification
- Cross-reference legacy interface against PSR-6 `CacheItemPoolInterface` and PSR-16 `SimpleCacheInterface`
- Validate that all consumer HLD cache needs can be met by proposed interface
- Check Symfony Cache component for bridge patterns (PSR-6 ↔ PSR-16 adapter)
- Verify no conflicts between key namespacing and legacy SQL caching approach

---

## Sub-Questions by Category

### 1. Legacy Drivers
- What is the full method contract in `driver_interface.php`?
- How does `file.php` handle serialization, locking, and TTL expiry?
- How do `redis.php` and `memcached.php` handle connection, key prefixing, and TTL?
- What is the `_` prefix convention for cache keys? How does it affect storage?
- How does `base.php` implement SQL query caching (table tracking, md5 keys)?
- What does `memory.php` add over `base.php`? (key prefix, extension checking, SQL table tracking in memory)
- How do apcu/wincache/dummy differ from the memory base?

### 2. Legacy Service
- What domain-specific methods does `service.php` expose? (word censors, icons, ranks, bots, extensions, cfg, disallowed usernames)
- How does the `__call` magic method delegate to the driver?
- How does `deferred_purge()` work with the event dispatcher?
- What is the service's DI signature and how is it wired in `services.yml`?
- Should domain-specific methods (obtain_ranks, obtain_icons) live in the cache service or move to their respective domain services?

### 3. PSR Standards
- What methods does PSR-6 `CacheItemPoolInterface` require?
- What methods does PSR-16 `SimpleCacheInterface` require?
- How do PSR-6 and PSR-16 relate? Can a single implementation satisfy both?
- Does Symfony Cache component provide a bridge/adapter pattern?
- What are the semantic differences (PSR-6 deferred save vs PSR-16 immediate)?
- What are the type constraints (PSR-16 key validation regex, PSR-6 CacheItem)?

### 4. Cache Patterns
- **Tag-based invalidation**: How to invalidate all cache entries tagged with a domain concept (e.g., `forum:42`)?
- **Stampede prevention**: Lock-based vs probabilistic early expiry (XFetch algorithm)?
- **Multi-tier caching**: L1 (APCu/in-process) + L2 (Redis) — how to maintain consistency?
- **Serialization strategies**: PHP serialize vs JSON vs igbinary vs msgpack — trade-offs for cache?
- **Cache warming**: Preload strategies for known-hot data?
- **Event-driven invalidation**: How to integrate with phpbb's Symfony EventDispatcher?
- **Database-backed cache**: When is a DB cache tier useful? (shared hosting without Redis)

### 5. Service Integration
- How should cache keys be namespaced per-service? (`threads:topic:42:meta`, `auth:acl:user:7`)
- How should services declare their cache needs via DI? (tagged services, named pools, or single service with namespace?)
- What isolation guarantees are needed? (service A can't accidentally purge service B's cache)
- How should SQL query caching be exposed? (Transparent via DB layer? Explicit cache-aside? Both?)
- What cache interfaces should consumer services depend on? (PSR-16? Custom? Pool per service?)
- How do existing HLDs reference caching needs?
  - **threads**: ContentPipeline render caching, counter caching, TopicMetadata caching, CacheInvalidation event listeners
  - **auth**: AclCacheService with bitfield encode/decode, option registry cache, role cache, event-driven invalidation
  - **hierarchy**: CacheInvalidationListener for SQL cache + forum_parents, listens to domain events
  - **messaging**: Counter caching (tiered hot+cold), conversation list caching
  - **storage**: Metadata caching, quota caching (minimal references)

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | legacy-drivers | Driver interface, all 7 driver implementations, inheritance hierarchy, key prefix/TTL/locking/serialization internals | Read, Grep | legacy-drivers |
| 2 | legacy-service | Service wrapper (service.php), DI wiring (services.yml, di/extension/config.php), domain data methods, deferred purge, SQL caching flow through base.php | Read, Grep | legacy-service |
| 3 | psr-standards | PSR-6 CacheItemPoolInterface, PSR-16 SimpleCacheInterface, Symfony Cache component bridge patterns, PHP-FIG specifications | WebFetch | psr-standards |
| 4 | cache-patterns | Industry patterns: tag-based invalidation, stampede prevention (XFetch), multi-tier, serialization strategies, cache warming, event-driven invalidation, database-backed cache | WebFetch | cache-patterns |
| 5 | service-integration | Cache needs from consumer HLDs (threads, auth, hierarchy, messaging, storage), key namespacing patterns, DI pool patterns, SQL caching design options | Read, Grep | service-integration |

### Rationale
The research naturally divides along the technical vs literature boundary:
- **Categories 1-2** (legacy-drivers, legacy-service): Deep codebase reading of a contained 2,376-line subsystem. Split into driver internals vs service/DI layer because they serve different design questions.
- **Category 3** (psr-standards): External web research for authoritative PSR specs. Separate from patterns because it's normative (what MUST be implemented) vs advisory.
- **Category 4** (cache-patterns): External research for industry best practices. Advisory — informs design choices but doesn't dictate interface.
- **Category 5** (service-integration): Cross-referencing existing HLD documents to catalog consumer requirements. Distinct source material (project docs, not external or legacy cache code).

---

## Success Criteria

1. ✅ Complete method-by-method inventory of legacy `driver_interface.php` with gap analysis vs PSR-6/PSR-16
2. ✅ All 7 drivers characterized (storage mechanism, key format, TTL handling, connection config)
3. ✅ SQL caching flow documented end-to-end (query → hash → table tracking → invalidation)
4. ✅ PSR-6 and PSR-16 contracts fully understood with bridge/adapter patterns documented
5. ✅ At least 3 industry cache patterns analyzed with applicability to phpbb (tag-based, stampede, multi-tier)
6. ✅ Cache needs from all 5 consumer HLDs cataloged (threads, auth, hierarchy, messaging, storage)
7. ✅ Key namespacing strategy options identified with trade-offs
8. ✅ Serialization strategies compared with recommendation
9. ✅ Clear recommendation on PSR-6 vs PSR-16 vs both vs custom interface

---

## Expected Outputs

1. **Research report** (`outputs/research-report.md`): Comprehensive findings organized by category
2. **Interface design options** (within report): PSR compliance analysis and recommended approach
3. **Pattern recommendations** (within report): Which patterns to adopt and which to skip
4. **Consumer requirements catalog** (within report): Per-service cache needs matrix
5. **Decision log** (`outputs/decision-log.md`): Key design decisions with rationale

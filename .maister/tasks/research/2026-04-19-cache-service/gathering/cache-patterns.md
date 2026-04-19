# Industry Cache Design Patterns — Findings

**Source category**: external (industry knowledge + web references)
**Research question**: What industry-standard cache design patterns are relevant for building a PHP caching utility service?

---

## 1. Tag-Based Invalidation

### How It Works

Tag-based invalidation associates one or more string tags with each cache item. When a domain entity changes, you invalidate all cache items carrying that entity's tag — without needing to know the individual cache keys.

```
// Writing:
$cache->get('forum_3_topics', function (ItemInterface $item) {
    $item->tag(['forum:3', 'topics']);
    $item->expiresAfter(3600);
    return $this->repository->getTopicsForForum(3);
});

// Invalidating — when a topic is created in forum 3:
$cache->invalidateTags(['forum:3']);
// All cache items tagged with 'forum:3' are now invalid
```

### Implementation Strategies

#### Strategy A: Version Counter Per Tag (Symfony's approach)

Each tag has a monotonically increasing version number. When `invalidateTags(['forum:3'])` is called, the version for `forum:3` is incremented. On read, the cache checks the current version of every tag attached to an item against the version stored when the item was created. If any tag version is newer, the item is treated as a miss.

**Source**: Symfony `TagAwareAdapter` — "implements instantaneous invalidation (time complexity O(N) where N is the number of invalidated tags). It needs one or two cache adapters: the first required one is used to store cached items; the second optional one is used to store tags and their invalidation version number (conceptually similar to their latest invalidation date)."
**Reference**: https://symfony.com/doc/current/components/cache/cache_invalidation.html

**Characteristics**:
- O(N) invalidation where N = number of tags being invalidated, NOT number of items
- No need to scan or delete individual items
- Stale items are lazily cleaned up — they appear as misses and get overwritten on next compute
- Tag versions can be stored in a fast backend (Redis) even when items are on slower backend (filesystem)

**Implementation detail (Symfony)**:
```
// Two-adapter setup for mixed storage:
$cache = new TagAwareAdapter(
    new FilesystemAdapter(),     // items stored on disk
    new RedisAdapter('redis://localhost')  // tags stored in Redis (fast lookups)
);
```

#### Strategy B: Tag-to-Key Mapping (direct deletion)

Maintain a reverse index: for each tag, store the set of cache keys that carry that tag. On invalidation, look up the key set and delete each item.

**Characteristics**:
- Immediate physical deletion (no stale reads)
- O(M) invalidation where M = number of items carrying the tag (could be large)
- Storage overhead for the reverse mapping
- Consistency risk: if the mapping gets out of sync with cache contents (crash between writing item and updating mapping), orphaned items or missing invalidation result

#### Strategy C: Key-Prefix with Version (namespace approach)

Encode a version number into the cache key namespace. Invalidation = increment namespace version. Old keys become unreachable and expire naturally.

```
$version = $this->getTagVersion('forum:3'); // e.g., 42
$key = "forum:3:v{$version}:topics";
```

**Characteristics**:
- Zero overhead on invalidation (just increment a counter)
- No explicit deletion needed
- Old items waste storage until TTL expiry or GC
- Cannot be combined with other tag schemes on the same item

### Trade-offs

| Aspect | Version Counter (A) | Direct Mapping (B) | Key-Prefix (C) |
|--------|-------------------|-------------------|----------------|
| Invalidation speed | O(tags) — fast | O(items per tag) — can be slow | O(1) — fastest |
| Storage overhead | Version int per tag | Full reverse index | Wasted stale items |
| Consistency | Lazy — reads verify | Immediate — but crash risk | Lazy — unreachable keys |
| Complexity | Medium | High | Low |
| Multi-tag per item | Yes | Yes | No (one namespace) |

### When Valuable vs Overkill

**Valuable when**:
- Cache items span multiple entities (e.g., a "forum homepage" cache depends on topics, users, announcements)
- Entity changes should invalidate multiple unrelated cache keys
- You cannot enumerate all affected cache keys at invalidation time
- phpBB use cases: forum view (depends on topics + permissions + user prefs), user profile aggregates, ACL bitmask caches

**Overkill when**:
- Cache items have 1:1 relationship with entities (just use the entity ID as key)
- TTL-based expiry is sufficient (e.g., short-lived counters)
- The application has very few cache keys

**Recommendation for phpBB**: Version counter approach (Strategy A). It's proven in Symfony, O(N) in tags not items, and supports multi-tag per item which phpBB needs (e.g., topic cache tagged with both `forum:{id}` and `user:{author_id}`).

---

## 2. Cache Stampede Prevention

A **cache stampede** (thundering herd) occurs when a popular cache item expires and many concurrent requests attempt to regenerate it simultaneously, overloading the backend.

### Pattern A: Locking (Mutex)

One process acquires a lock, regenerates the value, and stores it. Other concurrent processes either:
- **Wait** for the lock to release, then read the fresh value
- **Return stale value** if available (lock + stale-while-revalidate hybrid)
- **Return a default/error** if no stale value exists

```php
public function get(string $key, callable $compute, int $ttl): mixed
{
    $value = $this->backend->get($key);
    if ($value !== null) {
        return $value;
    }

    $lock = $this->lockFactory->createLock("cache_lock:{$key}", 30);
    if ($lock->acquire(blocking: false)) {
        try {
            // Double-check after acquiring lock
            $value = $this->backend->get($key);
            if ($value !== null) {
                return $value;
            }
            $value = $compute();
            $this->backend->set($key, $value, $ttl);
            return $value;
        } finally {
            $lock->release();
        }
    }

    // Could not acquire lock — another process is regenerating
    // Option 1: wait and retry
    // Option 2: return stale value
    // Option 3: compute anyway (degrade gracefully)
    return $this->getStale($key) ?? $compute();
}
```

**Characteristics**:
- Guarantees only one process computes at a time
- Requires a distributed lock mechanism for multi-server setups (Redis SETNX, filesystem flock)
- Lock timeout must be longer than computation time
- Risk: lock holder crashes → all waiters stall until lock TTL expires

**PHP applicability**: Good fit. PHP's shared-nothing model means each request is independent. `flock()` works for single-server; Redis `SET NX EX` for distributed.

**Source**: Symfony Cache uses locking by default — "The first solution is to use locking: only allow one PHP process (on a per-host basis) to compute a specific key at a time. Locking is built-in by default."
**Reference**: https://symfony.com/doc/current/components/cache.html#stampede-prevention

### Pattern B: Probabilistic Early Expiry (XFetch)

Instead of expiring at exactly TTL, each request has a small random chance of treating the item as expired *before* it actually expires. The probability increases as the real expiry approaches. This spreads regeneration across time, avoiding a single thundering-herd moment.

**The XFetch Algorithm**:
```
time_to_recompute = currentTime - (expiry - delta * beta * ln(random()))
```

Where:
- `delta` = time the last computation took
- `beta` = tuning parameter (default 1.0; higher = earlier recompute)
- `random()` = uniform random in (0, 1)
- `expiry` = scheduled expiration timestamp

```php
public function get(string $key, callable $compute, int $ttl, float $beta = 1.0): mixed
{
    $item = $this->backend->getWithMetadata($key);

    if ($item !== null) {
        $expiry = $item['expiry'];
        $delta = $item['compute_time'];
        $now = microtime(true);

        // Probabilistic early expiry check
        $threshold = $delta * $beta * log(random_int(1, PHP_INT_MAX) / PHP_INT_MAX);
        if ($now - ($expiry - $threshold) < 0) {
            return $item['value']; // Not yet time to recompute
        }
        // Randomly chosen to recompute early — fall through
    }

    $start = microtime(true);
    $value = $compute();
    $computeTime = microtime(true) - $start;

    $this->backend->set($key, $value, $ttl, ['compute_time' => $computeTime]);
    return $value;
}
```

**Characteristics**:
- No locks needed — probabilistic, not deterministic
- Works across multiple servers without coordination
- Requires storing computation time metadata alongside the cached value
- Small chance of duplicate computation (acceptable trade-off)
- Only effective when computation time (`delta`) is significant relative to TTL

**Source**: Symfony implements this as the `beta` parameter: "higher values mean earlier recompute. Set it to 0 to disable early recompute and set it to INF to force an immediate recompute."
**Reference**: https://symfony.com/doc/current/components/cache.html#stampede-prevention
**Academic**: Vattani, A., Chierichetti, F., Lowenstein, K. (2015). "Optimal Probabilistic Cache Stampede Prevention." https://cseweb.ucsd.edu/~avattani/papers/cache_stampede.pdf

### Pattern C: Stale-While-Revalidate

Serve the stale (expired) value immediately while triggering an asynchronous refresh in the background. The next request gets the freshen value.

```php
public function get(string $key, callable $compute, int $ttl): mixed
{
    $item = $this->backend->getWithMetadata($key);

    if ($item !== null && !$item['expired']) {
        return $item['value']; // Fresh hit
    }

    if ($item !== null && $item['expired']) {
        // Serve stale, trigger async refresh
        $this->scheduleRefresh($key, $compute, $ttl);
        return $item['value']; // Stale but available
    }

    // Complete miss — must compute synchronously
    $value = $compute();
    $this->backend->set($key, $value, $ttl);
    return $value;
}
```

**Characteristics**:
- Best user-perceived latency (always returns immediately if stale data exists)
- Requires storing data beyond its TTL (separate "stale TTL" / "grace period")
- Requires a mechanism for async refresh (message queue, deferred processing, or next-request refresh)
- Reads may get slightly outdated data

**PHP applicability**: Tricky in vanilla PHP because there's no built-in async. Options:
1. **Pseudo-async**: Mark item as "refreshing", serve stale, let the next request without a "refreshing" flag do the actual compute
2. **`fastcgi_finish_request()`**: Finish HTTP response, then compute in the background (PHP-FPM only)
3. **Queue-based**: Dispatch a job to a worker that refreshes the cache

### Comparison for PHP/phpBB

| Pattern | Complexity | PHP Fit | Best For |
|---------|-----------|---------|----------|
| Locking | Medium | Good (flock/Redis) | High-cost computations, single hot keys |
| XFetch (probabilistic) | Low | Excellent (no infrastructure) | Medium-traffic items, general-purpose |
| Stale-while-revalidate | High | Poor (no native async) | Real-time systems (not typical PHP) |

**Recommendation for phpBB**:
1. **Primary**: XFetch (probabilistic early expiry) — zero infrastructure cost, works everywhere, effective for most phpBB cache items
2. **Secondary**: Locking for specific high-cost items (ACL resolution, content pipeline renders) — using `flock()` for file driver, Redis `SET NX` for Redis driver
3. **Skip**: Stale-while-revalidate — too complex for PHP's request-response model without a job queue

---

## 3. Multi-Tier Caching

### The L1/L2 Pattern

**L1 (in-process)**: APCu — shared memory within a single PHP-FPM pool. Sub-microsecond reads. Scoped to one server.

**L2 (distributed)**: Redis or Memcached — network-based. ~0.1-1ms reads. Shared across all servers.

### Read Path

```
Request → L1 (APCu) hit? → return
                    miss? → L2 (Redis) hit? → populate L1 → return
                                       miss? → compute → populate L2 → populate L1 → return
```

### Write/Invalidation Path

```
Invalidation event → delete from L1 (APCu on this server)
                   → delete from L2 (Redis — removes for all servers)
```

**Problem**: Other servers' L1 caches still hold stale data until their APCu TTL expires.

### Consistency Concerns

In PHP-FPM, each worker process shares APCu memory within a single pool (server). But multiple servers have independent APCu instances. This creates a fundamental consistency challenge:

**Scenario**:
1. Server A caches `forum:3:topics` in APCu (L1) and Redis (L2)
2. A new topic is posted via Server B
3. Server B invalidates Redis (L2) and its own APCu (L1)
4. Server A's APCu (L1) still has stale data until TTL expires

**Mitigation strategies**:

1. **Short L1 TTL**: Set APCu TTL to 5-30 seconds. Limits staleness window. Most effective and simplest.
2. **Version tagging in L2**: Store a version counter in Redis. L1 items include the version. On read, check L2 version against L1 version — if mismatch, treat as miss. Adds one Redis call per read (defeats purpose if done every time).
3. **Accept eventual consistency**: For many use cases (forum topic lists, user profiles, statistics), a few seconds of staleness is acceptable.
4. **Skip L1 for volatile data**: Only use APCu for truly stable data (configuration, ACL bitmasks, extension metadata). Use Redis directly for frequently-changing data.

### Symfony ChainAdapter

**Source**: "Cache items are fetched from the first adapter containing them and cache items are saved to all the given adapters. This exposes a simple and efficient method for creating a layered cache."
**Reference**: https://symfony.com/doc/current/components/cache/adapters/chain_adapter.html

```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

$cache = new ChainAdapter([
    new ApcuAdapter(),      // L1 — fast, local
    new RedisAdapter(...),  // L2 — shared, slower
]);
```

Key behavior: "When an item is not found in the first adapter but is found in the next ones, this adapter ensures that the fetched item is saved to all the adapters where it was previously missing."

### When Multi-Tier Is Worth the Complexity

**Worth it when**:
- Application runs on multiple servers behind a load balancer
- Cache hit rate is high (>90%) and latency matters
- Read-to-write ratio is very high (100:1 or more)
- APCu is available (PHP-FPM with `apcu` extension)
- Data can tolerate short staleness (5-30s)

**NOT worth it when**:
- Single-server deployment (Redis alone is fast enough)
- Low traffic (the latency difference is negligible)
- Data requires strong consistency (use L2 only)
- Shared hosting without APCu
- Cache items are large (APCu has limited shared memory, typically 32-128MB)

**Recommendation for phpBB**:
- Support multi-tier as an **optional** configuration, not the default
- Default: single tier (filesystem or Redis depending on availability)
- When configured, use short L1 TTL (10-30s) for APCu to limit staleness
- Categorize cache data by staleness tolerance: config/ACL → L1 OK; topic lists/counters → L2 only

---

## 4. Serialization Strategies

### Comparison Matrix

| Format | Speed (serialize) | Speed (deserialize) | Size | Type Fidelity | Extension Required | Cross-Language |
|--------|-----------------|-------------------|------|---------------|-------------------|---------------|
| `serialize()`/`unserialize()` | Medium | Medium | Large | Full (objects, resources excluded) | No | No |
| `json_encode()`/`json_decode()` | Fast | Fast | Medium | Lossy (no objects, no typed arrays) | No | Yes |
| `igbinary` | Fast | Fast | Small (~50% of serialize) | Full | Yes (`ext-igbinary`) | No |
| `msgpack` | Fast | Fast | Small | Partial (no PHP objects) | Yes (`ext-msgpack`) | Yes |
| `var_export()`+`include` | Slow (write) | Very Fast (read via opcache) | Medium | Full (scalars, arrays) | No | No |

### Detailed Analysis

#### `serialize()` / `unserialize()` — PHP Native

```php
$serialized = serialize($data);      // string
$data = unserialize($serialized);    // original types restored
```

- **Pros**: Universal. Handles objects (with `__sleep()`/`__wakeup()` or `Serializable`), nested structures, typed properties, circular references
- **Cons**: Largest output size. `unserialize()` is a known attack surface if fed untrusted data (object injection attacks) — phpBB only stores its own data so this is mitigated. Slower than binary alternatives for large payloads
- **Best for**: General-purpose when no extensions are available. Safe default.

#### `json_encode()` / `json_decode()` — JSON

```php
$json = json_encode($data);
$data = json_decode($json, associative: true);
```

- **Pros**: Fast. Compact for simple structures. Human-readable. Cross-language compatible
- **Cons**: Loses type information — objects become arrays, integer keys become strings, `DateTime` objects need manual handling. Cannot represent PHP-specific types
- **Best for**: Simple key-value data, API caches, configuration values. NOT suitable for complex domain objects

#### `igbinary` — Binary PHP Serializer

```php
$binary = igbinary_serialize($data);
$data = igbinary_unserialize($binary);
```

- **Pros**: ~50% smaller output than `serialize()`. Often faster. Drop-in replacement for serialize (same type fidelity). Redis can use it natively as serializer (`Redis::OPT_SERIALIZER = Redis::SERIALIZER_IGBINARY`)
- **Cons**: Requires `ext-igbinary`. Not universally installed. Binary format — not debuggable
- **Best for**: Redis/Memcached backends where size and speed matter. Excellent choice when extension is available

#### `msgpack` — MessagePack Binary

```php
$binary = msgpack_pack($data);
$data = msgpack_unpack($binary);
```

- **Pros**: Compact binary format. Cross-language (interop with Python, Go, JS clients). Faster than JSON for complex structures
- **Cons**: Requires `ext-msgpack`. No PHP object support (arrays only). Less common in PHP ecosystem than igbinary
- **Best for**: Cross-language cache sharing (rare in phpBB context). Not recommended over igbinary for PHP-only caches

#### `var_export()` + `include` — PHP File Cache

```php
// Write:
file_put_contents($path, '<?php return ' . var_export($data, true) . ';');

// Read (opcache-optimized):
$data = include $path;
```

- **Pros**: When PHP opcache is enabled, `include` reads the compiled opcode from shared memory — **zero deserialization cost**. This is the fastest possible read path for array/scalar data. This is the current phpBB approach (`data_*.php` files in `cache/production/`)
- **Cons**: Write is slow (generate PHP code + write file). Only works for arrays and scalars (no objects). File I/O on write. Requires filesystem. Not suitable for distributed caching. Security: generated files must not be web-accessible
- **Best for**: Filesystem-backed caches with read-heavy access patterns. Configuration, routing tables, compiled container definitions. **Current phpBB default — proven and effective**

### Recommendation Per Backend

| Backend | Primary Serializer | Fallback | Rationale |
|---------|-------------------|----------|-----------|
| **Filesystem** | `var_export()` + `include` | — | Opcache-optimized, zero-cost reads. Current phpBB approach. Keep it. |
| **Redis** | `igbinary` (if available) | `serialize()` | igbinary is ~50% smaller, natively supported by phpredis. Fall back to serialize if ext not installed. |
| **Memcached** | `igbinary` (if available) | `serialize()` | Same rationale as Redis. Memcached ext supports igbinary natively. |
| **APCu** | None (native PHP values) | — | APCu stores PHP values directly in shared memory. No serialization needed. |
| **Database** | `serialize()` | `json_encode()` | serialize for full fidelity. json if readability/debugging needed. |

### Marshaller Pattern (Symfony approach)

Symfony abstracts serialization behind a `MarshallerInterface`:

```php
interface MarshallerInterface {
    public function marshall(array $values, ?array &$failed): array;
    public function unmarshall(string $value): mixed;
}
```

**Source**: "The DefaultMarshaller uses PHP's serialize() function by default, but you can optionally use igbinary_serialize() from the Igbinary extension."
**Reference**: https://symfony.com/doc/current/components/cache.html#marshalling-serializing-data

Symfony also provides `DeflateMarshaller` — wraps another marshaller and applies gzip compression:

```php
$marshaller = new DeflateMarshaller(new DefaultMarshaller());
$cache = new RedisAdapter(new \Redis(), 'namespace', 0, $marshaller);
```

**Recommendation for phpBB**: Adopt the marshaller abstraction. Default to `serialize()`, auto-detect `igbinary` at runtime. Allow per-backend override. The filesystem driver should continue using `var_export()` as a special case (it's not really "serialization" — it's PHP code generation).

---

## 5. Event-Driven Cache Invalidation

### The Pattern

Domain events (emitted by services when state changes) trigger cache invalidation through dedicated listeners. This decouples the service that modifies data from the cache management logic.

```
Service Layer                     Cache Layer
─────────────                     ───────────
TopicService::create()
  → persist to DB
  → dispatch TopicCreatedEvent ──→ CacheInvalidationListener::onTopicCreated()
                                     → $cache->invalidateTags(['forum:{forumId}', 'topics'])
                                     → $cache->delete("topic_count:forum:{forumId}")
```

### Architecture

```php
// Event class
class TopicCreatedEvent {
    public function __construct(
        public readonly int $topicId,
        public readonly int $forumId,
        public readonly int $authorId,
    ) {}
}

// Listener — subscribed via DI container
class CacheInvalidationListener implements EventSubscriberInterface
{
    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            TopicCreatedEvent::class => 'onTopicCreated',
            TopicEditedEvent::class  => 'onTopicEdited',
            TopicDeletedEvent::class => 'onTopicDeleted',
            UserUpdatedEvent::class  => 'onUserUpdated',
        ];
    }

    public function onTopicCreated(TopicCreatedEvent $event): void
    {
        $this->cache->invalidateTags([
            "forum:{$event->forumId}",
            "user_topics:{$event->authorId}",
        ]);
    }
}
```

### Explicit vs Implicit Invalidation

**Explicit invalidation** (event-driven, as above):
- Service explicitly dispatches event → listener explicitly invalidates specific tags/keys
- Precise: only affected items are invalidated
- Requires maintaining the event→tag mapping
- Risk: missing an event means stale data persists

**Implicit invalidation** (TTL-based):
- Items expire after a fixed time, regardless of data changes
- Simple: no event wiring needed
- Imprecise: items may be stale within TTL window, or unnecessarily recomputed before data changes
- Good for: data that changes on a somewhat predictable schedule (e.g., "active users" list, daily statistics)

**Hybrid approach** (recommended):
- Use TTL as a safety net (maximum staleness window)
- Use events for immediate invalidation when possible
- Items that are not event-invalidated still expire via TTL — no indefinitely stale data

### Integration with phpBB's Event System

phpBB already uses Symfony EventDispatcher (available in vendor). The existing legacy cache uses a `core.garbage_collection` event for deferred purge. The new service architecture (per HLD documents) plans domain events extensively:

**Events from service HLDs that need cache invalidation**:
- `TopicCreatedEvent`, `TopicEditedEvent`, `TopicMovedEvent`, `TopicDeletedEvent` → invalidate forum view caches, topic count caches
- `PostCreatedEvent`, `PostEditedEvent`, `PostDeletedEvent` → invalidate topic view caches, rendered content caches
- `UserUpdatedEvent` → invalidate user profile caches, ACL caches
- `PermissionChangedEvent` → invalidate ACL bitmask caches (critical — must be immediate)
- `ForumUpdatedEvent` → invalidate forum tree/hierarchy caches
- `ConfigChangedEvent` → invalidate config caches

**Recommended pattern for phpBB**:

1. **One `CacheInvalidationSubscriber` per service domain** (not one giant subscriber):
   - `ThreadCacheSubscriber` handles topic/post events
   - `AuthCacheSubscriber` handles permission events
   - `UserCacheSubscriber` handles user events

2. **Register as DI services** with `kernel.event_subscriber` tag (or phpBB equivalent)

3. **Use tag-based invalidation** (not individual key deletion) — the subscriber doesn't need to know which exact cache keys exist, only which tags to invalidate

4. **Priority**: cache invalidation listeners should run AFTER the primary event handling (lower priority number in Symfony, higher in phpBB legacy) to ensure DB changes are committed before cache is cleared

---

## 6. Cache Key Design

### Namespace Prefix Convention

```
{service}:{entity}:{identifier}:{variant}
```

**Examples**:
```
threads:topic:42:metadata        // Topic 42 metadata
threads:topic:42:rendered:v3     // Topic 42 rendered content, pipeline version 3
auth:acl:user:15:bitfield        // User 15 ACL bitmask
forums:tree:1:children           // Forum 1 child list
config:global                    // Global configuration cache
sql:a3f8b2c1d4e5                 // SQL query result (md5 hash)
```

### Design Rules

1. **Hierarchical namespacing**: Use colons (`:`) as separators. This is a Redis convention that enables `SCAN` pattern matching and visual grouping.

2. **Service prefix**: First segment identifies the owning service. Prevents key collisions between services using the same cache pool.

3. **Entity type**: Second segment identifies what kind of thing is cached. Enables tag-based invalidation by entity type.

4. **Identifier**: The specific entity ID. Usually numeric (database primary key).

5. **Variant**: Optional qualifier for different representations of the same entity (rendered vs raw, locale-specific, version-specific).

### Version Prefixing for Schema Changes

When the cached data structure changes (e.g., new fields added to a cached array), old cache entries become incompatible. Two approaches:

**Approach A: Version in key**
```
auth:acl:v2:user:15:bitfield
```
Change the version segment when schema changes. Old `v1` keys expire naturally.

**Approach B: Global cache version**
```php
const CACHE_SCHEMA_VERSION = 3;

public function makeKey(string ...$parts): string
{
    return 'v' . self::CACHE_SCHEMA_VERSION . ':' . implode(':', $parts);
}
```
One constant change invalidates everything. Simple but aggressive.

**Recommendation**: Per-service version (Approach A). Each service owns its cache key format and can version independently. `threads:v2:topic:42` is better than a global version bump that purges unrelated caches.

### Hash-Based Keys for Complex Queries

For SQL results or complex query parameters, use a hash of the normalized query:

```php
// Deterministic key from query parameters
$params = ['forum_id' => 3, 'sort' => 'date', 'page' => 1, 'per_page' => 25];
ksort($params); // Normalize order
$key = 'threads:query:' . hash('xxh128', serialize($params));
// Result: threads:query:a7b3c4d5e6f7... (32 hex chars)
```

**Hash algorithm choice**:
- `xxh128` — fastest, good distribution, 128-bit. Available in PHP 8.1+
- `md5` — universal, 128-bit. Current phpBB approach for SQL caching. Sufficient for cache keys (not used for security)
- `sha256` — overkill for cache keys; slower than xxh128

**Current phpBB**: Uses `md5()` for SQL query cache keys (e.g., `sql_3e34796286cb810b99597106afdc2dce.php`). This works but `xxh128` is ~10x faster.

### Key Length Limits

| Backend | Max Key Length | Notes |
|---------|---------------|-------|
| **Memcached** | 250 bytes | Hard limit in protocol. Hash long keys. |
| **Redis** | 512 MB | Practically unlimited. But keep short for memory/debugging. |
| **APCu** | No hard limit | Reasonable limit for shared memory efficiency. |
| **Filesystem** | OS path limit (~255 chars per component) | Key becomes filename. Must sanitize (no `/`, `\`, `:` on Windows). |
| **Database** | VARCHAR column size | Typically 255 or 512 chars. Configurable. |

**Recommendation**: Target max 200 characters including namespace. If a key exceeds 200 chars (complex query hash), hash the entire key:
```php
public function normalizeKey(string $key): string
{
    if (strlen($key) <= 200) {
        return $key;
    }
    // Preserve namespace for readability, hash the rest
    $parts = explode(':', $key, 3);
    $prefix = $parts[0] . ':' . ($parts[1] ?? '');
    return $prefix . ':h:' . hash('xxh128', $key);
}
```

---

## 7. Database-Backed Cache

### When Useful

- **Shared hosting** without Redis/Memcached/APCu (very common for phpBB installations)
- **Better than filesystem** for:
  - Concurrent access (database handles locking natively via transactions)
  - Cleanup (SQL `DELETE WHERE expiry < NOW()` vs directory scanning)
  - Shared across multiple web servers (if using a shared database)
- **Worse than filesystem** for:
  - Raw read speed (DB query > file include with opcache)
  - Setup complexity (needs a table, migration)
  - Load on database server (adds queries to an already-busy DB)

### Table Schema

**Minimal schema (Symfony PdoAdapter compatible)**:

```sql
CREATE TABLE phpbb_cache_items (
    item_id     VARCHAR(255) NOT NULL,
    item_data   MEDIUMBLOB   NOT NULL,   -- serialized value
    item_expiry INTEGER UNSIGNED DEFAULT NULL,  -- Unix timestamp, NULL = never expires
    PRIMARY KEY (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Index for cleanup queries
CREATE INDEX idx_cache_expiry ON phpbb_cache_items (item_expiry);
```

**Source**: Symfony's `PdoAdapter` uses this approach — "The table where values are stored is created automatically on the first call to the save() method."
**Reference**: https://symfony.com/doc/current/components/cache/adapters/pdo_adapter.html

**Extended schema with tags**:

```sql
CREATE TABLE phpbb_cache_items (
    item_id     VARCHAR(255) NOT NULL,
    item_data   MEDIUMBLOB   NOT NULL,
    item_expiry INTEGER UNSIGNED DEFAULT NULL,
    item_tags   TEXT DEFAULT NULL,   -- JSON array of tags, e.g. '["forum:3","topics"]'
    PRIMARY KEY (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_cache_expiry ON phpbb_cache_items (item_expiry);

-- For tag-based invalidation (if using direct deletion strategy):
CREATE TABLE phpbb_cache_tags (
    tag_name    VARCHAR(255) NOT NULL,
    item_id     VARCHAR(255) NOT NULL,
    PRIMARY KEY (tag_name, item_id),
    INDEX idx_tag_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Alternative (version-counter tag approach — simpler, recommended)**:

```sql
CREATE TABLE phpbb_cache_items (
    item_id     VARCHAR(255) NOT NULL,
    item_data   MEDIUMBLOB   NOT NULL,
    item_expiry INTEGER UNSIGNED DEFAULT NULL,
    item_tags   TEXT DEFAULT NULL,          -- tag names + versions at write time
    PRIMARY KEY (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_cache_expiry ON phpbb_cache_items (item_expiry);

-- Tag version store (one row per tag)
CREATE TABLE phpbb_cache_tag_versions (
    tag_name    VARCHAR(255) NOT NULL,
    tag_version INTEGER UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tag_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Invalidation = `UPDATE phpbb_cache_tag_versions SET tag_version = tag_version + 1 WHERE tag_name IN (...)`. On read, compare stored tag versions against current versions.

### Performance Characteristics

| Operation | File Cache (opcache) | File Cache (no opcache) | Database Cache | Redis |
|-----------|---------------------|------------------------|---------------|-------|
| Read (hit) | ~0.01ms (opcode) | ~0.1ms (file I/O) | ~0.5-2ms (query) | ~0.1-0.5ms (network) |
| Write | ~1-5ms (write file) | ~1-5ms (write file) | ~1-3ms (INSERT/REPLACE) | ~0.1-0.5ms (network) |
| Delete | ~0.1ms (unlink) | ~0.1ms (unlink) | ~0.5-1ms (DELETE) | ~0.1ms (DEL) |
| Bulk purge | Slow (dir scan) | Slow (dir scan) | Fast (DELETE WHERE) | Fast (SCAN+DEL or FLUSHDB) |
| Tag invalidation | Not supported | Not supported | Medium (UPDATE + verify) | Fast (version counter) |
| Concurrent access | Risky (file locks) | Risky (file locks) | Safe (transactions) | Safe (atomic ops) |

### Cleanup Strategies

#### Cron-based cleanup
```php
// Run periodically (e.g., every 15 minutes via phpBB cron)
class TidyCacheTask extends AbstractTask
{
    public function run(): void
    {
        $sql = 'DELETE FROM phpbb_cache_items
                WHERE item_expiry IS NOT NULL AND item_expiry < ' . time();
        $this->db->sql_query($sql);
    }
}
```

#### Probabilistic cleanup (on each request)
```php
public function get(string $key): mixed
{
    // 1% chance of running cleanup on any read
    if (random_int(1, 100) === 1) {
        $this->pruneExpired();
    }
    return $this->doGet($key);
}
```

This is how phpBB's legacy `tidy()` method works — called periodically by the cron task `tidy_cache`.

#### Hybrid (recommended for phpBB)
- **Lazy expiry on read**: When reading a specific key, check its expiry. If expired, delete it and return miss. Zero overhead for non-expired items.
- **Periodic bulk cleanup**: Cron task runs `DELETE WHERE expiry < NOW()` every N minutes. Prevents table bloat.
- **Probabilistic fallback**: If cron hasn't run recently (detected via a "last cleanup" timestamp), trigger cleanup probabilistically.

**Recommendation for phpBB**:
- Database cache should be a supported backend (many phpBB installs are on shared hosting)
- Use the version-counter approach for tag invalidation (avoids a separate tag-to-key mapping table)
- Use `MEDIUMBLOB` for value column (supports up to 16MB; large SQL result sets can be cached)
- Implement lazy expiry + cron cleanup (hybrid)
- The database backend will be slower than file+opcache for reads, but safer for concurrent access and easier to manage across multiple workers

---

## Cross-Cutting Recommendations for phpBB Cache Service

### Priority of patterns to implement

1. **Serialization abstraction** (Marshaller pattern) — needed from day one. Each backend uses optimal serializer.
2. **Cache key namespacing** — `service:entity:id` convention. Enforce at the service level.
3. **Tag-based invalidation** (version counter strategy) — essential for phpBB's entity-relationship model.
4. **Event-driven invalidation subscribers** — clean integration with phpBB's EventDispatcher.
5. **XFetch stampede prevention** — low complexity, high value. Implement in the base `get()` method.
6. **Database backend** — important for shared hosting deployments.
7. **Multi-tier (APCu + Redis)** — optional optimization for high-traffic deployments.
8. **Locking for stampede** — secondary stampede prevention for specific high-cost computations.

### Patterns to skip or defer

- **Stale-while-revalidate** — too complex for PHP's execution model without a job queue
- **msgpack serialization** — igbinary covers the same niche better for PHP-only caches
- **Direct tag-to-key mapping** — version counter is simpler and avoids consistency issues
- **Global cache version** — per-service versioning is more surgical

---

**Confidence**: High (90-95%). All patterns described are well-established in the PHP ecosystem with proven implementations in Symfony Cache, Laravel Cache, and Doctrine Cache. The recommendations are grounded in phpBB's specific deployment model (shared hosting common, PHP-FPM, legacy EventDispatcher already in vendor).

**Sources**:
- Symfony Cache Component: https://symfony.com/doc/current/components/cache.html
- Symfony Cache Invalidation (tags): https://symfony.com/doc/current/components/cache/cache_invalidation.html
- Symfony ChainAdapter: https://symfony.com/doc/current/components/cache/adapters/chain_adapter.html
- Symfony PdoAdapter: https://symfony.com/doc/current/components/cache/adapters/pdo_adapter.html
- Symfony Doctrine DBAL Adapter: https://symfony.com/doc/current/components/cache/adapters/doctrine_dbal_adapter.html
- XFetch paper: Vattani et al. (2015) https://cseweb.ucsd.edu/~avattani/papers/cache_stampede.pdf
- phpBB legacy cache codebase (session memory from prior analysis)

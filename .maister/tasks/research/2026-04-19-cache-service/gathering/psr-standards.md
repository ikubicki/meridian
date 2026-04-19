# PSR-6 & PSR-16 Cache Standards — Findings

**Sources**: php-fig.org/psr/psr-6, php-fig.org/psr/psr-16, symfony.com/doc/current/components/cache
**Confidence**: High (100%) — official specifications and reference implementations

---

## 1. PSR-6: Caching Interface (CacheItemPoolInterface)

**Source**: https://www.php-fig.org/psr/psr-6/

### 1.1 Core Concepts

PSR-6 uses an **Item + Pool** model:
- **Pool** (`CacheItemPoolInterface`) — logical repository of cache items. All operations go through the pool.
- **Item** (`CacheItemInterface`) — a single key/value pair within a pool. Key is immutable, value can change.
- **Deferred saves** — items can be queued and committed in bulk (batch optimization).

### 1.2 CacheItemInterface — Full Definition

```php
namespace Psr\Cache;

interface CacheItemInterface
{
    /** @return string — the key for this cache item */
    public function getKey();

    /** @return mixed — the cached value, or null if miss (check isHit()) */
    public function get();

    /** @return bool — true if cache hit, false otherwise */
    public function isHit();

    /**
     * @param mixed $value — the serializable value to store
     * @return static
     */
    public function set($value);

    /**
     * @param \DateTimeInterface|null $expiration — absolute expiration time
     * @return static
     */
    public function expiresAt($expiration);

    /**
     * @param int|\DateInterval|null $time — TTL in seconds or DateInterval
     * @return static
     */
    public function expiresAfter($time);
}
```

**Key properties**:
- `get()` returns `null` on miss, but `null` is also a valid cached value → must use `isHit()` to distinguish.
- Fluent setters (`set()`, `expiresAt()`, `expiresAfter()` return `static`).
- Items are NEVER instantiated by callers — only obtained from pool via `getItem()`.

### 1.3 CacheItemPoolInterface — Full Definition

```php
namespace Psr\Cache;

interface CacheItemPoolInterface
{
    /**
     * @param string $key
     * @return CacheItemInterface — always returns item, even on miss
     * @throws InvalidArgumentException on illegal key
     */
    public function getItem($key);

    /**
     * @param string[] $keys
     * @return iterable|CacheItemInterface[] — keyed by cache key
     * @throws InvalidArgumentException on illegal key
     */
    public function getItems(array $keys = []);

    /**
     * @param string $key
     * @return bool — true if item exists and is not expired
     * @throws InvalidArgumentException on illegal key
     */
    public function hasItem($key);

    /**
     * Deletes ALL items in the pool.
     * @return bool
     */
    public function clear();

    /**
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException on illegal key
     */
    public function deleteItem($key);

    /**
     * @param string[] $keys
     * @return bool
     * @throws InvalidArgumentException on illegal key
     */
    public function deleteItems(array $keys);

    /**
     * Persists a cache item immediately.
     * @param CacheItemInterface $item
     * @return bool
     */
    public function save(CacheItemInterface $item);

    /**
     * Queues a cache item for deferred persistence.
     * @param CacheItemInterface $item
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item);

    /**
     * Persists all deferred items.
     * @return bool
     */
    public function commit();
}
```

### 1.4 Exception Interfaces

```php
namespace Psr\Cache;

interface CacheException {}  // Critical errors (connection failures, etc.)
interface InvalidArgumentException extends CacheException {}  // Bad keys
```

### 1.5 Key Constraints

- Minimum supported characters: `A-Z`, `a-z`, `0-9`, `_`, `.`
- Minimum key length support: up to 64 characters
- Reserved (MUST NOT support): `{}()/\@:`
- Implementations MAY support more characters/lengths, MUST support minimum

### 1.6 PSR-6 Pros/Cons for a phpBB Utility Service

**Pros**:
- Deferred saves enable batch optimization (useful for SQL-result caching)
- Item objects provide metadata (isHit, expiration)
- Battle-tested in Symfony, Drupal, and other large frameworks
- `getItems()` / `deleteItems()` native batch operations

**Cons**:
- Verbose API — simple get/set requires 3 lines (getItem → set → save)
- Item wrapper overhead for simple key/value lookups
- Overkill for `get($key)` / `put($key, $value, $ttl)` patterns

---

## 2. PSR-16: Simple Cache Interface (CacheInterface)

**Source**: https://www.php-fig.org/psr/psr-16/

### 2.1 Motivation

PSR-16 was created because PSR-6 is "formal and verbose for the most simple use cases." It provides a streamlined interface independent of PSR-6 but designed for easy interoperability.

### 2.2 CacheInterface — Full Definition

```php
namespace Psr\SimpleCache;

interface CacheInterface
{
    /**
     * @param string $key
     * @param mixed $default — returned on cache miss (default: null)
     * @return mixed — cached value or $default
     * @throws InvalidArgumentException on illegal key
     */
    public function get($key, $default = null);

    /**
     * @param string $key
     * @param mixed $value — must be serializable
     * @param null|int|\DateInterval $ttl — null = implementation default
     * @return bool — true on success
     * @throws InvalidArgumentException on illegal key
     */
    public function set($key, $value, $ttl = null);

    /**
     * @param string $key
     * @return bool — true on success
     * @throws InvalidArgumentException on illegal key
     */
    public function delete($key);

    /**
     * Wipes entire cache.
     * @return bool
     */
    public function clear();

    /**
     * @param iterable $keys
     * @param mixed $default — returned for missing keys
     * @return iterable — key => value pairs
     * @throws InvalidArgumentException
     */
    public function getMultiple($keys, $default = null);

    /**
     * @param iterable $values — key => value pairs
     * @param null|int|\DateInterval $ttl
     * @return bool
     * @throws InvalidArgumentException
     */
    public function setMultiple($values, $ttl = null);

    /**
     * @param iterable $keys
     * @return bool
     * @throws InvalidArgumentException
     */
    public function deleteMultiple($keys);

    /**
     * Warning: subject to race conditions, use for warming only.
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException
     */
    public function has($key);
}
```

### 2.3 Exception Interfaces

```php
namespace Psr\SimpleCache;

interface CacheException {}
interface InvalidArgumentException extends CacheException {}
```

### 2.4 Key Difference from PSR-6

- **Cache miss detection**: PSR-16 returns `$default` (null) on miss. Storing `null` is indistinguishable from a miss. PSR-6 uses `isHit()` to distinguish.
- **No item objects**: Direct key/value operations, no intermediate CacheItem wrapper.
- **TTL in set()**: TTL is passed directly to `set()`, not set on an item.
- Same key constraints as PSR-6.

### 2.5 PSR-16 Pros/Cons for a phpBB Utility Service

**Pros**:
- Maps almost 1:1 to phpBB's existing `get($var_name)` / `put($var_name, $var, $ttl)` pattern
- Minimal boilerplate — single method call for get, set, delete
- Batch operations built-in (`getMultiple`, `setMultiple`, `deleteMultiple`)
- Easy to understand and implement

**Cons**:
- No deferred saves (no batch commit pattern)
- Cannot distinguish stored `null` from cache miss (minor issue)
- No built-in tag support (PSR-6 has no tag support either, but Symfony's PSR-6 extensions do)

---

## 3. Feature Comparison: PSR-6 vs PSR-16

| Feature | PSR-6 | PSR-16 |
|---------|-------|--------|
| **API Style** | Item-based (Pool + Item objects) | Direct key/value |
| **get()** | Returns CacheItem (call ->get() + ->isHit()) | Returns value directly (or $default) |
| **set()** | Pool::getItem() → Item::set() → Pool::save() | Cache::set($key, $value, $ttl) |
| **delete()** | Pool::deleteItem($key) | Cache::delete($key) |
| **clear()** | Pool::clear() | Cache::clear() |
| **Batch get** | Pool::getItems([...]) | Cache::getMultiple([...]) |
| **Batch set** | saveDeferred() + commit() | Cache::setMultiple([...]) |
| **Batch delete** | Pool::deleteItems([...]) | Cache::deleteMultiple([...]) |
| **Has/exists** | Pool::hasItem($key) | Cache::has($key) |
| **TTL setting** | Item::expiresAfter() / expiresAt() | TTL param in set() / setMultiple() |
| **Deferred saves** | ✅ Yes (saveDeferred + commit) | ❌ No |
| **Null distinction** | ✅ isHit() distinguishes null from miss | ❌ Cannot distinguish |
| **Lines for get+set** | 3 minimum | 1 each |
| **Learning curve** | Moderate | Low |
| **Tag support** | Not in spec (Symfony extension) | Not in spec |

### Which Standard is Better for a UTILITY Service?

**PSR-16 is the better fit** for a phpBB cache utility service because:
1. The utility service wraps simple get/set/delete operations — exactly what PSR-16 is designed for
2. Maps directly to phpBB's legacy API: `get()` → `get()`, `put()` → `set()`, `destroy()` → `delete()`, `purge()` → `clear()`
3. Consumers don't need to learn item objects — they just pass keys and values
4. For advanced features (tags, deferred), the service can extend beyond PSR-16 or use PSR-6 internally

**PSR-6 as internal implementation** is appropriate because:
- Symfony adapters implement PSR-6 natively
- Tag support is built on PSR-6 (TagAwareCacheInterface)
- The utility service can expose PSR-16 externally while using PSR-6 adapters internally

### Framework Adoption

| Framework | PSR-6 | PSR-16 | Notes |
|-----------|-------|--------|-------|
| **Symfony** | ✅ Primary | ✅ Via Psr16Cache bridge | Adapters implement PSR-6, expose PSR-16 via wrapper |
| **Laravel** | ✅ Via bridge | ✅ Native-like | Laravel Cache is PSR-16-like, adapters available for both |
| **Laminas** | ✅ laminas-cache | ✅ laminas-cache | Supports both |
| **Drupal** | ✅ Core cache API | ❌ | Uses PSR-6 internally |

---

## 4. PSR-6 ↔ PSR-16 Bridge Pattern

**Source**: https://symfony.com/doc/current/components/cache/psr6_psr16_adapters.html

Symfony provides bidirectional adapters:

### PSR-6 → PSR-16 (wrapping a PSR-6 pool as PSR-16)

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$psr6Cache = new FilesystemAdapter();
$psr16Cache = new Psr16Cache($psr6Cache);

// Now use PSR-16 API:
$psr16Cache->set('key', 'value', 3600);
$value = $psr16Cache->get('key');
```

### PSR-16 → PSR-6 (wrapping a PSR-16 cache as PSR-6)

```php
use Symfony\Component\Cache\Adapter\Psr16Adapter;

$psr6Cache = new Psr16Adapter($psr16Cache);
// Now use PSR-6 API:
$item = $psr6Cache->getItem('key');
```

**Implication for phpBB**: The utility service can use Symfony's PSR-6 adapters internally and expose a PSR-16-compatible interface. The `Psr16Cache` bridge makes this trivial.

---

## 5. Symfony Cache Component (Reference Implementation)

**Source**: https://symfony.com/doc/current/components/cache.html

### 5.1 Three API Levels

Symfony Cache offers three approaches:

1. **Cache Contracts** (`Symfony\Contracts\Cache\CacheInterface`) — Recommended. Callback-based `get()` with automatic stampede protection. Only `get()` and `delete()`.
2. **PSR-6** (`Psr\Cache\CacheItemPoolInterface`) — All Symfony adapters implement this natively.
3. **PSR-16** — Available via `Psr16Cache` bridge wrapping any PSR-6 adapter.

### 5.2 Available Adapters

All adapters implement both `CacheInterface` (Symfony Contracts) and `CacheItemPoolInterface` (PSR-6):

| Adapter | Backend | Use Case |
|---------|---------|----------|
| `FilesystemAdapter` | Filesystem | Default, no external deps |
| `PhpFilesAdapter` | PHP files (opcache) | Fast reads, PHP 7+ |
| `PhpArrayAdapter` | PHP array file | Static data, warmup |
| `ApcuAdapter` | APCu shared memory | Single-server, fast |
| `RedisAdapter` | Redis server | Multi-server, scalable |
| `MemcachedAdapter` | Memcached | Multi-server legacy |
| `PdoAdapter` | PDO/database | When DB is only option |
| `DoctrineDbalAdapter` | Doctrine DBAL | Doctrine projects |
| `ArrayAdapter` | PHP array (in-memory) | Tests, dev |
| `ChainAdapter` | Multiple adapters | Multi-tier caching |
| `ProxyAdapter` | Wraps PSR-6 pool | Interop |
| `CouchbaseCollectionAdapter` | Couchbase | Couchbase users |

### 5.3 Chain Adapter (Multi-Tier Caching)

```php
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

$cache = new ChainAdapter([
    new ApcuAdapter(),          // L1: fast in-memory
    new RedisAdapter('redis://localhost'),  // L2: shared
    new FilesystemAdapter(),    // L3: fallback
]);
```

**Relevance for phpBB**: Could replace phpBB's single-driver model with tiered caching (APCu → Redis → Filesystem).

### 5.4 Tag-Aware Caching

**Source**: https://symfony.com/doc/current/components/cache/cache_invalidation.html

```php
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

$cache = new TagAwareAdapter(
    new FilesystemAdapter(),       // Items storage
    new RedisAdapter('redis://localhost')  // Tags storage (optional, for speed)
);

// Tagging items:
$item = $cache->get('cache_key', function (ItemInterface $item): string {
    $item->tag(['tag_1', 'tag_2']);
    return $cachedValue;
});

// Invalidating by tag:
$cache->invalidateTags(['tag_1']);
```

**Key details**:
- `TagAwareAdapter` wraps any adapter and adds tag tracking
- Two adapters possible: one for items (disk/DB), one for tags (Redis for speed)
- O(N) invalidation where N = number of invalidated tags
- Specialized versions: `RedisTagAwareAdapter`, `FilesystemTagAwareAdapter`
- Tags are NOT part of PSR-6 or PSR-16 spec — they're a Symfony extension

**Relevance for phpBB**: Tags could replace phpBB's manual invalidation patterns (e.g., purging all `data_*` files when config changes).

### 5.5 Stampede Protection

Built into Symfony's Cache Contracts:

1. **Locking**: Only one PHP process computes a given key at a time per host. Built-in by default.
2. **Probabilistic early expiration**: Randomly recomputes before TTL expires. Controlled by `$beta` parameter.

```php
$value = $cache->get('key', function (ItemInterface $item): string {
    $item->expiresAfter(3600);
    return computeExpensiveValue();
}, $beta = 1.0);  // beta > 0 enables early recompute, INF = immediate recompute
```

**Relevance for phpBB**: The legacy cache has no stampede protection. High-traffic boards can experience cache stampedes on `data_global.php` and SQL query cache expiration.

### 5.6 Sub-Namespaces

```php
$userCache = $cache->withSubNamespace(sprintf('user-%d', $user->getId()));
$userCache->get('dashboard_data', function (ItemInterface $item) { ... });
```

Automatically prefixes keys. Useful for isolating cache contexts.

### 5.7 Marshalling

- `DefaultMarshaller` — uses `serialize()`, optional `igbinary_serialize()`
- `DeflateMarshaller` — compresses data
- Custom marshallers for encryption

### 5.8 Using Symfony Cache Directly as a Dependency

**Current status**: phpBB does NOT have `symfony/cache` in composer.json. The vendor directory contains several Symfony components but not the cache component.

**Feasibility**: High. Adding `symfony/cache` would bring:
- Package: `symfony/cache` (~100KB)
- Dependencies: `psr/cache`, `psr/simple-cache`, `symfony/cache-contracts`
- Compatibility: Symfony Cache 6.x/7.x supports PHP 8.1+

**Risk**: Low. Symfony Cache is a standalone component with minimal dependencies.

---

## 6. Mapping Legacy phpBB API → PSR Equivalents

### 6.1 Driver Interface Methods

| Legacy phpBB Method | PSR-16 Equivalent | PSR-6 Equivalent | Notes |
|---------------------|-------------------|-------------------|-------|
| `get($var_name)` | `$cache->get($var_name)` | `$pool->getItem($var_name)->get()` | Direct 1:1 for PSR-16 |
| `put($var_name, $var, $ttl)` | `$cache->set($var_name, $var, $ttl)` | `$item->set($var)->expiresAfter($ttl); $pool->save($item)` | PSR-16 is cleaner |
| `destroy($var_name)` | `$cache->delete($var_name)` | `$pool->deleteItem($var_name)` | Direct 1:1 |
| `purge()` | `$cache->clear()` | `$pool->clear()` | Direct 1:1 |
| `_exists($var_name)` | `$cache->has($var_name)` | `$pool->hasItem($var_name)` | Direct 1:1 |
| `tidy()` | No PSR equivalent | No PSR equivalent | Implementation-specific pruning; Symfony has `PruneableInterface::prune()` |

### 6.2 Service-Level Methods

| Legacy phpBB Method | PSR Approach | Notes |
|---------------------|-------------|-------|
| `sql_load($query)` | `$cache->get(md5($query))` | Key generation strategy stays the same |
| `sql_save($query, $data, $ttl)` | `$cache->set(md5($query), $data, $ttl)` | Could add tag: `$item->tag(['sql_query'])` for bulk invalidation |
| `obtain_word_list()` | `$cache->get('word_censors')` | Dedicated key, with tag `['config']` |
| `obtain_icons()` | `$cache->get('icons')` | Dedicated key |
| `obtain_ranks()` | `$cache->get('ranks')` | Dedicated key |
| `deferred_purge()` | `saveDeferred()` + `commit()` (PSR-6) | PSR-6 has native deferred; PSR-16 does not |

### 6.3 Tag-Based Invalidation (New Capability)

Instead of phpBB's current `purge()` (destroy everything), tags enable surgical invalidation:

| Scenario | Legacy Approach | Tag-based Approach |
|----------|----------------|-------------------|
| Config change | `$cache->purge()` (clears ALL) | `$cache->invalidateTags(['config'])` |
| Extension install | `$cache->purge()` | `$cache->invalidateTags(['extensions'])` |
| Style change | `$cache->purge()` | `$cache->invalidateTags(['styles'])` |
| SQL cache clear | Delete all `sql_*` files | `$cache->invalidateTags(['sql_query'])` |
| User rank change | `$cache->destroy('_ranks')` | `$cache->invalidateTags(['ranks'])` |

---

## 7. Recommendation Summary

### For the phpBB Cache Utility Service

1. **External interface**: PSR-16 (`Psr\SimpleCache\CacheInterface`) — maps 1:1 to legacy patterns, minimal consumer friction.
2. **Internal implementation**: Symfony Cache PSR-6 adapters wrapped by `Psr16Cache` — gets tags, stampede protection, chain adapters, and the full adapter ecosystem.
3. **Tag support**: Expose `TagAwareCacheInterface` (Symfony) or a custom phpBB tagging interface on top — not part of PSR but critical for selective invalidation.
4. **Deferred saves**: Available through PSR-6 internally when needed (e.g., batch SQL cache writes during a single request).
5. **Migration path**: Legacy `cache\service` → new utility service is feasible because method signatures are nearly identical to PSR-16.

### Companion Packages to Add

```json
{
    "require": {
        "psr/cache": "^3.0",
        "psr/simple-cache": "^3.0",
        "symfony/cache": "^6.4|^7.0"
    }
}
```

`symfony/cache` is a standalone component and does not pull in the full Symfony framework.

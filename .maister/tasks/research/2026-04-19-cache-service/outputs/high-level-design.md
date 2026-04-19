# Cache Service — High-Level Design

## 1. Overview

`phpbb\cache` is a utility caching service providing a reusable, backend-agnostic key-value store with tag-based invalidation and namespace-isolated pools. It serves as the shared caching layer for all phpBB services (threads, messaging, storage, auth, hierarchy).

### Design Principles

1. **Filesystem-first** — default backend requires zero dependencies, works on shared hosting
2. **PSR-16 compatible** — base interface is standard `CacheInterface`; extended with tags
3. **Pool isolation** — each service gets a namespaced pool; shared connection, logical separation
4. **Tag invalidation via version counters** — O(tags) invalidation, lazy cleanup, multi-tag per item
5. **No stampede prevention** — simplest implementation; accept duplicate compute on miss
6. **Clean break from legacy** — no backward-compatible bridge; all consumers use new API

### Namespace

```
phpbb\cache\
```

---

## 2. Interface Contracts

### 2.1 CacheInterface (PSR-16)

The base contract. Any consumer needing only get/set/delete depends on this.

```php
namespace phpbb\cache;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

interface CacheInterface extends PsrCacheInterface
{
    // Inherits from PSR-16:
    // get(string $key, mixed $default = null): mixed
    // set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    // delete(string $key): bool
    // clear(): bool
    // has(string $key): bool
    // getMultiple(iterable $keys, mixed $default = null): iterable
    // setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    // deleteMultiple(iterable $keys): bool
}
```

### 2.2 TagAwareCacheInterface

Extended contract for consumers needing tag-based invalidation and compute pattern.

```php
namespace phpbb\cache;

interface TagAwareCacheInterface extends CacheInterface
{
    /**
     * Store a value with associated tags.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time-to-live in seconds (null = backend default)
     * @param string[] $tags Tags to associate with this entry
     */
    public function setTagged(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool;

    /**
     * Invalidate all cache entries associated with any of the given tags.
     * Uses version-counter approach — O(N) where N = number of tags.
     *
     * @param string[] $tags Tags to invalidate
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Get a cached value or compute and store it.
     *
     * @param string $key Cache key
     * @param callable $compute Function that produces the value: fn(): mixed
     * @param int|null $ttl Time-to-live in seconds
     * @param string[] $tags Tags to associate with the computed value
     * @return mixed The cached or freshly computed value
     */
    public function getOrCompute(string $key, callable $compute, ?int $ttl = null, array $tags = []): mixed;
}
```

### 2.3 CachePoolFactoryInterface

Creates namespace-isolated cache pools.

```php
namespace phpbb\cache;

interface CachePoolFactoryInterface
{
    /**
     * Get or create a cache pool for the given namespace.
     * Pools share backend connection but have isolated key spaces.
     *
     * @param string $namespace Service identifier (e.g., 'threads', 'messaging')
     * @return TagAwareCacheInterface Namespaced cache pool
     */
    public function getPool(string $namespace): TagAwareCacheInterface;
}
```

### 2.4 CacheBackendInterface

Internal contract for backend adapters. Not exposed to consumers.

```php
namespace phpbb\cache\backend;

interface CacheBackendInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(string $prefix = ''): bool;
    public function getMultiple(array $keys): array;
}
```

### 2.5 MarshallerInterface

Serialization abstraction per backend.

```php
namespace phpbb\cache\marshaller;

interface MarshallerInterface
{
    /**
     * Serialize a PHP value to storage format.
     */
    public function marshall(mixed $value): string;

    /**
     * Deserialize storage format back to PHP value.
     */
    public function unmarshall(string $data): mixed;
}
```

---

## 3. Architecture

### 3.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Consumer Services                             │
│  (threads, messaging, storage, auth, hierarchy)                      │
└──────────────────────────┬──────────────────────────────────────────┘
                           │ depends on TagAwareCacheInterface
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      CachePoolFactory                                 │
│  Creates namespaced CachePool instances                              │
└──────────────────────────┬──────────────────────────────────────────┘
                           │ creates
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         CachePool                                     │
│  Implements TagAwareCacheInterface                                    │
│  - Key prefixing (namespace:key)                                     │
│  - Tag version checking on read                                      │
│  - Tag version storage on write                                      │
│  - Delegates to backend + marshaller                                 │
└────────────┬─────────────────────────────────┬──────────────────────┘
             │                                 │
             ▼                                 ▼
┌────────────────────────┐       ┌────────────────────────────────────┐
│    TagVersionStore     │       │         CacheBackend                │
│  Stores/reads tag      │       │  (Filesystem/Redis/Memcached/       │
│  version counters      │       │   APCu/Database/Null)               │
└────────────────────────┘       └────────────────────────────────────┘
                                              │
                                              ▼
                                 ┌────────────────────────────────────┐
                                 │          Marshaller                 │
                                 │  (VarExport/Igbinary/PhpSerialize/ │
                                 │   Null)                             │
                                 └────────────────────────────────────┘
```

### 3.2 Data Flow — Read (Cache Hit)

```
1. Consumer calls $cache->get('topic:42:html')
2. CachePool prepends namespace → 'threads:topic:42:html'
3. CachePool calls Backend->get('threads:topic:42:html')
4. Backend reads raw data, Marshaller unmarshalls
5. CachePool extracts stored tag versions from entry metadata
6. CachePool calls TagVersionStore->getCurrentVersions(['post:42', 'forum:3'])
7. Compare stored versions vs current:
   - Match → return value (HIT)
   - Mismatch → return default (MISS, stale entry ignored)
```

### 3.3 Data Flow — Read (Cache Miss + Compute)

```
1. Consumer calls $cache->getOrCompute('topic:42:html', fn() => $pipeline->render(...), 3600, ['post:42', 'forum:3'])
2. CachePool prepends namespace → 'threads:topic:42:html'
3. CachePool calls Backend->get(...)
4. Miss (null returned) OR tag version mismatch
5. CachePool invokes $compute() callable
6. CachePool gets current tag versions from TagVersionStore
7. CachePool stores: value + tag versions metadata via Backend->set(...)
8. Return computed value
```

### 3.4 Data Flow — Tag Invalidation

```
1. Event listener calls $cache->invalidateTags(['forum:3'])
2. CachePool delegates to TagVersionStore->incrementVersions(['forum:3'])
3. TagVersionStore increments version counter for 'forum:3'
4. No items are deleted — stale items fail version check on next read
```

---

## 4. Class Design

### 4.1 CachePool

The core implementation. Each instance serves one namespace.

```php
namespace phpbb\cache;

class CachePool implements TagAwareCacheInterface
{
    public function __construct(
        private readonly string $namespace,
        private readonly backend\CacheBackendInterface $backend,
        private readonly TagVersionStore $tagStore,
        private readonly marshaller\MarshallerInterface $marshaller,
        private readonly ?int $defaultTtl = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function has(string $key): bool;
    public function getMultiple(iterable $keys, mixed $default = null): iterable;
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;
    public function deleteMultiple(iterable $keys): bool;

    // TagAwareCacheInterface
    public function setTagged(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool;
    public function invalidateTags(array $tags): bool;
    public function getOrCompute(string $key, callable $compute, ?int $ttl = null, array $tags = []): mixed;
}
```

**Internal storage format** (per cache entry):

```php
[
    'value' => $marshalledValue,
    'expiry' => $unixTimestamp | null,
    'tags' => ['forum:3' => 5, 'post:42' => 12],  // tag => version at write time
]
```

### 4.2 CachePoolFactory

```php
namespace phpbb\cache;

class CachePoolFactory implements CachePoolFactoryInterface
{
    private array $pools = [];

    public function __construct(
        private readonly backend\CacheBackendInterface $defaultBackend,
        private readonly TagVersionStore $tagStore,
        private readonly marshaller\MarshallerInterface $defaultMarshaller,
        private readonly ?int $defaultTtl = null,
        private readonly array $poolOverrides = [],  // namespace => backend config
    ) {}

    public function getPool(string $namespace): TagAwareCacheInterface
    {
        if (!isset($this->pools[$namespace])) {
            $backend = $this->resolveBackend($namespace);
            $marshaller = $this->resolveMarshaller($namespace);
            $this->pools[$namespace] = new CachePool(
                $namespace, $backend, $this->tagStore, $marshaller, $this->defaultTtl
            );
        }
        return $this->pools[$namespace];
    }
}
```

### 4.3 TagVersionStore

Manages tag version counters. Backend-agnostic — uses the same or different backend.

```php
namespace phpbb\cache;

class TagVersionStore
{
    private const TAG_PREFIX = '_tags:';

    public function __construct(
        private readonly backend\CacheBackendInterface $backend,
    ) {}

    /**
     * Get current versions for given tags.
     * @return array<string, int> tag => current version
     */
    public function getCurrentVersions(array $tags): array;

    /**
     * Increment version counters for given tags.
     */
    public function incrementVersions(array $tags): void;
}
```

### 4.4 Backend Adapters

#### FilesystemBackend

```php
namespace phpbb\cache\backend;

class FilesystemBackend implements CacheBackendInterface
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly int $directoryPermissions = 0777,
        private readonly int $filePermissions = 0666,
    ) {}

    // Storage: one file per key
    // Format: var_export() PHP array with metadata
    // Read: include $file (opcache-optimized)
    // Write: tmpfile + rename (atomic on POSIX)
    // Clear: glob + unlink by prefix
}
```

File naming: `{namespace}_{md5(key)}.php`

File content:
```php
<?php return ['value' => ..., 'expiry' => 1234567890, 'tags' => ['forum:3' => 5]];
```

#### RedisBackend

```php
namespace phpbb\cache\backend;

class RedisBackend implements CacheBackendInterface
{
    public function __construct(
        private readonly \Redis $redis,
    ) {}

    // Uses Redis STRING type
    // TTL via SETEX/PSETEX
    // Clear by prefix via SCAN + DEL (non-blocking iteration)
    // getMultiple via MGET
}
```

#### MemcachedBackend

```php
namespace phpbb\cache\backend;

class MemcachedBackend implements CacheBackendInterface
{
    public function __construct(
        private readonly \Memcached $memcached,
    ) {}
}
```

#### ApcuBackend

```php
namespace phpbb\cache\backend;

class ApcuBackend implements CacheBackendInterface
{
    // Uses apcu_store/apcu_fetch/apcu_delete
    // No serialization needed (native PHP values)
    // Clear by prefix via APCUIterator
}
```

#### DatabaseBackend

```php
namespace phpbb\cache\backend;

class DatabaseBackend implements CacheBackendInterface
{
    public function __construct(
        private readonly \phpbb\db\driver\driver_interface $db,
        private readonly string $tableName = 'phpbb_cache_items',
    ) {}

    // Table schema:
    // item_id VARCHAR(255) PK
    // item_data MEDIUMBLOB
    // item_expiry INT UNSIGNED NULL
    // Lazy expiry on read + periodic cron cleanup
}
```

#### NullBackend

```php
namespace phpbb\cache\backend;

class NullBackend implements CacheBackendInterface
{
    // No-op implementation for testing and cache-disabled mode
    // get() always returns null, set() returns true, etc.
}
```

---

## 5. Marshaller Implementations

### 5.1 VarExportMarshaller (Filesystem)

```php
namespace phpbb\cache\marshaller;

class VarExportMarshaller implements MarshallerInterface
{
    public function marshall(mixed $value): string
    {
        return var_export($value, true);
    }

    public function unmarshall(string $data): mixed
    {
        // For filesystem, unmarshalling is handled by PHP include
        // This method is used for non-include contexts (testing)
        return eval('return ' . $data . ';');
    }
}
```

Note: The filesystem backend uses `include` directly (opcache-optimized) rather than `unmarshall()`. The marshaller is used only for writing.

### 5.2 IgbinaryMarshaller (Redis/Memcached)

```php
namespace phpbb\cache\marshaller;

class IgbinaryMarshaller implements MarshallerInterface
{
    public function marshall(mixed $value): string
    {
        return igbinary_serialize($value);
    }

    public function unmarshall(string $data): mixed
    {
        return igbinary_unserialize($data);
    }
}
```

### 5.3 PhpSerializeMarshaller (Fallback)

```php
namespace phpbb\cache\marshaller;

class PhpSerializeMarshaller implements MarshallerInterface
{
    public function marshall(mixed $value): string
    {
        return serialize($value);
    }

    public function unmarshall(string $data): mixed
    {
        return unserialize($data, ['allowed_classes' => true]);
    }
}
```

### 5.4 NullMarshaller (APCu)

```php
namespace phpbb\cache\marshaller;

class NullMarshaller implements MarshallerInterface
{
    // APCu stores native PHP values — no serialization needed
    public function marshall(mixed $value): string { return $value; }
    public function unmarshall(string $data): mixed { return $data; }
}
```

### 5.5 Auto-Detection

```php
namespace phpbb\cache\marshaller;

class MarshallerFactory
{
    public static function createForBackend(string $backendType): MarshallerInterface
    {
        return match ($backendType) {
            'filesystem' => new VarExportMarshaller(),
            'redis', 'memcached' => self::createNetworkMarshaller(),
            'database' => new PhpSerializeMarshaller(),
            'apcu' => new NullMarshaller(),
            default => new PhpSerializeMarshaller(),
        };
    }

    private static function createNetworkMarshaller(): MarshallerInterface
    {
        if (extension_loaded('igbinary')) {
            return new IgbinaryMarshaller();
        }
        return new PhpSerializeMarshaller();
    }
}
```

---

## 6. Tag Version Store — Detailed Design

### 6.1 Concept

Each tag (e.g., `forum:3`) has an integer version. When a cache entry is written with tags, the current version of each tag is stored alongside the value. On read, current tag versions are fetched and compared — any increment means the entry is stale.

### 6.2 Storage

Tag versions are stored in the same backend as cache items, with a reserved prefix `_tags:`:

```
_tags:forum:3 → 5
_tags:topic:42 → 12
_tags:post:99 → 1
```

Tags never expire (null TTL). They persist until the entire cache is cleared.

### 6.3 Operations

**On setTagged()**:
```
1. Get current versions: TagVersionStore->getCurrentVersions(['forum:3', 'post:42'])
   → ['forum:3' => 5, 'post:42' => 12]
2. Store entry with metadata: {value, expiry, tags: {'forum:3': 5, 'post:42': 12}}
```

**On get() / getOrCompute()**:
```
1. Fetch raw entry from backend
2. If entry has tags metadata:
   a. Get current versions for those tags
   b. Compare: if ANY stored version < current version → MISS (stale)
   c. Otherwise → HIT
3. If entry has no tags: standard expiry check only
```

**On invalidateTags()**:
```
1. For each tag: backend->set('_tags:{tag}', currentVersion + 1, ttl: null)
   (Or increment atomically on backends that support it: Redis INCR)
```

### 6.4 Filesystem Tag Version Store

For the filesystem backend, tag versions are stored as individual files:

```
cache/_tags_forum_3.php → <?php return 5;
cache/_tags_topic_42.php → <?php return 12;
```

Opcache makes these reads effectively free after first access.

---

## 7. Cache Key Design

### 7.1 Convention

```
{namespace}:{entity}:{identifier}:{variant}
```

The namespace prefix is added automatically by CachePool. Consumers specify only the key within their namespace.

### 7.2 Examples Per Service

| Service | Consumer Key | Full Key (with namespace) |
|---|---|---|
| Threads | `content:{post_id}` | `threads:content:42` |
| Threads | `query:forum_topics:{forum_id}:{page}` | `threads:query:forum_topics:3:1` |
| Messaging | `conversations:{user_id}:{state}` | `messaging:conversations:15:active` |
| Messaging | `rules:{user_id}` | `messaging:rules:15` |
| Storage | `file:{file_id}` | `storage:file:99` |
| Hierarchy | `tree` | `hierarchy:tree` |
| Hierarchy | `forum:{forum_id}` | `hierarchy:forum:3` |
| Auth | `options` | `auth:options` |
| Auth | `roles` | `auth:roles` |

### 7.3 Key Constraints

- Max 200 characters (including namespace prefix)
- Allowed characters: `[a-zA-Z0-9:_-]`
- Separator: `:` (Redis convention)
- For complex query cache keys, use hash: `query:` + `md5(serialize($params))`

### 7.4 Key Normalization

```php
private function makeKey(string $key): string
{
    $fullKey = $this->namespace . ':' . $key;

    if (strlen($fullKey) > 200) {
        // Preserve namespace, hash the rest
        return $this->namespace . ':h:' . md5($key);
    }

    return $fullKey;
}
```

---

## 8. Backend Configuration

### 8.1 Default Configuration

```php
// DI container configuration
'cache.backend' => 'filesystem',
'cache.dir' => '%kernel.cache_dir%/pools/',
'cache.default_ttl' => 3600,

// Pool overrides (optional)
'cache.pools' => [
    // 'threads' => ['backend' => 'redis', 'ttl' => 1800],
    // 'auth' => ['backend' => 'filesystem', 'ttl' => null],
],
```

### 8.2 Redis Configuration

```php
'cache.redis.host' => '127.0.0.1',
'cache.redis.port' => 6379,
'cache.redis.database' => 0,
'cache.redis.password' => null,
'cache.redis.prefix' => 'phpbb:',
```

### 8.3 Database Configuration

```php
'cache.database.table' => 'phpbb_cache_items',
```

### 8.4 DI Service Registration

```yaml
services:
    # Backend
    phpbb.cache.backend.filesystem:
        class: phpbb\cache\backend\FilesystemBackend
        arguments: ['%cache.dir%']

    phpbb.cache.backend.redis:
        class: phpbb\cache\backend\RedisBackend
        arguments: ['@phpbb.cache.redis_connection']

    # Tag Store
    phpbb.cache.tag_store:
        class: phpbb\cache\TagVersionStore
        arguments: ['@phpbb.cache.backend']

    # Marshaller
    phpbb.cache.marshaller:
        factory: ['phpbb\cache\marshaller\MarshallerFactory', 'createForBackend']
        arguments: ['%cache.backend%']

    # Pool Factory
    phpbb.cache.pool_factory:
        class: phpbb\cache\CachePoolFactory
        arguments:
            - '@phpbb.cache.backend'
            - '@phpbb.cache.tag_store'
            - '@phpbb.cache.marshaller'
            - '%cache.default_ttl%'
            - '%cache.pools%'

    # Convenience aliases for services
    phpbb.cache.pool.threads:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['threads']

    phpbb.cache.pool.messaging:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['messaging']

    phpbb.cache.pool.hierarchy:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['hierarchy']

    phpbb.cache.pool.storage:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['storage']

    phpbb.cache.pool.auth:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['auth']
```

---

## 9. Event-Driven Invalidation Pattern

### 9.1 Architecture

Each service provides its own `CacheInvalidationSubscriber` that listens to domain events and calls `invalidateTags()` on its pool.

```php
namespace phpbb\threads\listener;

use phpbb\cache\TagAwareCacheInterface;
use phpbb\threads\event\PostEditedEvent;
use phpbb\threads\event\TopicCreatedEvent;

class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            PostEditedEvent::class => 'onPostEdited',
            TopicCreatedEvent::class => 'onTopicCreated',
        ];
    }

    public function onPostEdited(PostEditedEvent $event): void
    {
        $this->cache->invalidateTags([
            "post:{$event->postId}",
            "topic:{$event->topicId}",
        ]);
    }

    public function onTopicCreated(TopicCreatedEvent $event): void
    {
        $this->cache->invalidateTags([
            "forum:{$event->forumId}",
        ]);
    }
}
```

### 9.2 Tag Naming Convention

```
{entity_type}:{entity_id}
```

| Tag | Invalidation Trigger | Affected Caches |
|---|---|---|
| `post:{id}` | PostEdited, PostDeleted | Rendered content |
| `topic:{id}` | TopicEdited, PostCreated/Deleted | Topic view, post listing |
| `forum:{id}` | TopicCreated/Moved/Deleted | Forum listing, topic counts |
| `user:{id}` | UserUpdated | Profile caches |
| `conversation:{id}` | MessageDelivered | Conversation detail |
| `user_messages:{id}` | Any messaging activity for user | Conversation list, unread counts |
| `tree` | Forum CRUD, move, reorder | Full forum tree |
| `file:{id}` | FileDeleted, FileClaimed | File metadata |
| `config` | ConfigChanged | Config values |

### 9.3 Hybrid TTL + Events

All cache entries have a TTL as safety net. Events provide immediate invalidation. If an event is missed, TTL ensures maximum staleness window.

| Data Type | TTL (safety net) | Primary Invalidation |
|---|---|---|
| Rendered post HTML | 3600s (1h) | `PostEditedEvent` → tag `post:{id}` |
| Forum topic listing | 60s | `TopicCreatedEvent` → tag `forum:{id}` |
| Forum tree | 3600s (1h) | Forum mutation events → tag `tree` |
| Conversation list | 30s | Message events → tag `user_messages:{id}` |
| File metadata | 3600s (1h) | `FileDeletedEvent` → tag `file:{id}` |
| User rules | 3600s (1h) | Rule CRUD events → tag `user:{id}:rules` |
| Auth options | null (no TTL) | Extension change → explicit delete |
| Auth roles | null (no TTL) | Role change → explicit delete |

---

## 10. Database Backend Schema

For shared hosting installations without Redis.

### 10.1 Cache Items Table

```sql
CREATE TABLE phpbb_cache_items (
    item_id     VARCHAR(255) NOT NULL,
    item_data   MEDIUMBLOB   NOT NULL,
    item_expiry INT UNSIGNED DEFAULT NULL,
    item_tags   TEXT         DEFAULT NULL,
    PRIMARY KEY (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_cache_items_expiry ON phpbb_cache_items (item_expiry);
```

- `item_id`: Full namespaced key (e.g., `threads:content:42`)
- `item_data`: Serialized value (via PhpSerializeMarshaller)
- `item_expiry`: Unix timestamp or NULL (never expires)
- `item_tags`: JSON-encoded map of `{tag: version_at_write}` (e.g., `{"post:42":5,"forum:3":12}`)

### 10.2 Tag Versions Table

```sql
CREATE TABLE phpbb_cache_tag_versions (
    tag_name    VARCHAR(255)     NOT NULL,
    tag_version INT UNSIGNED     NOT NULL DEFAULT 0,
    PRIMARY KEY (tag_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 10.3 Operations

**Invalidate tags**:
```sql
INSERT INTO phpbb_cache_tag_versions (tag_name, tag_version)
VALUES ('forum:3', 1)
ON DUPLICATE KEY UPDATE tag_version = tag_version + 1;
```

**Read with tag verification**:
```sql
SELECT item_data, item_expiry, item_tags FROM phpbb_cache_items WHERE item_id = ?;
-- Then check tags:
SELECT tag_name, tag_version FROM phpbb_cache_tag_versions WHERE tag_name IN (...);
```

**Cleanup (cron)**:
```sql
DELETE FROM phpbb_cache_items WHERE item_expiry IS NOT NULL AND item_expiry < UNIX_TIMESTAMP();
```

---

## 11. Consumer Integration Examples

### 11.1 Threads — ContentPipeline Cache

```php
namespace phpbb\threads\service;

class ContentRenderService
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        private readonly ContentPipelineInterface $pipeline,
    ) {}

    public function getRenderedContent(int $postId, string $rawText, ContentContext $context): string
    {
        $key = "content:{$postId}";

        return $this->cache->getOrCompute(
            $key,
            fn() => $this->pipeline->render($rawText, $context),
            ttl: 3600,
            tags: ["post:{$postId}", "forum:{$context->forumId}"],
        );
    }
}
```

### 11.2 Hierarchy — Forum Tree Cache

```php
namespace phpbb\hierarchy\repository;

class CachedForumRepository
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        private readonly ForumRepository $repository,
    ) {}

    public function getTree(): array
    {
        return $this->cache->getOrCompute(
            'tree',
            fn() => $this->repository->getTree(),
            ttl: 3600,
            tags: ['tree'],
        );
    }

    public function findById(int $forumId): ?Forum
    {
        return $this->cache->getOrCompute(
            "forum:{$forumId}",
            fn() => $this->repository->findById($forumId),
            ttl: 300,
            tags: ["forum:{$forumId}", 'tree'],
        );
    }
}
```

### 11.3 Messaging — Conversation List Cache

```php
namespace phpbb\messaging\service;

class ConversationQueryService
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        private readonly ConversationRepository $repository,
    ) {}

    public function getConversations(int $userId, string $state, int $limit, ?string $cursor): array
    {
        $key = "conversations:{$userId}:{$state}:" . md5("{$limit}:{$cursor}");

        return $this->cache->getOrCompute(
            $key,
            fn() => $this->repository->getConversations($userId, $state, $limit, $cursor),
            ttl: 30,
            tags: ["user_messages:{$userId}"],
        );
    }
}
```

---

## 12. Cache Tidy (Garbage Collection)

### 12.1 Filesystem

Periodic cleanup task removes expired files:

```php
namespace phpbb\cache\task;

class CacheTidyTask
{
    public function __construct(
        private readonly string $cacheDir,
    ) {}

    public function run(): void
    {
        $now = time();
        foreach (glob($this->cacheDir . '*.php') as $file) {
            $data = include $file;
            if (isset($data['expiry']) && $data['expiry'] !== null && $data['expiry'] < $now) {
                unlink($file);
            }
        }
    }
}
```

Registered as phpBB cron task, runs every 15 minutes (configurable).

### 12.2 Database

```sql
DELETE FROM phpbb_cache_items
WHERE item_expiry IS NOT NULL AND item_expiry < UNIX_TIMESTAMP();
```

### 12.3 Redis / Memcached / APCu

Built-in TTL eviction — no cleanup task needed. Redis/Memcached expire keys automatically. APCu evicts on memory pressure.

---

## 13. CLI Commands

### 13.1 Cache Clear

```
php bin/phpbbcli.php cache:clear [--pool=<name>]
```

- No `--pool`: clears all pools
- With `--pool=threads`: clears only the threads namespace

### 13.2 Cache Stats (diagnostic)

```
php bin/phpbbcli.php cache:stats
```

Outputs: pool names, backend type per pool, approximate number of keys (where supported), tag count.

---

## 14. File Structure

```
src/phpbb/cache/
├── CacheInterface.php                 # PSR-16 extension marker
├── TagAwareCacheInterface.php         # Extended interface with tags + compute
├── CachePoolFactoryInterface.php      # Factory contract
├── CachePool.php                      # Core implementation
├── CachePoolFactory.php               # Pool creation + config resolution
├── TagVersionStore.php                # Tag version management
├── backend/
│   ├── CacheBackendInterface.php      # Internal backend contract
│   ├── FilesystemBackend.php          # File-based storage
│   ├── RedisBackend.php               # Redis adapter
│   ├── MemcachedBackend.php           # Memcached adapter
│   ├── ApcuBackend.php                # APCu shared memory
│   ├── DatabaseBackend.php            # SQL table storage
│   └── NullBackend.php                # No-op (testing)
├── marshaller/
│   ├── MarshallerInterface.php        # Serialization contract
│   ├── VarExportMarshaller.php        # Filesystem optimized
│   ├── IgbinaryMarshaller.php         # Binary compact
│   ├── PhpSerializeMarshaller.php     # PHP native fallback
│   ├── NullMarshaller.php             # APCu (no-op)
│   └── MarshallerFactory.php          # Auto-detection factory
├── task/
│   └── CacheTidyTask.php             # Cron garbage collection
└── command/
    ├── ClearCommand.php               # CLI cache:clear
    └── StatsCommand.php               # CLI cache:stats
```

---

## 15. Performance Characteristics

| Operation | Filesystem (opcache) | Redis | Database | APCu |
|---|---|---|---|---|
| get (hit, no tags) | ~0.01ms | ~0.2ms | ~1ms | ~0.001ms |
| get (hit, 2 tags verify) | ~0.03ms | ~0.3ms | ~2ms | ~0.003ms |
| set (with tags) | ~2ms (file write) | ~0.3ms | ~1.5ms | ~0.001ms |
| invalidateTags (1 tag) | ~1ms (file write) | ~0.2ms | ~1ms | ~0.001ms |
| getOrCompute (hit) | ~0.03ms | ~0.3ms | ~2ms | ~0.003ms |
| clear (full pool) | ~5ms (glob+unlink) | ~2ms (SCAN+DEL) | ~1ms (DELETE) | ~0.01ms |

---

## 16. Security Considerations

1. **Cache files not web-accessible** — cache directory outside document root, or protected by `.htaccess`/nginx deny rule
2. **No unserialize of untrusted data** — cache only stores data produced by the application itself; filesystem uses `include` (not unserialize)
3. **Key sanitization** — keys are validated against allowed character set before use as filenames
4. **Redis authentication** — password support in config, no credentials in cache keys
5. **No sensitive data in cache keys** — session tokens, passwords, etc. never used as key components
6. **Tag namespace isolation** — services cannot invalidate other services' tags (enforce via pool boundary)

---

## 17. Future Considerations (V2+)

1. **Multi-tier ChainAdapter** (ADR-006) — APCu L1 + Redis L2, explicit per-pool config
2. **Stampede prevention** — XFetch (beta parameter on `getOrCompute`) and/or mutex locking if traffic justifies
3. **Cache warming** — CLI command to pre-populate known-hot entries after deploy
4. **Monitoring integration** — hit/miss rate metrics, per-pool statistics
5. **Distributed invalidation** — Redis pub/sub for multi-server L1 cache busting
6. **Compression** — gzip/deflate marshaller wrapper for large cache values

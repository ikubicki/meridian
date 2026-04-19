# Research Sources: phpbb\cache Utility Service

---

## Category 1: Legacy Drivers

### Core Interface
| File | Lines | Description |
|------|-------|-------------|
| `src/phpbb/forums/cache/driver/driver_interface.php` | 176 | Full interface contract: `load()`, `unload()`, `save()`, `tidy()`, `get()`, `put()`, `purge()`, `destroy()`, `_exists()`, `sql_load()`, `sql_save()`, `sql_exists()`, `sql_fetchrow()`, `sql_fetchfield()`, `sql_rowseek()`, `sql_freeresult()`, `clean_query_id()` |

### Abstract Bases
| File | Lines | Description |
|------|-------|-------------|
| `src/phpbb/forums/cache/driver/base.php` | 256 | Abstract base: SQL caching implementation (`sql_load/save/fetchrow/freeresult`), `purge()` with file pattern matching (`data_*`, `sql_*`, `container_*`, `autoload_*`, `url_*`), `remove_file()`, `remove_dir()`, `clean_query_id()` |
| `src/phpbb/forums/cache/driver/memory.php` | 280 | Abstract for in-memory backends: key prefix (`substr(md5($dbname.$table_prefix), 0, 8)_`), extension/function checking, `_` prefix convention routing, SQL table→query tracking for invalidation, 2592000s (30d) default TTL |

### Driver Implementations
| File | Lines | Description |
|------|-------|-------------|
| `src/phpbb/forums/cache/driver/file.php` | 629 | Filesystem driver (default): `var_expires` tracking, `\phpbb\filesystem\filesystem` integration, PHP `var_export` serialization in `data_*.php`/`sql_*.php` files, file locking (`.lock` files), `DirectoryIterator`-based GC |
| `src/phpbb/forums/cache/driver/redis.php` | 162 | Redis via phpredis extension: config via `PHPBB_ACM_REDIS_HOST/PORT/PASSWORD/DB` constants, extends `memory` |
| `src/phpbb/forums/cache/driver/memcached.php` | 148 | Memcached via memcached extension: config via `PHPBB_ACM_MEMCACHED*` constants, multi-server support (`host1/port1,host2/port2`), compression flag |
| `src/phpbb/forums/cache/driver/apcu.php` | 77 | APCu user cache: extends `memory`, minimal — delegates to `apcu_*` functions |
| `src/phpbb/forums/cache/driver/wincache.php` | 73 | WinCache: extends `memory`, Windows-only, minimal |
| `src/phpbb/forums/cache/driver/dummy.php` | 155 | Null/no-op driver: all operations are in-memory only (request-scoped), useful for testing |

**Total legacy driver code**: 1,961 lines (drivers + bases)

---

## Category 2: Legacy Service & DI

### Service Wrapper
| File | Lines | Description |
|------|-------|-------------|
| `src/phpbb/forums/cache/service.php` | 420 | Service wrapper: delegates to driver via `__call()`, exposes domain data methods (`obtain_word_list()`, `obtain_icons()`, `obtain_ranks()`, `obtain_attach_extensions()`, `obtain_bots()`, `obtain_cfg_items()`, `obtain_disallowed_usernames()`), implements `deferred_purge()` via event dispatcher (`core.garbage_collection`) |

### DI Configuration
| File | Lines (relevant) | Description |
|------|----------|-------------|
| `src/phpbb/common/config/default/container/services.yml` | L44-57 | Service definitions: `cache` (class: `phpbb\cache\service`, args: `@cache.driver`, `@config`, `@dbal.conn`, `@dispatcher`, root_path, php_ext), `cache.driver` (class: `%cache.driver.class%`, args: `%core.cache_dir%`) |
| `src/phpbb/common/config/config.php` | L12 | `$acm_type = 'phpbb\\cache\\driver\\file'` — default driver config |
| `src/phpbb/forums/di/extension/config.php` | L45 | `'cache.driver.class' => $this->convert_30_acm_type($this->config_php->get('acm_type'))` — maps config to DI parameter |

### DI Consumers (services.yml references)
| Service | How it uses cache |
|---------|-------------------|
| `class_loader` | `set_cache('@cache.driver')` — class map caching |
| `class_loader.ext` | `set_cache('@cache.driver')` — extension class map caching |
| `config` (phpbb\config\db) | `'@cache.driver'` — config value caching |
| `controller.helper` | `'@cache.driver'` — route/controller caching |
| Multiple services | `'@cache'` — use full service wrapper (L132, L147, L193) |

### Cache Data Files (cache/production/)
| Pattern | Example | Description |
|---------|---------|-------------|
| `data_*.php` | `data_acl_options.php`, `data_bots.php`, `data_ranks.php`, `data_icons.php`, `data_word_censors.php`, `data_ext.php`, `data_global.php` | Domain data cached via `driver->put('_name', data)` |
| `sql_*.php` | `sql_3e34796286cb810b99597106afdc2dce.php` | SQL query result caches (md5 of normalized query) |
| `data_global.php` | — | Global vars store (non-prefixed cache entries) |
| `data_global.php.lock` | — | File locking for concurrent access |
| `container_*.php` | `container_46a2dd...php` | Compiled DI container (Symfony, not cache service concern) |
| `autoload_*.php` | `autoload_4335...php` | Autoloader class maps |
| `url_matcher.php` | — | Routing URL matcher cache |
| `data_cfg_*.php` | `data_cfg_prosilver.php` | Style config parsed from `.cfg` file |

---

## Category 3: PSR Standards

### PSR-6: Cache Item Pool (php-fig.org)
| Source | URL | What to extract |
|--------|-----|-----------------|
| PSR-6 specification | `https://www.php-fig.org/psr/psr-6/` | `CacheItemPoolInterface` methods, `CacheItemInterface` methods, key validation rules, deferred save semantics |
| PSR-6 meta document | `https://www.php-fig.org/psr/psr-6/meta/` | Design rationale, rejected alternatives |

### PSR-16: Simple Cache (php-fig.org)
| Source | URL | What to extract |
|--------|-----|-----------------|
| PSR-16 specification | `https://www.php-fig.org/psr/psr-16/` | `CacheInterface` methods (`get`, `set`, `delete`, `clear`, `getMultiple`, `setMultiple`, `deleteMultiple`, `has`), key rules, TTL semantics |
| PSR-16 meta document | `https://www.php-fig.org/psr/psr-16/meta/` | Relationship to PSR-6, design rationale |

### Symfony Cache Component
| Source | URL | What to extract |
|--------|-----|-----------------|
| Symfony Cache docs | `https://symfony.com/doc/current/components/cache.html` | Architecture overview, PSR-6 + PSR-16 bridge, adapter pattern |
| Symfony Cache adapters | `https://symfony.com/doc/current/components/cache/adapters.html` | Available adapters (filesystem, redis, memcached, apcu, doctrine, chain), configuration |
| Symfony Cache tag-aware | `https://symfony.com/doc/current/components/cache/cache_invalidation.html` | Tag-based invalidation via `TagAwareCacheInterface` |
| Symfony Cache stampede | `https://symfony.com/doc/current/components/cache.html#stampede-prevention` | Early expiry / beta parameter |

### Current vendor state
- **NOT present**: `psr/cache`, `psr/simple-cache`, `symfony/cache` — none in `vendor/` or `composer.json`
- **Present Symfony**: config, console, debug, dependency-injection, event-dispatcher, filesystem, finder, http-foundation, http-kernel, process, routing, twig-bridge, yaml (all ~3.4)
- **Implication**: New `psr/cache`, `psr/simple-cache`, and optionally `symfony/cache` would be new dependencies

---

## Category 4: Cache Patterns

### Tag-Based Invalidation
| Source | URL | What to extract |
|--------|-----|-----------------|
| Symfony tag-aware cache | `https://symfony.com/doc/current/components/cache/cache_invalidation.html` | Implementation approach, `invalidateTags()` method |
| Redis SCAN pattern | (general knowledge) | Alternative: key-prefix scanning for invalidation |

### Stampede Prevention
| Source | URL | What to extract |
|--------|-----|-----------------|
| XFetch algorithm paper | `https://cseweb.ucsd.edu/~avattani/papers/cache_stampede.pdf` | Probabilistic early expiry: `time - delta * beta * ln(random())` |
| Symfony lock-based | `https://symfony.com/doc/current/components/cache.html` | `beta` parameter in Symfony Cache |

### Multi-Tier Caching
| Source | URL | What to extract |
|--------|-----|-----------------|
| Symfony ChainAdapter | `https://symfony.com/doc/current/components/cache/adapters/chain_adapter.html` | L1 (APCu) + L2 (Redis) chain, read-through/write-through semantics |

### Serialization Strategies
| Source | Type | What to extract |
|--------|------|-----------------|
| PHP serialize/unserialize | Built-in | Default PHP serialization — universal, slower, larger |
| igbinary | Extension | Binary serialization — faster, smaller, requires ext |
| JSON encode/decode | Built-in | Portable but loses type fidelity (objects → arrays) |
| msgpack | Extension | Binary, compact, cross-language, requires ext |

### Event-Driven Invalidation
| Source | Type | What to extract |
|--------|------|-----------------|
| Existing HLDs | Project docs | All services emit domain events → cache listeners invalidate selectively |
| Symfony EventDispatcher | Already in vendor | Integration pattern for cache invalidation subscribers |

### Database-Backed Cache
| Source | Type | What to extract |
|--------|------|-----------------|
| Symfony PdoAdapter | External docs | SQL-backed cache for shared hosting without Redis |
| Doctrine DBAL cache | External docs | Alternative DB-backed approach |

---

## Category 5: Service Integration (Consumer HLD References)

### threads service
| File | Lines (relevant) | Cache references |
|------|----------|-----------------|
| `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` | L7 | "future Redis/file cache will optimize" ContentPipeline render |
| same | L752 | "Caching will be a future concern addressed by a separate Redis/file cache layer" |
| same | L781-782 | ContentPipeline: "no persistent cache in core. Future: Redis/file cache wraps this method externally" |
| same | L1069-1073 | Events: `TopicEditedEvent`, `TopicMovedEvent`, `TopicTypeChangedEvent` → `CacheInvalidation` listener |
| same | L2000 | ADR-001: "caching deferred to separate layer" |
| same | L2019 | "Render caching: Future Redis/file cache layer wraps ContentPipeline.render()" |
| **Cache needs** | — | ContentPipeline render result caching (key: post_id + pipeline_version), counter caching, topic metadata, SQL query caching, event-driven invalidation |

### auth service
| File | Lines (relevant) | Cache references |
|------|----------|-----------------|
| `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` | L7 | "O(1) bitfield cache read path", "event-driven cache invalidation" |
| same | L57-58 | Architecture: "resolves, caches, checks permissions", "user_permissions bitfield cache column" |
| same | L76, L84 | Components: `AclCacheService`, `AclCacheRepository` |
| same | L122-143 | File structure: `AclCacheServiceInterface.php`, `AclCacheRepositoryInterface.php`, `AclCacheService.php`, `AclCacheRepository.php` |
| same | L156-162 | Component table: AclCacheService manages "bitfield encode/decode + cache lifecycle", depends on "file cache, EventDispatcher" |
| same | L316 | "Decodes user_permissions bitstring or triggers full resolution + cache rebuild" |
| same | L330-350 | AclCacheServiceInterface: `getCachedPermissions()`, `clearCachedPermissions()` |
| **Cache needs** | — | Bitfield permission cache (per-user, long-lived), option registry cache, role cache, event-driven invalidation on permission changes |

### hierarchy service
| File | Lines (relevant) | Cache references |
|------|----------|-----------------|
| `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` | L59 | "phpbb\cache — Listens to hierarchy domain events to invalidate SQL caches" |
| same | L890-893 | `invalidateParentCache(?array $forumIds = null): void` |
| same | L1314-1330 | `CacheInvalidationListener`: `$this->cache->destroy('sql', FORUMS_TABLE)` |
| same | L1742 | Flow: "CacheInvalidationListener → clears forum cache" |
| **Cache needs** | — | SQL query result caching (forum list, tree), `forum_parents` column cache, event-driven invalidation on structure changes |

### messaging service
| File | Lines (relevant) | Cache references |
|------|----------|-----------------|
| `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md` | L7 (table) | "Counters: Tiered hot+cold (event-driven hot, cron-reconciled cold)" |
| **Cache needs** | — | Counter caching (unread counts per user), conversation list caching, participant metadata |

### storage service
| File | Lines (relevant) | Cache references |
|------|----------|-----------------|
| `.maister/tasks/research/2026-04-19-storage-service/outputs/high-level-design.md` | L805 | Cached FilesystemOperator instances (in-process, not persistent cache) |
| same | L1248, L1333-1337 | HTTP `Cache-Control` headers (not application cache — out of scope) |
| **Cache needs** | — | Minimal persistent cache needs; potentially file metadata/quota caching, but mostly HTTP-level |

### Cross-Cutting Cache Needs Summary
| Service | Key types | TTL range | Invalidation | Priority |
|---------|-----------|-----------|-------------|----------|
| **threads** | Render results, counters, topic metadata | 5min–1hr | Event-driven (post edit/delete) | High |
| **auth** | Permission bitfields, option registry, role map | Long-lived (until change) | Event-driven (permission change) | Critical |
| **hierarchy** | Forum tree, parent chains, SQL results | Long-lived (until structure change) | Event-driven (forum CRUD) | High |
| **messaging** | Unread counters, conversation lists | 1min–5min | Event-driven (message sent) | Medium |
| **storage** | File metadata, quota | 5min–1hr | Event-driven (upload/delete) | Low |

---

## Configuration Sources

| File | Description |
|------|-------------|
| `config.php` → `src/phpbb/common/config/config.php` L12 | `$acm_type = 'phpbb\\cache\\driver\\file'` |
| `src/phpbb/common/config/default/container/services.yml` L44-57 | DI service definitions for `cache` and `cache.driver` |
| `src/phpbb/forums/di/extension/config.php` L45 | `convert_30_acm_type()` — maps config → DI parameter `cache.driver.class` |
| `composer.json` L39-55 | Current dependencies (no PSR cache, no Symfony Cache) |
| `docker-compose.yml` | Infrastructure (check for Redis/Memcached services) |

# Plan: phpBB4 Cache Service (`phpbb\cache`)

**Date**: 2026-04-22
**Scope**: Phase 1 — foundational caching layer consumed by all other services
**Research**: `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md`
**Target dir**: `src/phpbb/cache/`

---

## Applicable Standards

### `standards/global/STANDARDS.md`
- `declare(strict_types=1)` in every file
- Allman-style braces (`{` on next line)
- `PascalCase` classes and methods; `snake_case` variables
- PHPDoc only where native types insufficient
- File header: `phpBB4 "Meridian"`, author `Irek Kubicki <phpbb@codebuilders.pl>`
- No closing PHP tag
- Tabs for indentation
- Constants: `UPPER_SNAKE_CASE`

### `standards/backend/STANDARDS.md`
- `readonly` constructor promotion for value-object-style classes
- All properties explicitly typed; all method return types declared
- PSR-4 namespace `phpbb\cache\` mirroring `src/phpbb/cache/`
- Constructor-only DI — no service locator
- PDO for any DB-backed features — no legacy DBAL
- Zero references to legacy `includes/` or global variables

### `standards/backend/COUNTER_PATTERN.md`
- Cache key convention: `counter.{service}.{entity_id}.{field}`
- Tiered counters: hot (cache) → cold (DB column) → recalc cron
- Default flush: 100 increments OR 60 seconds (hybrid strategy)
- Every counter must have a `recalculateCounters()` method in its repository

### `standards/testing/STANDARDS.md`
- PHPUnit 10+ with `#[Test]`, `#[DataProvider]` PHP 8 attributes
- No annotations; `setUp(): void` (not `protected` override style)
- Isolated unit tests — no real filesystem I/O in unit tests (use VFS or temp dir)
- Integration tests allowed for filesystem backend (clearly separated)

---

## Goal

Implement a fully tested, PSR-16-compatible cache service with:
- `TagAwareCacheInterface` (tag-based invalidation via version counters)
- `CachePoolFactory` (namespace-isolated pools)
- `FilesystemBackend` (default, zero-deps)
- `NullBackend` (for testing / environments without caching)
- `VarExportMarshaller` (default serialization)
- Symfony DI wiring in `services.yaml`

---

## Group 1: Interfaces

**Files to create**: 5

### `src/phpbb/cache/CacheInterface.php`
- Extends `Psr\SimpleCache\CacheInterface`
- No additional methods — pure type alias for internal use

### `src/phpbb/cache/TagAwareCacheInterface.php`
- Extends `CacheInterface`
- Methods:
  - `setTagged(string $key, mixed $value, ?int $ttl, array $tags): bool`
  - `invalidateTags(array $tags): bool`
  - `getOrCompute(string $key, callable $compute, ?int $ttl, array $tags): mixed`

### `src/phpbb/cache/CachePoolFactoryInterface.php`
- `getPool(string $namespace): TagAwareCacheInterface`

### `src/phpbb/cache/backend/CacheBackendInterface.php`
- `get(string $key): ?string`
- `set(string $key, string $value, ?int $ttl): bool`
- `delete(string $key): bool`
- `has(string $key): bool`
- `clear(string $prefix = ''): bool`
- `getMultiple(array $keys): array`

### `src/phpbb/cache/marshaller/MarshallerInterface.php`
- `marshall(mixed $value): string`
- `unmarshall(string $data): mixed`

---

## Group 2: Backend Implementations

**Files to create**: 2

### `src/phpbb/cache/backend/FilesystemBackend.php`
- Constructor: `string $cacheDir` (resolved via `Kernel::getCacheDir()`)
- Stores serialized values as flat files: `{cacheDir}/{sha256(key)}.cache`
- TTL stored as first line of file (Unix timestamp or 0=never)
- `clear(prefix)`: glob + unlink matching files
- No external deps

### `src/phpbb/cache/backend/NullBackend.php`
- All reads return `null`; all writes return `true`
- Used in tests and `APP_ENV=test`

---

## Group 3: Marshaller

**Files to create**: 1

### `src/phpbb/cache/marshaller/VarExportMarshaller.php`
- `marshall()`: `serialize()`
- `unmarshall()`: `unserialize()` with `allowed_classes: false` for safety
- No external deps

---

## Group 4: Core — `CachePool` + `TagVersionStore`

**Files to create**: 2

### `src/phpbb/cache/TagVersionStore.php`
- Stores tag version counters in the backend under key `__tags__:{tag}`
- `getCurrentVersions(array $tags): array<string, int>`
- `incrementVersion(string $tag): int`
- On cache miss for a tag version, initialises to 1

### `src/phpbb/cache/CachePool.php`
- Implements `TagAwareCacheInterface`
- Constructor: `string $namespace`, `CacheBackendInterface`, `MarshallerInterface`, `TagVersionStore`
- `get()` / `set()` / `delete()` etc.: delegates to backend with `{namespace}:{key}` prefix
- `setTagged()`: stores value with metadata bag (tag version snapshot at write time)
- `getOrCompute()`: read → miss → compute → setTagged
- `invalidateTags()`: loops tags, calls `TagVersionStore::incrementVersion()`
- Tag validation: if stored version ≠ current version → treat as cache miss

---

## Group 5: Factory + DI wiring

**Files to create**: 1 new, 1 modified

### `src/phpbb/cache/CachePoolFactory.php`
- Implements `CachePoolFactoryInterface`
- Constructor: `CacheBackendInterface`, `MarshallerInterface`
- `getPool(string $namespace): TagAwareCacheInterface`
  - Creates `new CachePool($namespace, $backend, $marshaller, new TagVersionStore($backend))`
  - Pools are not cached internally — creation is cheap

### `src/phpbb/config/services.yaml` (modify)
- Register:
  - `phpbb\cache\backend\FilesystemBackend` → bind `$cacheDir` to `%kernel.cache_dir%/phpbb4`
  - `phpbb\cache\backend\NullBackend`
  - `phpbb\cache\marshaller\VarExportMarshaller`
  - `phpbb\cache\CachePoolFactory` — alias to `phpbb\cache\CachePoolFactoryInterface`
  - `phpbb\cache\TagVersionStore` — internal service
- In `APP_ENV=test`: bind `CacheBackendInterface` to `NullBackend`
- In production: bind `CacheBackendInterface` to `FilesystemBackend`

---

## Group 6: Unit Tests

**Files to create**: 5

### `tests/phpbb/cache/backend/FilesystemBackendTest.php`
- Uses `sys_get_temp_dir()` for isolated temp dir
- Tests: set/get hit, get miss, TTL expiry, delete, has, clear, getMultiple

### `tests/phpbb/cache/backend/NullBackendTest.php`
- Tests: get always returns null, set returns true, has returns false

### `tests/phpbb/cache/marshaller/VarExportMarshallerTest.php`
- Tests: string, int, array, nested object round-trip
- Tests: `unserialize` with malicious class is blocked (`allowed_classes: false`)

### `tests/phpbb/cache/TagVersionStoreTest.php`
- Uses `NullBackend` (always miss) + real `FilesystemBackend` in temp dir
- Tests: initial version = 1, increment returns new version, multi-tag increment

### `tests/phpbb/cache/CachePoolTest.php`
- Full integration: `FilesystemBackend` + `VarExportMarshaller` in temp dir
- Tests (12+ cases):
  - get miss → null
  - set → get hit
  - setTagged → invalidateTags → get miss
  - getOrCompute: computes once, caches, skips on second call
  - getOrCompute: recomputes after tag invalidation
  - TTL expiry (use 1-second TTL + sleep in integration test)
  - Namespace isolation: pool-A key not visible in pool-B
  - Multi-tag invalidation: invalidate one tag → only tagged entries miss
  - clear() flushes all entries in namespace

---

## Standards Compliance Checklist

- [ ] All files have `declare(strict_types=1)` (global/STANDARDS.md)
- [ ] All files have Meridian file header with `@copyright Irek Kubicki` (global/STANDARDS.md)
- [ ] All properties and method return types explicitly declared (backend/STANDARDS.md)
- [ ] `readonly` promotion used in value-object constructors where applicable (backend/STANDARDS.md)
- [ ] PSR-4 namespace `phpbb\cache\` mirrors `src/phpbb/cache/` directory (backend/STANDARDS.md)
- [ ] No legacy DBAL / global variables referenced (backend/STANDARDS.md)
- [ ] `NullBackend` wired for `APP_ENV=test` (backend/STANDARDS.md)
- [ ] `unserialize` uses `allowed_classes: false` (OWASP — deserialization safety)
- [ ] Cache key convention `counter.{service}.{entity_id}.{field}` documented in `TagAwareCacheInterface` PHPDoc (counter-pattern/COUNTER_PATTERN.md)
- [ ] PHPUnit tests use `#[Test]` attribute, no annotations (testing/STANDARDS.md)
- [ ] Unit tests use no real filesystem I/O except `FilesystemBackendTest` with temp dir (testing/STANDARDS.md)
- [ ] Tabs for indentation throughout (global/STANDARDS.md)
- [ ] No closing PHP tag (global/STANDARDS.md)

---

## Group 7: `RedisBackend` [optional, Phase 2]

> **Not part of Phase 1 implementation.** Implement after Cache Service is stable
> and Redis is added to `docker-compose.yml`. No other code changes needed —
> `CacheBackendInterface` is the only contract.

**Prerequisites**:
- Add `redis:alpine` service to `docker-compose.yml`
- Add `PHPBB_CACHE_BACKEND=redis|filesystem|null` env variable
- PHP ext `redis` in Dockerfile (or `predis/predis` if ext unavailable)

### `src/phpbb/cache/backend/RedisBackend.php`
- Implements `CacheBackendInterface`
- Constructor: `\Redis $redis` (injected via Symfony DI)
- `get()`: `$redis->get($key)` → returns `null` on miss
- `set()`: `$redis->setex($key, $ttl, $value)` / `$redis->set($key, $value)` if no TTL
- `delete()`: `$redis->del($key)`
- `has()`: `$redis->exists($key) > 0`
- `clear(prefix)`: `SCAN` with `{prefix}*` pattern → pipeline `DEL`
- `getMultiple()`: `$redis->mGet($keys)` → map nulls

### `docker-compose.yml` changes (Phase 2)
```yaml
redis:
  image: redis:7-alpine
  container_name: phpbb_redis
  restart: unless-stopped
  networks:
    - phpbb
```
Add `PHPBB_REDIS_HOST: redis` + `PHPBB_REDIS_PORT: 6379` to `app` env.

### `src/phpbb/config/services.yaml` changes (Phase 2)
- Register `phpbb\cache\backend\RedisBackend` with `\Redis` dependency
- Add conditional env-based backend binding:
  ```yaml
  # when PHPBB_CACHE_BACKEND=redis
  phpbb\cache\backend\CacheBackendInterface:
      alias: phpbb\cache\backend\RedisBackend
  ```

### `tests/phpbb/cache/backend/RedisBackendTest.php` (Phase 2)
- Requires running Redis (`@requires extension redis` or skip if no connection)
- Same test cases as `FilesystemBackendTest`: set/get/miss/TTL/delete/clear/getMultiple

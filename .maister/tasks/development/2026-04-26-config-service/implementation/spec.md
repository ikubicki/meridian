# Specification: Config Service (M9 — phpBB4 Meridian)

## Goal

Extend the existing `ConfigRepository` with full CRUD + atomic increment, wrap it behind a caching `ConfigService` facade with typed getters, and expose all operations through a four-endpoint admin-only REST API — backed by PHPUnit (unit + integration) and Playwright E2E tests.

---

## User Stories

- As an **admin**, I want to read all board configuration keys via REST so that I can inspect the current board state without direct DB access.
- As an **admin**, I want to set a configuration value via REST so that I can update board settings at runtime without redeploying.
- As an **admin**, I want to delete an obsolete configuration key via REST so that I can clean up stale entries.
- As a **developer**, I want typed getters (`getInt`, `getBool`) on `ConfigService` so that call sites do not scatter casting logic throughout the codebase.
- As a **developer**, I want config reads to be served from cache so that high-frequency reads (e.g. `sitename`) do not hit the database on every request.

---

## Scope

### Included

- `ConfigRepositoryInterface` — extend with `set`, `getAll`, `increment`, `delete` signatures
- `ConfigRepository` — implement the four new methods
- `ConfigServiceInterface` — new contract (all typed getters + write methods)
- `ConfigService` — new facade with `CachePoolFactoryInterface`-backed tag cache
- `ConfigController` — four admin-only endpoints (GET list, GET single, PUT, DELETE)
- `services.yaml` — add `phpbb.cache.pool.config` pool, `ConfigService` wiring
- Unit tests for `ConfigService` (mock repo, verify cache behavior, verify type parsing)
- Integration tests for `ConfigRepository` new methods (SQLite in-memory via `IntegrationTestCase`)
- Expand existing `ConfigRepositoryTest` with new-method coverage
- E2E Playwright tests for all four endpoints

### Excluded

- Hardcoded limits in `FilesController` / `ThumbnailGenerator` (deferred — out of scope)
- Frontend UI for config management
- Bulk upsert / import endpoint
- Config versioning or audit log
- `increment()` REST endpoint (internal use only)

---

## Functional Requirements

### FR-1: ConfigRepositoryInterface — method signatures

| # | Acceptance Criterion |
|---|----------------------|
| 1.1 | Interface declares `set(string $key, string $value, bool $isDynamic = false): void` |
| 1.2 | Interface declares `getAll(bool $dynamicOnly = false): array` returning `['key' => 'value', ...]` |
| 1.3 | Interface declares `increment(string $key, int $by = 1): void` |
| 1.4 | Interface declares `delete(string $key): int` — returns number of affected rows (0 = key did not exist, 1 = deleted) |
| 1.5 | `SearchService` continues to compile — existing `get()` signature is unchanged |

### FR-2: ConfigRepository — new method implementations

| # | Acceptance Criterion |
|---|----------------------|
| 2.1 | `set()` performs a platform-aware upsert: `INSERT OR REPLACE` on SQLite, `INSERT … ON DUPLICATE KEY UPDATE` on MariaDB; detects driver via `$connection->getDatabasePlatform()` |
| 2.2 | `set()` stores `isDynamic` as `1` or `0` in `is_dynamic` column |
| 2.3 | `getAll(false)` returns all rows as `['config_name' => 'config_value', ...]` |
| 2.4 | `getAll(true)` returns only rows where `is_dynamic = 1` |
| 2.5 | `increment()` executes a single atomic `UPDATE phpbb_config SET config_value = config_value + :by WHERE config_name = :key`; does NOT read before write; if 0 rows affected (key does not exist) throws `RepositoryException` |
| 2.6 | `delete()` executes `DELETE FROM phpbb_config WHERE config_name = :key` and returns `(int) $this->connection->executeStatement(…)` (affected row count) |
| 2.7 | All new methods wrap Doctrine exceptions in `RepositoryException` (same as existing `get()`) |

### FR-3: ConfigServiceInterface

| # | Acceptance Criterion |
|---|----------------------|
| 3.1 | Interface declares `get(string $key, string $default = ''): string` |
| 3.2 | Interface declares `getInt(string $key, int $default = 0): int` |
| 3.3 | Interface declares `getBool(string $key, bool $default = false): bool` |
| 3.4 | Interface declares `getAll(): array` |
| 3.5 | Interface declares `set(string $key, string $value, bool $isDynamic = false): void` |
| 3.6 | Interface declares `increment(string $key, int $by = 1): void` |
| 3.7 | Interface declares `delete(string $key): int` — returns number of affected rows (propagated from repository; 0 = key did not exist) |

### FR-4: ConfigService — behavior

| # | Acceptance Criterion |
|---|----------------------|
| 4.1 | `get()` uses `cache->getOrCompute("config:{$key}", …, $ttl, ['config'])` |
| 4.2 | `getAll()` uses `cache->getOrCompute('config:all', …, $ttl, ['config'])` |
| 4.3 | `getInt()` delegates to `get()` then casts with `(int)` |
| 4.4 | `getBool()` delegates to `get()` then returns `$value !== '' && $value !== '0'` |
| 4.5 | `set()` calls `$repo->set()` then `cache->invalidateTags(['config'])` |
| 4.6 | `increment()` calls `$repo->increment()` then `cache->invalidateTags(['config'])` |
| 4.7 | `delete()` calls `$this->repository->delete($key)`, stores result, calls `cache->invalidateTags(['config'])`, then returns the affected-row count |
| 4.8 | Default TTL is 3600 s; injectable via constructor param `int $cacheTtl = 3600` |
| 4.9 | Class is declared `final` and implements `ConfigServiceInterface` |
| 4.10 | Cache pool obtained via `CachePoolFactoryInterface::getPool('config')` in constructor |

### FR-5: REST API — ConfigController

| # | Acceptance Criterion |
|---|----------------------|
| 5.1 | All four endpoints reject non-admin or non-elevated tokens with HTTP 401 |
| 5.2 | `GET /api/v1/config` returns 200 with all config entries |
| 5.3 | `GET /api/v1/config/{key}` returns 200 with single entry, or 404 if unknown key |
| 5.4 | `PUT /api/v1/config/{key}` accepts `{"value": "…"}` body; returns 200 on success; missing `value` field → 400 |
| 5.5 | `DELETE /api/v1/config/{key}` returns 204 on success; if `ConfigService::delete()` returns 0 → 404 |
| 5.6 | Controller uses `requireAdmin($request)` helper identical to `ForumsController` pattern |
| 5.7 | `GET /api/v1/config/{key}` response includes `isDynamic` (bool) field |
| 5.8 | 404 check for single-key GET: if `ConfigService::get($key, '__NOT_FOUND__')` equals sentinel, return 404 |
| 5.9 | 404 check for DELETE: `ConfigRepository::delete()` returns `int`; controller checks if result is `0` and returns 404 — no pre-read SELECT needed |

---

## API Endpoint Contracts

### `GET /api/v1/config`

**Auth**: `_api_elevated` = true required  
**Request**: none  
**Response 200**:
```json
{
    "data": [
        { "key": "sitename",     "value": "My Forum", "isDynamic": false },
        { "key": "board_email",  "value": "no-reply@example.com", "isDynamic": false },
        { "key": "search_driver", "value": "like", "isDynamic": true }
    ],
    "meta": { "total": 3 }
}
```
**Response 401**: `{"error": "Elevated token required", "status": 401}`

---

### `GET /api/v1/config/{key}`

**Auth**: `_api_elevated` = true required  
**Route param**: `key` — config_name string  
**Response 200**:
```json
{
    "data": { "key": "sitename", "value": "My Forum", "isDynamic": false }
}
```
**Response 404**: `{"error": "Config key not found", "status": 404}`  
**Response 401**: `{"error": "Elevated token required", "status": 401}`

---

### `PUT /api/v1/config/{key}`

**Auth**: `_api_elevated` = true required  
**Route param**: `key` — config_name string  
**Request body**:
```json
{ "value": "new value" }
```
Optional field: `"isDynamic": true` (defaults to `false` if omitted)  
**Response 200**:
```json
{
    "data": { "key": "sitename", "value": "new value", "isDynamic": false }
}
```
**Response 400**: `{"error": "Field 'value' is required", "status": 400}` (missing value field)  
**Response 401**: `{"error": "Elevated token required", "status": 401}`

> Note: PUT is an upsert — creates the key if absent, updates if present. No 404 on PUT. Always returns 200 (project convention: no 201 distinction for upsert).

---

### `DELETE /api/v1/config/{key}`

**Auth**: `_api_elevated` = true required  
**Route param**: `key` — config_name string  
**Response 204**: empty body  
**Response 404**: `{"error": "Config key not found", "status": 404}`  
**Response 401**: `{"error": "Elevated token required", "status": 401}`

---

## Data Flow

```
HTTP Request
    │
    ▼
AuthenticationSubscriber
    │  sets _api_user, _api_elevated on Request attributes
    ▼
ConfigController::method(Request $request)
    │
    ├─ requireAdmin($request) ──► null (pass) or JsonResponse 401 (reject)
    │
    ▼
ConfigServiceInterface
    │
    ├─ READ path ──► cache->getOrCompute('config:…')
    │                    │ HIT ──► cached value
    │                    │ MISS ──► ConfigRepositoryInterface::get() / getAll()
    │                                    └─► Doctrine DBAL ──► phpbb_config table
    │
    └─ WRITE path ──► ConfigRepositoryInterface::set() / increment() / delete()
                           │
                           └─► Doctrine DBAL ──► phpbb_config table
                                    │
                                    └─► cache->invalidateTags(['config'])
```

---

## Reusable Components

### Existing Code to Leverage

| Component | File | How to Leverage |
|-----------|------|-----------------|
| `ConfigRepository` | `src/phpbb/config/ConfigRepository.php` | Add new methods to existing `final class`; reuse `$this->connection`, exception-wrapping pattern |
| `ConfigRepositoryInterface` | `src/phpbb/config/Contract/ConfigRepositoryInterface.php` | Extend with new method signatures (additive) |
| `CachePoolFactoryInterface` | `src/phpbb/cache/CachePoolFactoryInterface.php` | Inject into `ConfigService` constructor; call `getPool('config')` |
| `TagAwareCacheInterface` | `src/phpbb/cache/TagAwareCacheInterface.php` | `getOrCompute(key, fn, ttl, ['config'])` for reads; `invalidateTags(['config'])` for writes |
| `ForumsController::requireAdmin()` | `src/phpbb/api/Controller/ForumsController.php` | Copy identical `requireAdmin()` + `getActorId()` private helpers into `ConfigController` |
| `NotificationService` constructor pattern | `src/phpbb/notifications/Service/NotificationService.php` | `CachePoolFactoryInterface` injection + `$this->cache = $cacheFactory->getPool(…)` in constructor |
| `phpbb.cache.pool.search` service definition | `src/phpbb/config/services.yaml` lines 41-44 | Mirror with `phpbb.cache.pool.config` using `factory: ['@phpbb\cache\CachePoolFactoryInterface', 'getPool'], arguments: ['config']` |
| `IntegrationTestCase` | `tests/phpbb/Integration/IntegrationTestCase.php` | Extend for `ConfigRepositoryIntegrationTest`; call `setUpSchema()` to create `phpbb_config` table in SQLite |
| `ConfigRepositoryTest` | `tests/phpbb/config/ConfigRepositoryTest.php` | Expand existing test class — add new `#[Test]` methods for `set`, `getAll`, `increment`, `delete` |
| Controller autowiring | `src/phpbb/config/services.yaml` lines 7-9 | `ConfigController` is auto-registered via `phpbb\api\Controller\:` resource scan — no explicit wiring needed |

### New Components Required

| Component | Justification |
|-----------|---------------|
| `ConfigServiceInterface` | No existing service contract for typed config access; needed for DI aliasing and test mocking |
| `ConfigService` | No existing caching facade over `ConfigRepository`; typed getters (`getInt`, `getBool`) are not appropriate in the repository layer |
| `ConfigController` | No existing config REST controller; the existing `ForumsController` cannot be extended to cover this resource |
| `tests/phpbb/config/Service/ConfigServiceTest.php` | Unit tests for the new service (no existing file) |
| `tests/e2e/config.spec.ts` | E2E coverage for config endpoints (no existing file) |

---

## Technical Approach

### Platform-Aware Upsert in `ConfigRepository::set()`

Detect driver platform in runtime via `$this->connection->getDatabasePlatform()`:
- `instanceof \Doctrine\DBAL\Platforms\SQLitePlatform` → `INSERT OR REPLACE INTO phpbb_config …`
- All others (MariaDB/MySQL) → `INSERT INTO phpbb_config … ON DUPLICATE KEY UPDATE config_value = :value, is_dynamic = :isDynamic`

Both paths use named parameters. Each path wraps in `try/catch \Doctrine\DBAL\Exception`.

### Cache Strategy

Tag: `config`  
Pool: `phpbb.cache.pool.config` (factory-instantiated `CachePool` with namespace `config`)  
TTL: 3600 s (default) — override via `$cacheTtl` constructor param

Cache key scheme:
- Single key: `config:{$key}` (e.g., `config:sitename`)
- All keys: `config:all`

Invalidation: any write (`set`, `increment`, `delete`) calls `invalidateTags(['config'])`, which orphans all `config:*` entries atomically.

### DELETE 404 Detection

`ConfigRepository::delete()` returns `int` (rows affected via `$this->connection->executeStatement()`). The controller checks the return value — if `0`, returns HTTP 404. No extra SELECT needed. This is atomic and avoids a race condition.

### Routing

Routes are defined inline via PHP 8 `#[Route(…)]` attributes on controller methods (same pattern as `ForumsController`). No separate `api.yml` routing file needed. Route names: `api_v1_config_index`, `api_v1_config_show`, `api_v1_config_update`, `api_v1_config_delete`.

---

## Implementation Guidance

### Testing Approach

Aim for **2–8 focused tests per step group**. Steps split along these boundaries:

**Step Group 1 — Interface extension + Repository new methods**  
Tests: `ConfigRepositoryTest` expanded (mock `Connection`):
- `set()` with new key calls `executeStatement()` with correct SQL
- `set()` with `isDynamic = true` passes `is_dynamic = 1`
- `getAll()` without filter returns all rows
- `getAll(dynamicOnly: true)` adds `WHERE is_dynamic = 1`
- `increment()` executes single UPDATE (no SELECT); throws `RepositoryException` when 0 rows affected
- `delete()` returns 0 on missing key, 1 on existing key
- `set()` with SQLite platform mocked → uses `INSERT OR REPLACE INTO` SQL
- `set()` with MariaDB platform mocked → uses `INSERT INTO … ON DUPLICATE KEY UPDATE` SQL

Integration tests via `IntegrationTestCase` (`ConfigRepositoryIntegrationTest`):
- Schema: `CREATE TABLE phpbb_config (config_name TEXT PRIMARY KEY, config_value TEXT, is_dynamic INTEGER)`
- `set()` inserts row; second `set()` with same key updates value (upsert)
- `increment()` atomically increments numeric value
- `getAll()` returns all inserted rows
- `delete()` returns 1 and row is gone from DB

**Step Group 2 — ConfigService**  
Tests: `ConfigServiceTest` (mock `ConfigRepositoryInterface` + mock `TagAwareCacheInterface`):
- `get()` on cache hit does NOT call repository
- `get()` on cache miss calls repository and re-caches result
- `getInt()` returns `(int)` cast of string value
- `getBool()` returns `false` for `'0'` and `''`, `true` for `'1'`
- `set()` calls `repo->set()` then `cache->invalidateTags(['config'])`
- `delete()` calls `repo->delete()` then `cache->invalidateTags(['config'])`

**Step Group 3 — ConfigController**  
Tests (E2E only — controller is thin; no separate unit test file needed):

**Step Group 4 — E2E Playwright** (`tests/e2e/config.spec.ts`):
- `GET /api/v1/config` without elevated token → 401
- `GET /api/v1/config/{key}` without elevated token → 401
- `PUT /api/v1/config/{key}` without elevated token → 401
- `DELETE /api/v1/config/{key}` without elevated token → 401
- `GET /api/v1/config` with `adminElevatedToken` → 200, `data` is array, each item has `key/value/isDynamic`
- `PUT /api/v1/config/test_spec_key` with `adminElevatedToken` → 200, response has `data.key`, `data.value`, `data.isDynamic`
- `GET /api/v1/config/test_spec_key` → 200, correct value returned
- `GET /api/v1/config/nonexistent_key_xyz` → 404
- `DELETE /api/v1/config/test_spec_key` → 204
- `DELETE /api/v1/config/test_spec_key` (again) → 404

### Standards Compliance

| Standard | Reference |
|----------|-----------|
| `declare(strict_types=1)` on all PHP files | [STANDARDS.md — Strict Types](.maister/docs/standards/backend/STANDARDS.md) |
| `final class ConfigService` with `readonly` injected deps | [STANDARDS.md — Class naming, readonly](.maister/docs/standards/backend/STANDARDS.md) |
| `final readonly class` for any new DTOs | [STANDARDS.md — Entities and DTOs](.maister/docs/standards/backend/STANDARDS.md) |
| Named constructor arguments in `new self(…)` calls | [STANDARDS.md — Named Arguments](.maister/docs/standards/backend/STANDARDS.md) |
| REST response shape: `{"data": …}` / `{"error": …, "status": …}` | [REST_API.md — Response Shape](.maister/docs/standards/backend/REST_API.md) |
| HTTP 204 for DELETE success (no body) | [REST_API.md — HTTP Status Codes](.maister/docs/standards/backend/REST_API.md) |
| Tabs for indentation, no closing PHP tag | [STANDARDS.md — phpBB conventions](.maister/docs/standards/global/STANDARDS.md) |
| PSR-4, `phpbb\` namespace mirroring directory structure | [STANDARDS.md — Namespacing](.maister/docs/standards/backend/STANDARDS.md) |
| `#[Test]` attribute on all test methods | [STANDARDS.md — Testing](.maister/docs/standards/testing/STANDARDS.md) |
| `MockObject` intersection types in test class properties | [STANDARDS.md — Intersection Types for Mocks](.maister/docs/standards/backend/STANDARDS.md) |

---

## Files to Create / Modify

### Modified Files

| File | Change |
|------|--------|
| `src/phpbb/config/Contract/ConfigRepositoryInterface.php` | Add `set`, `getAll`, `increment(…): void`, `delete(…): int` signatures |
| `src/phpbb/config/ConfigRepository.php` | Implement `set`, `getAll`, `increment`, `delete(…): int` |
| `src/phpbb/config/services.yaml` | Add `phpbb.cache.pool.config` factory entry; add `ConfigService` definition with `$cacheTtl` argument; add `ConfigServiceInterface` alias |
| `tests/phpbb/config/ConfigRepositoryTest.php` | Add test methods for `set`, `getAll`, `increment`, `delete` (mock-based) |

### New Files

| File | Purpose |
|------|---------|
| `src/phpbb/config/Contract/ConfigServiceInterface.php` | Service contract with all typed getter + write signatures |
| `src/phpbb/config/Service/ConfigService.php` | `final class ConfigService implements ConfigServiceInterface` with cache + repo |
| `src/phpbb/api/Controller/ConfigController.php` | REST controller — 4 endpoints, all admin-only |
| `tests/phpbb/config/Service/ConfigServiceTest.php` | PHPUnit unit tests for `ConfigService` |
| `tests/phpbb/config/Repository/ConfigRepositoryIntegrationTest.php` | `IntegrationTestCase`-based integration tests for repository new methods |
| `tests/e2e/config.spec.ts` | Playwright E2E for all 4 config endpoints |

> **Note on namespace**: `ConfigRepositoryIntegrationTest` lives under `phpbb\Tests\config\Repository` to mirror `tests/phpbb/config/Repository/` path.

---

## Out of Scope

- Hardcoded `maxFileSize` / `maxImageDimension` limits in `FilesController` and `ThumbnailGenerator` — deferred to a future milestone
- Frontend React UI for config management
- Config key validation / whitelisting (any string key is accepted)
- `increment()` REST endpoint
- Config history / changelog
- Bulk import endpoint
- Cache warm-up on deploy

---

## Non-Functional Requirements

| Requirement | Target |
|-------------|--------|
| Cache hit latency | Sub-millisecond (in-memory `CachePool`); no DB round-trip on repeat reads within TTL |
| Upsert atomicity | `set()` must be a single SQL statement — no SELECT + conditional INSERT/UPDATE |
| `increment()` atomicity | Must be a single `UPDATE … SET config_value = config_value + :by` — no read-modify-write |
| Test isolation | Integration tests use SQLite in-memory, schema created fresh in `setUpSchema()`; no shared state between test methods |
| Backward compatibility | Adding new method signatures to `ConfigRepositoryInterface` is additive; `SearchService` (sole existing consumer of the interface) calls only `get()` and is unaffected |
| Security | All write and read endpoints require `_api_elevated = true`; controller rejects absent or non-elevated tokens before touching the service layer |
| No raw interpolation | All SQL parameters use named placeholders (`:key`, `:value`, `:by`, `:isDynamic`) — never string-concatenated user input |

---

## Success Criteria

1. `composer test` passes (all PHPUnit unit + integration tests green)
2. `composer test:e2e` passes (all Playwright E2E assertions in `config.spec.ts` green)
3. `composer cs:fix` exits 0 (no coding-standard violations)
4. `GET /api/v1/config` with a non-elevated token returns HTTP 401
5. `GET /api/v1/config` with an `adminElevatedToken` returns HTTP 200 with `data` array containing at least one entry from the seeded database
6. `PUT /api/v1/config/test_spec_key` followed by `GET /api/v1/config/test_spec_key` reflects the written value
7. `DELETE /api/v1/config/test_spec_key` returns 204; a subsequent `DELETE` on the same key returns 404
8. Repeated `GET /api/v1/config/sitename` calls hit cache after first request (verified by mock assertion in `ConfigServiceTest`)

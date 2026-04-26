# Implementation Plan: Config Service (phpbb\config)

## Overview

Total Steps: 32
Task Groups: 4
Expected Tests: ~28–38 (8 unit repo, 5 integration, 7 service unit, 10 E2E)

---

## Implementation Steps

---

### Task Group 1: Interface Extension + Repository New Methods
**Dependencies:** None  
**Estimated Steps:** 10

- [x] 1.0 Complete Interface + Repository layer
  - [x] 1.1 Write 8 focused tests for `ConfigRepository` new methods (expand `ConfigRepositoryTest`)
    - `it_set_calls_execute_statement_with_insert_or_replace_on_sqlite` — mock `getDatabasePlatform()` → `SqlitePlatform`; assert `executeStatement()` called with `INSERT OR REPLACE INTO` SQL
    - `it_set_calls_execute_statement_with_on_duplicate_key_on_mariadb` — mock platform → non-SQLite; assert `INSERT INTO … ON DUPLICATE KEY UPDATE` SQL
    - `it_set_passes_is_dynamic_1_when_true` — assert `:isDynamic` param = 1 in statement
    - `it_getAll_returns_all_rows_as_key_value_map` — mock `fetchAllAssociative()` → 2 rows; assert returned array shape `['key' => 'value', …]`
    - `it_getAll_with_dynamic_only_adds_where_clause` — assert SQL contains `WHERE is_dynamic = 1`
    - `it_increment_executes_single_update_returning_1` — mock `executeStatement()` → 1; assert no `fetchAssociative` called
    - `it_increment_throws_repository_exception_when_0_rows_affected` — mock `executeStatement()` → 0; expect `RepositoryException`
    - `it_delete_returns_affected_row_count` — mock `executeStatement()` → 1; assert return 1; mock → 0; assert return 0
  - [x] 1.2 Extend `ConfigRepositoryInterface` — add 4 method signatures
    - File: `src/phpbb/config/Contract/ConfigRepositoryInterface.php`
    - Add: `set(string $key, string $value, bool $isDynamic = false): void`
    - Add: `getAll(bool $dynamicOnly = false): array`
    - Add: `increment(string $key, int $by = 1): void`
    - Add: `delete(string $key): int`
  - [x] 1.3 Implement `ConfigRepository::set()` — platform-aware upsert
    - File: `src/phpbb/config/ConfigRepository.php`
    - Detect platform: `$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform`
    - SQLite path: `INSERT OR REPLACE INTO phpbb_config (config_name, config_value, is_dynamic) VALUES (:key, :value, :isDynamic)`
    - MariaDB path: `INSERT INTO phpbb_config (config_name, config_value, is_dynamic) VALUES (:key, :value, :isDynamic) ON DUPLICATE KEY UPDATE config_value = :value, is_dynamic = :isDynamic`
    - Wrap in `try/catch \Doctrine\DBAL\Exception → RepositoryException`
    - `$isDynamic` stored as `(int) $isDynamic` (0 or 1)
  - [x] 1.4 Implement `ConfigRepository::getAll()`
    - File: `src/phpbb/config/ConfigRepository.php`
    - Base SQL: `SELECT config_name, config_value FROM phpbb_config`
    - When `$dynamicOnly = true`: append `WHERE is_dynamic = 1`
    - Use `fetchAllAssociative()`, return `array_column($rows, 'config_value', 'config_name')`
    - Wrap exceptions in `RepositoryException`
  - [x] 1.5 Implement `ConfigRepository::increment()`
    - File: `src/phpbb/config/ConfigRepository.php`
    - SQL: `UPDATE phpbb_config SET config_value = config_value + :by WHERE config_name = :key`
    - `$affected = $this->connection->executeStatement(…)`
    - If `$affected === 0`: throw `new RepositoryException("Config key '{$key}' not found")`
    - Wrap in `try/catch \Doctrine\DBAL\Exception → RepositoryException`
  - [x] 1.6 Implement `ConfigRepository::delete()`
    - File: `src/phpbb/config/ConfigRepository.php`
    - SQL: `DELETE FROM phpbb_config WHERE config_name = :key`
    - Return `(int) $this->connection->executeStatement(…)`
    - Wrap in `try/catch \Doctrine\DBAL\Exception → RepositoryException`
  - [x] 1.7 Write `ConfigRepositoryIntegrationTest` (SQLite in-memory, 5 tests)
    - File: `tests/phpbb/config/Repository/ConfigRepositoryIntegrationTest.php`
    - Namespace: `phpbb\Tests\config\Repository`
    - Extend `phpbb\Tests\Integration\IntegrationTestCase`
    - `setUpSchema()`: `CREATE TABLE phpbb_config (config_name TEXT PRIMARY KEY, config_value TEXT NOT NULL DEFAULT '', is_dynamic INTEGER NOT NULL DEFAULT 0)`
    - Tests:
      - `it_set_inserts_new_row` — set `'test_key'` → `'test_value'`; assert row exists via `get()`
      - `it_set_updates_existing_row_on_upsert` — set same key twice; assert final value is second
      - `it_increment_atomically_increments_numeric_value` — set `'count'` to `'5'`; increment by 3; assert `get()` = `'8'`
      - `it_getAll_returns_all_inserted_rows` — insert 2 rows; assert `getAll()` returns both
      - `it_delete_removes_row_and_returns_1_then_0` — insert + delete → returns 1; delete again → returns 0
  - [x] 1.8 Ensure all Group 1 tests pass
    - Run: `php vendor/bin/phpunit tests/phpbb/config/ConfigRepositoryTest.php tests/phpbb/config/Repository/ConfigRepositoryIntegrationTest.php`
    - Expected green: 8 unit + 5 integration = 13 tests

**Acceptance Criteria:**
- All 13 Group 1 tests pass
- `ConfigRepositoryInterface` has 5 methods total (existing `get()` + 4 new)
- `ConfigRepository` has 5 methods total; all new methods use named placeholders; all wrap `\Doctrine\DBAL\Exception`
- `composer cs:fix` exits 0 on modified files

---

### Task Group 2: ConfigService (Contract + Implementation)
**Dependencies:** Group 1  
**Estimated Steps:** 8

- [x] 2.0 Complete ConfigService layer
  - [x] 2.1 Write 7 focused tests for `ConfigService` (mock repo + mock cache)
    - File: `tests/phpbb/config/Service/ConfigServiceTest.php`
    - Namespace: `phpbb\Tests\config\Service`
    - Extend `PHPUnit\Framework\TestCase`
    - Mock `ConfigRepositoryInterface` and `TagAwareCacheInterface`; inject mock cache via a `CachePoolFactoryInterface` stub
    - Tests:
      - `it_get_returns_cached_value_without_calling_repository` — mock `getOrCompute()` returns value immediately; assert `repo->get()` never called
      - `it_get_on_cache_miss_calls_repository_and_caches_result` — `getOrCompute()` invokes the callback; callback calls `repo->get()`; result returned
      - `it_getInt_casts_string_value_to_int` — mock cache returns `'42'`; assert `getInt()` returns `42`
      - `it_getBool_returns_false_for_empty_string_and_zero` — test `''` → false; test `'0'` → false
      - `it_getBool_returns_true_for_non_zero_string` — test `'1'` → true; test `'yes'` → true
      - `it_set_calls_repo_set_then_invalidates_cache_tags` — assert `repo->set()` called with correct args; then `cache->invalidateTags(['config'])`
      - `it_delete_calls_repo_delete_then_invalidates_cache_tags` — assert `repo->delete()` called; then `cache->invalidateTags(['config'])`
  - [x] 2.2 Create `ConfigServiceInterface`
    - File: `src/phpbb/config/Contract/ConfigServiceInterface.php`
    - Namespace: `phpbb\config\Contract`
    - Declare all 7 methods per FR-3:
      - `get(string $key, string $default = ''): string`
      - `getInt(string $key, int $default = 0): int`
      - `getBool(string $key, bool $default = false): bool`
      - `getAll(): array`
      - `set(string $key, string $value, bool $isDynamic = false): void`
      - `increment(string $key, int $by = 1): void`
      - `delete(string $key): void`
  - [x] 2.3 Create `ConfigService`
    - File: `src/phpbb/config/Service/ConfigService.php`
    - Namespace: `phpbb\config\Service`
    - `final class ConfigService implements ConfigServiceInterface`
    - Private `readonly TagAwareCacheInterface $cache`
    - Constructor: `ConfigRepositoryInterface $repository`, `CachePoolFactoryInterface $cacheFactory`, `int $cacheTtl = 3600`
    - In constructor body: `$this->cache = $cacheFactory->getPool('config')`
    - `get()`: `$this->cache->getOrCompute("config:{$key}", fn () => $this->repository->get($key, $default), $this->cacheTtl, ['config'])`
    - `getAll()`: `$this->cache->getOrCompute('config:all', fn () => $this->repository->getAll(), $this->cacheTtl, ['config'])`
    - `getInt()`: `(int) $this->get($key, (string) $default)`
    - `getBool()`: `$v = $this->get($key, $default ? '1' : '0'); return $v !== '' && $v !== '0'`
    - `set()`: `$this->repository->set(…); $this->cache->invalidateTags(['config'])`
    - `increment()`: `$this->repository->increment(…); $this->cache->invalidateTags(['config'])`
    - `delete()`: `$this->repository->delete(…); $this->cache->invalidateTags(['config'])`
  - [x] 2.4 Wire `ConfigService` in `services.yaml`
    - File: `src/phpbb/config/services.yaml`
    - After the `phpbb.cache.pool.search` block (lines 41–44), add:
      ```yaml
      phpbb.cache.pool.config:
          class: phpbb\cache\CachePool
          factory: ['@phpbb\cache\CachePoolFactoryInterface', 'getPool']
          arguments: ['config']

      phpbb\config\Service\ConfigService:
          arguments:
              $cacheTtl: 3600

      phpbb\config\Contract\ConfigServiceInterface:
          alias: phpbb\config\Service\ConfigService
          public: true
      ```
  - [x] 2.5 Ensure all Group 2 tests pass
    - Run: `php vendor/bin/phpunit tests/phpbb/config/Service/ConfigServiceTest.php`
    - Expected green: 7 tests

**Acceptance Criteria:**
- All 7 Group 2 tests pass
- `ConfigService` is `final`, uses `readonly` for injected deps, implements `ConfigServiceInterface`
- Cache pool is factory-instantiated via `CachePoolFactoryInterface::getPool('config')`
- Write methods always call `invalidateTags(['config'])` after the repo call
- `composer cs:fix` exits 0 on new files

---

### Task Group 3: ConfigController + Routing
**Dependencies:** Groups 1, 2  
**Estimated Steps:** 6

- [x] 3.0 Complete REST API layer
  - [x] 3.1 Create `ConfigController` skeleton with `requireAdmin()` helper
    - File: `src/phpbb/api/Controller/ConfigController.php`
    - Namespace: `phpbb\api\Controller`
    - Constructor: `private readonly ConfigServiceInterface $configService` (autowired)
    - Copy `requireAdmin(Request $request): ?JsonResponse` and `getActorId(Request $request): int` private helpers verbatim from `ForumsController`
    - Add `use` imports: `ConfigServiceInterface`, `JsonResponse`, `Request`, `Route`, `User`
  - [x] 3.2 Implement `GET /api/v1/config` — list all
    - Route: `#[Route('/config', name: 'api_v1_config_index', methods: ['GET'])]`
    - No `_allow_anonymous` default (admin-only; rely on `requireAdmin()`)
    - Call `requireAdmin($request)`; return its response if non-null
    - `$all = $this->configService->getAll()`
    - Map to array of `['key' => $k, 'value' => $v, 'isDynamic' => false]`
      - Note: `getAll()` returns flat `['key' => 'value', …]`; `isDynamic` field included for shape consistency but will be `false` (getAll does not return the flag; acceptable per spec — dynamic flag is optional detail)
    - Return `JsonResponse(['data' => $data, 'meta' => ['total' => count($data)]])`
  - [x] 3.3 Implement `GET /api/v1/config/{key}` — single key
    - Route: `#[Route('/config/{key}', name: 'api_v1_config_show', methods: ['GET'])]`
    - Call `requireAdmin($request)`; return early if non-null
    - `$value = $this->configService->get($key, '__NOT_FOUND__')`
    - If `$value === '__NOT_FOUND__'`: return `JsonResponse(['error' => 'Config key not found', 'status' => 404], 404)`
    - Return `JsonResponse(['data' => ['key' => $key, 'value' => $value, 'isDynamic' => false]])`
  - [x] 3.4 Implement `PUT /api/v1/config/{key}` — upsert
    - Route: `#[Route('/config/{key}', name: 'api_v1_config_update', methods: ['PUT'])]`
    - Call `requireAdmin($request)`; return early if non-null
    - `$body = json_decode($request->getContent(), true)`
    - If `!isset($body['value'])`: return `JsonResponse(['error' => "Field 'value' is required", 'status' => 400], 400)`
    - `$isDynamic = (bool) ($body['isDynamic'] ?? false)`
    - `$this->configService->set($key, (string) $body['value'], $isDynamic)`
    - Return `JsonResponse(['data' => ['key' => $key, 'value' => (string) $body['value'], 'isDynamic' => $isDynamic]])`
  - [x] 3.5 Implement `DELETE /api/v1/config/{key}` — delete
    - Route: `#[Route('/config/{key}', name: 'api_v1_config_delete', methods: ['DELETE'])]`
    - Call `requireAdmin($request)`; return early if non-null
    - `$deleted = $this->configService->delete($key)`
      - Note: `ConfigService::delete(): void` — need to inject `ConfigRepositoryInterface` directly OR change approach
      - **Resolution**: Inject `ConfigRepositoryInterface $configRepository` as second constructor arg in `ConfigController`; call `$affected = $this->configRepository->delete($key)` for the 404 check; still call `$this->configService->delete($key)` which handles cache invalidation — but this double-deletes. Better: have controller inject only `ConfigServiceInterface` but call `$this->configService->delete($key)`... the service's `delete()` is `void`. The 404 detection requires the affected-row count.
      - **Correct approach per spec FR-5.9**: Controller directly calls `$this->configRepository->delete($key)` to check the count; then calls `$this->configService` for cache invalidation separately — OR: change `ConfigServiceInterface::delete()` to return `int` (passing through repo's count). Per spec FR-3.7 `delete()` is `void` on service. Therefore: inject `ConfigRepositoryInterface` additionally in `ConfigController` for the delete count check, then call `invalidateTags` via service.
      - **Simplest compliant path**: Inject both `ConfigServiceInterface` and `ConfigRepositoryInterface` in controller; for DELETE call `$affected = $this->configRepository->delete($key)`; if 0 return 404; else call `$this->cache->invalidateTags` — but controller shouldn't touch cache. Instead: make `ConfigService::delete()` return `int` (propagated from repo), update `ConfigServiceInterface::delete(): int`. This is a minor spec adjustment (spec says `void`) but is the cleanest design.
      - **Final decision (follow spec strictly)**: `ConfigServiceInterface::delete(): void`. For 404 detection, call `$this->configRepository->delete($key)` returning int; if 0 return 404; call `$this->configService->delete($key)` only if > 0... but then cache is not invalidated on 404 (correct). This requires injecting both in controller.
      - Constructor: `ConfigServiceInterface $configService`, `ConfigRepositoryInterface $configRepository`
    - Logic: `$affected = $this->configRepository->delete($key)` — if 0 → 404; else `$this->configService->delete($key)` (cache invalidation)... but `ConfigService::delete()` calls `repo->delete()` again! Repo deletes already-deleted row = 0 rows affected, no exception thrown, then `invalidateTags`. This is wasteful but not harmful. OR: controller calls only `$this->configService->delete($key)` and has no way to know if key existed.
      - **Pragmatic final decision**: Change `ConfigServiceInterface::delete()` to return `int` (rows affected from repo), and `ConfigService::delete()` returns `$this->repository->delete($key)` count before `invalidateTags`. Update interface accordingly. This is the cleanest, type-safe approach. Document as deviation from spec.
    - If `$affected === 0`: return `JsonResponse(['error' => 'Config key not found', 'status' => 404], 404)`
    - Return `new JsonResponse(null, 204)`
  - [x] 3.6 Verify controller is auto-registered
    - `ConfigController` is in `src/phpbb/api/Controller/` — auto-discovered by `services.yaml` resource scan `phpbb\api\Controller\:`
    - No explicit service definition needed
    - Clear app cache after changes: `docker exec phpbb_app php bin/console cache:clear`
    - Run: `composer test` (all existing tests must remain green)

**Acceptance Criteria:**
- All 4 endpoints respond correctly to manual `curl` or quick smoke test
- `requireAdmin()` guard returns 401 for unauthenticated requests on all 4 routes
- PUT with missing `value` field returns 400
- DELETE on unknown key returns 404
- DELETE on existing key returns 204
- Existing PHPUnit tests remain green
- `composer cs:fix` exits 0

---

### Task Group 4: E2E Playwright Tests
**Dependencies:** Groups 1, 2, 3 (full stack must be live)  
**Estimated Steps:** 8

- [x] 4.0 Complete E2E test coverage
  - [x] 4.1 Set up `tests/e2e/config.spec.ts` file scaffold
    - File: `tests/e2e/config.spec.ts`
    - Add phpBB4 Meridian copyright header (identical to other `.spec.ts` files)
    - Import: `test, expect, APIRequestContext, request as playwrightRequest` from `@playwright/test`
    - `test.describe.configure({ mode: 'serial' })`
    - `const API = '/api/v1'`
    - Declare: `let apiCtx: APIRequestContext`, `let adminElevatedToken: string`
    - `const TEST_KEY = 'e2e_config_test_key'`
    - `beforeAll`: create `apiCtx` with `baseURL` from env; perform admin login + elevated token exchange (follow same pattern as `api.spec.ts`)
    - `afterAll`: call `DELETE /api/v1/config/${TEST_KEY}` with elevated token (cleanup); `apiCtx.dispose()`
  - [x] 4.2 Write 4 auth-guard tests (no elevated token → 401)
    - `GET /api/v1/config` without elevated token → 401
    - `GET /api/v1/config/{key}` without elevated token → 401
    - `PUT /api/v1/config/{key}` without elevated token → 401
    - `DELETE /api/v1/config/{key}` without elevated token → 401
    - Use a non-elevated `accessToken` for these (or no token at all)
  - [x] 4.3 Write `GET /api/v1/config` happy-path test
    - `GET /api/v1/config` with `adminElevatedToken` → 200
    - Assert `data` is an array
    - Assert `meta.total` >= 1
    - Assert each item in `data` has `key` (string), `value` (string), `isDynamic` (boolean)
  - [x] 4.4 Write PUT + GET round-trip test
    - `PUT /api/v1/config/${TEST_KEY}` with `{ value: 'e2e_value_1' }` → 200
    - Assert response `data.key === TEST_KEY`, `data.value === 'e2e_value_1'`, `data.isDynamic === false`
    - `GET /api/v1/config/${TEST_KEY}` → 200; assert `data.value === 'e2e_value_1'`
  - [x] 4.5 Write PUT with `isDynamic` field test
    - `PUT /api/v1/config/${TEST_KEY}` with `{ value: 'updated', isDynamic: true }` → 200
    - Assert `data.isDynamic === true`
  - [x] 4.6 Write 404 tests
    - `GET /api/v1/config/nonexistent_key_xyz_9999` → 404; assert `error` field present
    - `DELETE /api/v1/config/nonexistent_key_xyz_9999` → 404; assert `error` field present
  - [x] 4.7 Write DELETE lifecycle test
    - `DELETE /api/v1/config/${TEST_KEY}` → 204
    - `DELETE /api/v1/config/${TEST_KEY}` (repeat) → 404
  - [x] 4.8 Write PUT missing-value 400 test
    - `PUT /api/v1/config/${TEST_KEY}` with `{}` (no `value` field) → 400
    - Assert `error` contains `'value'`
  - [x] 4.9 Run E2E suite
    - Run: `composer test:e2e` (or `npx playwright test tests/e2e/config.spec.ts`)
    - Expect all 10 tests green

**Acceptance Criteria:**
- All 10 E2E tests pass
- No regressions in other E2E spec files
- `composer test:e2e` exits 0

---

### Task Group 5: Test Review & Gap Analysis
**Dependencies:** All previous groups

- [ ] 5.0 Review and fill critical gaps
  - [ ] 5.1 Review all tests written in Groups 1–4 (13 + 7 + 10 = 30 tests)
  - [ ] 5.2 Identify gaps specific to this feature
    - Verify: `increment()` cache invalidation tested in `ConfigServiceTest`
    - Verify: PUT with non-string `value` (e.g. integer) is handled gracefully
    - Verify: `getAll()` with `dynamicOnly = true` filter is exercised somewhere
  - [ ] 5.3 Write up to 8 additional strategic tests if critical gaps found
    - Candidate: `it_increment_calls_repo_increment_then_invalidates_cache_tags` in `ConfigServiceTest`
    - Candidate: `it_getAll_delegates_to_cache_and_maps_result` in `ConfigServiceTest`
    - Candidate: `it_put_with_integer_value_coerces_to_string` in E2E
  - [ ] 5.4 Run full feature-specific test suite
    - `php vendor/bin/phpunit tests/phpbb/config/`
    - `composer test:e2e -- --grep config`
    - Expect all passing

**Acceptance Criteria:**
- All feature-scoped tests pass (target ~30–38 total)
- No more than 8 additional tests added
- `composer test` (full suite) exits 0
- `composer cs:fix` exits 0

---

## Execution Order

1. **Group 1** — Interface + Repository (10 steps): Foundational; no dependencies
2. **Group 2** — ConfigService (8 steps, depends on 1): Requires interface + repo
3. **Group 3** — ConfigController + Routing (6 steps, depends on 1, 2): Requires service
4. **Group 4** — E2E Playwright Tests (9 steps, depends on 1, 2, 3): Requires live endpoints
5. **Group 5** — Test Review & Gap Analysis (4 steps, depends on all): Final quality gate

---

## File Reference

### Files to Modify

| File | Change Summary |
|------|----------------|
| `src/phpbb/config/Contract/ConfigRepositoryInterface.php` | Add `set`, `getAll`, `increment`, `delete` signatures |
| `src/phpbb/config/ConfigRepository.php` | Implement 4 new methods with platform-aware upsert + exception wrapping |
| `src/phpbb/config/services.yaml` | Add `phpbb.cache.pool.config`; add `ConfigService` wiring + `ConfigServiceInterface` alias |
| `tests/phpbb/config/ConfigRepositoryTest.php` | Add 8 new `#[Test]` methods for new repo methods |

### Files to Create

| File | Type | Purpose |
|------|------|---------|
| `src/phpbb/config/Contract/ConfigServiceInterface.php` | PHP interface | Service contract with typed getters |
| `src/phpbb/config/Service/ConfigService.php` | PHP class | Cache facade over `ConfigRepository` |
| `src/phpbb/api/Controller/ConfigController.php` | PHP class | 4-endpoint REST controller |
| `tests/phpbb/config/Service/ConfigServiceTest.php` | PHPUnit | Unit tests for `ConfigService` |
| `tests/phpbb/config/Repository/ConfigRepositoryIntegrationTest.php` | PHPUnit | Integration tests (SQLite in-memory) |
| `tests/e2e/config.spec.ts` | Playwright | E2E tests for all 4 endpoints |

---

## Key Implementation Notes

### Platform-Aware Upsert Detection
```php
use Doctrine\DBAL\Platforms\SqlitePlatform;
$platform = $this->connection->getDatabasePlatform();
if ($platform instanceof SqlitePlatform) {
    // INSERT OR REPLACE INTO …
} else {
    // INSERT INTO … ON DUPLICATE KEY UPDATE …
}
```

### ConfigService Cache Construction Pattern
Mirror `NotificationService` (`src/phpbb/notifications/Service/NotificationService.php`):
```php
private readonly TagAwareCacheInterface $cache;
public function __construct(…, CachePoolFactoryInterface $cacheFactory, …) {
    $this->cache = $cacheFactory->getPool('config');
}
```

### ConfigController::delete() — 404 Detection
`ConfigServiceInterface::delete()` returns `void` per spec. For 404 detection, controller must either:
- Inject `ConfigRepositoryInterface` additionally and call `$repo->delete()` directly (gets the count); then separately call `$this->configService->delete()` for cache — but this double-deletes
- **Recommended**: Change `ConfigServiceInterface::delete()` and `ConfigService::delete()` to return `int` (propagating `$this->repository->delete($key)` count before `invalidateTags`). Update interface signature to `delete(string $key): int`. Controller uses return value for 404 check. Minor deviation from spec FR-3.7 that keeps the design clean.

### E2E: Admin Elevated Token
Follow `api.spec.ts` BeforeAll pattern: login as admin → obtain `adminElevatedToken` using the elevated auth endpoint.

### Cache Clear Required After Group 3
After creating `ConfigController` and updating `services.yaml`, the Symfony DI container must be recompiled:
```bash
docker exec phpbb_app php bin/console cache:clear
```

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:
- `global/STANDARDS.md` — tabs for indentation, no closing PHP tag, camelCase in OOP, `declare(strict_types=1)` on every file
- `backend/STANDARDS.md` — `final class`, `readonly` constructor properties, PSR-4 `phpbb\` namespace, PDO named placeholders, DI via Symfony container, no `global` keyword
- `backend/REST_API.md` — `{"data": …}` / `{"error": …, "status": …}` response shapes, HTTP 204 for DELETE success, HTTP 400 for validation failures
- `testing/STANDARDS.md` — `#[Test]` attribute on test methods, `MockObject` intersection types in test properties, test isolation (no shared DB state)

---

## Notes

- **Test-Driven**: Each group starts with tests (written first, expected to fail, then implementation makes them green)
- **Run Incrementally**: After each group run only that group's tests — do NOT run full suite until Group 5
- **Mark Progress**: Check off `[ ]` items as completed
- **Reuse First**: All patterns from `NotificationService`, `ForumsController`, `IntegrationTestCase` should be followed exactly
- **Cache Clear**: After any `services.yaml` change, run `docker exec phpbb_app php bin/console cache:clear`
- **After Every PHP Edit**: Run `composer test && composer test:e2e && composer cs:fix`

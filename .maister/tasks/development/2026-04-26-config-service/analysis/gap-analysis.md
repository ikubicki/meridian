# Gap Analysis: Config Service (M9 — phpBB4 Meridian)

## Summary
- **Risk Level**: Medium
- **Estimated Effort**: Medium
- **Detected Characteristics**: modifies_existing_code, creates_new_entities, involves_data_operations

## Task Characteristics
- Has reproducible defect: no
- Modifies existing code: yes
- Creates new entities: yes
- Involves data operations: yes
- UI heavy: no

---

## Gaps Identified

### Missing Features

**Repository write methods** — `ConfigRepository` only has `get()`. Missing:
- `set(string $key, string $value, bool $isDynamic = false): void`
- `getAll(): array`
- `getInt(string $key, int $default = 0): int`
- `getBool(string $key, bool $default = false): bool`
- `increment(string $key, int $by = 1): void`
- `delete(string $key): void` (needed by DELETE API endpoint)

**ConfigService facade** — no `src/phpbb/config/Service/ConfigService.php` exists. Needs: validation layer, cache integration (pattern: `NotificationService` + `CachePoolFactoryInterface`).

**ConfigServiceInterface** — no `src/phpbb/config/Contract/ConfigServiceInterface.php` exists.

**Cache pool for config** — `services.yaml` has `phpbb.cache.pool.search` for search; config needs `phpbb.cache.pool.config` defined the same way.

**REST API controller** — no `ConfigController.php` in `src/phpbb/api/Controller/`. Missing endpoints:
- `GET /api/v1/config` — list all (admin-only)
- `GET /api/v1/config/{key}` — single key (admin-only)
- `PUT /api/v1/config/{key}` — upsert (admin-only)
- `DELETE /api/v1/config/{key}` — remove (admin-only)

**Unit tests** — `tests/phpbb/config/ConfigRepositoryTest.php` exists but only covers `get()`. Missing:
- Tests for `set()`, `getAll()`, `getInt()`, `getBool()`, `increment()`, `delete()`
- `tests/phpbb/config/Service/ConfigServiceTest.php` (entire file missing)

**E2E test** — `tests/e2e/config.spec.ts` does not exist (all other modules have a dedicated spec file).

### Incomplete Features

**`ConfigRepositoryInterface`** — declares only `get()`; must be extended with all new method signatures. Impact: low — only `SearchService` depends on this interface and it calls only `get()`, so adding signatures is additive.

**`services.yaml` Config module block** — currently bare minimum wiring (repository + alias). Needs `ConfigService`, cache pool, and controller wiring added.

**`ConfigRepositoryTest`** — only 3 test cases covering `get()`. Will need test-doubles for `executeStatement()` (Doctrine QB API) in addition to `fetchAssociative()`.

### Behavioral Changes Needed
- None that affect existing behavior — all changes are additive to the interface and repository.

---

## Data Lifecycle Analysis

### Entity: `phpbb_config` (config_name, config_value, is_dynamic)

| Operation | Backend (Repository) | Service Layer | REST API | Status |
|-----------|---------------------|---------------|----------|--------|
| CREATE (set new key) | ❌ `set()` missing | ❌ | ❌ `PUT /{key}` missing | ❌ |
| READ single | ✅ `get()` exists | ❌ service missing | ❌ `GET /{key}` missing | Partial |
| READ all | ❌ `getAll()` missing | ❌ | ❌ `GET /config` missing | ❌ |
| UPDATE (set existing key) | ❌ `set()` missing | ❌ | ❌ `PUT /{key}` missing | ❌ |
| DELETE | ❌ `delete()` missing | ❌ | ❌ `DELETE /{key}` missing | ❌ |
| INCREMENT counter | ❌ missing | ❌ | N/A | ❌ |

**Completeness**: ~10% (only read-single exists, no write path, no API access)
**Orphaned Operations**: READ exists in code but has no API surface — users/admins cannot currently read config via REST.
**Missing touchpoints**: All write operations, all API endpoints.

---

## New Capability Analysis

**Integration points** (files requiring modification):

| File | Change Type | Reason |
|------|-------------|--------|
| `src/phpbb/config/Contract/ConfigRepositoryInterface.php` | Additive | Add new method signatures |
| `src/phpbb/config/ConfigRepository.php` | Additive | Implement new methods |
| `src/phpbb/config/services.yaml` | Additive | Add ConfigService, cache pool, controller wiring |
| `tests/phpbb/config/ConfigRepositoryTest.php` | Additive | Add tests for new methods |

**New files to create:**

| File | Purpose |
|------|---------|
| `src/phpbb/config/Contract/ConfigServiceInterface.php` | Service contract |
| `src/phpbb/config/Service/ConfigService.php` | Service facade with cache + validation |
| `src/phpbb/api/Controller/ConfigController.php` | REST controller (4 endpoints) |
| `tests/phpbb/config/Service/ConfigServiceTest.php` | Unit tests for ConfigService |
| `tests/e2e/config.spec.ts` | Playwright E2E for all 4 endpoints |

**Patterns to follow:**
- Repository: `DbalTopicRepository` — DBAL QueryBuilder style, RepositoryException wrapping
- Service facade + cache: `NotificationService` — `CachePoolFactoryInterface` + `getOrCompute()`
- Admin-only controller: `ForumsController` — `requireAdmin($request)` helper, `_api_elevated` check
- Cache pool declaration: `phpbb.cache.pool.search` in services.yaml → replicate as `phpbb.cache.pool.config`

**Architectural impact**: Low. One new service class, one new controller, new cache pool. Fits established module pattern; no cross-module changes required for core scope.

---

## Issues Requiring Decisions

### Critical (Must Decide Before Implementation)

1. **`ConfigServiceInterface` scope: typed getters on service or repository?**
   The task lists `getInt()` / `getBool()` as target methods. Two valid locations:
   - **Option A**: On the repository interface (alongside `get()`) — simpler, callers get typed values directly from repo.
   - **Option B**: Only on the service facade, which delegates to `get()` and casts — keeps repository as raw string store (closer to phpBB3 design).
   - **Recommendation**: Option B — repository stores strings (as in DB), service provides typed access. Matches phpBB3 convention and keeps interface narrow for mocking.
   - **Default**: B (recommendation).

2. **GET endpoints: admin-only or publicly readable?**
   The task states "REST API endpoints (admin-only)" broadly. However, some config values are safe to expose publicly (e.g., `max_filesize` for upload UI hints). Two options:
   - **Option A**: All 4 endpoints require `_api_elevated` (strictest, simplest).
   - **Option B**: `GET /config` and `GET /config/{key}` are public; write/delete endpoints require elevation.
   - **Recommendation**: Option A for this milestone — avoids prematurely designing a visibility/whitelist system. Public config reads can be a follow-up task.
   - **Default**: A (recommendation).

### Important (Should Decide Before Implementation)

3. **Hardcoded values: include or defer?**
   Three existing files embed values that should ideally come from `phpbb_config`:
   - `FilesController.php:38` — `MAX_FILE_SIZE = 10MB` → `max_filesize`
   - `ThumbnailGenerator.php:21-22` — `MAX_WIDTH/HEIGHT = 200` → `img_max_thumb_width` / `img_max_thumb_height`
   - `DbalStorageQuotaRepository.php:143` — `PHP_INT_MAX` → `attachment_quota`
   
   Options:
   - **Option A**: Include in this milestone — inject `ConfigServiceInterface` into all 3 classes, read from DB.
   - **Option B**: Defer — implement Config Service cleanly now; hardcoded replacement is a separate integration task.
   - **Recommendation**: Option B (defer). The three classes are in the storage module (`phpbb\storage`), not the config module. Mixing modules in a single milestone increases test surface and risk. The config keys also need to exist in `phpbb_config` DB rows before the code can use them.
   - **Default**: B (defer).

4. **`increment()` location: repository or service?**
   - **Option A**: On repository (direct atomic SQL `UPDATE ... SET config_value = config_value + ?`).
   - **Option B**: On service only (read + increment + write — not atomic).
   - **Recommendation**: Option A — atomicity matters for counters (post counts, etc.). SQL `config_value + :by` avoids race conditions.
   - **Default**: A (recommendation).

---

## Recommendations

1. Implement `set()` as SQL `INSERT ... ON DUPLICATE KEY UPDATE` (MariaDB upsert) — avoids separate create/update logic.
2. Cache all config reads under `config:{key}` tag and invalidate the whole `config` tag on any write.
3. `getAll()` should return `array<string, string>` (keyed by `config_name`) — matches phpBB3 `$config` array shape.
4. Restrict REST input validation: `config_name` must be non-empty, max 255 chars, alphanumeric + underscore (matches DB column constraints). Validate in controller before delegating.
5. Service test should mock only `ConfigRepositoryInterface` and `CachePoolFactoryInterface` — no DB dependency.
6. E2E test needs an admin elevated token (pattern already established in `api.spec.ts`).

---

## Risk Assessment

| Risk | Level | Notes |
|------|-------|-------|
| Interface extension breaks existing code | Low | Only `SearchService` depends on `ConfigRepositoryInterface`, only calls `get()`. Additive change. |
| Cache invalidation on write | Medium | Must invalidate correctly or stale values persist. Use tag-based invalidation. |
| `set()` atomicity (upsert) | Low | MariaDB `INSERT ... ON DUPLICATE KEY UPDATE` is atomic and well-supported in DBAL. |
| REST key validation | Low | Input must be validated to prevent arbitrary key injection; straightforward regex guard. |
| Hardcoded replacement (if included) | Medium | Would require seeding `phpbb_config` DB rows and touching storage module classes — separate concern. |

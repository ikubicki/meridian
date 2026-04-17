# Gap Analysis: phpBB REST API

## Summary
- **Risk Level**: Medium
- **Estimated Effort**: Medium
- **Change Type**: Additive (minimal modifications to existing files)
- **Detected Characteristics**: modifies_existing_code, creates_new_entities, involves_data_operations

## Task Characteristics
- Has reproducible defect: **no**
- Modifies existing code: **yes** (6 existing files need changes)
- Creates new entities: **yes** (18 new files)
- Involves data operations: **yes** (`phpbb_api_tokens` table required by auth_subscriber)
- UI heavy: **no**

---

## Gaps Identified

### Missing: Namespace Registration (BLOCKER)
- `phpbb\api\` namespace **not in `composer.json`** PSR-4 autoload — PHP cannot load any `phpbb\api\*` class until added.
- `phpbb\core\`, `phpbb\admin\`, `phpbb\install\` are already registered ✓

### Missing: PHP Classes (10 new files)

| Class | Path | Status |
|-------|------|--------|
| `phpbb\core\Application` | `src/phpbb/core/Application.php` | ❌ MISSING |
| `phpbb\api\event\json_exception_subscriber` | `src/phpbb/api/event/json_exception_subscriber.php` | ❌ MISSING |
| `phpbb\api\event\auth_subscriber` | `src/phpbb/api/event/auth_subscriber.php` | ❌ MISSING |
| `phpbb\api\v1\controller\health` | `src/phpbb/api/v1/controller/health.php` | ❌ MISSING |
| `phpbb\api\v1\controller\forums` | `src/phpbb/api/v1/controller/forums.php` | ❌ MISSING |
| `phpbb\api\v1\controller\topics` | `src/phpbb/api/v1/controller/topics.php` | ❌ MISSING |
| `phpbb\admin\api\v1\controller\health` | `src/phpbb/admin/api/v1/controller/health.php` | ❌ MISSING |
| `phpbb\admin\api\v1\controller\users` | `src/phpbb/admin/api/v1/controller/users.php` | ❌ MISSING |
| `phpbb\install\api\v1\controller\health` | `src/phpbb/install/api/v1/controller/health.php` | ❌ MISSING |
| `phpbb\install\api\v1\controller\status` | `src/phpbb/install/api/v1/controller/status.php` | ❌ MISSING |

### Missing: Entry Points (3 new files)

| File | Status | Notes |
|------|--------|-------|
| `web/api.php` | ❌ MISSING | Forum REST API entry point |
| `web/adm/api.php` | ❌ MISSING | Admin REST API entry point |
| `web/install/api.php` | ❌ MISSING | Installer REST API entry point; `web/install/` directory also does not exist |

### Missing: DI Service YAML files (2 new files)

| File | Status |
|------|--------|
| `src/phpbb/common/config/default/container/services_api.yml` | ❌ MISSING |
| `src/phpbb/common/config/installer/container/services_install_api.yml` | ❌ MISSING |

### Missing: Routing YAML files (3 new files)

| File | Status |
|------|--------|
| `src/phpbb/common/config/default/routing/api.yml` | ❌ MISSING |
| `src/phpbb/common/config/default/routing/admin_api.yml` | ❌ MISSING |
| `src/phpbb/common/config/installer/routing/install_api.yml` | ❌ MISSING |

### Missing: DB Schema
- `phpbb_api_tokens` table does not exist; required by `auth_subscriber` for token lookup.

---

## Existing Files Requiring Modification (6 files)

| File | Change Needed | Risk |
|------|---------------|------|
| `composer.json` | Add `"phpbb\\api\\": "src/phpbb/api/"` to PSR-4 autoload | Low |
| `src/phpbb/common/config/default/container/services.yml` | Add `{ resource: services_api.yml }` import | Low |
| `src/phpbb/common/config/default/routing/routing.yml` | Add resources for `api.yml` (prefix `/api`) and `admin_api.yml` (prefix `/adm/api`) | Low |
| `src/phpbb/common/config/installer/container/services.yml` | Add `{ resource: services_install_api.yml }` after existing imports | Low |
| `src/phpbb/common/config/installer/routing/environment.yml` | Add `install_api.yml` resource (currently only `installer.yml` loaded) | Low |
| `docker/nginx/default.conf` | Add 3 `location ^~` blocks before the generic PHP regex `location ~ ^(.+\.php)` | Medium |

---

## Architecture Clarifications (from verification)

### Forum + Admin API Share One DI Container
`web/api.php` and `web/adm/api.php` both include `common.php`, which builds the **same** forum DI container. This means:
- `services_api.yml` registers services for **both** forum API and admin API
- Both APIs reuse the existing `http_kernel`, `router`, `router.listener`, `dispatcher` services
- The `phpbb\core\Application` class is registered as DI services `api.application` and `admin_api.application`; they wrap the same `http_kernel`

### Installer API Uses a Separate Container
`web/install/api.php` bootstraps via `startup.php` → `with_environment('installer')`, producing `$phpbb_installer_container`. Installer routing is controlled by `installer_resources_locator`, which reads `src/phpbb/common/config/installer/routing/environment.yml` (currently loads only `installer.yml`). The install API routes go into `install_api.yml`, referenced from `environment.yml`.

### Route Prefix Strategy (Critical Detail)
The forum container's router handles ALL registered routes. API routes added to `routing.yml` must use full URI paths so the shared router can match them correctly:
- Forum routing.yml: `prefix: /api` → routes resolve to `/api/v1/health`
- Forum routing.yml: `prefix: /adm/api` → routes resolve to `/adm/api/v1/health`
- Installer `environment.yml`: `prefix: /api` → routes resolve to `/api/v1/health` (within installer's own router)

This avoids collisions with existing `/cron`, `/feed`, `/user`, `/help` routes.

### Existing `kernel_exception_subscriber` Conflict
The forum container already has `kernel_exception_subscriber` (HTML error pages, priority 0). The new `json_exception_subscriber` must register at **priority 10** on `kernel.exception` and call `$event->stopPropagation()` so the HTML subscriber never fires during API requests.

### Nginx Location Block Ordering (Critical)
Nginx must match `/api/`, `/adm/api/`, `/install/api/` BEFORE the generic PHP regex `location ~ ^(.+\.php)`. Use `location ^~` (prefix match, no regex) to guarantee interception order. All three blocks must hardcode `SCRIPT_FILENAME` to their respectve entry file (not `$document_root$fastcgi_script_name`).

### `phpbb\core\Application` Design
Composition, not inheritance. ~40 lines:
```php
class Application {
    public function __construct(private HttpKernelInterface $kernel) {}
    public function run(Request $request): void {
        $response = $this->kernel->handle($request);
        $response->send();
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
    }
}
```
The DI container wires `api.application` and `admin_api.application` both passing `@http_kernel`. The installer wires `install_api.application` with the installer container's `@http_kernel`.

---

## Data Lifecycle Analysis

### Entity: `api_tokens`

| Operation | Backend | UI Component | User Access | Status |
|-----------|---------|--------------|-------------|--------|
| CREATE | ❌ No endpoint | ❌ No form | ❌ Not accessible | ❌ |
| READ (auth check) | ❌ `phpbb_api_tokens` table missing | N/A | N/A | ❌ (stub only) |
| UPDATE | ❌ No endpoint | ❌ No UI | ❌ Not accessible | ❌ |
| DELETE | ❌ No endpoint | ❌ No UI | ❌ Not accessible | ❌ |

**Completeness**: 0% — Table does not exist; auth is stub-only in Phase 1  
**Assessment**: Intentional Phase 1 limitation. Full token lifecycle is Phase 2 scope.

---

## User Journey Impact Assessment

This task creates new REST API endpoints — no existing user workflows are affected.

| Dimension | Before | After | Assessment |
|-----------|--------|-------|------------|
| Reachability | N/A (doesn't exist) | `curl /api/v1/health` | ✅ New capability |
| Discoverability | 0/10 | 7/10 (standard REST pattern) | +7 |
| Flow Integration | N/A | Parallel to existing forum routes | ✅ Non-disruptive |
| Multi-persona | N/A | Forum API (users), Admin API (admins), Install API (installer) | ✅ Correct separation |

---

## Issues Requiring Decisions

### Critical (Must Decide Before Proceeding)

#### 1. DB Migration for `phpbb_api_tokens` — SCOPE DECISION
**Issue**: `auth_subscriber` is described as "API token auth: Authorization: Bearer <token> checked against `phpbb_api_tokens` table". This table does not exist. After `common.php` boots and connects to DB, any `auth_subscriber` invoking `$db->sql_query()` against a missing table will produce a fatal DB error — ALL API requests fail.

**Options**:
- **A. Stub `auth_subscriber` in Phase 1** — implement the class but have it always allow requests through (no DB lookup); defer DB migration to Phase 2. Mock controllers still work correctly.
- **B. Create migration + table as part of this task** — fully implements token storage, but increases scope and requires running migration before testing.

**Recommendation**: Option A (stub auth_subscriber). Task explicitly says "mock controllers returning hardcoded data" — the auth check is equally mock in Phase 1. Migration is Phase 2.

---

#### 2. Session Handling in Forum + Admin API Entry Points
**Issue**: `web/app.php` calls `$user->session_begin()` + `$auth->acl($user->data)` before dispatching to the kernel. Should `web/api.php` and `web/adm/api.php` do the same?

The token-based `auth_subscriber` runs at `kernel.request` (inside kernel dispatch) and does not depend on `session_begin()`. However, if `acl()` is not called, any downstream code checking `$auth->acl_get()` will fail.

**Options**:
- **A. Keep `session_begin()` + `acl()` for Phase 1** — matches existing pattern; session overhead on every request; correct auth context available.
- **B. Skip `session_begin()` — token-only** — truly stateless; some forum code paths may break if they call `$auth->acl_get()`.

**Recommendation**: Option A for Phase 1. Research synthesis confirms this. Remove in Phase 2 when full stateless token flow is implemented.

---

### Important (Should Decide)

#### 3. Route Prefix Strategy
**Issue**: Forum container's router has all existing routes (`/cron`, `/feed`, `/user`, etc.). API routes need the full URI path to avoid collision.

**Options**:
- **A. Use `prefix: /api` in routing.yml** → routes compile to `/api/v1/health` (no collision risk) ✅ Recommended
- **B. Use bare paths in api.yml** (`/v1/health`) → routes would be `/v1/health` in the router; nginx routes correctly but path is misleading in route dump

**Recommendation**: Option A. Full-path routes in the shared container are unambiguous.

#### 4. `phpbb\core\Application` Scope
**Issue**: Should `web/app.php` also be refactored to use `Application->run()`, or only the three new API entry points?

**Options**:
- **A. API entry points only** — do not touch `web/app.php`; minimal blast radius ✅ Recommended
- **B. Refactor `web/app.php` as well** — consistency, but off-task scope

**Recommendation**: Option A. Task says "minimal modifications to existing files".

#### 5. Route Cache Clearing
**Issue**: phpBB's router caches compiled routes to `cache/production/`. Without clearing it, newly registered routes return 404 even when correctly implemented. The test `curl` commands will appear broken.

**Options**:
- **A. Include cache-clear step in implementation** (delete `cache/production/url_*.php`) — developer doesn't need to remember
- **B. Document as manual step**

**Recommendation**: Option A. Cache clear must be part of implementation task notes.

---

## Risk Assessment

| Risk | Level | Notes |
|------|-------|-------|
| **Namespace missing from composer.json** | 🔴 High (blocker) | Must be first change; `composer dump-autoload` required |
| **Route cache stale** | 🟡 Medium | Routes invisible until cache cleared |
| **Exception subscriber priority** | 🟡 Medium | Wrong priority → HTML responses from API; must be exactly `priority: 10` with `stopPropagation()` |
| **Installer routing injection** | 🟡 Medium | `environment.yml` mechanism is less obvious; must follow `installer_resources_locator` pattern |
| **Nginx location ordering** | 🟡 Medium | `location ^~` must appear before `location ~ ^(.+\.php)` or generic regex wins |
| **`phpbb_api_tokens` DB error** | 🔴 High (if not stubbed) | Fatal crash on every API request; resolved by stubbing auth_subscriber |
| **Regression risk to existing routes** | 🟢 Low | Changes are purely additive; existing routing.yml import doesn't alter existing routes |
| **`web/install/` directory creation** | 🟢 Low | Simple mkdir; no complex dependencies |

---

## Recommendations

1. **First commit**: Add `phpbb\api\` to `composer.json` and run `composer dump-autoload`. Nothing else works without this.
2. **Implement in this order** to enable incremental testing:
   1. `phpbb\core\Application` class
   2. `services_api.yml` + `services.yml` import
   3. `api.yml` + `routing.yml` import
   4. `web/api.php` entry point
   5. Forum API controllers (health, forums, topics)
   6. Nginx location blocks (forum API only first)
   7. Verify `curl /api/v1/health` → `{"status":"ok"}`
   8. Admin API (adm/api.php + admin_api.yml + admin controllers + nginx)
   9. Installer API (install/api.php + install_api.yml + install controllers + nginx)
3. **Clear `cache/production/url_*.php`** every time routes change during development.
4. **Stub `auth_subscriber`** to always pass through (no DB lookup) — mark with `TODO Phase 2` comment.
5. **Register `json_exception_subscriber` at priority 10** and verify it fires before HTML subscriber with a small test throwing an exception.

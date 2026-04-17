# Decision Log — phpBB REST API

Architecture Decision Records in MADR format.
All decisions reflect the converged choices from Phase 4 (Solution Convergence).

---

## ADR-001: Application Design Pattern — Composition over Inheritance

### Status
Accepted

### Context
The three REST API entry points (Forum API, Admin API, Installer API) each need a class that:
1. Accepts a Symfony `HttpKernel` and a DI container
2. Wraps `HttpKernel::handle()` for a Symfony `Request`
3. Exposes a `run()` method callable from the entry point PHP file

The class must be reusable across all three APIs (different DI service IDs, same class) and
must remain stable across Symfony 3.x minor version upgrades. phpBB already has `@http_kernel`
registered as `Symfony\Component\HttpKernel\HttpKernel` in the forum container.

### Decision Drivers
- Research synthesis (HIGH confidence): "Composition preferred over inheritance"
- The `HttpKernel::__construct` signature changed between Symfony minor versions (3.4 added `ArgumentResolver`)
- All three APIs must use the same `phpbb\core\Application` class — a subtype of `HttpKernel` in the DI container creates two services in the same class hierarchy, confusing type-wiring
- phpBB style guide: ~40 lines for this class; composition keeps it at exactly that

### Considered Options
1. **Composition** — `Application` holds `HttpKernel` as a private field, delegates `handle()` and `terminate()`
2. **Inheritance** — `Application` extends `HttpKernel`, calls `parent::__construct()`
3. **Trait** — `ApiRunnerTrait` provides `run()` as a mixin used by three small classes

### Decision Outcome
Chosen option: **1 — Composition**

`phpbb\core\Application` implements `HttpKernelInterface` and `TerminableInterface` by
delegating to a constructor-injected `HttpKernel`. The three DI service IDs (`api.application`,
`admin_api.application`, `install_api.application`) all resolve to the same class with the
same `@http_kernel` + `@service_container` arguments.

### Consequences

#### Good
- Constructor signature is controlled by `phpbb\core\Application`, not Symfony — Symfony upgrades cannot silently break it
- Trivially unit-testable: inject mock `HttpKernel` and mock `Container`
- Identical DI definition across all three APIs: same class, same arguments, different service ID
- No class hierarchy collision with the existing `@http_kernel` service

#### Bad
- One extra delegation layer (`handle()` calls `$this->kernel->handle()`) — negligible PHP overhead
- Must manually track `HttpKernelInterface` signature changes (extremely rare; covered by PHPStan)

---

## ADR-002: Authentication Strategy — Session-Based in Phase 1, Token-Based in Phase 2

### Status
Accepted (Phase 1 decision; Phase 2 migration path documented)

### Context
REST API entry points must authenticate requests before passing control to the kernel.
phpBB's existing entry points call `$user->session_begin()` + `$auth->acl($user->data)` —
this is the only fully-supported auth path. Research confirmed (HIGH confidence) that
`$auth->acl()` cannot function without `session_begin()` having been called first, because it
depends on `$user->data` being populated from the session.

Phase 1 goal is architecture validation (routing, DI wiring, JSON responses). Phase 1 has no real
data; session-based auth keeps dev setup at zero additional complexity.

### Decision Drivers
- HIGH-confidence research finding: `session_begin()` must precede `acl()`
- Phase 1 explicitly scoped to mock data and architecture validation — auth complexity is unwarranted
- Session-based auth is the only zero-new-code option; all other options require new services
- Bearer token auth requires a new DB table (`phpbb_api_tokens`) and user hydration logic — out of scope for Phase 1

### Considered Options
1. **Session-based** — `session_begin()` + `acl()` in the entry point (existing phpBB pattern)
2. **DB API token** — `Authorization: Bearer <token>`, new `phpbb_api_tokens` table, `kernel.request` subscriber
3. **No auth** — skip auth entirely in Phase 1; add in Phase 2
4. **SID in URL** — reuse phpBB's existing session ID as a URL parameter

### Decision Outcome
Chosen option: **1 — Session-based** for Phase 1; **2 — DB API token** targeted for Phase 2

Phase 1: `web/api.php` and `web/adm/api.php` include the standard `session_begin()` + `acl()` calls.
Phase 2: A `phpbb\api\event\token_auth_subscriber` (priority 16 on `kernel.request`) handles token
validation and user hydration, replacing the entry-point session calls.

Option 3 (no auth) was rejected because publicly accessible staging endpoints are a security
risk and because `acl_get()` calls in controllers would throw fatal errors without initialized
user data. Option 4 (SID in URL) is an OWASP-documented anti-pattern (token leaks into logs,
browser history, referrer headers) and was rejected unconditionally.

### Consequences

#### Good
- Zero new code for Phase 1 — every developer can test the API immediately with a browser session
- The entry point pattern is identical to all existing phpBB entry points — no new knowledge required
- Phase 2 migration is additive: add the subscriber, remove two lines from the entry point; controller code is unchanged

#### Bad
- Session file I/O on every API request — overhead is real but acceptable for Phase 1 development traffic
- Stateful: not usable from cron jobs or non-browser REST clients without cookie management
- `web/api.php` must be modified in Phase 2 — creates a small, documented remediation step

---

## ADR-003: Routing Strategy — YAML Files Imported into Existing `routing.yml`

### Status
Accepted

### Context
phpBB's routing infrastructure uses `phpbb\routing\router` which reads YAML resource files
and populates a route cache at `cache/production/url_generator.php`. Three sets of API routes
need to be registered (forum, admin, installer), each with a URL prefix (`/api/v1/`, `/adm/api/v1/`,
`/install/api/v1/`). phpBB ships with PHP 7.2 as the minimum requirement.

### Decision Drivers
- phpBB uses YAML-based routing exclusively — no programmatic registration exists
- PHP 7.2 compatibility rules out PHP 8 Attributes; Doctrine Annotations is not in `composer.json`
- The existing `phpbb\routing\router` automatically populates route cache from YAML — no new caching code
- Developer familiarity: YAML routes are consistent with all existing phpBB routing

### Considered Options
1. **New YAML files** imported via `resource:` in `routing.yml` / `environment.yml`
2. **PHP 8 Attributes / Doctrine Annotations** on controller classes
3. **Programmatic PHP** — `RouteCollection::add()` in a compiler pass

### Decision Outcome
Chosen option: **1 — New YAML files**

Three new files: `api.yml`, `admin_api.yml` (imported into forum container's `routing.yml`),
`install_api.yml` (imported into installer container's `environment.yml`). Route IDs are prefixed
to avoid collision: `phpbb_api_v1_*`, `phpbb_admin_api_v1_*`, `phpbb_install_api_v1_*`.

### Consequences

#### Good
- Zero new PHP routing infrastructure — `phpbb\routing\router` handles discovery and cache automatically
- New developers immediately recognize the pattern (consistent with existing codebase)
- Each API's routes are contained in one file — easy to diff, review, and reason about
- Route cache is populated automatically on first request post-deploy

#### Bad
- **Cache must be cleared** after adding or modifying routes: `rm -rf cache/production/` — must be documented and automated in the deploy process
- YAML syntax errors cause silent "Route not found" failures (no 500 error) — mitigated by YAML linting in CI
- The installer's `resources_locator` integration is confirmed by pattern analysis but not yet tested in this codebase — test with the first installer route before declaring it complete (Risk 5 from research-report §11)

---

## ADR-004: Namespace and File Structure — Separate PSR-4 Namespaces Per API

### Status
Accepted

### Context
Three API applications need code homes in the source tree. Current PSR-4 mappings include
`phpbb\core\` (empty), `phpbb\admin\` (empty), `phpbb\\` → `src/phpbb/forums/`, and
`phpbb\install\` → `src/phpbb/install/`. A `phpbb\api\` namespace does not exist yet.

### Decision Drivers
- Research architecture defines distinct namespaces per API: `phpbb\api\`, `phpbb\admin\api\`, `phpbb\install\api\`
- Future microservice extraction: separate namespace roots allow relocation without namespace surgery
- Adding a new API version (v2) is a sub-namespace addition, not a modification of existing code
- Domain clarity: `phpbb\api\v1\controller\forums` is immediately self-documenting

### Considered Options
1. **Separate top-level namespaces** — `phpbb\core\`, `phpbb\api\`, `phpbb\admin\` each own their subtree
2. **Under the forum namespace** — `phpbb\forums\api\`, `phpbb\forums\admin\api\` (no `composer.json` change)
3. **phpBB extensions** — each API as a separate extension in `ext/`

### Decision Outcome
Chosen option: **1 — Separate top-level namespaces**

| Namespace | Directory | composer.json key |
|-----------|-----------|-------------------|
| `phpbb\core\` | `src/phpbb/core/` | already mapped |
| `phpbb\api\` | `src/phpbb/api/` | **ADD**: `"phpbb\\api\\": "src/phpbb/api/"` |
| `phpbb\admin\api\` | `src/phpbb/admin/` | already mapped as `phpbb\admin\` |
| `phpbb\install\api\` | `src/phpbb/install/api/` | covered by existing `phpbb\install\` mapping |

Only one new `composer.json` autoload entry is required (`phpbb\api\`).

### Consequences

#### Good
- Each API domain is independently relocatable — enables microservice extraction later
- Namespace communicates API layer membership immediately
- `src/phpbb/admin/api/v1/controller/` is unambiguous: admin API controllers, versioned
- Adding API v2 = new subdirectory, zero changes to v1 code

#### Bad
- `src/phpbb/admin/` directory name may cause confusion: does it hold admin panel code (none yet) or only admin API code? — resolved by the `api/` subdirectory always being present
- One new `composer.json` entry — a one-time, trivially reversible change

---

## ADR-005: API Token Schema — `phpbb_api_tokens` Table Design

### Status
Accepted (Phase 2 implementation; schema finalized now to avoid future migration conflicts)

### Context
Phase 2 replaces session-based auth with `Authorization: Bearer <token>`. A DB table is needed
to store tokens. The schema must support: per-user tokens, multiple tokens per user (labeled),
token revocation, last-used tracking, and SHA-256 storage (raw token never stored).

phpBB's DB abstraction (`$db`) does not support FK constraints by convention — referential
integrity is enforced at the application level.

### Decision Drivers
- Token must never be stored in plaintext — SHA-256 hex (64 chars) is the correct storage format
- Multiple tokens per user (labeled) enables revocable API clients (e.g., "CI pipeline", "mobile app")
- `last_used` enables detection of stale/unused tokens and rate-limit analysis
- `is_active` soft-delete enables revocation without data loss (audit trail remains)
- `CHAR(64)` with `UNIQUE` index enables O(log n) lookup by token hash

### Considered Options
1. **New `phpbb_api_tokens` table** — dedicated table with all auth metadata
2. **Column on `phpbb_users`** — `user_api_token CHAR(64)` — one token per user, no label, no revocation audit
3. **Session table reuse** — piggyback on `phpbb_sessions`, store API tokens as special session types

### Decision Outcome
Chosen option: **1 — New `phpbb_api_tokens` table**

```sql
CREATE TABLE phpbb_api_tokens (
    token_id   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id    INT(10) UNSIGNED  NOT NULL,
    token      CHAR(64)          NOT NULL COMMENT 'SHA-256 hex of the raw token',
    label      VARCHAR(255)      NOT NULL DEFAULT '',
    created    DATETIME          NOT NULL,
    last_used  DATETIME          DEFAULT NULL,
    is_active  TINYINT(1)        NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    UNIQUE KEY uidx_token (token),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Token lifecycle**: Application generates 32 random bytes → encodes as 64-char hex → stores `hash('sha256', $raw)` in DB → returns raw token to client once. Verification: `hash('sha256', $incoming_token)` → SELECT.

### Consequences

#### Good
- Multiple tokens per user with labels — enables granular revocation (revoke CI token without affecting mobile app token)
- `last_used` updated on each request — enables token expiry policy enforcement later
- `is_active` = 0 revokes the token without deleting the audit row
- `UNIQUE` index on `token` ensures no collision is possible and enables constant-time lookup
- Raw token never persists — even DB compromise does not expose usable tokens

#### Bad
- Requires a DB migration file before Phase 2 begins — must be tested against the existing `phpbb_dump.sql` schema
- `last_used` write on every authenticated request = one extra DB write per API call — accepted overhead vs. the security value of tracking
- `BIGINT` for `token_id` is oversized for typical phpBB installs, but is correct for long-lived schemas

---

## ADR-006: Mock Data Strategy — Hardcoded Arrays in Phase 1 Controllers

### Status
Accepted (Phase 1 only; replaced by real queries in Phase 2)

### Context
Phase 1 goal is to validate the architecture: nginx routing, DI wiring, HttpKernel dispatch, JSON
exception handling. Real DB queries add an orthogonal concern (DB schema, query correctness, data
seeding) that could mask architecture bugs. The user requirement explicitly states
*"póki co zwraca zmockowane dane"* (for now returns mocked data).

### Decision Drivers
- Phase 1 scope is architecture validation, not data layer implementation
- Every mock endpoint must be trivially identifiable and removable (`grep -r "JsonResponse(\["`)
- Zero new dependencies, zero file I/O, zero DB access in Phase 1 controllers
- All developers, including those unfamiliar with phpBB DB patterns, can read and modify mock data

### Considered Options
1. **Hardcoded PHP arrays** — `return new JsonResponse([...])` with static data in each controller method
2. **JSON fixture files** — controllers load `.json` files from `tests/fixtures/api/`
3. **Real DB queries** — implement the full data layer from day 1
4. **Service-layer stubs** — interface + mock implementation, wired via DI parameter

### Decision Outcome
Chosen option: **1 — Hardcoded arrays in controller**

Each Phase 1 controller method returns `new JsonResponse([static_array])`. The static arrays
are chosen to represent realistic response shapes (so API clients can be built against them)
but contain obviously-fake data (IDs start at 1, names are "Mock forum", etc.).

Phase 2 replaces each controller method body (not the class structure) with real DB queries.
The DI service definitions and route registrations remain unchanged.

### Consequences

#### Good
- Minimum new code: one PHP file per resource, no dependencies beyond `JsonResponse`
- Every mock is removable without touching routing, DI, or nginx
- API client developers can build against realistic response shapes immediately
- PHPStan can infer the return type of the array literal — no type annotation needed

#### Bad
- Mock data diverges from real response structure if schema decisions change before Phase 2 — accepted; Phase 1 is explicitly temporary
- Large mock datasets (50+ items) will clutter the controller file — mitigated by keeping Phase 1 mocks small (2–5 items)
- No shared fixture between the controller and PHPUnit tests — each test must declare its expected response independently

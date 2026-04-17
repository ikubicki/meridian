# Solution Exploration — phpBB REST API

**Research question**: How to build `phpbb\core` as a base for three independent Symfony applications
(`phpbb\api`, `phpbb\admin\api`, `phpbb\install\api`) that expose REST APIs?
**Date**: 2026-04-16
**Confidence**: HIGH

---

## Decision Area 1: `phpbb\core\Application` Design

How should the base Application class be structured?

---

### Alternative A: Composition — Wraps `HttpKernel`

`phpbb\core\Application` is a standalone class that holds `HttpKernel` and `ContainerInterface` as
private fields (injected via constructor). It implements `HttpKernelInterface` and `TerminableInterface`
by delegating to the inner `HttpKernel`. The public `run()` method fetches the request from the
container, dispatches it, sends the response, and calls `terminate()`.

**Pros**:
- No coupling to Symfony internals of `HttpKernel` — future Symfony upgrades cannot silently break the class
- Trivially testable: pass a mock `HttpKernel` to the constructor
- `~40 lines` — entirely readable in one screen; matches the "≤40 lines" target from research
- Aligns with research evidence: synthesis §2.1 explicitly records "Composition preferred over inheritance" as HIGH-confidence finding
- Identical DI service definition for forum, admin, and install APIs — same class, different service ID

**Cons**:
- Adds a delegation layer (`handle()` calls `$this->kernel->handle()`); minor overhead (negligible in PHP)
- Must manually keep `HttpKernelInterface` method signatures in sync if Symfony changes them (rare, and covered by interface type-checks)

**Evidence**: external-symfony-kernel §17 (composition explicitly recommended); research-report §4.1 (complete class definition provided)

---

### Alternative B: Inheritance — Extends `HttpKernel`

`phpbb\core\Application` extends `Symfony\Component\HttpKernel\HttpKernel`, inheriting `handle()` and
`terminate()`. The constructor calls `parent::__construct()` with the dispatcher and resolver arguments, then
stores the container separately. `run()` is the only new method.

**Pros**:
- No delegation boilerplate — `handle()` not needed; already inherited
- PHPStan/IDE type inference recognizes the class as a `HttpKernel` directly (no double-casting)
- Slightly fewer lines than composition

**Cons**:
- `HttpKernel::__construct` signature changes between Symfony minor versions (e.g., Symfony 3.4 added `argumentResolver`); every upgrade risks a constructor mismatch
- `HttpKernel` methods are not `final` but also not designed as extension points — overriding behavior accidentally is easy
- The forum container already registers `@http_kernel` as an `HttpKernel`; registering `phpbb\core\Application` as a subtype creates two services in the same class hierarchy, which confuses DI type-wiring
- Breaks the "reuse `@http_kernel` as-is" strategy from research — would require a separate `http_kernel` instance per API application

**Evidence**: synthesis §2.1 rules this out with HIGH confidence; research-report §4.1 explicitly rejects it

---

### Alternative C: Trait — Provides `run()` as a Mixin

A `phpbb\core\ApiRunnerTrait` provides the `run()` method. Each API application class (`phpbb\api\Application`,
`phpbb\admin\api\Application`) is a small class that uses the trait and holds its own `$kernel`/`$container`
references.

**Pros**:
- Maximum flexibility: each API application can diverge independently at the cost of duplicating boilerplate
- No shared base class — avoids diamond-inheritance issues if phpBB ever wants apps to extend other base classes

**Cons**:
- Traits in PHP cannot enforce constructor injection — each consuming class must manually wire `$kernel` and `$container`, violating DRY
- Creates three separate PHP files instead of one, tripling the maintenance surface for identical behavior
- PSR-2/phpBB style guides discourage traits for structural code; traits are for cross-cutting concerns (logging, events), not primary class behavior
- Harder to test: no single test target for the shared `run()` logic
- Gains nothing over composition — the trait effectively IS composition without the clarity

**Evidence**: No research finding supports this pattern; it contradicts the "~40 lines single class" architecture from research-report §4.1

---

**Recommendation**: **Alternative A (Composition)**. Research evidence directly supports it (HIGH confidence), it is the simplest implementation with the fewest coupling risks, and the DI wiring is identical for all three APIs.

---

## Decision Area 2: Authentication Strategy (Phase 1)

How should the REST API authenticate requests during Phase 1?

---

### Alternative A: Session-Based — Reuse `$user->session_begin()`

The entry point (`web/api.php`) calls `$user->session_begin()` and `$auth->acl($user->data)` before
passing control to the kernel, exactly as `web/app.php` does. Controllers access `$auth` via the container
to check permissions. Session cookie is used as the credential.

**Pros**:
- Zero new code: the pattern already exists in every phpBB entry point
- `$auth->acl()` works immediately after `session_begin()` — permission checks in controllers require no extra setup
- Consistent with the insight that "session_begin() must precede acl()" (synthesis §2.1, HIGH confidence)
- Phase 2 migration is additive: replace the two lines in `web/api.php` with a JWT listener, leaving controller code unchanged
- Works in the browser immediately — no separate token management needed for initial development

**Cons**:
- Stateful: session file I/O on every request — performance cost on high-traffic APIs (synthesis §7, gap documented)
- Requires the client to maintain a session cookie — not idiomatic for REST clients (curl, mobile apps)
- CSRF risk if session cookie is shared across subdomains (mitigated by `check_form_key` on write endpoints)
- Not usable for machine-to-machine calls without a browser session

**Evidence**: entrypoints §3, §5 (session_begin + acl pattern); synthesis Insight 4 ("Phase 1 mocks should use session auth for simplicity")

---

### Alternative B: API Token in DB — `Authorization: Bearer` Header

A new DB column (`phpbb_users.user_api_token`) stores a random 64-char hex token per user. The entry
point (or a `kernel.request` subscriber) reads the `Authorization: Bearer <token>` header, looks up the
user, and hydrates `$user->data` manually. A small token-checking service replaces `session_begin()`.

**Pros**:
- Stateless: no session file I/O on every request
- Standard REST authentication convention — compatible with curl, Postman, mobile clients from day 1
- Clean separation of auth from session — enables calling the API from cron jobs or CLI without browser cookies

**Cons**:
- Requires a DB schema change (`ALTER TABLE`) — cannot implement without modifying phpBB's table structure
- The token lookup still requires a DB round-trip; not faster than a session lookup for Phase 1
- Manual `$user->data` hydration is non-trivial: `session_begin()` does 15+ DB queries and sets globals; replicating this without it risks breaking `$auth->acl()` (synthesis §2.1, HIGH confidence that acl depends on session_begin)
- Phase 1 endpoints return mock data — the complexity cost is not justified yet
- Token rotation, revocation, and expiry are out of scope for Phase 1

**Evidence**: synthesis Insight 4 identifies this as a Phase 2 concern; research-report §11 lists it as out-of-scope for this phase

---

### Alternative C: No Auth — All Phase 1 Endpoints Are Public

Phase 1 endpoints skip authentication entirely. `web/api.php` still includes `common.php` but does not
call `session_begin()` or `$auth->acl()`. Controllers return mock data without checking permissions.
Authentication is deferred to Phase 2.

**Pros**:
- Maximum development speed in Phase 1 — no auth-related bugs block progress on routing, response formatting, or mock data
- Allows front-end/API clients to be built and tested immediately without credential handling
- Eliminates the session file I/O cost entirely during development

**Cons**:
- Every Phase 1 endpoint is publicly accessible — dangerous if deployed to a staging environment with real data
- When auth is added in Phase 2, controllers must be audited and retrofitted — technical debt
- If the forum container's `acl()` is never called, any controller that tries to use `$auth->acl_get()` will throw a PHP notice/fatal (uninitialized user data)
- The entry point still needs to call `session_begin()` for `$phpbb_container` to initialize correctly — skipping it partially does not save code

**Evidence**: No finding recommends this; synthesis §3 (Pattern 3) and research §5 show that session_begin is woven into the bootstrap sequence

---

### Alternative D: phpBB SID Parameter — URL Token Piggyback

phpBB already uses a `sid` (session ID) GET parameter for form submission protection. REST calls include
`?sid=<user_sid>` in the URL. The entry point extracts it, validates it against `phpbb_sessions`, and
hydrates the user. This reuses existing phpBB CSRF infrastructure.

**Pros**:
- Zero new DB schema changes — `phpbb_sessions` already exists
- SID validation is already implemented in phpBB core
- Works immediately for authenticated browser users (their SID is in document cookies/JS)

**Cons**:
- SIDs in URLs leak into server access logs, browser history, and referrer headers — a known security anti-pattern (OWASP A07: Identification and Authentication Failures)
- SIDs expire on logout — not suitable for persistent machine-to-machine API clients
- The `session_begin()` method already handles SID via cookie/POST — this alternative is effectively what session auth already does, with added URL exposure

**Evidence**: OWASP guidelines; entrypoints §3 (session_begin handles SID validation internally)

**⚠ Security note**: SID-in-URL is explicitly flagged as an anti-pattern; this alternative exists for completeness but should not be implemented.

---

**Recommendation**: **Alternative A (Session-Based)** for Phase 1. It requires zero new code, is consistent with every existing phpBB entry point, and the session_begin → acl chain is confirmed to work correctly by high-confidence research. Phase 2 will replace it with token-based auth (Alternative B, properly implemented with a kernel.request subscriber).

---

## Decision Area 3: Routing Strategy

How should REST API routes be organized and registered?

---

### Alternative A: New YAML Files, Imported into Existing `routing.yml`

Each API has a dedicated YAML route file (`api.yml`, `admin_api.yml`, `install_api.yml`) using phpBB's
existing resource-based routing format. These files are imported via `resource:` directives in the
top-level `routing.yml` (forum/admin) or the installer's equivalent. Route IDs are prefixed: `api_v1_`,
`admin_api_v1_`, etc.

**Pros**:
- Zero new infrastructure: phpBB's `phpbb\routing\router` already supports YAML resource imports (confirmed by synthesis §6, Pattern 6)
- Route cache (`cache/production/url_generator.php`) is populated automatically on first request
- Consistent with all existing phpBB routing — new developers immediately understand the pattern
- Each API's routes are in one discoverable place; diff/review is easy
- Works with phpBB's route generation (`generate_board_url`) if needed later

**Cons**:
- Cache must be cleared after adding routes — an operational step that developers may forget (synthesis §7, gap documented)
- YAML syntax errors cause silent routing failures (no HTTP 500, just "Route not found")
- Requires understanding phpBB's resource locator pattern to add routes for the installer API (medium-confidence gap in synthesis §7)

**Evidence**: synthesis §6 (Pattern 6: Router caches to `cache/production/`); di-kernel §4 (routing already in YAML); research-report §8 (routing design section confirms YAML approach)

---

### Alternative B: PHP Annotations — `@Route` on Controllers

Controllers use Doctrine Annotations (or PHP 8 Attributes) to declare routes inline:

```php
/** @Route("/api/v1/forums", name="api_v1_forums_list", methods={"GET"}) */
public function list(): JsonResponse { ... }
```

Annotations are discovered by Symfony's AnnotationClassLoader via a `AnnotationDirectoryLoader`.

**Pros**:
- Routes live next to the code they serve — no context switching between YAML and PHP
- Modern PHP 8 Attributes are first-class language syntax (no Doctrine dependency)
- Easier to see "what routes does this controller have?" in a single file

**Cons**:
- phpBB targets PHP 7.2 — PHP 8 Attributes are not available; Doctrine Annotations is an additional dependency not in `composer.json`
- phpBB does NOT use annotation-based routing anywhere — introduces a completely foreign pattern into the codebase
- Annotations require a dedicated class loader (`AnnotationClassLoader`) not wired in phpBB's DI container
- Conflicts with the phpBB Coding Guidelines which prefer explicit configuration over magic discovery
- Route cache management is more complex: cache invalidation requires re-scanning class files, not just re-reading YAML

**Evidence**: No phpBB source uses annotation routing; composer.json has no `doctrine/annotations`; PHP 7.2 compatibility requirement rules out Attributes

---

### Alternative C: Programmatic Routes — PHP File Loaded by Container Builder

A PHP file (e.g., `api_routes.php`) calls `$router->add(...)` directly on the `RouteCollection` object:

```php
$routes->add('api_v1_forums', new Route('/api/v1/forums', ['_controller' => 'api.v1.forums_controller:list']));
```

This file is loaded via a compiler pass or a `ContainerBuilder` extension.

**Pros**:
- Full PHP expressiveness — loops, conditionals, computed route names
- No YAML parsing overhead at runtime
- Route configuration is type-checkable by IDEs and PHPStan

**Cons**:
- phpBB uses zero programmatic route registration — this is entirely foreign to the codebase
- The compiler pass integration point is non-trivial and undocumented in phpBB's DI setup
- Loses the route cache benefits: phpBB's `phpbb\routing\router` populates its cache from YAML resources, not from programmatic registration
- Maintenance burden: developers must understand two route registration patterns (YAML for existing, PHP for API)

**Evidence**: phpBB routing codebase uses `routing.yml` exclusively; no compiler pass extension point for routing exists

---

**Recommendation**: **Alternative A (YAML files)**. It is the only option consistent with phpBB's existing routing infrastructure, requires no new dependencies, and the route cache is already handled by `phpbb\routing\router`. Document "clear cache after adding routes" as a required step.

---

## Decision Area 4: Namespace and File Structure

Where should API code live in the source tree?

---

### Alternative A: Separate Top-Level Namespaces Under `src/phpbb/`

Each API has its own namespace root, mapped via PSR-4:

| Namespace | Directory | Composer key |
|-----------|-----------|--------------|
| `phpbb\core\` | `src/phpbb/core/` | `"phpbb\\core\\": "src/phpbb/core/"` |
| `phpbb\api\` | `src/phpbb/api/` | `"phpbb\\api\\": "src/phpbb/api/"` |
| `phpbb\admin\api\` | `src/phpbb/admin/` | `"phpbb\\admin\\": "src/phpbb/admin/"` |
| `phpbb\install\api\` | `src/phpbb/install/api/` | reuses existing install mapping |

**Pros**:
- Clean separation: each API is independently relocatable to its own package/microservice later
- Matches the research-report §4.2/4.3/4.4 naming exactly — no translation needed
- Namespace clearly communicates API domain; `phpbb\api\v1\controller\ForumsController` is self-documenting
- Adding a new API version (`v2`) is a new sub-namespace, not a modification to existing code

**Cons**:
- Three new PSR-4 entries in `composer.json` (minor change, but a change)
- `src/phpbb/admin/` directory name is potentially ambiguous: does "admin" mean admin panel controllers or admin API? (resolved by subdirectory `api/`)
- Slightly more directories to navigate in IDE file trees

**Evidence**: research-report §4.2 ("phpbb\\api\\": "src/phpbb/api/"") and §4.3 ("phpbb\\admin\\": "src/phpbb/admin/")

---

### Alternative B: Under Existing Forum Namespace — `phpbb\forums\api\`

API controllers and services live under `phpbb\forums\api\`, `phpbb\forums\admin\api\` within the
existing `src/phpbb/forums/` directory tree. No new PSR-4 entries needed — the `phpbb\\` autoload mapping
already covers all subpaths.

**Pros**:
- Zero `composer.json` changes — existing `"phpbb\\": "src/phpbb/"` catches everything
- All phpBB code remains in one directory tree — simpler for developers unfamiliar with the API split
- Consistent with how phpBB extensions currently extend the `phpbb\` namespace

**Cons**:
- `phpbb\forums\api\` implies the API belongs to the Forums subsystem, but the API is a separate concern (it uses forum data, but is not part of the forum rendering layer)
- Future microservice extraction becomes harder: untangling `phpbb\forums\api\` from `phpbb\forums\` requires namespace migration
- The admin API lives at `phpbb\forums\admin\api\` — misleadingly suggests it's inside the forum admin module, not the REST admin API
- Violates the research design (synthesis §5, Critical Relationships) which treats Forum API and Admin API as sibling applications, not children of the forum module

**Evidence**: research-report §4 defines explicit namespaces for each application; synthesis §5 maps them as peers

---

### Alternative C: phpBB Extensions — Each API as a Separate Extension

Each API is packaged as a phpBB extension (`phpbbextensions/api`, `phpbbextensions/admin-api`, etc.),
living in `ext/phpbb/api/`, loaded by the extension manager. Routes and services are declared in the
extension's manifest and auto-discovered.

**Pros**:
- Fits phpBB's extension system perfectly — enables/disables via admin panel without code changes
- Extensions are self-contained — each API can be versioned independently
- No core changes to `services.yml` or `routing.yml` — extensions inject themselves

**Cons**:
- Extensions require the forum to be fully installed (`checkInstallation()` passes) — the Install API cannot be an extension (it must bootstrap before DB exists)
- Extension loading adds initialization overhead on every request — not appropriate for a high-frequency REST endpoint
- phpBB extensions are designed for optional forum features, not primary application infrastructure; using them for the core API is architecturally inverted
- Extension namespace convention is `vendor\extname\` (e.g., `phpbb\api\`) — works syntactically but blurs the line between phpBB core code and third-party extensions
- CI/CD deployment becomes more complex: extensions must be enabled/disabled via DB, not deploy scripts

**Evidence**: installer §2 (installer container uses `->without_extensions()` — extensions explicitly excluded from the installer context); synthesis §3 (Pattern 3: installer bootstrap is completely separate)

---

**Recommendation**: **Alternative A (Separate top-level namespaces)**. Clean domain separation, directly matches the research architecture, and enables future microservice extraction without namespace surgery. The three `composer.json` additions are a one-time, reversible change.

---

## Decision Area 5: Mock Data Strategy (Phase 1)

How should Phase 1 endpoints return data while real DB queries are not yet implemented?

---

### Alternative A: Hardcoded Arrays in Controller

Each controller method returns `new JsonResponse([...])` with a static PHP array. No fixtures, no files,
no DB access.

```php
public function list(Request $request): JsonResponse
{
    return new JsonResponse([
        ['id' => 1, 'name' => 'General Discussion', 'description' => 'Mock forum'],
        ['id' => 2, 'name' => 'Announcements', 'description' => 'Mock forum'],
    ], 200);
}
```

**Pros**:
- Simplest possible implementation — every developer understands it immediately
- Zero file I/O, zero DB access — fastest possible response time for development
- PHPStan and IDE can type-check the returned structure
- Matches the stated goal *"póki co zwraca zmockowane dane"* (for now returns mocked data) literally
- Easy to spot and replace: grep for `JsonResponse([` to find all mocked endpoints

**Cons**:
- Mock data diverges from real response structure if the schema changes — must manually keep in sync
- Data cannot be shared across test cases without re-declaring the same arrays
- If mock data grows large (e.g., 50 forums), the controller file becomes cluttered

**Evidence**: research-report §9 (Mock Endpoints Phase 1) describes exactly this pattern

---

### Alternative B: JSON Fixture Files — Controllers Load `.json` Files

Controllers read from `tests/fixtures/api/*.json` files:

```php
public function list(): JsonResponse
{
    $data = json_decode(file_get_contents(__DIR__ . '/../tests/fixtures/api/forums.json'), true);
    return new JsonResponse($data);
}
```

**Pros**:
- Fixtures can be shared between the mock controller and PHPUnit test assertions — single source of truth
- JSON files are readable by non-PHP developers (front-end team can view/edit expected responses)
- Easier to maintain large datasets without touching PHP files

**Cons**:
- Adds file I/O on every request during development (minor, but avoidable)
- Controllers become dependent on filesystem layout — breaks if files move
- JSON fixtures need a loader abstraction or repeated `file_get_contents` boilerplate across controllers
- Not how phpBB applications manage data — introduces a pattern foreign to the codebase
- PHPUnit fixtures already have a specific meaning in phpBB (`tests/dbal/fixtures/`) — mixing controller fixtures and test fixtures in the same directory is confusing

**Evidence**: No phpBB pattern uses this approach; research found zero examples of fixture-based controllers in the codebase

---

### Alternative C: Real DB Queries from Day 1 — Skip Mocking Entirely

Controllers implement actual database queries immediately using phpBB's `$db` service, returning real forum
data. No mock layer is introduced.

**Pros**:
- No technical debt from mock removal — the code that ships in Phase 1 is production code
- Developers immediately validate that the DI wiring, routing, and response format work with real data
- Avoids the "mock → real" migration step entirely

**Cons**:
- Requires the DB to be set up and seeded for every development environment — raises the setup bar
- Errors in DB query logic block progress on routing/response-format development
- Phase 1 goal is to validate the architecture (entry points, kernel, routing, DI) — DB queries introduce an orthogonal concern that may mask architecture bugs
- Significantly more code per controller in Phase 1; harder to review the core infrastructure changes
- The stated user goal explicitly says "póki co zwraca zmockowane dane" — real DB queries contradict the scope

**Evidence**: research-report §1 (Phase 1 scope: architecture validation, not data layer implementation); synthesis §3 (goal is to prove the architecture works)

---

### Alternative D: Service-Layer Stubs — Interface + Mock Implementation

Define a service interface (`phpbb\api\v1\service\ForumsServiceInterface`) and provide a mock implementation
(`phpbb\api\v1\service\MockForumsService`) that returns hardcoded data. The DI container parameter selects
the mock vs. real implementation.

**Pros**:
- Forces good architecture: controllers are decoupled from the data source from day 1
- Switching from mock to real is a DI configuration change, not a controller change
- The interface documents the contract before the real implementation exists

**Cons**:
- Introduces four PHP files per endpoint (interface, mock class, controller, routing) instead of one controller
- Premature abstraction for Phase 1 — the service interface design may change once real DB queries are written
- phpBB's coding guidelines favor explicit DI over interface-driven design for internal services
- Over-engineered for the stated goal of "póki co zwraca zmockowane dane"

**Evidence**: No phpBB internal service uses this pattern for new features; premature for Phase 1 scope

---

**Recommendation**: **Alternative A (Hardcoded arrays in controller)**. It is the correct tradeoff for Phase 1: zero complexity, immediate implementation, and every mock is trivially identifiable and removable. The user's own phrase *"póki co zwraca zmockowane dane"* (for now returns mocked data) confirms the intent is temporary data, not a real data architecture decision. Introduce the real service layer in Phase 2 when the data contracts are clear.

---

## Trade-Off Matrix

| Decision Area | Alt A | Alt B | Alt C | Recommended |
|---------------|-------|-------|-------|-------------|
| Application Design | Composition ✅ | Inheritance ❌ | Trait ❌ | **A** |
| Authentication | Session ✅ | DB Token | No Auth ❌ | **A → B in Phase 2** |
| Routing | YAML ✅ | Annotations ❌ | Programmatic ❌ | **A** |
| Namespace/Structure | Separate NSes ✅ | Under forum ❌ | Extensions ❌ | **A** |
| Mock Data | Hardcoded ✅ | JSON fixtures | Real DB | **A** |

### 5-Perspective Summary

| Perspective | Recommended Combination Score |
|-------------|-------------------------------|
| **Technical Feasibility** | HIGH — all alternatives use existing phpBB/Symfony primitives |
| **User Impact** | HIGH — session auth works immediately in the browser; mock data unblocks front-end |
| **Simplicity** | HIGH — composition + YAML + hardcoded mocks = minimum new code |
| **Risk** | LOW — no new dependencies; no framework changes; composition is Symfony-idiomatic |
| **Scalability** | MEDIUM — session auth is stateful (Phase 2 plan addresses it); namespace design enables microservice extraction |

---

## Recommended Architecture (Convergence)

**Approach**: Composition-based `phpbb\core\Application` (Alt A1) + Session auth (Alt A2) + YAML routing (Alt A3) + Separate namespaces (Alt A4) + Hardcoded mocks (Alt A5).

**Primary rationale**: Every recommended alternative uses zero new PHP dependencies, is consistent with existing phpBB patterns, and is directly supported by HIGH-confidence research evidence. The combination minimizes implementation risk while delivering the three independent API entry points.

**Key trade-offs accepted**:
- Session-based auth incurs per-request session I/O — accepted for Phase 1 simplicity; a documented migration to token auth exists for Phase 2
- YAML routing requires a manual cache clear after changes — accepted; lower cost than introducing a foreign routing paradigm
- Hardcoded mock data is temporary technical debt — accepted; designed to be replaced in Phase 2; every mock is one `grep` away

**Key assumptions**:
1. phpBB installation is complete before forum/admin APIs are called (enforced by `checkInstallation()` in `common.php`)
2. PHP 7.2 compatibility is maintained (rules out PHP 8 Attributes for routing)
3. Phase 2 will implement token-based auth — if the decision is made to keep session auth permanently, the performance concern must be revisited
4. The route cache at `cache/production/` is writable by the web process

---

## Why Not the Others

| Alternative | Rejection Rationale |
|-------------|---------------------|
| **Inheritance (App Design)** | Symfony `HttpKernel` internals change across minor versions; subclassing creates fragile coupling. Research synthesis §2.1 explicitly rejects with HIGH confidence. |
| **Trait (App Design)** | No enforcement of constructor injection; duplicates identical behavior across three classes; traits are meant for cross-cutting concerns, not primary class structure. |
| **DB Token auth (Phase 1)** | Requires ALTER TABLE schema change; manual `$user->data` hydration without `session_begin()` risks breaking `$auth->acl()`; complexity not justified for mock endpoints. |
| **No auth (Phase 1)** | All endpoints are publicly accessible without any credential; partial `session_begin()` skip is not safe and leaves `$auth` in an undefined state. |
| **Annotations routing** | phpBB targets PHP 7.2 (no native Attributes); Doctrine Annotations not in `composer.json`; zero precedent in phpBB codebase; conflicts with explicit-configuration guideline. |
| **Programmatic routing** | Entirely foreign to phpBB's routing system; loses cache benefits; no documented integration point in phpBB's ContainerBuilder. |
| **Under forum namespace** | Misleads about domain ownership; blocks clean microservice extraction; Admin API at `phpbb\forums\admin\api\` is semantically wrong. |
| **Extensions** | Installer API cannot be an extension (`without_extensions()` in installer container); extensions add initialization overhead; inverts the architectural hierarchy (core infrastructure as optional feature). |
| **JSON fixtures** | Adds file I/O and filesystem coupling for temporary data; `tests/fixtures/` naming conflicts with phpBB's existing DBAL fixtures convention. |
| **Real DB from day 1** | Raises setup requirements; introduces orthogonal concerns during architecture validation phase; contradicts explicit user intent (*"póki co zwraca zmockowane dane"*). |
| **Service-layer stubs** | Premature abstraction; 4× file count per endpoint; interface design is speculative before real queries are written. |

---

## Deferred Ideas

| Idea | Why Deferred |
|------|-------------|
| **JWT / OAuth2 authentication** | Phase 2 concern; current auth is session-based. Implement after Phase 1 validates architecture. |
| **OpenAPI spec generation** | Out of scope for this research question; requires dedicated tooling (NelmioApiDocBundle or equivalent). |
| **Rate limiting on API endpoints** | Infrastructure concern (nginx or middleware); not relevant while endpoints return mock data. |
| **API versioning strategy (`/v1/` vs. `/v2/` vs. Accept-header)** | Current design uses URL prefix; alternative strategies (Accept: `application/vnd.phpbb.v2+json`) deferred until v2 is planned. |
| **CORS configuration for production** | Current nginx config uses `Allow-Origin: *` (development only). Restrict for production; deferred as deployment config concern. |
| **Extension API discovery** | phpBB extensions registering their own REST routes (e.g., `GET /api/v1/ext/myext/resources`) deferred to Phase 3+. |

---

```yaml
status: "success"
exploration_path: "outputs/solution-exploration.md"

summary:
  hmw_questions_addressed: 5
  alternatives_generated: 15
  recommended_approach: "Composition + Session Auth + YAML routing + Separate namespaces + Hardcoded mocks"
  deferred_ideas_count: 6
  confidence: "high"

perspectives_covered:
  technical_feasibility: true
  user_impact: true
  simplicity: true
  risk: true
  scalability: true

warnings:
  - "Alternative D (SID-in-URL) for authentication flagged as OWASP anti-pattern — documented but marked with security warning"
  - "Session-based auth (Phase 1 recommendation) has known performance cost at scale — Phase 2 migration plan required"
  - "YAML route cache must be manually cleared after adding new routes — document as operational requirement"
```

# Gap Analysis: M9 Search Service

## Summary
- **Risk Level**: Medium
- **Estimated Effort**: Medium
- **Detected Characteristics**: creates_new_entities, involves_data_operations, modifies_existing_code

## Task Characteristics
- Has reproducible defect: no
- Modifies existing code: yes (`src/phpbb/config/services.yaml` — add M9 DI block)
- Creates new entities: yes (entire `phpbb\search` module)
- Involves data operations: yes (SELECT on `phpbb_posts`; driver config read from `phpbb_config`)
- UI heavy: no

---

## Gaps Identified

### Missing Features (everything is net-new)

| Gap | Evidence |
|-----|---------|
| `phpbb\search` module | `ls src/phpbb/` — no `search/` directory exists |
| `SearchController` | No file in `src/phpbb/api/Controller/` matching search |
| Driver abstraction | No interface or pattern for pluggable search drivers exists |
| `phpbb_config` reader | No PHP service reads from `phpbb_config` table in phpBB4 codebase |
| `search_driver` config key | SQL dump has `search_type` (old phpBB3 key), no `search_driver` key |
| Test suite | No `tests/phpbb/search/` directory; no `tests/e2e/search.spec.ts` |

### Critical Architectural Gap: No phpbb_config Reader

The `phpbb_config` table schema is `(config_name VARCHAR PK, config_value VARCHAR, is_dynamic TINYINT)`. The task requires driver selection via `phpbb_config.search_driver`, but **no service in `src/phpbb/` reads from this table**. The `src/phpbb/config/` directory is Symfony config (YAML files), not a phpBB admin config reader.

The SQL dump contains `search_type` = `\phpbb\search\fulltext_native` (phpBB3 legacy key). No `search_driver` key exists with values `fulltext|like|elasticsearch`.

---

## File Inventory — Files to Create

### Module: `src/phpbb/search/`

| File | Class / Responsibility |
|------|----------------------|
| `Contract/SearchDriverInterface.php` | `search(string $query, ?int $forumId, PaginationContext $ctx): PaginatedResult` |
| `Contract/SearchServiceInterface.php` | Public service contract (mirroring driver signature + driver name accessor) |
| `Driver/FullTextDriver.php` | MySQL `MATCH...AGAINST`, SQLite FTS5 — dialect detected via `$connection->getDatabasePlatform()` |
| `Driver/LikeDriver.php` | `LIKE '%query%'` fallback on `post_text`, `post_subject` |
| `Driver/ElasticsearchDriver.php` | Stub — returns empty `PaginatedResult`, logs `LoggerInterface::warning()` |
| `Service/SearchService.php` | Reads driver config, resolves driver, delegates; fallback to `LikeDriver` on unknown driver |
| `DTO/SearchResultDTO.php` | `post_id, topic_id, forum_id, subject, excerpt, postedAt` |

### API Layer: `src/phpbb/api/Controller/`

| File | Responsibility |
|------|--------------|
| `SearchController.php` | `GET /search?q=&forumId=&page=&perPage=` → JWT required, returns `{data: SearchResultDTO[], meta: pagination}` |

### DI: `src/phpbb/config/services.yaml`

Add M9 block with driver services + alias wiring + `SearchService` argument injection.

### Tests: `tests/phpbb/search/`

| File | Type |
|------|------|
| `Service/SearchServiceTest.php` | Unit — mocks `SearchDriverInterface`, tests driver selection + fallback |
| `Driver/FullTextDriverTest.php` | Integration (`IntegrationTestCase` SQLite) — requires FTS5 virtual table setup in `setUpSchema()` |
| `Driver/LikeDriverTest.php` | Integration (`IntegrationTestCase` SQLite) — standard `phpbb_posts` table |
| `Driver/ElasticsearchDriverTest.php` | Unit — asserts empty result + logger::warning called |
| `DTO/SearchResultDTOTest.php` | Unit — mapping from DB row |

### E2E: `tests/e2e/`

| File | Responsibility |
|------|--------------|
| `search.spec.ts` | UC-S1: auth guard (401), UC-S2: basic search returns results, UC-S3: forumId filter, UC-S4: pagination, UC-S5: empty result on no match |

---

## Data Lifecycle Analysis

### Entity: Post (read-only)

| Operation | Backend | UI Component | User Access | Status |
|-----------|---------|--------------|-------------|--------|
| READ (search) | `phpbb_posts` table exists with `post_text`, `post_subject`, `post_visibility` | New `SearchResultDTO` | New `SearchController` | ❌ needs creation |
| CREATE | Exists in `ThreadsService` + `DbalPostRepository` | Exists in `PostsController` | Routed | ✅ exists |
| UPDATE | N/A for search scope | N/A | N/A | N/A |
| DELETE | N/A for search scope | N/A | N/A | N/A |

**Completeness**: Search READ is entirely missing — all 3 layers need creation.
**Orphaned ops**: None (posts are created via threads module; search only reads).

### Entity: Config (phpbb_config)

| Operation | Backend | UI Component | User Access | Status |
|-----------|---------|--------------|-------------|--------|
| READ `search_driver` key | `phpbb_config` table exists in DB | N/A (internal only) | N/A (internal only) | ❌ no PHP reader |

---

## Issues Requiring Decisions

### Critical (Must Decide Before Proceeding)

**1. phpbb_config reader strategy**

No `phpbb_config` reader service exists in phpBB4. Three options:

- **Option A — Inline DB read in SearchService** (scope: minimal): `SearchService` constructor executes `SELECT config_value FROM phpbb_config WHERE config_name = 'search_driver'` directly. Quick, contained, no new module.
- **Option B — Create `phpbb\config\ConfigRepository`** (scope: expanded): New reusable cross-cutting service for all future modules that need admin config. Adds ~3 files but established the pattern phpBB4 will need anyway.
- **Option C — Env var `PHPBB_SEARCH_DRIVER`** (scope: minimal, skips DB): Driver injected as Symfony parameter from env var. Bypasses `phpbb_config` entirely. Config changes require container restart, not DB edit.

**Recommendation**: Option C for MVP — avoids building a config subsystem that isn't scoped to M9. Option B if the team wants to establish the pattern now.

Options: `["Option A — inline DB read", "Option B — create ConfigRepository", "Option C — env var PHPBB_SEARCH_DRIVER"]`
Recommendation: `Option C`
Rationale: Smallest scope, no new module, mirrors how `JWT_SECRET` and DB creds are handled. Can be replaced with proper config reader later.

---

**2. Config key name**

The `phpbb_config` table has `search_type` = `\phpbb\search\fulltext_native` (phpBB3 legacy). The task spec introduces `search_driver` with values `fulltext|like|elasticsearch`.

- **Option A — Reuse `search_type` key**: No migration needed, but requires mapping old classname values to new enum strings.
- **Option B — Add new `search_driver` key via migration**: Clean break, simple string values, requires `INSERT INTO phpbb_config` migration class.
- **Option C — Use env var (from Decision 1C)**: Moot — no DB key needed at all.

Options: `["Option A — reuse search_type", "Option B — new search_driver key + migration", "Option C — env var (tied to Decision 1C)"]`
Default: Option C (tied to Decision 1)
Rationale: If env var chosen in Decision 1, this decision is resolved automatically.

---

### Important (Should Decide)

**3. FullText driver: SQLite FTS5 test strategy**

`IntegrationTestCase` uses SQLite in-memory. MySQL `MATCH...AGAINST` does not work on SQLite. SQLite FTS5 requires a virtual table (`CREATE VIRTUAL TABLE fts_posts USING fts5(...)`), which is a different schema from `phpbb_posts`.

- **Option A — Skip FullText integration test on SQLite**: Mark FTS test with `@requires extension pdo_mysql` and skip in unit env. Test LIKE driver in integration, FTS driver in E2E only.
- **Option B — FTS5 virtual table in SQLite test**: `setUpSchema()` creates both `phpbb_posts` and an FTS5 virtual table. Driver detects SQLite and uses `fts_posts MATCH ?` syntax. More complex but fully tested.
- **Option C — Only unit-test FullTextDriver** (mock connection): Assert SQL contains `MATCH` keyword; rely on E2E for real execution.

Options: `["Option A — skip FTS integration on SQLite", "Option B — FTS5 virtual table in tests", "Option C — unit test only + E2E for real DB"]`
Default: Option C
Rationale: Lowest complexity; E2E tests run against real MariaDB.

---

**4. SearchResultDTO field set**

Need to define exactly which fields are returned per result. Candidates:

| Field | Source | Note |
|-------|--------|------|
| `postId` | `phpbb_posts.post_id` | Required |
| `topicId` | `phpbb_posts.topic_id` | Required |
| `forumId` | `phpbb_posts.forum_id` | Required |
| `subject` | `phpbb_posts.post_subject` | Required |
| `excerpt` | `post_text` truncated to 200 chars | Or full text? |
| `postedAt` | `phpbb_posts.post_time` | Unix timestamp |
| `posterId` | `phpbb_posts.poster_id` | Optional |

Options: `["Minimal: postId, topicId, forumId, subject, excerpt(200), postedAt", "Extended: + posterId, username join"]`
Default: Minimal
Rationale: Keeps query simple (no JOIN), aligns with MVP scope.

---

**5. Visibility filtering**

`phpbb_posts.post_visibility = 1` means approved/visible. Should search respect ACL (`f_read` per forum) or only filter by `post_visibility = 1`?

- **Option A — visibility = 1 only**: Simple, no auth cross-checking. JWT auth still required.
- **Option B — visibility = 1 AND ACL `f_read` check per forum**: Correct but requires `AuthorizationService` in SearchService — adds complexity.

Options: `["Option A — post_visibility = 1 filter only", "Option B — visibility + f_read ACL per result"]`
Default: Option A
Rationale: MVP. Full ACL per post would require N+1 ACL checks or a subquery join on ACL tables.

---

## Architectural Impact

### New module structure
```
src/phpbb/search/
├── Contract/
│   ├── SearchDriverInterface.php
│   └── SearchServiceInterface.php
├── Driver/
│   ├── FullTextDriver.php
│   ├── LikeDriver.php
│   └── ElasticsearchDriver.php
├── Service/
│   └── SearchService.php
└── DTO/
    └── SearchResultDTO.php
```

### Driver instantiation pattern
No existing driver/plugin factory to follow. Two viable patterns in codebase:
- **Flat switch in service constructor** (simplest — see `NotificationService` pattern)
- **Named service + tagged collection** (see `TypeRegistry`/`MethodManager` in notifications — overkill for 3 fixed drivers)

**Recommendation**: Flat match expression in `SearchService`:
```php
$this->driver = match($driverName) {
    'fulltext'      => $fulltextDriver,
    'elasticsearch' => $elasticsearchDriver,
    default         => $likeDriver,     // fallback including 'like'
};
```
All three drivers injected as constructor args — no registry needed.

---

## Integration Points

| Existing File | Change Required |
|---------------|----------------|
| `src/phpbb/config/services.yaml` | Add M9 block: register 3 drivers + `SearchService` + `SearchServiceInterface` alias |
| `src/phpbb/api/Controller/` | New file only — autodiscovered via `resource:` in routes.yaml |
| `tests/e2e/helpers/db.ts` | Add `seedPosts()` / `clearPosts()` helpers for E2E test data |
| `phpbb_config` table | No change if env var approach; INSERT migration if DB config approach |

**No changes to**: `AuthenticationSubscriber` (search requires JWT — no public suffix needed), `routes.yaml` (attribute-based routing auto-discovers `SearchController`), existing modules.

---

## Risk Assessment

| Risk | Level | Detail |
|------|-------|--------|
| **Complexity** | Medium | FullText dialect detection across MySQL/SQLite/PostgreSQL adds branching logic in FullTextDriver |
| **Integration** | Low | Search is read-only on existing `phpbb_posts` — no write risk to existing data |
| **Regression** | Low | Entirely new module; only `services.yaml` touches existing files |
| **Test complexity** | Medium | FullTextDriver integration test requires SQLite FTS5 virtual table OR skip strategy decision |
| **Config reader** | Medium | No phpbb_config reader exists — requires one of 3 strategic choices before implementation begins |

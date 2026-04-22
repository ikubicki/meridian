# Gap Analysis: M4 — Migrate PDO → Doctrine DBAL 4.x

**Date**: 2026-04-22
**Task**: M4 — Migrate raw PDO layer (PdoFactory + 4 repositories) to Doctrine DBAL 4.x (DBAL-only, no ORM). Add SQLite-in-memory integration test harness. Implement platform-switched upsert. Delete PdoFactory. Keep `*RepositoryInterface` contracts unchanged.

---

## Summary

- **Risk Level**: Medium
- **Estimated Effort**: Medium
- **Detected Characteristics**: modifies_existing_code, creates_new_entities, involves_data_operations

---

## Task Characteristics

| Characteristic | Value | Rationale |
|---|---|---|
| has_reproducible_defect | **false** | This is a planned migration, not a bug fix |
| modifies_existing_code | **true** | 5 existing files rewritten; services.yaml, composer.json, phpunit.xml modified |
| creates_new_entities | **true** | 4 new `Dbal*Repository` classes + `DbalConnectionFactory` created from scratch |
| involves_data_operations | **true** | All 4 repositories perform CRUD; integration tests must validate real SQL execution |
| ui_heavy | **false** | No frontend changes; API contracts unchanged; consumers untouched |

---

## Current State Summary

The `src/phpbb/` namespace uses a **raw PDO layer** consisting of:

| File | LOC | Role | Test Coverage |
|---|---|---|---|
| `src/phpbb/db/PdoFactory.php` | 47 | Static factory wiring PDO via env vars | None |
| `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` | 112 | Auth token storage (6 ops, all named params) | 4 unit tests (PDO mock) |
| `src/phpbb/user/Repository/PdoUserRepository.php` | 330 | Full user CRUD (10 ops, IN-list, dynamic SET, pagination) | 4 unit tests (PDO mock) |
| `src/phpbb/user/Repository/PdoBanRepository.php` | 166 | Ban management (8 ops, `fetchColumn`, no-param `query()`) | **NONE** |
| `src/phpbb/user/Repository/PdoGroupRepository.php` | 104 | Group/membership (5 ops, MySQL-only `ON DUPLICATE KEY UPDATE`) | **NONE** |

**Wiring**: `PDO` is instantiated via `PdoFactory::create()` registered as a Symfony factory service in `services.yaml`, using `PHPBB_DB_HOST`, `PHPBB_DB_NAME`, `PHPBB_DB_USER`, `PHPBB_DB_PASSWD` env vars from `docker-compose.yml`.

**Dependencies installed**: `doctrine/dbal` — **NOT present** in `composer.json` or `vendor/`.

**Test strategy**: Existing tests mock `\PDO` and `\PDOStatement` directly. They verify parameter wiring and hydration logic but do not execute real SQL.

---

## Desired State Summary

After migration, the `src/phpbb/` namespace uses **Doctrine DBAL 4.x** exclusively:

| New File | Replaces | Notes |
|---|---|---|
| `src/phpbb/db/DbalConnectionFactory.php` | `PdoFactory.php` → **deleted** | Assembles `DriverManager::getConnection()` from same env vars |
| `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` | `PdoRefreshTokenRepository.php` | Named params → `executeStatement/executeQuery` |
| `src/phpbb/user/Repository/DbalUserRepository.php` | `PdoUserRepository.php` | IN-list → `ArrayParameterType`, LIMIT/OFFSET → `ParameterType::INTEGER`, `lastInsertId()` |
| `src/phpbb/user/Repository/DbalBanRepository.php` | `PdoBanRepository.php` | `fetchColumn` → `fetchOne`, `query()` → `executeQuery` |
| `src/phpbb/user/Repository/DbalGroupRepository.php` | `PdoGroupRepository.php` | `ON DUPLICATE KEY UPDATE` → platform-switched upsert |

**Interface contracts**: All four `*RepositoryInterface` files remain **frozen and unchanged**.

**Consumer impact**: Zero — all 6 consuming services inject via the interface; no source changes needed.

**Test strategy**: Delete PDO-mock unit tests; replace with **SQLite-in-memory integration tests** via a shared `InMemoryConnectionFactory` test helper. Four test files created, covering all repos (including the two currently at 0%).

**Infrastructure changes**:
- `composer.json` — add `doctrine/dbal: ^4.0` to `require`
- `services.yaml` — replace PDO factory block with `Doctrine\DBAL\Connection` factory block
- `phpunit.xml` — add SQLite env vars or integration test suite tag
- `bundles.php` — **only if DoctrineBundle route chosen** (see Decision #1)

---

## Gap: What's Missing

### 1. Dependency Missing
`doctrine/dbal` is not installed. The entire DBAL API surface is unavailable. Must be installed before any other step.

### 2. Connection Factory Missing
No `DbalConnectionFactory` exists. `PdoFactory` is MySQL-only, not adaptable for DBAL or SQLite. Must be created fresh.

### 3. DBAL Repositories Missing (All 4)
No `Dbal*Repository` class exists. All four must be implemented.

### 4. Platform-Switched Upsert Missing
`PdoGroupRepository::addMember()` uses MySQL-specific `ON DUPLICATE KEY UPDATE`. This SQL will fail on SQLite (integration test platform). A portable alternative must be chosen and implemented.

### 5. SQLite Integration Test Harness Missing
No `InMemoryConnectionFactory` test helper exists. No DDL schema snippets for test setup exist. Two repositories (`Ban`, `Group`) have zero test coverage and will have no baseline to migrate from — schemas must be designed from scratch.

### 6. Test Coverage Gaps (Pre-existing)
`PdoBanRepository` and `PdoGroupRepository` have **zero existing tests**. The migration cannot inherit any PDO-mock baseline for these two; integration tests must be written fresh.

---

## Data Lifecycle Analysis

All four repositories are read/write. The key asset is **user and auth data** — the migration must not regress any CRUD path.

### Three-Layer Verification (Migration Scope)

| Repository | Backend (DBAL API) | UI/Consumer | User Access | Status After Migration |
|---|---|---|---|---|
| DbalRefreshTokenRepository | All 6 ops via `executeStatement`/`executeQuery` | AuthService (interface) | JWT flows untouched | ✅ (interface frozen) |
| DbalUserRepository | All 10 ops; IN-list, dynamic update, pagination | UserSearchService, Controllers (interface) | API endpoints unchanged | ✅ (interface frozen) |
| DbalBanRepository | All 8 ops; `executeQuery`, `fetchOne` | Controllers (interface) | API endpoints unchanged | ✅ (interface frozen) |
| DbalGroupRepository | All 5 ops; platform upsert | Controllers (interface) | API endpoints unchanged | ✅ (interface frozen) |

**Completeness**: 100% — no CRUD orphans introduced by migration. The interface freeze ensures no operations are exposed or removed.

**Orphaned Operations**: None. Interfaces remain identical; all operations present before migration remain after.

---

## Integration Points

| Point | Current | After |
|---|---|---|
| `composer.json` | No DBAL | `doctrine/dbal: ^4.0` added |
| `services.yaml` | `PDO` factory service | `Doctrine\DBAL\Connection` factory service |
| `bundles.php` | No Doctrine entry | Added **only if DoctrineBundle** chosen |
| `config/packages/doctrine.yaml` | Does not exist | Created **only if DoctrineBundle** chosen |
| `phpunit.xml` | No SQLite env | SQLite env vars or `dbal-integration` suite added |
| 4× `*RepositoryInterface` alias | `Pdo*Repository` | `Dbal*Repository` |
| All 6 consumer services | Inject via interface | **No change** |

---

## Migration Steps (High Level)

```
Step 1 — Dependency & Connection
  1.1  composer require doctrine/dbal:^4.0
  1.2  Create DbalConnectionFactory; register Doctrine\DBAL\Connection in services.yaml
  1.3  Smoke-test: container boots, Connection resolves — no repository changes yet
  1.4  Add SQLite env vars to phpunit.xml; create InMemoryConnectionFactory test helper

Step 2 — Simplest Repository: RefreshToken (low complexity, has existing tests)
  2.1  Create DbalRefreshTokenRepository (named params only)
  2.2  Swap services.yaml alias; delete PdoRefreshTokenRepository
  2.3  Write DbalRefreshTokenRepositoryTest (SQLite integration)
  2.4  Green gate: phpunit + E2E

Step 3 — Ban Repository (no existing tests; straightforward DBAL migration)
  3.1  Create DbalBanRepository (fetchOne, executeQuery)
  3.2  Swap alias; delete PdoBanRepository
  3.3  Write DbalBanRepositoryTest (SQLite integration — first coverage for this repo)
  3.4  Green gate

Step 4 — Group Repository (no existing tests; platform-switched upsert required)
  4.1  Create DbalGroupRepository with platform-portable upsert for addMember()
  4.2  Swap alias; delete PdoGroupRepository
  4.3  Write DbalGroupRepositoryTest including upsert idempotency assertion
  4.4  Green gate

Step 5 — Most Complex: User Repository (IN-list, dynamic SET, LIMIT/OFFSET)
  5.1  Create DbalUserRepository (ArrayParameterType, ParameterType::INTEGER, lastInsertId)
  5.2  Swap alias; delete PdoUserRepository
  5.3  Write DbalUserRepositoryTest (full DDL schema, all 10 ops covered)
  5.4  Green gate

Step 6 — Cleanup
  6.1  Delete PdoFactory.php; remove PDO service block from services.yaml
  6.2  Delete old PDO-mock test files (PdoRefreshTokenRepositoryTest, PdoUserRepositoryTest)
  6.3  Final full test run (unit + E2E + Playwright suite)
  6.4  Verify: zero references to \PDO::class in src/phpbb/
```

---

## Issues Requiring Decisions

### Critical (Must Decide Before Implementation Begins)

#### Decision 1: DoctrineBundle vs Bundle-Less Manual Wiring

**Issue**: Two valid approaches exist for registering `Doctrine\DBAL\Connection` as a Symfony service:

| Option | Description | Pros | Cons |
|---|---|---|---|
| **A — DoctrineBundle** | Add `doctrine/doctrine-bundle` to `composer.json`; register in `bundles.php`; configure `config/packages/doctrine.yaml` | Official Symfony pattern; autowires `Connection`; future-proofs if ORM ever added | Extra package + bundle overhead; YAML config ceremony; DoctrineBundle v2.12 required for Symfony 8 (verify compat) |
| **B — Bundle-less** | Add only `doctrine/dbal`; write `DbalConnectionFactory`; register `Connection` manually in `services.yaml` | Minimal deps; matches "DBAL-only, no ORM" intent exactly; no `doctrine.yaml` ceremony; more explicit control | No autowire magic for `Connection`; must update services.yaml wiring manually as repos grow |

**Recommendation**: **Option B (bundle-less)** — matches the project's stated "DBAL-only, no ORM" constraint without the DoctrineBundle overhead. Codebase analysis also recommends this. Switch to DoctrineBundle only if ORM is planned.

- `id: "connection-strategy"`
- `options: [A — DoctrineBundle, B — Bundle-less DbalConnectionFactory]`
- `recommended: B`

---

#### Decision 2: Upsert Strategy for `GroupRepository::addMember()`

**Issue**: Current `ON DUPLICATE KEY UPDATE` is MySQL-only and will fail on SQLite (integration test platform). Two options:

| Option | Description | Pros | Cons |
|---|---|---|---|
| **A — DBAL Connection::upsert()** | Use `$conn->upsert(table, data, uniqueKeyColumns)` which emits platform-specific SQL | Portable; no branching code; DBAL 4.x supported | Requires verifying exact DBAL 4.x method signature before coding; less control over SQL emitted |
| **B — Platform branch** | `if ($platform instanceof MySQLPlatform)` → raw SQL; `else` → `DELETE + INSERT` pair | Explicit; no DBAL API uncertainty | More code; two-op non-atomic on non-MySQL platforms |

**Recommendation**: **Option A** — verify `Connection::upsert()` availability/signature during `composer require` step. Fall back to Option B only if the API is missing or behaves unexpectedly.

- `id: "upsert-strategy"`
- `options: [A — Connection::upsert(), B — Platform branching]`
- `recommended: A`

---

### Important (Should Decide; Defaults Available)

#### Decision 3: LIMIT/OFFSET Binding in UserRepository

**Issue**: PDO requires `PDO::PARAM_INT` for `LIMIT`/`OFFSET` with `EMULATE_PREPARES=false`. DBAL has two approaches:

| Option | Description |
|---|---|
| **A — ParameterType::INTEGER in types array** | `executeQuery($sql, $params, $types)` with explicit integer type annotation |
| **B — Integer literals in SQL** | Cast `$limit`/`$offset` to `(int)` and embed directly (safe — not user input) |

**Default**: Option B (simpler; values are already `int`-cast in the current code; no injection risk with typed PHP params).

- `id: "pagination-binding"`
- `default: B — integer literals`

---

#### Decision 4: lastInsertId() Behaviour on SQLite

**Issue**: `Connection::lastInsertId()` on SQLite may return `'0'` for some table configurations (tables without an explicit `INTEGER PRIMARY KEY AUTOINCREMENT`). This affects `DbalBanRepository::create()` and `DbalUserRepository::create()`.

**Action needed**: Integration test schemas must define PKs as `INTEGER PRIMARY KEY` (SQLite autoincrement syntax) and assert that `lastInsertId()` returns a non-zero string. If it returns `'0'`, the DDL in the test harness must be fixed.

**Default**: Accept DBAL behaviour; validate in integration tests rather than adapting production code. No production code change anticipated.

- `id: "last-insert-id-sqlite"`
- `default: Validate in integration tests; adjust DDL if needed`

---

#### Decision 5: Schema DDL for Integration Tests

**Issue**: SQLite integration tests require `CREATE TABLE` DDL snippets matching the production MySQL schema. These do not exist yet. They must be defined per repository (column names + types compatible with both MySQL and SQLite).

**Affected tables**:
- `phpbb_refresh_tokens` (RefreshTokenRepository)
- `phpbb_users` (~22 columns needed for UserRepository tests)
- `phpbb_banlist` (BanRepository)
- `phpbb_groups` + `phpbb_user_group` pivot (GroupRepository)

**Default**: Define minimal DDL (only columns exercised by the tested operations) in the `InMemoryConnectionFactory` helper or per-test `setUp()`. Full schema not required.

- `id: "test-ddl-scope"`
- `default: Minimal per-test DDL; not full production schema`

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `ON DUPLICATE KEY UPDATE` breaks SQLite tests | Certain (if unaddressed) | High | Upsert decision must be made before Group repo step |
| Ban/Group repos have zero tests → silent regressions | High (pre-existing gap) | Medium | Mandatory: write integration tests for both before merge; no exception |
| `Connection::upsert()` signature differs in DBAL 4.x | Low | Medium | Verify post `composer require`; fallback to platform branching |
| `lastInsertId()` returns `'0'` on SQLite | Medium | Medium | Assert in integration test; fix DDL if triggered |
| DoctrineBundle compat with Symfony 8.x | Low (v2.12 supports it) | Medium | Only relevant if DoctrineBundle chosen (Decision 1) |
| E2E Playwright suite (21 specs) regresses at runtime | Low | High | Run full E2E suite after each phase green gate |

- **Complexity Risk**: Low-Medium — SQL logic is correct; changes are mechanical API substitution
- **Integration Risk**: Low — interface contracts frozen; consumers are zero-touch
- **Regression Risk**: Medium — driven primarily by zero coverage on Ban and Group repos

---

## Phase Summary

This is a **complete mechanical migration** of 5 source files from raw PDO to Doctrine DBAL 4.x with zero interface-contract changes and zero consumer impact; the primary technical risk is the MySQL-only upsert in `GroupRepository::addMember()`, which requires a platform-portable replacement before SQLite integration tests can pass. One architectural decision (DoctrineBundle vs bundle-less manual wiring) and one technical decision (upsert strategy) must be confirmed before implementation begins; all other choices have clear defaults and can be resolved during coding.

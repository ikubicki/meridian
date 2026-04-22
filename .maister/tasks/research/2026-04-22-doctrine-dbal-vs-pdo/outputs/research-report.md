# Research Report — Adopt Doctrine DBAL over PDO?

**Task:** `2026-04-22-doctrine-dbal-vs-pdo`
**Type:** Technical (architecture / tooling)
**Status:** Phase 1 (Foundation) complete — awaiting user confirmation to continue.

## TL;DR

**Recommendation: adopt Doctrine DBAL 4.x for the `phpbb\` rewrite, now — DBAL-only, no ORM.**

Rationale: the current PDO surface is small (5 files, ~720 LOC), well-structured, and easy to port. Doctrine DBAL is the Symfony-native path (`doctrine/doctrine-bundle` autowires `Connection`), introduces only 4 runtime packages, and pays back immediately through `ArrayParameterType` for IN-list binding, `Connection::update/insert/delete` helpers (replacing our hand-rolled dynamic SET builder), removal of `PdoFactory` boilerplate, and a cleaner path to SQLite-in-memory **integration tests** over today's brittle PDO mocks. The gain compounds as more repositories are added (forums, topics, posts, …). Risk is low because our usage stays inside `Connection` + `QueryBuilder` — the area with the smallest BC surface in DBAL.

Alternative "stay on PDO" is defensible only if the rewrite will not grow beyond auth+user.

## 1. Question

> Should the `phpbb\` rewrite adopt Doctrine DBAL in place of raw PDO?

## 2. Scope

- **In:** 5 files in `src/phpbb/` using PDO (`PdoFactory` + 4 repositories), DI wiring, unit tests, Symfony 8 integration, SQLite test harness.
- **Out:** Doctrine ORM (entities stay as readonly VOs); legacy `src/phpbb3/*` (different DB layer, not Doctrine); schema migration tool choice.

## 3. Key Facts

### Current PDO layer (see [findings](analysis/findings/current-pdo-profile.md))
- 5 files, ~720 LOC, 36 distinct SQL ops, no JOINs / no subqueries / no transactions.
- Only MySQL-specific construct: `ON DUPLICATE KEY UPDATE` in `PdoGroupRepository`.
- Tests are PDO mocks (no SQLite integration).
- DI: single `PDO` factory service, autowired into repositories.

### Doctrine DBAL 4.x (see [fact sheet](outputs/doctrine-dbal-facts.md))
- PHP 8.2+, MIT, 4 runtime deps (`doctrine/deprecations`, `psr/cache`, `psr/log` + DoctrineBundle pulls `doctrine/persistence`).
- Symfony-first via `doctrine/doctrine-bundle`, DBAL-only mode (omit `orm:` key).
- `Connection`, QueryBuilder (with CTEs, list binding, `forUpdate()`), typed fetch helpers, `Connection::insert/update/delete` convenience methods, PSR-6 result cache, primary/replica routing.
- Supports MariaDB/MySQL, PostgreSQL, SQLite, SQL Server, Oracle, Db2.
- Known drawbacks: aggressive deprecation cadence on **Schema/Platform** APIs (we don't use them); BIGINT returns `int` within `PHP_INT_MAX`; upserts are NOT abstracted (write vendor SQL via `executeStatement()`).

## 4. Pros / Cons

### Pros (DBAL)
1. Symfony-native autowiring → delete `PdoFactory` (-52 LOC).
2. `Connection::update/insert/delete` → delete hand-rolled SET-clause builder in `PdoUserRepository::update()` (~20 LOC).
3. `ArrayParameterType` → removes manual `?,?,?` expansion (2 methods today, more later).
4. Fluent QueryBuilder improves readability of dynamic queries (user search).
5. Type system removes manual `(int)`/`(bool)` casts in hydration.
6. Enables SQLite-in-memory **integration tests** — higher fidelity than PDO mocks.
7. Option value: PSR-6 caching, replica routing, CTE/window functions, portability if we ever target PostgreSQL.
8. Compounds well: every future repository benefits from the above for free.

### Cons (DBAL)
1. One-time port of 5 files + rewrite of repository tests.
2. 4 new runtime packages + DoctrineBundle.
3. Schema APIs in DBAL churn (irrelevant unless we use SchemaManager — we don't).
4. Small wrapper overhead vs raw PDO (microseconds per query; negligible for our workload).
5. Upserts still not abstracted — `ON DUPLICATE KEY UPDATE` stays as raw SQL.
6. Team must learn DBAL idioms; 4 repos is a small training corpus but non-zero.

### Stay-on-PDO Pros
- Zero migration cost, zero BC-break exposure, zero new deps.
- Current tests pass. Works against MariaDB prod and (theoretically) SQLite.

### Stay-on-PDO Cons
- Every new repository re-implements plumbing DBAL would provide.
- Tests stay as brittle PDO mocks (or require us to build our own integration helper).
- `PdoFactory` and manual IN-list code must be maintained.
- Hybrid adoption (mix PDO and DBAL) is worse than either pure choice.

## 5. Migration Cost Estimate

| Item | Effort |
|---|---|
| Add `doctrine/dbal` + `doctrine/doctrine-bundle`, configure `doctrine.yaml` | 1 small change |
| Delete `PdoFactory`; update `services.yaml` | 1 small change |
| Port `PdoRefreshTokenRepository` (112 LOC, 6 ops) | small |
| Port `PdoUserRepository` (330 LOC, 10 ops incl. dynamic update + pagination) | medium |
| Port `PdoBanRepository` (166 LOC, 8 ops) | small |
| Port `PdoGroupRepository` (104 LOC, upsert) | small (keep upsert as raw SQL) |
| Rewrite 4 repository test files as SQLite-in-memory integration tests | medium |
| Keep `*RepositoryInterface` unchanged → callers untouched | zero |
| Update `AuthenticationService` / other consumers | none (they depend on interfaces) |

**Net:** one focused milestone, fully additive (new repos land alongside old; swap via DI alias). Zero impact on the 145 PHPUnit + 21 E2E suites at interface level.

## 6. Recommendation

**Adopt Doctrine DBAL 4.x, DBAL-only, via `doctrine/doctrine-bundle`.** Phased:

1. **Foundation:** add deps, configure `doctrine.yaml` (MariaDB prod + SQLite test), wire a new `DoctrineUserRepository` alongside `PdoUserRepository`.
2. **Pilot:** migrate `RefreshTokenRepository` (smallest; high-confidence win). Introduce SQLite-in-memory test base class. Measure test runtime impact.
3. **Roll forward:** migrate Ban, Group, User repositories. Delete `PdoFactory`.
4. **Cleanup:** remove PDO repository classes. Keep `ON DUPLICATE KEY UPDATE` upsert as raw `executeStatement()` call with a comment.
5. **Exit criteria:** 0 references to `PDO::class` in `src/phpbb/`; all repository tests run against SQLite-in-memory; CI green against MariaDB service.

## 7. Explicit Non-Goals

- Not adopting Doctrine ORM.
- Not introducing migrations (`doctrine/migrations`) in this task.
- Not refactoring legacy `src/phpbb3/` DB layer.
- Not abstracting the upsert — it stays MySQL-specific raw SQL.

## 8. Open Questions (before Phase 2)

1. **Roadmap signal:** is `phpbb\` expected to grow beyond auth+user in the next 2–3 milestones? (If no → keep PDO.)
2. **Test direction:** is the team comfortable moving from PDO mocks to SQLite-in-memory integration tests?
3. **Portability ambitions:** MariaDB-forever, or is PostgreSQL ever on the table?

## 9. Sources

- `src/phpbb/db/PdoFactory.php`, `src/phpbb/*/Repository/Pdo*.php`, `src/phpbb/config/services.yaml`, `tests/phpbb/**/Pdo*Test.php` (read this session).
- Doctrine DBAL docs: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/
- Symfony DBAL-only guide: https://symfony.com/doc/current/doctrine/dbal.html
- DBAL 4.x UPGRADE.md: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- Packagist `doctrine/dbal` metadata: https://packagist.org/packages/doctrine/dbal

Full fact sheet with citations: [outputs/doctrine-dbal-facts.md](outputs/doctrine-dbal-facts.md).
Synthesis matrix: [analysis/synthesis.md](analysis/synthesis.md).

---

## Phase Gate

Foundation research is complete. Continue to Phase 2 (evaluate if brainstorming further alternatives adds value) only if the recommendation above is not yet actionable. Given the evidence, the decision is well-formed — a short confirmation from the user should unblock implementation planning directly.

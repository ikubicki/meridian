# Finding: Current PDO Layer Profile

**Source:** Static analysis of `src/phpbb/db/*`, `src/phpbb/*/Repository/Pdo*`, `src/phpbb/config/services.yaml`, tests.

## Inventory (764 LOC, 5 files)

| File | LOC | Responsibility |
|---|---|---|
| `src/phpbb/db/PdoFactory.php` | 52 | Connection factory (MySQL-only DSN); EXCEPTION + ASSOC + `EMULATE_PREPARES=false` |
| `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` | 112 | JWT refresh tokens |
| `src/phpbb/user/Repository/PdoUserRepository.php` | 330 | User CRUD + search + pagination |
| `src/phpbb/user/Repository/PdoBanRepository.php` | 166 | Ban checks (user/IP/email) + CRUD |
| `src/phpbb/user/Repository/PdoGroupRepository.php` | 104 | Groups + pivot membership (uses `ON DUPLICATE KEY UPDATE`) |

## SQL Surface (36 ops)

- 15 SELECT, 5 INSERT, 7 UPDATE, 4 DELETE + some helpers.
- **No JOINs. No subqueries. No transactions.** All queries target single tables.
- **Aggregates:** only `COUNT(*)` (user search).
- **LIMIT/OFFSET:** pagination in user search; `LIMIT 1` in single-row lookups.
- **LIKE:** username search only.
- **Vendor-specific:** `INSERT … ON DUPLICATE KEY UPDATE` in `PdoGroupRepository::addMember` — **MySQL-only** (HIGH severity portability hotspot).
- `lastInsertId()` without sequence hint — works on MySQL/SQLite, PostgreSQL needs `lastInsertId('seq')` (MEDIUM).
- Binding: 95% named (`:param`); 2 methods use positional `?` for `IN (?,?,?)` lists (manual placeholder expansion).
- `bindValue(..., PDO::PARAM_INT)` used for LIMIT/OFFSET (because `EMULATE_PREPARES=false` quotes integers as strings otherwise).

## DI Wiring

- `PDO` registered as factory service in `services.yaml`, `public: false`, constructor-autowired into each repository.
- Connection params from env vars (`PHPBB_DB_HOST`, etc.).
- Repositories aliased to `*RepositoryInterface` contracts.
- No query-builder helper; SQL is inline strings in private methods.

## Tests

- Unit tests only. All use `createMock(\PDO::class)` + `createMock(\PDOStatement::class)`.
- **No integration tests, no SQLite-in-memory**. No fixtures.
- Implication: replacing PDO requires rewriting all repository tests (mocks would break) but no schema/fixture migration.

## Refactor-Friendliness

Favorable:
- Table names as `private const TABLE`.
- Semantic named parameters map 1:1 to DBAL placeholders.
- Hydration isolated in private methods.
- No global SQL constants, no scattered PDO config.

Unfavorable:
- Dynamic `SET` clause builder in `update()` (manageable — maps to DBAL `QueryBuilder::set()` or `Connection::update()` with dynamic `$values` array).
- Positional IN-clauses would become `ArrayParameterType::INTEGER` — simpler, not harder.

## Transactions

None used. Multi-step flows (e.g., create user + add group) are **not transactional today** — parity after migration trivial; opportunity to add transactions where appropriate.

## Bottom Line (facts only)

- Small, clean, MySQL-centric surface (~720 LOC of SQL code + 52 LOC factory).
- One MySQL-only construct (upsert) to decide about.
- One factory to rewrite; 4 repositories to port; all unit tests to revise; zero fixture/schema tests to migrate.

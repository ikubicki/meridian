# Research Plan: Replacing Raw SQL with DBAL QueryBuilder

## Research Overview

**Research Question**: How should we replace all hand-written SQL queries (`executeQuery`/`executeStatement` with raw SQL strings) with Doctrine DBAL QueryBuilder across the `phpbb\` namespace?

**Research Type**: Technical — Codebase analysis + pattern assessment

**Scope**:
- Included: `src/phpbb/` — all `Dbal*Repository` classes, service/job classes using raw SQL, integration test `setUpSchema()` fixtures
- Excluded: `src/phpbb3/`, `vendor/`, `src/phpbb/db/migrations/*.sql` (intentionally raw SQL)
- Constraints: DB-portable (MySQL + SQLite), no ORM/DoctrineBundle, all tests must pass

---

## Methodology

**Primary approach**: Static code analysis of the `src/phpbb/` tree:
1. Enumerate all files containing `executeQuery`/`executeStatement` with a raw SQL string arguments
2. Classify each call by SQL operation type (SELECT, INSERT, UPDATE, DELETE) and by dialect risk
3. Map which patterns QueryBuilder supports natively vs which need Expression API or must stay raw
4. Assess portability risk per pattern (MySQL-only vs ANSI-SQL)

**Fallback**: Where dialect capability is uncertain, cross-reference Doctrine DBAL 3.x documentation and check existing QueryBuilder-based code already present in the codebase (e.g., `DbalUserRepository::update()` and `DbalUserRepository::search()` already use `createQueryBuilder()`).

---

## Preliminary Findings (from initial exploration)

### Files with raw SQL usage

| File | Module | Raw calls | Notes |
|------|--------|-----------|-------|
| `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` | auth | 6 | Standard CRUD, no dialect-specific SQL |
| `src/phpbb/hierarchy/Repository/DbalForumRepository.php` | hierarchy | 12 | Dynamic UPDATE, nested-set tree shifts |
| `src/phpbb/hierarchy/Service/TrackingService.php` | hierarchy | 5 | SELECT-then-INSERT-or-UPDATE upsert pattern |
| `src/phpbb/hierarchy/Service/SubscriptionService.php` | hierarchy | 3 | Simple CRUD |
| `src/phpbb/messaging/Repository/DbalConversationRepository.php` | messaging | 5 | JOIN + conditional WHERE, LIMIT/OFFSET |
| `src/phpbb/messaging/Repository/DbalMessageRepository.php` | messaging | 9 | Pagination, LIKE search, soft-delete |
| `src/phpbb/messaging/Repository/DbalParticipantRepository.php` | messaging | 6 | Standard CRUD |
| `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php` | storage | 6 | **GREATEST()** function (MySQL-only) |
| `src/phpbb/storage/Repository/DbalStoredFileRepository.php` | storage | 6 | **UNHEX/HEX** binary UUID (MySQL-only) |
| `src/phpbb/storage/Quota/QuotaService.php` | storage | 1 | **COALESCE(SUM())** aggregate |
| `src/phpbb/threads/Repository/DbalTopicRepository.php` | threads | 4 | LIMIT/OFFSET pagination |
| `src/phpbb/threads/Repository/DbalPostRepository.php` | threads | 3 | LIMIT/OFFSET pagination |
| `src/phpbb/user/Repository/DbalBanRepository.php` | user | 6 | Standard CRUD |
| `src/phpbb/user/Repository/DbalGroupRepository.php` | user | 5 | Transaction-wrapped multi-statement |
| `src/phpbb/user/Repository/DbalUserRepository.php` | user | 5 (partial)| Already partially uses QueryBuilder; `IN (?)` array params |

**Note**: `DbalUserRepository` already demonstrates the target pattern — `createQueryBuilder()` with `->set()`, `->andWhere()`, `->setParameter()`, `->executeQuery()`, `->executeStatement()`.

### Dialect-specific SQL patterns identified

| Pattern | Location | MySQL only? | QB / DBAL workaround |
|---------|----------|-------------|----------------------|
| `GREATEST(0, x - :n)` | `DbalStorageQuotaRepository::decrementUsage()` | Yes | `ExpressionBuilder` does not support `GREATEST`; needs `$qb->expr()->...` or `new Expression('GREATEST(0, used_bytes - :bytes)')`. Alternative: PHP-side guard (read → subtract → max(0)). |
| `UNHEX(:id)` / `HEX(id)` | `DbalStoredFileRepository` (all methods) | Yes | DBAL `Types::BINARY` / `BINARY` column type can handle it transparently; alternatively DBAL platform-aware `$platform->quoteStringLiteral()` path. This is the highest-risk pattern and may need to stay as raw expression. |
| `COALESCE(SUM(…), 0)` | `QuotaService` | No (ANSI SQL) | QueryBuilder `->select('COALESCE(SUM(filesize), 0)')` works; QB passes raw expressions in `select()` as-is. |
| `LIMIT :n OFFSET :m` with `ParameterType::INTEGER` | Multiple repos | No (ANSI SQL) | `->setMaxResults()` / `->setFirstResult()` — direct QB support. |
| `IN (?)` with `ArrayParameterType` | `DbalUserRepository` | No | QueryBuilder: `->andWhere($qb->expr()->in('user_id', ':ids'))` + `setParameter('ids', $ids, ArrayParameterType::INTEGER)`. |
| SELECT-then-INSERT-or-UPDATE (upsert) | `TrackingService` | No | Must remain two calls or use platform `INSERT ... ON DUPLICATE KEY UPDATE` (MySQL) / `INSERT OR REPLACE` (SQLite). Two-call approach is already portable — keep as-is or wrap in QB. |
| Dynamic SET list in UPDATE | `DbalForumRepository::update()`, `DbalConversationRepository::update()`, `DbalMessageRepository::update()` | No | `QueryBuilder::set($col, ':param')` in a loop — exact QB idiom. |
| Nested-set bulk shifts (`left_id + :delta WHERE left_id >= :threshold`) | `DbalForumRepository` | No | `$qb->update()->set('left_id', 'left_id + :delta')` — QB supports arithmetic expressions in `set()`. |

---

## Research Phases

### Phase 1: Full Inventory (Broad Discovery)
- Confirm complete list of files via `grep` for `executeQuery|executeStatement` in `src/phpbb/`
- Confirm which files already use `createQueryBuilder()` (partial/full)
- Identify test files that use raw SQL in `setUpSchema()` or fixture helpers

**Target output**: Exhaustive table of every raw-SQL method, with SQL operation type and any non-ANSI SQL functions used.

### Phase 2: QueryBuilder Capability Map (Targeted Reading)
- For each SQL pattern category, determine the exact QueryBuilder API:
  - Simple SELECT/INSERT/UPDATE/DELETE → `select()`, `insert()`, `update()`, `delete()`, `values()`, `set()`, `where()`, `andWhere()`, `setParameter()`, `executeQuery()`, `executeStatement()`
  - Pagination → `setMaxResults()`, `setFirstResult()`
  - Dynamic WHERE/SET → `andWhere()` in a loop + `setParameter()`
  - Aggregate expressions in SELECT → raw string in `->select('COUNT(*)')` etc.
  - `IN` with array → `$qb->expr()->in()` + `ArrayParameterType`
  - Arithmetic in SET → `$qb->set('col', 'col + :delta')`
- Identify patterns that QueryBuilder cannot express portably:
  - `GREATEST()` — not in ANSI SQL; needs `Expression` or PHP-side fallback
  - `UNHEX/HEX` — MySQL binary UUID; needs explicit binary handling or stays raw

### Phase 3: Portability Risk Assessment (Deep Dive)
- Cross-check each dialect-specific function against SQLite (used in unit/integration tests)
- Determine if SQLite supports `HEX()`/`UNHEX()` (it does as of 3.38+, but test runner SQLite version must be confirmed)
- Determine if SQLite supports `GREATEST()` (it does NOT natively — requires `MAX(0, …)` or `CASE WHEN … END`)
- Check `composer.json` for SQLite extension used in tests

### Phase 4: Migration Strategy & Order (Verification)
- Determine safe migration order: low-risk repos first, high-risk (UNHEX/GREATEST) last
- Identify repos where tests already mock `Connection` (mock-based tests don't catch QB bugs) vs integration tests (SQLite in-memory)
- Outline the incremental approach: one file per PR, tests green at every step

---

## Gathering Strategy

### Instances: 4

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | repositories | Full SQL audit of all Dbal*Repository classes — enumerate every raw SQL call, SQL operation, parameters, dialect patterns | Read, Grep | repositories |
| 2 | services | Raw SQL in non-repository classes: TrackingService, SubscriptionService, QuotaService; also db/migrations | Read, Grep | services |
| 3 | tests | Test files using `setUpSchema`, `executeStatement` for fixture DDL, mock-based vs integration distinction | Read, Grep | tests |
| 4 | dbal-capabilities | DBAL QueryBuilder API capability assessment: what QB supports, Expression API, binary types, portability (MySQL vs SQLite) | Read (existing QB usage in codebase + vendor DBAL docs/source), Grep | dbal-capabilities |

**Rationale**: Repositories and services are the two main production-code categories and have different patterns (services tend to have upsert/conditional logic). Tests are a separate concern (DDL schema SQL is never migrated to QB). The QB capability category exists to produce a definitive "can/cannot/workaround" reference for every dialect-specific function found in categories 1–2.

---

## Success Criteria

1. **Complete inventory**: Every file and method containing raw SQL in `src/phpbb/` identified with SQL operation type
2. **QueryBuilder capability map**: For every SQL pattern found, a concrete QB equivalent (or explicit "keep raw" decision) documented
3. **Portability risk matrix**: Every dialect-specific function (GREATEST, UNHEX/HEX, COALESCE, arithmetic in SET, etc.) evaluated against both MySQL and SQLite
4. **Migration strategy**: Recommended migration order (low-risk first), with approach per file
5. **Identified exceptions**: Cases where raw SQL must be kept, with rationale

---

## Expected Outputs

- `analysis/findings/repositories-*.md` — Per-repository SQL audit
- `analysis/findings/services-*.md` — Service/job SQL audit
- `analysis/findings/tests-*.md` — Test fixture SQL inventory
- `analysis/findings/dbal-capabilities-*.md` — QB vs raw SQL capability reference
- `outputs/migration-strategy.md` — Final recommended migration order and approach

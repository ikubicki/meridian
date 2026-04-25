# Research Brief: Replacing Hand-Written SQL with DBAL QueryBuilder

## Research Question

How should we replace all hand-written SQL queries (`executeQuery`/`executeStatement` with raw SQL strings) with Doctrine DBAL QueryBuilder across the `phpbb\` namespace?

## Research Type

**Technical** — Codebase analysis + pattern assessment

## Scope

### Included
- All `Dbal*Repository` classes in `src/phpbb/`
- Any service/job class calling `$connection->executeQuery()` or `$connection->executeStatement()` with raw SQL
- Integration test `setUpSchema()` methods (SQLite fixture SQL)

### Excluded
- `src/phpbb3/` — legacy code, separate migration plan
- `vendor/` — third-party
- `src/phpbb/storage/Migration/*.sql` — raw migration files are intentionally raw SQL

### Constraints
- **DB portability**: Must work on both MySQL (production) and SQLite (unit/integration tests)
- **No ORM** — Doctrine DBAL only, no DoctrineBundle, no EntityManager
- **All tests must pass**: `composer test` green throughout
- **Incremental**: Can be done repository by repository, no big-bang rewrite required

## Success Criteria

1. Complete inventory: every file and method containing raw SQL identified
2. QueryBuilder capability map: what QueryBuilder supports vs what needs special handling
3. Portability risk matrix: which raw SQL patterns are DB-dialect-specific (UNHEX/HEX, GREATEST, etc.)
4. Migration strategy: recommended approach and order
5. Identified exceptions: cases where raw SQL must be kept (dialect-specific, performance-critical)

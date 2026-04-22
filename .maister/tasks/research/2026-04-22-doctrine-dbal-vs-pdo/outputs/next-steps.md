# Next Steps — Doctrine DBAL Adoption

**Decision:** Approved. Adopt Doctrine DBAL 4.x (DBAL-only, via `doctrine/doctrine-bundle`) in the `phpbb\` rewrite.

## User-confirmed answers to Phase-Gate questions

1. **Roadmap will grow** → migration pays off via compounding (every future repo benefits).
2. **OK to move tests** from PDO mocks → SQLite-in-memory integration tests.
3. **PostgreSQL in future** → DBAL platform abstraction upgrades from "option value" to **required capability**. This changes one design decision:
   - The `ON DUPLICATE KEY UPDATE` in `PdoGroupRepository::addMember` **must not** stay as raw MySQL SQL. Pick one:
     - (a) Platform-switched raw SQL (MySQL `ON DUPLICATE KEY` / Postgres `ON CONFLICT`) — pragmatic.
     - (b) Application-level upsert: `SELECT … FOR UPDATE` then INSERT or UPDATE in a transaction — portable, slower.
     - Recommendation: (a), gated by `$connection->getDatabasePlatform()`.

## Proposed implementation milestone

Title: **M4 — Adopt Doctrine DBAL**

### Phased plan (suggested for implementation-planner skill)

**Phase A — Foundation**
- Add deps: `composer require doctrine/dbal:^4 doctrine/doctrine-bundle`.
- Create `src/phpbb/config/packages/doctrine.yaml` (DBAL-only, `DATABASE_URL` from env, separate config in `.env.test` → `pdo-sqlite:///:memory:`).
- Register `DoctrineBundle` in `src/phpbb/config/bundles.php`.
- Keep `PdoFactory` + PDO repos untouched (parallel stack).

**Phase B — Test harness**
- Base test class `IntegrationTestCase` booting the Symfony kernel with SQLite in-memory, running minimal DDL for tables under test (or loading `phpbb_dump.sql` schema subset).
- Validate on 1 pilot: port `RefreshTokenRepository` tests to the new base class while still testing the existing PDO implementation — prove the harness before swapping implementation.

**Phase C — Pilot migration**
- Implement `DoctrineRefreshTokenRepository` behind the existing `RefreshTokenRepositoryInterface`.
- Swap DI alias: `RefreshTokenRepositoryInterface → DoctrineRefreshTokenRepository`.
- Delete `PdoRefreshTokenRepository` + its mock-based tests.
- Full test suite + E2E must stay green.

**Phase D — Roll forward**
- Port `PdoBanRepository` → `DoctrineBanRepository`.
- Port `PdoGroupRepository` → `DoctrineGroupRepository` (implement platform-switched upsert — MySQL `ON DUPLICATE KEY` / Postgres `ON CONFLICT`).
- Port `PdoUserRepository` → `DoctrineUserRepository` (largest; use QueryBuilder for dynamic search/update).

**Phase E — Cleanup**
- Delete `src/phpbb/db/PdoFactory.php`.
- Remove `PDO` service from `services.yaml`.
- Remove `PDO::class` type-hints from any remaining code.
- CI: add a MariaDB 11.x integration job (already available via docker-compose) alongside the SQLite unit run, if not yet present.

### Exit criteria
- Zero references to `PDO::class` in `src/phpbb/`.
- All repository tests run against SQLite-in-memory.
- 145+ PHPUnit tests green; 21 Playwright E2E green.
- CI green against MariaDB (prod-equivalent) for auth and user flows.
- `composer.json` stable; no `doctrine/orm` added.

### Out of scope for M4
- Doctrine ORM.
- `doctrine/migrations` (schema lives in legacy `phpbb_dump.sql` for now — separate decision).
- Refactor of `src/phpbb3/*` DB layer.
- Performance optimization (baseline only; hot-path tuning deferred).

## Suggested commands

```sh
# When you're ready to start M4:
/maister-copilot:development Implement M4: adopt Doctrine DBAL 4 in phpbb namespace
# or, to plan only first:
/maister-copilot:implementation-plan Migrate phpbb PDO repositories to Doctrine DBAL 4 (5 files, keep RepositoryInterface, add SQLite-in-memory integration tests, platform-switched upsert)
```

## Research artifacts (final)

- Brief: [planning/research-brief.md](../planning/research-brief.md)
- Current PDO profile: [analysis/findings/current-pdo-profile.md](../analysis/findings/current-pdo-profile.md)
- DBAL 4.x fact sheet: [outputs/doctrine-dbal-facts.md](./doctrine-dbal-facts.md)
- Synthesis / trade-offs: [analysis/synthesis.md](../analysis/synthesis.md)
- Research report: [outputs/research-report.md](./research-report.md)
- State: [orchestrator-state.yml](../orchestrator-state.yml)

# Research Brief: Adopt Doctrine DBAL over PDO?

**Date:** 2026-04-22
**Task:** `2026-04-22-doctrine-dbal-vs-pdo`
**Research Type:** Technical (architectural / tooling decision)

## Research Question

> Should the `phpbb\` rewrite namespace adopt **Doctrine DBAL** as its database abstraction layer in place of the current **raw PDO** implementation?

## Background

The phpBB rewrite (targeting Symfony 8 / PHP 8.2+) currently uses pure PDO through a thin `PdoFactory` plus four repository classes (~720 LOC):

| File | LOC |
|---|---|
| `src/phpbb/db/PdoFactory.php` | ~ |
| `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` | 111 |
| `src/phpbb/user/Repository/PdoUserRepository.php` | 331 |
| `src/phpbb/user/Repository/PdoBanRepository.php` | 152 |
| `src/phpbb/user/Repository/PdoGroupRepository.php` | 127 |

Repositories implement `*RepositoryInterface` contracts; entities are **readonly value objects** (no ActiveRecord). Tests: 145 PHPUnit + 21 Playwright E2E currently passing.

Legacy `src/phpbb3/` uses phpBB's **own DB driver** (service id `dbal.conn`, namespace `phpbb\db\driver`) — this is historically named "DBAL" but is **not** Doctrine DBAL. It is out of scope.

## Motivation for Research

- Question raised during M3 auth milestone review: do we want richer SQL tooling (QueryBuilder, portable quoting, platform abstraction, schema diff) before surface area grows?
- Early moment — 4 repositories only, small blast radius.

## Scope

### In scope
- Doctrine DBAL ^4.x feature set (QueryBuilder, platform abstraction, types, migrations)
- Migration effort for 4 existing repositories + `PdoFactory`
- Symfony 8 integration (`doctrine/dbal-bundle` or service wiring)
- Cross-DB portability (MySQL primary; PostgreSQL/SQLite potential)
- Testability vs. current PDO+SQLite test pattern
- Performance baseline (no benchmark execution required, citations OK)
- Dependency cost (footprint, upgrade cadence, license)

### Out of scope
- Doctrine **ORM** (entities stay as readonly VOs)
- Refactoring legacy `src/phpbb3/` DB layer
- Schema migrations tool choice (separate decision)

## Constraints

- PHP 8.2+, Symfony 8.x
- Readonly value-object entities; no proxies/hydrators required
- Must preserve prepared-statement safety (OWASP SQLi hardening)
- Must not break existing `*RepositoryInterface` consumers
- Existing 145 unit + 21 E2E tests must keep passing post-migration

## Success Criteria

A research report that delivers:

1. Feature comparison matrix (PDO vs Doctrine DBAL)
2. Pros / cons / risks with citations
3. Migration cost estimate (files touched, LOC delta, test impact)
4. Explicit recommendation: **adopt**, **don't adopt**, or **hybrid** (DBAL only in new code)
5. If recommended: rough phased adoption plan + exit criteria

## Anti-Goals

- No premature implementation
- No benchmarks we cannot substantiate
- No over-engineering proposals (avoid pulling in ORM, migrations tool, etc., unless directly justified)

## Assumptions

- Database engine is MariaDB/MySQL in production (per `docker-compose.yml`); SQLite used in tests
- Repository count will grow (forums, posts, topics, attachments still to come) — decision compounds
- Team prefers minimal dependency surface unless payoff is clear

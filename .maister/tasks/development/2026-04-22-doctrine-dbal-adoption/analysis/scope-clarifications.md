# Scope Clarifications — Phase 2

**Date:** 2026-04-22

## Critical Decisions Made

### 1. Connection Wiring Strategy

**Decision:** B — `doctrine/dbal` only, własna `DbalConnectionFactory` (bez DoctrineBundle)

**Rationale:** Minimalne zależności; pełna kontrola; brak `doctrine.yaml` ceremony; projekt jest DBAL-only/no-ORM i nie potrzebuje żadnych feature'ów DoctrineBundle (SchemaManager CLI, Fixtures, Migrations bundle).

**Impact:**
- `composer require doctrine/dbal:^4`
- Własna klasa `phpbb\db\DbalConnectionFactory` rejestrowana w `services.yaml` jako `Doctrine\DBAL\Connection`
- Brak `bundles.php` zmiany dla Doctrine
- Brak `config/packages/doctrine.yaml`

### 2. Upsert Strategy

**Decision:** B — Platform branch (MySQLPlatform → raw `ON DUPLICATE KEY UPDATE` / else DELETE+INSERT w transakcji)

**Rationale:** Pełna kontrola; czyste SQL łatwiejsze do debugowania i testowania; `Connection::upsert()` API zmienia się między minor DBAL versions.

**Impact:**
- `DoctrineGroupRepository::addMember()` sprawdza `$this->connection->getDatabasePlatform() instanceof MySQLPlatform`
- MySQL path: `$this->connection->executeStatement('INSERT INTO ... ON DUPLICATE KEY UPDATE ...')`
- Inne platformy: transakcja obejściowa, DELETE WHERE (groupId, userId) + INSERT
- W testach SQLite: trafi do else-branch (DELETE+INSERT) — musi przejść

## Scope Boundaries

**In scope (M4):**
- `composer require doctrine/dbal:^4`
- `DbalConnectionFactory` (replace PdoFactory)
- 4× `Doctrine*Repository` (replace Pdo*Repository)
- SQLite-in-memory integration test harness (`IntegrationTestCase` base class)
- 4× repository integration tests (2 rewrite + 2 new)
- `services.yaml` DI swap
- Delete `PdoFactory`

**Out of scope (M4):**
- DoctrineBundle
- Doctrine ORM
- `doctrine/migrations`
- Legacy `src/phpbb3/` layer
- Performance benchmarks

## No Scope Expansion

`scope_expanded: false` — zakres zgodny z research report.

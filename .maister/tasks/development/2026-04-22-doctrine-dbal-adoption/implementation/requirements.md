# Requirements — M4: Adopt Doctrine DBAL 4

**Date:** 2026-04-22

## Initial Description

Replace raw PDO (PdoFactory + 4 repositories) with Doctrine DBAL 4.x (DBAL-only, no ORM) in src/phpbb/. Add SQLite-in-memory integration test harness. Implement platform-switched upsert. Delete PdoFactory. Keep *RepositoryInterface contracts unchanged.

## Research Context

Research task: `.maister/tasks/research/2026-04-22-doctrine-dbal-vs-pdo/`
Recommendation: Adopt DBAL now (high confidence). Full research report available.

## Q&A

**Q: Strategia połączenia?**
A: B — `doctrine/dbal` only, własna `DbalConnectionFactory`, bez DoctrineBundle.

**Q: Strategia upsert w GroupRepository::addMember()?**
A: B — Platform branch: MySQL `ON DUPLICATE KEY UPDATE` / else DELETE+INSERT w transakcji.

**Q: Obsługa błędów w Doctrine*Repository?**
A: Łap Doctrine\DBAL\Exception i tłumacz na własne domain exceptions (RepositoryException lub analogiczne).

**Q: Zakres testów integracyjnych?**
A: Pełne CRUD + edge case per metodę (analogicznie do obecnych mock testów).

**Q: DDL dla SQLite test harnessa?**
A: Inline w setUp() każdego testu — minimalne CREATE TABLE dla używanych kolumn.

**Q: Visual assets?**
A: Brak (migracja backendowa, brak UI).

## Functional Requirements

1. **FR-1 Connection factory:** `phpbb\db\DbalConnectionFactory` tworzy `Doctrine\DBAL\Connection` z parametrów PHPBB_DB_* (MySQL prod / SQLite test). Zarejestrowana w services.yaml jako `Doctrine\DBAL\Connection`.

2. **FR-2 Repository migration:** 4 nowe `Doctrine*Repository` klasy implementujące te same interfejsy co PDO counterparts. Pełna parytacja funkcjonalności.

3. **FR-3 ArrayParameterType:** Metody używające `IN (?,?,?)` (findByIds, findDisplayByIds w UserRepo) muszą używać `ArrayParameterType::INTEGER`.

4. **FR-4 Dynamic queries:** search() i update() w UserRepository muszą użyć QueryBuilder lub budować SQL z DBAL helpers (nie surowe string concatenation gdy DBAL daje lepszą abstrakcję).

5. **FR-5 Platform-switched upsert:** addMember() sprawdza `instanceof MySQLPlatform` → surowe `ON DUPLICATE KEY UPDATE` via executeStatement / else `DELETE + INSERT` w transakcji (działa na SQLite).

6. **FR-6 Exception translation:** każdy Doctrine*Repository łapie `Doctrine\DBAL\Exception` i rzuca własny wyjątek (`phpbb\db\Exception\RepositoryException` lub dedykowany per domain — TBD przez spec).

7. **FR-7 Integration tests:** Base class `IntegrationTestCase` booting SQLite-in-memory przez `DriverManager::getConnection()`. Każdy test suite ma inline DDL w setUp(). Pełny CRUD + edge case per metoda.

8. **FR-8 DI swap:** services.yaml: PDO service → Connection service; Pdo*Repository aliases → Doctrine*Repository aliases. Brak zmian w interfejsach ani konsumentach.

9. **FR-9 Cleanup:** Po zielonych testach: delete PdoFactory.php, delete Pdo*Repository.php (każdy z interfejsem wciąż żywym), usuń PDO service z services.yaml.

10. **FR-10 CI green:** 145+ PHPUnit tests green; 21 Playwright E2E tests green.

## Reusability Opportunities

- Pattern wiring factory service z services.yaml → PdoFactory.php jako template do DbalConnectionFactory
- Hydration logic (`private function hydrate(array $row): Entity`) — bez zmian, copy-paste do nowych klas
- `private const TABLE` pattern — zachowany

## Scope Boundaries

**In:** 5 plików src/phpbb/db i src/phpbb/*/Repository/Pdo*, services.yaml, bundles.php, phpunit.xml (jeśli potrzeba env DB do SQLite testu), composer.json.

**Out:** DoctrineBundle, ORM, migrations, legacy phpbb3 layer, UI, performance benchmarks.

## Technical Considerations

- PHPBB_DB_* env vars (nie DATABASE_URL) → DbalConnectionFactory musi budować DSN ręcznie: `mysql://user:pass@host/db?serverVersion=10.11&charset=utf8mb4` (MariaDB 10.11 per docker-compose)
- `serverVersion` wymagany w DBAL 4 dla prawidłowego wykrycia platformy (MariaDB prefix)
- SQLite in tests: `pdo-sqlite:///:memory:` lub `['driver' => 'pdo_sqlite', 'memory' => true]`
- BIGINT: DBAL 4 zwraca int gdy < PHP_INT_MAX → hydration casts `(int)` mogą być nadmiarowe ale nie szkodliwe
- lastInsertId(): MySQL/SQLite zwracają string; cast do int w hydration

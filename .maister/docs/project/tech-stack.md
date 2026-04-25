# Technology Stack

## Overview
This document describes the technology choices and rationale for **phpBB4 "Meridian"** — a ground-up modernisation of phpBB 3.3.x targeting Symfony 8.x, PHP 8.4, and a React SPA frontend.

> Legacy packages from the original phpBB 3.3.15 codebase remain in `src/phpbb3/` (vendor-pinned) but are not used by new `src/phpbb/` modules.

---

## Languages

### PHP (8.2+ minimum — 8.5 runtime)
- **Minimum version**: PHP 8.2 (enforced in Composer and CI)
- **Runtime**: PHP 8.5 (Docker image)
- **Key features used in new code**:
  - `declare(strict_types=1)` everywhere
  - `final readonly class` for Entities and DTOs
  - Named arguments throughout (`new self(id: $row['id'], ...)`)
  - Backed enums (`int` / `string`) for domain status types
  - Constructor promotion with `readonly` modifier
  - `match` expressions, nullsafe operator, first-class callables
  - Intersection types for mock properties (`Interface&MockObject`)

---

## Frameworks

### Backend

#### Symfony 8.x (current)
| Component | Purpose |
|-----------|---------|
| `symfony/http-kernel` | HTTP request/response lifecycle |
| `symfony/dependency-injection` | DI Container (YAML service definitions) |
| `symfony/event-dispatcher` | Domain event dispatch + extensibility |
| `symfony/routing` | YAML-configured route definitions |
| `symfony/console` | CLI tool (`bin/phpbbcli.php`) |
| `symfony/config` | YAML configuration loading |
| `symfony/yaml` | YAML parsing |

#### Doctrine DBAL 4
- **Usage**: All database access in `src/phpbb/` modules
- **No ORM** — QueryBuilder API with named parameters (all M0–M7 repos migrated; no raw `executeQuery`/`executeStatement` with interpolated SQL)
- **Connection**: `Doctrine\DBAL\Connection` injected via DI; `$this->connection->createQueryBuilder()` is the standard entry point
- **Migrations**: DBAL `Schema` for `setUpSchema()` in integration tests
- **Portability**: `setMaxResults()`/`setFirstResult()` for pagination, `$qb->expr()->isNull()` for IS NULL, `ArrayParameterType::INTEGER` for IN clauses — no MySQL-specific functions
- **Rationale**: Type-aware, modern API without Doctrine ORM overhead; replaces both raw PDO and the legacy phpBB multi-driver DBAL

### Frontend
- **React SPA** — full Single Page Application consuming REST API (`/api/v1/`)
- **Vite** — build tooling
- **TypeScript** — type-safe frontend
- No SSR — pure client-side React
- Legacy server-rendered Twig/prosilver templates fully retired (M10 target)
- Working prototype: `mocks/forum-index/` (React + Vite)

### Testing
| Tool | Version | Purpose |
|------|---------|---------|
| `phpunit/phpunit` | ^10.0 | Unit tests (`#[Test]` PHP 8 attributes, AAA pattern) |
| Playwright | latest | E2E tests (TypeScript, full user flows via React SPA + API) |
| `mysql2` | ^3.11.0 | E2E DB seeding — direct MariaDB access from Playwright workers (port 13306) |
| `friendsofphp/php-cs-fixer` | latest | Code style enforcement (`@PSR12` + `@PHP84Migration`) |

**Testing strategy**: Two layers — isolated unit tests (PHPUnit 10) + full-stack E2E (Playwright). Repository tests use `IntegrationTestCase` (SQLite in-memory). E2E DB seeding uses `tests/e2e/helpers/db.ts` (typed async wrappers over `mysql2`) — no `docker exec` / shell commands. MariaDB exposed on `localhost:13306` for Playwright workers.

---

## Database

### MariaDB 10.x (production)
- **Engine**: MariaDB 10.x via Docker (`phpbb_db` container)
- **Access**: Doctrine DBAL 4 (`Connection` injection)
- **E2E access**: Port `13306` exposed on localhost — `mysql2` in Playwright workers seeds/cleans test data directly (no `docker exec`)
- **Schema**: Reuses existing `phpbb_*` tables where possible; new tables for redesigned features
- **Query safety**: Parameterized queries only — no raw user input interpolation

### SQLite (integration tests)
- **Usage**: In-memory SQLite via `IntegrationTestCase` for repository unit/integration tests
- **Driver**: `pdo_sqlite` through Doctrine DBAL
- **No fixtures files** — schema created inline in `setUpSchema()`, test data inserted via `executeStatement()`

---

## Authentication & Security

- **JWT bearer tokens**: `firebase/php-jwt` — access + refresh tokens
- **Password hashing**: Argon2id (via PHP 8 native `password_hash()`/`password_verify()`)
- **ACL**: 5-layer permission resolver in `phpbb\auth` (Auth Unified Service)
- **CSRF**: Not applicable to REST API (stateless JWT bearer auth)
- **OAuth relic**: `carlos-mg89/oauth` remains in vendor for legacy `phpbb3\` layer only

---

## Caching

### PSR-16 TagAwareCacheInterface
- **Interface**: `phpbb\cache\service\TagAwareCacheInterface` (PSR-16 extended)
- **Backend**: Filesystem-first for dev/test; APCu or Redis for production
- **Pool isolation**: Each service uses its own cache pool (`cache.{service}`)
- **Tiered Counter Pattern**: Hot counter (cache) → Cold counter (DB column) → Recalculation cron

---

## Infrastructure

### Containerization (Docker + Docker Compose)
| Container | Image | Purpose |
|-----------|-------|---------|
| `phpbb_app` | PHP 8.4-FPM Alpine | PHP application server |
| `phpbb_nginx` | Nginx Alpine | Reverse proxy (port 8181) |
| `phpbb_db` | MariaDB 10.x | Database (port 13306 exposed for E2E tests) |

### CI/CD
- **Target**: GitHub Actions (lint + tests + `composer audit`)
- **Composer shortcuts**: `composer test`, `composer test:e2e`, `composer cs:fix`

---

## Text Processing (legacy `phpbb3\` layer)

- **BBCode / text formatter**: `s9e/text-formatter: ^2.0` (retained for legacy compatibility)
- **New content pipeline**: `encoding_engine` column; `s9e` XML default for new `phpbb\threads\` content

---

## Key Dependencies Summary (new `src/phpbb/` modules)

| Package | Version | Category |
|---------|---------|---------|
| `symfony/*` | ^8.x | Framework |
| `doctrine/dbal` | ^4.0 | Database access layer |
| `firebase/php-jwt` | latest | JWT token issuance + verification |
| `phpunit/phpunit` | ^10.0 | Unit testing |
| `friendsofphp/php-cs-fixer` | latest | Code style enforcement |
| `s9e/text-formatter` | ^2.0 | Content formatting (shared with legacy) |

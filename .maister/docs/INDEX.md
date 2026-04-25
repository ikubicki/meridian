# Project Documentation Index

`.maister/docs/` contains project-level documentation and coding standards used by AI assistants and developers to understand context and apply consistent conventions.

## Project

| File | Description |
|------|-------------|
| [project/vision.md](project/vision.md) | Project goals, current state, and modernization direction for phpBB Vibed |
| [project/roadmap.md](project/roadmap.md) | Service rewrite roadmap: 10 researched services, implementation phases, cross-cutting items |
| [project/services-architecture.md](project/services-architecture.md) | **NEW** — Services architecture plan: inventory, dependency graph, implementation order, shared patterns |
| [project/tech-stack.md](project/tech-stack.md) | Languages, frameworks, and tooling choices with rationale |
| [project/architecture.md](project/architecture.md) | Hybrid monolith architecture: legacy procedural layer vs. modern `phpbb/` OOP core |

---

## Standards

Coding standards and conventions for the project.

### [Global](standards/global/STANDARDS.md)
PHP 8.2+ (minimum) / PSR-1 conventions; runtime is **PHP 8.4**. `PascalCase` classes and methods (new code), `snake_case` variables, `declare(strict_types=1)`, Allman-style braces, PHPDoc only where native types are insufficient, constants, i18n. **File header**: all `src/phpbb/` files use the `phpBB4 "Meridian" package` / `Irek Kubicki` copyright block; `src/phpbb3/` legacy files retain the original `phpBB Forum Software package` / `phpBB Limited` header — do not alter them. **Code style enforcement via `php-cs-fixer`** (`@PSR12` + `@PHP84Migration` ruleset, `.php-cs-fixer.php` in repo root, mandatory before every commit and in CI). Legacy `includes/` layer keeps `snake_case` for backward compatibility.

### [Backend](standards/backend/STANDARDS.md)
PHP 8.3 patterns: `declare(strict_types=1)`, typed return/property types, `readonly` constructor promotion, `match` expressions, named arguments, nullsafe operator, first-class callables, enums. PSR-4 namespacing under `phpbb\`, `PascalCase` class names (new code), constructor-only DI, single-quote strings. **Schema compatibility & code isolation**: maximum reuse of phpBB3 database schema (tables, columns unchanged), zero references to legacy code (no DBAL, no `global`, no `includes/` imports — only PDO + Symfony DI). **Controller Design** (new section, placed before SQL Safety): controllers are thin routing layers — parse request, call service, return response; no business logic, no SQL, no data transformation belongs in a controller; pagination must use the shared `PaginationContext` DTO via `PaginationContext::fromQuery()`. **Entity & DTO pattern**: use `final readonly class` for Entities and DTOs; use `fromRow()` (DB row → Entity) and `fromEntity()` (Entity → DTO) static factory methods for hydration. **Enum convention**: backed enums live in an `Enum/` subdirectory alongside the entity they belong to. **Mocking**: mock properties typed as `Interface&MockObject` intersection type (PHPUnit 10+ style).

### [Backend / REST API](standards/backend/REST_API.md)
REST API conventions for `src/phpbb/api/`: versioned URL structure (`/api/v1/`), `JsonResponse` returns, HTTP status codes (200/201/401/403/404/409/422), error/validation response shapes, JWT Bearer auth via `AuthSubscriber`, input handling, versioning strategy. **Controller Conventions** (expanded): controllers are thin routing layers only — no business logic, no SQL, no data transformation; delegate everything to services. **Pagination Context**: standardised `PaginationContext` DTO lives in `src/phpbb/api/DTO/`; use `PaginationContext::fromQuery(Request $request)` to extract `page`/`per_page` from query params; all paginated endpoints return a `PaginatedResult` with a `meta` block (`total`, `page`, `per_page`, `total_pages`).

### [Backend / Counter Pattern](standards/backend/COUNTER_PATTERN.md)
Tiered Counter Pattern standard for denormalized counters: hot counter (cache) → cold counter (DB column) → recalculation cron job. Cache key convention `counter.{service}.{entity_id}.{field}`. Flush strategies (request-count, time-based, hybrid). Used by Hierarchy, Threads, Messaging, Notifications.

### [Backend / Domain Events](standards/backend/DOMAIN_EVENTS.md)
Domain event standard: all mutations return `DomainEventCollection`, controllers dispatch. Event naming `{Entity}{Action}Event`, base class `phpbb\common\Event\DomainEvent` with `entityId`, `actorId`, `occurredAt` (auto-defaulted — never pass explicitly, no `timestamp` param). `DomainEventCollection` requires the events array in constructor (no default); has **no** `add()`, `merge()`, `count()`, or `isEmpty()` — always construct with inline array. Anti-pattern "Mutable Accumulator" documented. Dispatch responsibility in controller layer, not service layer.

### [Testing](standards/testing/STANDARDS.md)
PHPUnit 10+ conventions: `#[Test]`, `#[DataProvider]`, `#[Before]`, `#[After]` PHP 8 attributes (no annotations), `PascalCase` test class names, `camelCase` method names, `setUp(): void`, `expectException()` over annotation, parameterized data providers, test isolation, DB integration testing patterns. **Namespace**: `phpbb\Tests\` preferred (PascalCase, mirrors `src/phpbb/`). **E2E**: Playwright (TypeScript) is the E2E tool — Goutte and Selenium are removed. **Integration tests**: repository tests extend `IntegrationTestCase` which provides an SQLite in-memory database. **Mocking**: mock properties typed as `Interface&MockObject` intersection type (PHPUnit 10+ style).

### [Frontend](standards/frontend/STANDARDS.md)
React SPA conventions from `mocks/forum-index`: functional components + hooks, split CSS by feature (`src/styles/*.css`), component-level interactions, accessibility attributes (`aria-*`), Material Symbols usage, and mock data conventions compatible with phpBB field naming.

---

## Usage for AI Assistants

When generating or reviewing code, use this index to locate relevant standards:

- For **naming, PHPDoc, file structure** → read `standards/global/STANDARDS.md`
- For **PHP namespacing, DI, SQL safety, security, PHP 8.3 patterns, schema compatibility** → read `standards/backend/STANDARDS.md`
- For **REST API conventions, HTTP status codes, JWT auth, JSON response shapes** → read `standards/backend/REST_API.md`
- For **denormalized counters, cache-to-DB flush, recalculation** → read `standards/backend/COUNTER_PATTERN.md`
- For **domain events, DomainEventCollection, event naming** → read `standards/backend/DOMAIN_EVENTS.md`
- For **React SPA patterns, component/CSS conventions, accessibility, icons** → read `standards/frontend/STANDARDS.md`
- For **unit/integration tests, mocking, PHPUnit 10+** → read `standards/testing/STANDARDS.md`
- For **project context, goals, architecture** → read the relevant `project/*.md` file

All standards are also referenced in [.github/copilot-instructions.md](../../.github/copilot-instructions.md).

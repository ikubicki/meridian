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
PHP 8.3 / PSR-1 conventions: `PascalCase` classes and methods (new code), `snake_case` variables, `declare(strict_types=1)`, Allman-style braces, PHPDoc only where native types are insufficient, file headers, constants, i18n. **Code style enforcement via `php-cs-fixer`** (`@PSR12` + `@PHP83Migration` ruleset, `.php-cs-fixer.php` in repo root, mandatory before every commit and in CI). Legacy `includes/` layer keeps `snake_case` for backward compatibility.

### [Backend](standards/backend/STANDARDS.md)
PHP 8.3 patterns: `declare(strict_types=1)`, typed return/property types, `readonly` constructor promotion, `match` expressions, named arguments, nullsafe operator, first-class callables, enums. PSR-4 namespacing under `phpbb\`, `PascalCase` class names (new code), constructor-only DI, single-quote strings, DBAL SQL safety (escape/cast/helper methods), cross-DB compatibility (`<>` not `!=`), CSRF, ACL, legacy `includes/` patterns.

### [Backend / REST API](standards/backend/REST_API.md)
REST API conventions for `src/phpbb/api/`: versioned URL structure (`/api/v1/`), `JsonResponse` returns, HTTP status codes (200/201/401/403/404/409/422), error/validation response shapes, JWT Bearer auth via `AuthSubscriber`, controller structure, input handling, versioning strategy.

### [Testing](standards/testing/STANDARDS.md)
PHPUnit 10+ conventions: `#[Test]`, `#[DataProvider]`, `#[Before]`, `#[After]` PHP 8 attributes (no annotations), `PascalCase` test class names, `camelCase` method names, `setUp(): void`, `expectException()` over annotation, parameterized data providers, test isolation, DB integration testing patterns.

### Frontend _(skipped)_
> Frontend standards were not selected during initialization and are not included in this project.

---

## Usage for AI Assistants

When generating or reviewing code, use this index to locate relevant standards:

- For **naming, PHPDoc, file structure** → read `standards/global/STANDARDS.md`
- For **PHP namespacing, DI, SQL safety, security, PHP 8.3 patterns** → read `standards/backend/STANDARDS.md`
- For **REST API conventions, HTTP status codes, JWT auth, JSON response shapes** → read `standards/backend/REST_API.md`
- For **unit/integration tests, mocking, PHPUnit 10+** → read `standards/testing/STANDARDS.md`
- For **project context, goals, architecture** → read the relevant `project/*.md` file

All standards are also referenced in [.github/copilot-instructions.md](../../.github/copilot-instructions.md).

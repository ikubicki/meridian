# Project Documentation Index

`.maister/docs/` contains project-level documentation and coding standards used by AI assistants and developers to understand context and apply consistent conventions.

## Project

| File | Description |
|------|-------------|
| [project/vision.md](project/vision.md) | Project goals, current state, and modernization direction for phpBB Vibed |
| [project/roadmap.md](project/roadmap.md) | Prioritized modernization roadmap: Symfony upgrade, PHP 8.x, CI/CD, static analysis |
| [project/tech-stack.md](project/tech-stack.md) | Languages, frameworks, and tooling choices with rationale |
| [project/architecture.md](project/architecture.md) | Hybrid monolith architecture: legacy procedural layer vs. modern `phpbb/` OOP core |

---

## Standards

Coding standards and conventions for the project.

### [Global](standards/global/STANDARDS.md)
General conventions: naming (camelCase / snake_case / PascalCase), PHPDoc, file headers, constants.

### [Backend](standards/backend/STANDARDS.md)
PHP-specific: PSR-4 namespacing, Dependency Injection, error handling, SQL safety, security practices.

### [Testing](standards/testing/STANDARDS.md)
PHPUnit 7+ conventions, test naming, DB unit testing patterns, mock usage, test isolation.

### Frontend _(skipped)_
> Frontend standards were not selected during initialization and are not included in this project.

---

## Usage for AI Assistants

When generating or reviewing code, use this index to locate relevant standards:

- For **naming, PHPDoc, file structure** → read `standards/global/STANDARDS.md`
- For **PHP namespacing, DI, SQL safety, security** → read `standards/backend/STANDARDS.md`
- For **unit/integration tests, mocking, PHPUnit** → read `standards/testing/STANDARDS.md`
- For **project context, goals, architecture** → read the relevant `project/*.md` file

All standards are also referenced in [.github/copilot-instructions.md](../../.github/copilot-instructions.md).

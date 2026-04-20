# Copilot Instructions

This project uses a structured documentation system for coding standards and project context.

## Standards Reference

When generating or reviewing code for this project, apply the standards documented in `.maister/docs/`:

- **General conventions** (naming, PHPDoc, file headers): [.maister/docs/standards/global/STANDARDS.md](.maister/docs/standards/global/STANDARDS.md)
- **PHP / Backend standards** (namespacing, DI, SQL safety, security): [.maister/docs/standards/backend/STANDARDS.md](.maister/docs/standards/backend/STANDARDS.md)
- **Testing standards** (PHPUnit, test naming, mocking, isolation): [.maister/docs/standards/testing/STANDARDS.md](.maister/docs/standards/testing/STANDARDS.md)

See the full index at [.maister/docs/INDEX.md](.maister/docs/INDEX.md).

## Key Reminders

- This is a **phpBB rewrite** targeting **Symfony 8.x**, **PHP 8.2+**, **React SPA** frontend.
- Follow phpBB naming and formatting conventions (tabs for indentation, no closing PHP tag, camelCase in OOP classes).
- Always use PDO prepared statements — never interpolate raw user input into SQL.
- New code must live under the `phpbb\` namespace (PSR-4).
- Use dependency injection via the Symfony 8.x container; avoid `global` in OOP code.
- Frontend is a React SPA consuming the REST API — no server-rendered views.
- All new code must have PHPUnit unit tests; critical flows need Playwright E2E tests.
- Auth Unified Service (`2026-04-19-auth-unified-service/`) is the authoritative auth design (supersedes `2026-04-18-auth-service/`).

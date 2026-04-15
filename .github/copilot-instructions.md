# Copilot Instructions

This project uses a structured documentation system for coding standards and project context.

## Standards Reference

When generating or reviewing code for this project, apply the standards documented in `.maister/docs/`:

- **General conventions** (naming, PHPDoc, file headers): [.maister/docs/standards/global/STANDARDS.md](.maister/docs/standards/global/STANDARDS.md)
- **PHP / Backend standards** (namespacing, DI, SQL safety, security): [.maister/docs/standards/backend/STANDARDS.md](.maister/docs/standards/backend/STANDARDS.md)
- **Testing standards** (PHPUnit, test naming, mocking, isolation): [.maister/docs/standards/testing/STANDARDS.md](.maister/docs/standards/testing/STANDARDS.md)

See the full index at [.maister/docs/INDEX.md](.maister/docs/INDEX.md).

## Key Reminders

- This is a **phpBB** codebase — follow phpBB naming and formatting conventions (tabs for indentation, no closing PHP tag, snake_case functions in legacy, camelCase in OOP classes).
- Always use parameterized queries or `$db->sql_escape()` — never interpolate raw user input into SQL.
- Check CSRF tokens (`check_form_key`) on all state-changing POST requests.
- New code must live under the `phpbb\` namespace (PSR-4).
- Use dependency injection via the Symfony container; avoid `global` in OOP code.
- All user-visible strings go through the phpBB language system.

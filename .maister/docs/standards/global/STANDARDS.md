# Global Coding Standards

General conventions applicable across the entire phpBB project.

## Naming Conventions

- **Variables**: `camelCase` (e.g., `$userCount`, `$postData`)
- **Functions** (legacy procedural): `snake_case` (e.g., `get_user_data()`, `format_post_text()`)
- **Classes**: `PascalCase` (e.g., `UserLoader`, `SessionManager`)
- **Class methods**: `camelCase` (e.g., `getUserById()`, `setPermission()`)
- **Constants**: `UPPER_SNAKE_CASE` (e.g., `MAX_RETRIES`, `VERSION_ID`)
- **File names**: `snake_case` matching the primary class or purpose (e.g., `user_loader.php`)

## PHPDoc Requirements

All public methods and functions must have PHPDoc blocks:

```php
/**
 * Brief description of what the function does.
 *
 * @param int    $userId  The user ID to look up
 * @param string $field   The field to return
 *
 * @return string|false  The field value, or false on failure
 */
public function getUserField(int $userId, string $field)
```

- Document `@param`, `@return`, and `@throws` for every public function
- Add `@deprecated` with version and replacement for deprecated code
- Internal/private helpers may use shorter single-line comments where self-evident

## File Headers

Every PHP file must begin with:

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */
```

- No closing `?>` tag at end of file (prevents accidental whitespace output)

## Constants

- Defined with `define()` in legacy code or `const` in class context
- Group related constants together with a comment block
- Never use magic numbers inline; always assign a named constant

## Code Style

- Indentation: tabs (phpBB legacy convention)
- Line endings: LF (`\n`)
- Max line length: 120 characters (soft limit)
- Opening braces on same line for control structures, new line for class/function bodies (phpBB style)
- Always use `<?php` — never short tags (`<?`)

## Internationalization

- All user-visible strings must go through the language system (`$user->lang()` or `$language->lang()`)
- Never hardcode English strings in output

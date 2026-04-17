# Global Coding Standards

Conventions for the phpBB Vibed project targeting **PHP 8.3** and PSR-1/PSR-12 compliance.
Legacy rules (phpBB 3.3 `includes/` layer) are preserved in a clearly labelled section below.

## Naming Conventions

### New Code (PHP 8.3 / PSR-1)
- **Variables**: `snake_case` — e.g., `$currentUser`, `$forumId`, `$postCount` *(camelCase accepted for local vars in new code too)*
- **Classes & Interfaces**: `PascalCase` — e.g., `ExceptionSubscriber`, `AuthProvider`, `ForumController`
- **Interface names**: `PascalCase` with `Interface` suffix — e.g., `DriverInterface`, `TreeInterface`
- **Abstract base classes**: `PascalCase` — e.g., `BaseCommand`, `MigrationCommand`
- **Class methods**: `camelCase` — e.g., `runAll()`, `setSubject()`, `getForumIds()`
- **File names for class files**: `PascalCase.php` matching the class name — e.g., `ExceptionSubscriber.php`
- **Constants (class)**: `UPPER_SNAKE_CASE` — e.g., `MAX_ITEMS`, `DEFAULT_LIMIT`
- **Constants (global)**: `UPPER_SNAKE_CASE` with `define()` — e.g., `PHPBB_VERSION`, `USERS_TABLE`

### Legacy Code (`includes/` layer — do not change)
- **Classes**: `snake_case` — e.g., `exception_subscriber`, `viglink_helper` *(backward-compat only)*
- **Methods**: `snake_case` — e.g., `run_all()`, `get_forum_ids()`
- **File names**: `snake_case.php` — e.g., `exception_subscriber.php`
- Procedural functions: `snake_case` with `phpbb_` prefix for bridge functions

## Indentation & Whitespace

- **Indentation**: Tabs (not spaces) — `\t` characters at line start. Tab width is 4 spaces visually.
- **Line endings**: UNIX LF (`\n`) — not Windows CRLF. Configure your editor accordingly.
- **End of file**: Every file must end with a newline character.
- **Token spacing**: One space between tokens in expressions. No spaces after `(` or before `)`.

```php
// Correct:
$i = 0;
if ($i < 7 && $j > 8)

// Wrong:
$i=0;
if($i<7&&$j>8)
```

## Braces & Control Structures

- **All control structures must have braces** — even single-line bodies:
  ```php
  // Correct:
  if (condition)
  {
      do_stuff();
  }
  
  // Wrong: no braces, even for one-liners
  if (condition) do_stuff();
  ```
- **Opening brace goes on its own line** (Allman style) for all control structures in PHP.
- **Switch formatting**: `break` on the same indentation level as `case` (not inside case body). Always include `default:` with `break`.

```php
switch ($mode)
{
    case 'mode1':
        // code
    break;

    default:
        // code
    break;
}
```

## File Structure

### PHP Version Declaration
Every new PHP file must begin with `declare(strict_types=1)` immediately after the opening `<?php` tag:

```php
<?php
declare(strict_types=1);
```

### Closing PHP Tag
Never use the optional closing `?>` tag in PHP-only files. Prevents accidental whitespace output.

### File Header
Every phpBB PHP file must begin with this exact license/copyright block immediately after `<?php`:

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

## PHPDoc Requirements

### On Class Properties (PHP 8.3)
Typed properties declared natively do **not** require a `@var` docblock. Use PHPDoc only when native typing is insufficient:

```php
// Preferred — native types, no @var needed:
protected string $language;
private readonly ContainerInterface $container;

// @var needed only for complex/generic types:
/** @var list<string> */
private array $roles = [];
```

### On Class Methods
All `public` and `protected` methods must have a docblock only when:
- The method has non-obvious behavior or side effects
- A parameter/return type cannot be fully expressed in the signature

Minimal example for well-typed methods:

```php
public function checkPermissions(User $user, int $id): bool
{
    // no docblock needed — signature is self-documenting
}
```

Full docblock when needed:

```php
/**
 * Validate user login credentials.
 *
 * @param array<string, string> $credentials  Associative array with 'username' and 'password'
 * @return array{user_id: int, token: string}
 * @throws RuntimeException when the auth provider is unavailable
 */
public function login(array $credentials): array
```

- Document `@throws` for methods that can throw exceptions
- Add `@deprecated` with version and replacement reference for deprecated code

## Constants & Magic Numbers

- Do not use magic numbers inline — always assign a named constant
- Use `define()` for global constants, `const` for class constants
- Group related constants with a comment block

```php
// Wrong:
if ($count > 42)

// Correct:
define('MAX_ITEMS_PER_PAGE', 42);
if ($count > MAX_ITEMS_PER_PAGE)
```

## Internationalization

- All user-visible strings must go through the language system (`$user->lang()` or `$language->lang()`)
- Never hardcode English strings in output — always use language keys
- String keys: `UPPER_SNAKE_CASE` (e.g., `'LOGIN_ERROR_USERNAME'`)

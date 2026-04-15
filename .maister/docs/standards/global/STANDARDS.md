# Global Coding Standards

General conventions applicable across the entire phpBB project, verified by analysis of actual codebase (phpBB 3.3.15).

## Naming Conventions

- **Variables**: `snake_case` — e.g., `$current_user`, `$forum_id`, `$post_count` *(NOT camelCase)*
- **Functions** (legacy procedural, `includes/`): `snake_case` — e.g., `make_forum_select()`, `delete_topics()`. New utility functions that bridge both layers use `phpbb_` prefix.
- **Classes** (OOP, `phpbb/`): `snake_case` — e.g., `exception_subscriber`, `cron_list`, `viglink_helper` *(NOT PascalCase — phpBB-specific convention, differs from PSR-1)*
- **Class methods**: `snake_case` — e.g., `run_all()`, `set_subject()`, `get_forum_ids()` *(NOT camelCase)*
- **Constants**: `UPPER_SNAKE_CASE` — e.g., `PHPBB_VERSION`, `USERS_TABLE`
- **File names**: `snake_case` — e.g., `exception_subscriber.php`, `driver_interface.php` (100% consistency across 866+ sampled files)

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

### On Class Properties
Every class property must have a `@var` PHPDoc inline doc comment:

```php
/** @var \phpbb\language\language */
protected $language;

/** @var \phpbb\db\driver\driver_interface */
protected $db;

/** @var \phpbb\config\config */
protected $config;
```

### On Class Methods
All public and protected methods must have a `/** ... */` docblock with `@param` and `@return` tags:

```php
/**
 * Brief description.
 *
 * @param \phpbb\user $user   The user object
 * @param int         $id     The ID
 * @return bool
 */
public function check_permissions(\phpbb\user $user, int $id)
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

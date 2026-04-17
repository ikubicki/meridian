# Backend Coding Standards

PHP 8.3 standards for the phpBB Vibed backend codebase.

## Strict Types

Every new PHP file must declare strict types immediately after `<?php`:

```php
<?php
declare(strict_types=1);
```

This enforces type coercion strictness for scalar type declarations and is **mandatory** for all new code under `src/phpbb/`.

## Return & Property Types

All methods must declare explicit return types. All class properties must have native type declarations:

```php
// Properties with types:
private string $jwtSecret;
private readonly int $tokenTtl;

// Methods with return types:
public function getUser(int $id): ?User
public function listForums(): array
public function handleLogin(): JsonResponse
public function tearDown(): void
```

Use `?Type` (nullable) only when `null` is a meaningful return. Prefer `never` for methods that always throw.

## Namespacing (PSR-4)

- All new OOP code lives under the `phpbb\` namespace
- Namespace mirrors the directory structure exactly:
  - `phpbb/auth/provider/Db.php` → `namespace phpbb\auth\provider;`
  - `phpbb/cache/driver/DriverInterface.php` → `namespace phpbb\cache\driver;`
- Never use global namespace for new classes; always declare `namespace phpbb\...;`
- Extensions use `<vendor>\<extension>\` namespace

```php
namespace phpbb\auth\provider;

class Db extends Base
{
    // ...
}
```

## Class Naming Conventions

- **Classes**: `PascalCase` — e.g., `ExceptionSubscriber`, `CronList`, `ViglinkHelper`
- **Interfaces**: `PascalCase` with `Interface` suffix — e.g., `DriverInterface`, `TreeInterface`
- **Abstract base classes**: `PascalCase` — e.g., `Base`, `MigrationCommand`

> **Legacy note**: Existing files in the old phpBB layer still use `snake_case` (e.g., `viglink_helper`). Do not rename them — keep backward compatibility. New files under `src/phpbb/api/` and extensions must use `PascalCase`.

## Dependency Injection

- **Constructor injection only** — all dependencies injected via `__construct()`
- Use **constructor property promotion** with `readonly` for injected services:

```php
class ForumRepository
{
    public function __construct(
        private readonly \phpbb\db\driver\driver_interface $db,
        private readonly \phpbb\config\config $config,
    ) {}
}
```

- Properties that must be mutable after construction use `private` without `readonly`
- Service definition in YAML: `config/default/container/services_*.yml`
- Avoid `global` in new OOP code — use DI container

## Readonly Properties

Use `readonly` for all constructor-injected dependencies and any value that must not change after construction:

```php
private readonly string $jwtSecret;
private readonly ContainerInterface $container;
```

For PHP 8.2+ readonly classes where all properties are readonly, consider `readonly class`:

```php
readonly class TokenClaims
{
    public function __construct(
        public int $userId,
        public bool $isAdmin,
        public int $exp,
    ) {}
}
```

## Modern PHP Constructs

### `match` Expression
Prefer `match` over `switch` for value-returning expressions:

```php
// Preferred:
$label = match($status) {
    'active'  => 'Active',
    'banned'  => 'Banned',
    default   => 'Unknown',
};

// Avoid (switch for value mapping):
switch ($status) { ... }
```

### Named Arguments
Use named arguments when calling functions with multiple parameters or non-obvious positional arguments:

```php
array_slice(array: $items, offset: 0, length: 10, preserve_keys: true);
str_contains(haystack: $path, needle: '/api/');
```

### Nullsafe Operator
Use `?->` instead of null-checks for chained property/method access:

```php
// Preferred:
$userId = $this->request->attributes->get('_api_token')?->userId;

// Avoid:
$token = $this->request->attributes->get('_api_token');
$userId = $token !== null ? $token->userId : null;
```

### First-Class Callables
Use first-class callables instead of closures when passing existing methods as callbacks:

```php
// Preferred:
$formatted = array_map($this->formatForum(...), $forums);

// Avoid unnecessary closure wrapper:
$formatted = array_map(fn($f) => $this->formatForum($f), $forums);
```

### Enums
Use `enum` for fixed value sets:

```php
enum HttpMethod: string
{
    case Get    = 'GET';
    case Post   = 'POST';
    case Patch  = 'PATCH';
    case Delete = 'DELETE';
}
```

## Class Member Visibility

- Always use explicit visibility: `public`, `protected`, `private`
- Never use `var` (PHP 4 legacy — only in `includes/`, never in new code)
- `static` keyword comes after visibility: `public static function`, `protected static int $count`

## String Quoting

- Prefer **single quotes** `'...'` unless variable interpolation is specifically needed
- Single quotes avoid unnecessary PHP parser work

```php
// Correct:
$str = 'This is a string without variables.';
$table = USERS_TABLE . " WHERE username = '" . $db->sql_escape($name) . "'";
```

## `use` Statements

- Place `use` import statements after `namespace` declaration, before the class body
- Group: PHP built-ins → external/vendor → project-internal, separated by blank lines

```php
namespace phpbb\console\command;

use RuntimeException;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use phpbb\db\driver\driver_interface;
```

## Error Handling

- **No silent failures**: never suppress errors with `@function_call()`
- **OOP layer**: throw typed exceptions:
  - `phpbb\exception\runtime_exception` — general runtime errors
  - `phpbb\exception\http_exception` — HTTP-layer errors (401, 403, 404)
  - `phpbb\exception\module_not_found_exception` — module loading failures
  - SPL exceptions: `\RuntimeException`, `\OutOfBoundsException`, `\InvalidArgumentException`
- **API layer**: return `JsonResponse` with appropriate HTTP status (see `REST_API.md`)
- **Legacy procedural** (`includes/`): use `trigger_error()` with `E_USER_ERROR` / `E_USER_WARNING`
- Never expose raw exception messages or stack traces to end users

```php
// OOP:
throw new \phpbb\exception\runtime_exception('CRON_LOCK_ERROR', [], null, 1);

// Legacy:
trigger_error($user->lang('OPERATION_FAILED'), E_USER_ERROR);
```

## SQL Safety

### Always use the DBAL
All SQL must go through the Database Abstraction Layer (`$db`). Never use raw `mysql_*`, `mysqli_*`, or `PDO` directly.

### Escaping
- `$db->sql_escape($value)` — for string values in SQL (even when you believe the variable is safe)
- `(int) $id` — cast integer IDs before inserting in SQL strings
- Never interpolate user input directly into SQL strings

```php
// Correct:
$sql = "SELECT * FROM " . USERS_TABLE . " WHERE username = '" . $db->sql_escape($username) . "'";

// Correct for integers:
$sql = 'SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id = ' . (int) $post_id;

// Wrong — never do this:
$sql = "SELECT * FROM " . USERS_TABLE . " WHERE username = '$username'";
```

### DBAL Helper Methods
- `$db->sql_build_array('INSERT', $data_array)` — for INSERT/UPDATE
- `$db->sql_build_query('SELECT', $sql_ary)` — for complex SELECT queries
- `$db->sql_in_set('column', $id_array)` — for IN clauses
- `$db->sql_query_limit($sql, $total, $offset)` — for LIMIT/pagination

### Cross-Database Compatibility
- All SQL must work across MySQL, PostgreSQL, MSSQL, SQLite3, Oracle
- Use `<>` for not-equals (SQL:2003 standard) — NOT `!=`
- Do not use database-specific functions (`CONCAT` vs `||`, etc.)

## Security Practices

### CSRF Protection
Every state-changing POST form must embed and validate a CSRF token:

```php
// In template preparation:
add_form_key('my_form_name');

// On form submit:
if ($submit && !check_form_key('my_form_name'))
{
    trigger_error('FORM_INVALID', E_USER_WARNING);
}
```

### Input Handling
- Use `$request->variable()` (OOP) or `request_var()` (legacy) with explicit type casting — never access `$_GET`/`$_POST` directly
- Always validate and sanitize user input at the boundary

### Output Escaping
- Template variables assigned via `$template->assign_vars()` — Twig handles escaping for `{{ VAR }}` blocks
- For raw PHP output: `htmlspecialchars($value, ENT_COMPAT, 'UTF-8')`

### Permissions
- Always check ACL before exposing data or performing privileged actions:
```php
if (!$auth->acl_get('f_read', $forum_id))
{
    trigger_error('NOT_AUTHORISED');
}
```

## Legacy Code (`includes/`)

The `includes/` layer contains procedural code that predates the OOP refactor. When working in this layer:
- Functions use `global $db, $user, $auth, $template, $config, $cache, $request` — this is expected legacy behavior
- New functionality should be placed in `phpbb/` as DI services, not added to `includes/`
- The `global` pattern is intentionally maintained for backward compatibility in `includes/`

## Database Conventions

- Table name constants: `USERS_TABLE`, `POSTS_TABLE`, `FORUMS_TABLE`, etc.
- Always reference tables via constants, never hardcode table names with prefix

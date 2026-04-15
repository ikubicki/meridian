# Backend Coding Standards

PHP-specific standards for the phpBB backend codebase, verified by analysis of 30+ classes and configuration.

## Namespacing (PSR-4)

- All new OOP code lives under the `phpbb\` namespace
- Namespace mirrors the directory structure exactly:
  - `phpbb/auth/provider/db.php` → `namespace phpbb\auth\provider;`
  - `phpbb/cache/driver/driver_interface.php` → `namespace phpbb\cache\driver;`
- Never use global namespace for new classes; always declare `namespace phpbb\...;`
- Extensions use `<vendor>\<extension>\` namespace

```php
namespace phpbb\auth\provider;

class db extends base
{
    // ...
}
```

## Class Naming Conventions

- **Classes**: `snake_case` — e.g., `class exception_subscriber`, `class cron_list`, `class viglink_helper`
  - This is a phpBB-specific convention that **differs from PSR-1** (which uses PascalCase)
- **Interfaces**: `snake_case` with `_interface` suffix — e.g., `interface driver_interface`, `interface tree_interface`
- **Abstract base classes**: `snake_case` — e.g., `class base`, `class migration_command`

## Dependency Injection

- **Constructor injection only** — all dependencies injected via `__construct()` (100% consistency in phpbb/ layer)
- Dependencies stored as `protected` properties (not `public`, not `var`)
- Service definition in YAML: `config/default/container/services_*.yml`
- Avoid `global` in new OOP code — use DI container

```php
class viglink_helper
{
    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /**
     * Constructor.
     *
     * @param \phpbb\config\config              $config
     * @param \phpbb\db\driver\driver_interface $db
     */
    public function __construct(\phpbb\config\config $config, \phpbb\db\driver\driver_interface $db)
    {
        $this->config = $config;
        $this->db = $db;
    }
}
```

## Class Member Visibility

- Always use explicit visibility: `public`, `protected`, `private`
- Never use `var` (PHP 4 legacy — only in `includes/`, never in new code)
- `static` keyword comes after visibility: `public static function`, `protected static $var`

## String Quoting

- Prefer **single quotes** `'...'` unless variable interpolation is specifically needed
- Single quotes avoid unnecessary PHP parser work

```php
// Correct:
$str = 'This is a string without variables.';
$table = USERS_TABLE . " WHERE username = '" . $db->sql_escape($name) . "'";

// Avoid (unless interpolation needed):
$str = "This is a string without variables.";
```

## `use` Statements

- Place `use` import statements after `namespace` declaration, before the class body
- Use for Symfony classes and frequently referenced external classes
- phpBB's own types may be referenced as FQN in constructor signatures alternatively

```php
namespace phpbb\console\command;

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

```php
$sql_ary = [
    'user_id'    => (int) $user_id,
    'post_text'  => $db->sql_escape($text),
];
$sql = 'INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary);
$db->sql_query($sql);
```

### Cross-Database Compatibility
- All SQL must work across MySQL, PostgreSQL, MSSQL, SQLite3, Oracle
- Use `<>` for not-equals (SQL:2003 standard) — NOT `!=`
- Do not use database-specific functions (`CONCAT` vs `||`, etc.)

```php
// Correct:
WHERE forum_id <> 0

// Wrong:
WHERE forum_id != 0
```

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

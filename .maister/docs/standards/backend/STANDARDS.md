# Backend Coding Standards

PHP-specific standards for the phpBB backend codebase.

## Namespacing (PSR-4)

- All new OOP code lives under the `phpbb\` namespace (or `phpbb\<component>\`)
- Extensions use `<vendor>\<extension>\` namespace
- Namespace maps to directory: `phpbb\auth\provider\oauth` → `phpbb/auth/provider/oauth.php`
- Never use global namespace for new classes; always declare `namespace phpbb\...;`

```php
namespace phpbb\auth\provider;

class oauth extends base
{
    // ...
}
```

## Dependency Injection

- Prefer **constructor injection** for mandatory dependencies
- Use the Symfony DI container (`config/default/container/`) to wire services
- Define services in `config/default/container/services_*.yml`
- Avoid `global` variables in new OOP code; retrieve from container or inject
- Service IDs use dot notation: `phpbb.user`, `dbal.conn`

```php
public function __construct(
    \phpbb\db\driver\driver_interface $db,
    \phpbb\config\config $config,
    \phpbb\user $user
) {
    $this->db     = $db;
    $this->config = $config;
    $this->user   = $user;
}
```

## Error Handling

- **No silent failures**: never suppress errors with `@function_call()`
- Throw exceptions in the OOP layer; use `\phpbb\exception\runtime_exception` or appropriate sub-class
- Legacy procedural code: use `trigger_error()` with `E_USER_ERROR` / `E_USER_WARNING`
- Log unexpected errors via `\phpbb\log\log_interface`
- Never expose raw exception messages or stack traces to end users

```php
if ($result === false)
{
    throw new \phpbb\exception\runtime_exception('OPERATION_FAILED');
}
```

## SQL Safety

- **Always use parameterized queries** via the phpBB DBAL — never interpolate user input into SQL strings
- Use `$db->sql_build_query()` for complex queries, `$db->sql_query()` for simple ones
- Escape values with `$db->sql_escape()` only when parameterized binding is not available
- Use `(int)` cast for integer IDs before inserting into SQL strings
- Check `$db->sql_affectedrows()` after DML operations when the count matters

```php
$sql = 'SELECT user_id, username
    FROM ' . USERS_TABLE . '
    WHERE user_id = ' . (int) $user_id;
$result = $db->sql_query($sql);
```

## Security Practices

### CSRF Protection
- All state-changing POST forms must include and validate a form token:
  ```php
  add_form_key('my_form_name');       // in template
  check_form_key('my_form_name');     // on submit
  ```

### Input Sanitization
- Validate and sanitize all user input before use
- Use `request_var()` (legacy) or `$request->variable()` (OOP) with type casting — never access `$_GET`/`$_POST` directly
- Strip HTML from user input using `htmlspecialchars_decode()` + re-encode on output

### Output Escaping
- Escape all dynamic data before rendering in templates: use `$template->assign_vars()` — the template engine handles escaping for `{VAR}` blocks
- For raw HTML output use `htmlspecialchars($value, ENT_COMPAT, 'UTF-8')`

### Permissions
- Always check permissions before exposing data or performing actions:
  ```php
  if (!$auth->acl_get('f_read', $forum_id))
  {
      trigger_error('NOT_AUTHORISED');
  }
  ```

## Database Conventions

- Table names use the `PHPBB_` prefix constant (e.g., `USERS_TABLE`, `POSTS_TABLE`)
- Never hard-code table names — always use the defined constants
- Transactions: wrap multi-step DML in `sql_transaction('begin')` / `sql_transaction('commit')` with rollback on error

## File & Class Organization

- One class per file, filename equals class name in snake_case
- Keep business logic out of controller/template layer — use dedicated service classes
- Configuration goes in `config/` YAML files, not hardcoded PHP

# Backend Coding Standards

PHP 8.2+ (minimum), runtime PHP 8.4 standards for the phpBB Vibed backend codebase.

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

### Intersection Types for Mocked Dependencies (Tests)

In test classes, use intersection types to combine the interface and `MockObject`:

```php
private ConversationRepositoryInterface&MockObject $conversationRepo;
```

This preserves IDE autocompletion for mock methods while enforcing the interface contract.

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

### `final readonly class` for Entities and DTOs

Entities and DTOs are immutable value objects — always declare them `final readonly class`:

```php
// ✅ Entity — constructed from DB row via static factory
final readonly class Conversation
{
    public function __construct(
        public int $conversationId,
        public string $participantHash,
        public int $createdBy,
        public int $createdAt,
        public ?int $lastMessageId,
        public int $messageCount,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            conversationId: (int) $row['conversation_id'],
            participantHash: $row['participant_hash'],
            createdBy: (int) $row['created_by'],
            createdAt: (int) $row['created_at'],
            lastMessageId: isset($row['last_message_id']) ? (int) $row['last_message_id'] : null,
            messageCount: (int) $row['message_count'],
        );
    }
}

// ✅ DTO — constructed from entity via static factory
final readonly class ConversationDTO
{
    public function __construct(
        public int $id,
        public int $createdBy,
        public int $createdAt,
    ) {}

    public static function fromEntity(Conversation $conversation): self
    {
        return new self(
            id: $conversation->conversationId,
            createdBy: $conversation->createdBy,
            createdAt: $conversation->createdAt,
        );
    }
}
```

Key rules:
- Entities always have `fromRow(array $row): self` static factory
- DTOs always have `fromEntity(EntityClass $entity): self` static factory
- Both are `final readonly` — never mutable, never extended
- Named arguments in `new self(...)` calls — mandatory for clarity

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
Use backed enums for all fixed-value domain sets (`UserType`, `BanType`, `GroupType`, `ForumStatus`, `ForumType`, etc.):

```php
// Backed int enum for DB-stored status
enum ForumStatus: int
{
    case Visible = 0;
    case Hidden  = 1;
    case Locked  = 2;
}

// Backed string enum for domain values
enum BanType: string
{
    case User  = 'user';
    case Ip    = 'ip';
    case Email = 'email';
}

// Backed string enum for HTTP methods
enum HttpMethod: string
{
    case Get    = 'GET';
    case Post   = 'POST';
    case Patch  = 'PATCH';
    case Delete = 'DELETE';
}
```

Conventions:
- Enums live in `src/phpbb/<module>/Enum/` or `src/phpbb/<module>/Entity/` (for status enums tightly coupled to an entity)
- File name matches enum name: `UserType.php` → `enum UserType`
- Always backed (`int` or `string`) — never pure enums for domain code
- Import via `use` — never reference by FQN inline

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

## Controller Design

Controllers (REST API or otherwise) are **thin routing layers** — they must not contain business logic.

### Responsibilities of a controller
1. Parse and validate the incoming request (input shape, required fields)
2. Build the appropriate DTO or context object
3. Call the relevant service method
4. Map the result or exception to a response (JSON, redirect, etc.)

### What does NOT belong in a controller
- Database queries or repository calls (use a service)
- Filtering, sorting, or transforming data beyond serialisation (use a service)
- Caching (use a service)
- Conditional logic that represents a business rule (use a service)

### Pagination — always use `PaginationContext`

All list/search controller actions must build a `phpbb\api\DTO\PaginationContext` (or a domain-specific DTO with the same fields) and pass it to the service. Never forward raw `$page` / `$perPage` integers:

```php
// ✅ Correct
$ctx    = PaginationContext::fromQuery($request->query);
$result = $this->service->listAll($ctx);   // PaginatedResult

// ❌ Wrong
$result = $this->service->listAll(
    page: (int) $request->query->get('page', 1),
    perPage: (int) $request->query->get('perPage', 25),
);
```

Service methods that return lists consume `PaginationContext` (or a subtype) — never bare integers. See `REST_API.md` for the full pagination contract.

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

---

## Schema Compatibility & Code Isolation

**Core principle**: Maximum compatibility with the phpBB 3.3 database schema, zero references to legacy code.

### Schema: Reuse Everything

- New services operate on the **existing phpBB3 tables** (`phpbb_users`, `phpbb_forums`, `phpbb_topics`, `phpbb_posts`, `phpbb_acl_*`, etc.) without renaming, dropping, or restructuring them.
- **Column names stay unchanged** — even if naming is inconsistent (`user_id` vs `poster_id` vs `topic_poster`), mirror the original column names in queries.
- **Add columns** when needed (`ALTER TABLE ... ADD COLUMN`). Never drop or rename existing columns.
- **New tables** only for genuinely new features that have no legacy equivalent (e.g., `phpbb_messaging_conversations`, `phpbb_stored_files`).
- Table prefix (`phpbb_`) is a **fixed project convention** — not configurable at runtime. Use `private const TABLE = 'phpbb_banlist'` in each repository. Do not inject the prefix via DI or config: this is a rewrite with a single deployment target, not an installer. Multi-tenancy is handled via separate databases/schemas, not table prefixes.

```php
// Correct — query uses original phpBB3 column names exactly:
$stmt = $this->db->prepare(
	'SELECT topic_id, topic_title, topic_poster, topic_time, topic_views
	 FROM ' . $this->topicsTable . '
	 WHERE forum_id = :forum_id AND topic_visibility = :visibility
	 ORDER BY topic_last_post_time DESC'
);

// Wrong — renaming columns to "clean up":
// SELECT topic_id AS id, topic_title AS title ...  ← do not alias for cosmetic reasons
```

### Code: Zero Legacy References

New code under `src/phpbb/` MUST NOT import, reference, instantiate, or call any legacy code:

| Forbidden | Replacement |
|-----------|-------------|
| `global $db, $user, $auth, $config, $cache, $request` | Constructor DI via Symfony container |
| `$db->sql_query()`, `$db->sql_escape()`, DBAL methods | PDO prepared statements with named parameters |
| `$request->variable()`, `request_var()` | Symfony `Request` object (`$request->query->get(...)`, `$request->request->get(...)`) |
| `$user->lang()`, `$user->lang['KEY']` | Language service via DI (TBD) |
| `$auth->acl_get()`, `$auth->acl_gets()` | `phpbb\auth\AuthorizationServiceInterface` |
| `$cache->get()`, `$cache->put()` | `TagAwareCacheInterface` from cache service |
| `$template->assign_vars()` | JSON responses via REST API controllers |
| `trigger_error()` with `E_USER_*` | Throw `phpbb\common\Exception\*` typed exceptions |
| `add_form_key()` / `check_form_key()` | JWT token validation (stateless) |
| `$phpbb_root_path`, `$phpEx` | `__DIR__`-based paths, `PHPBB_FILESYSTEM_ROOT` constant |
| `includes/*.php` require/include | PSR-4 autoloading only |
| `phpbb\db\driver\driver_interface` | `\PDO` injected via DI |

### What This Means in Practice

```php
// ✅ New service — uses phpBB3 schema, zero legacy code:
namespace phpbb\threads;

final class TopicRepository
{
	public function __construct(
		private readonly \PDO $db,
		private readonly string $topicsTable,
		private readonly string $forumsTable,
	) {}

	public function findByForum(int $forumId, int $limit, int $offset): array
	{
		$stmt = $this->db->prepare(
			'SELECT topic_id, topic_title, topic_poster, topic_time,
			        topic_first_post_id, topic_last_post_id,
			        topic_replies_real, topic_views, topic_status
			 FROM ' . $this->topicsTable . '
			 WHERE forum_id = :forum_id
			   AND topic_visibility = :visibility
			 ORDER BY topic_type DESC, topic_last_post_time DESC
			 LIMIT :limit OFFSET :offset'
		);
		$stmt->execute([
			'forum_id' => $forumId,
			'visibility' => 1,
			'limit' => $limit,
			'offset' => $offset,
		]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}
}
```

```php
// ❌ Wrong — mixes legacy DBAL with new code:
class TopicRepository
{
	public function findByForum(int $forumId): array
	{
		global $db;  // ← FORBIDDEN
		$sql = 'SELECT * FROM ' . TOPICS_TABLE . '
			WHERE forum_id = ' . (int) $forumId;  // ← legacy pattern
		$result = $db->sql_query($sql);  // ← legacy DBAL
	}
}
```

### Table Name Injection

Table names are injected via DI (YAML config), not via PHP constants:

```yaml
# services.yml
phpbb.threads.topic_repository:
    class: phpbb\threads\TopicRepository
    arguments:
        $db: '@database_connection'
        $topicsTable: '%tables.topics%'
        $forumsTable: '%tables.forums%'

# parameters.yml
parameters:
    tables.topics: '%table_prefix%topics'
    tables.forums: '%table_prefix%forums'
    table_prefix: 'phpbb_'
```

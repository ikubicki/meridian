# Internal Patterns: Database Usage Across Existing Services

## 1. Per-Service Database Usage

| Service | Repository Pattern | Query Style | Entity Hydration | Pagination | Transactions | JSON Handling | Tables |
|---------|-------------------|-------------|-----------------|------------|--------------|---------------|--------|
| **Threads** | 3 repositories (Topic, Post, Draft) with interface contracts; PDO injected via constructor | Raw SQL strings with prepared statements; `$visibilitySql` passed as SQL fragment parameter | Manual: `hydrate(array $row): Entity` method on repository; readonly constructor-promoted properties | Two-phase: Phase 1 = SELECT IDs with pagination, Phase 2 = SELECT full rows for those IDs | Sync in-transaction for topic/forum counters; event-driven post-commit for user counters | None — "raw text only" ADR; no JSON columns on posts | `phpbb_topics`, `phpbb_posts`, `phpbb_drafts` |
| **Hierarchy** | 1 repository (ForumRepository) + TreeService with own SQL; PDO injected | Raw SQL with PDO prepared statements; advisory locks via `GET_LOCK()`; nested set math operations | `hydrate(array $row): Forum` on repository; entity has value objects (ForumStats, ForumLastPost, ForumPruneSettings) composed inside | No pagination — full tree loaded, filtered client-side; batch operations on tree regions | Advisory lock (`GET_LOCK('hierarchy_tree')`) wrapping nested set mutations; multi-statement transactions | None on core entity; `metadata JSON` column added by plugin system for plugin-specific data | `phpbb_forums`, `phpbb_forums_track`, `phpbb_forums_watch` |
| **Users** | 4 repositories (User, Group, Ban, ShadowBan) with interface contracts; PDO injected | Raw SQL prepared statements; `IN (...)` for batch lookups; counter operations as atomic `UPDATE ... SET col = col + 1` | Manual hydration; entities are `final class` with readonly constructor promotion; UserPreferences parsed from JSON in PHP | Offset-based: `LIMIT :limit OFFSET :offset`; `PaginatedResult<T>` generic DTO wraps results + total count | Implicit — single statements are auto-committed; no explicit multi-statement transactions documented | `profile_fields JSON` and `preferences JSON` columns; JSON parsed/merged in PHP; stored sparse (only non-defaults); `JSON_EXTRACT` for read, full column replace for write | `phpbb_users`, `phpbb_groups`, `phpbb_user_group`, `phpbb_banlist`, `phpbb_shadow_bans` |
| **Search** | No repository per se — backends implement `SearcherInterface`/`IndexerInterface`; Native backend has own PDO queries | Backend-dependent: Native = raw SQL on word tables; MySQL = `MATCH...AGAINST`; Postgres = `to_tsquery`; all via PDO prepared statements | Minimal — returns `int[]` IDs only; hydration is controller responsibility | Offset-based in backend query; `SearchResult { ids[], totalCount, page, perPage }` | Native backend: index operations are individual INSERTs/DELETEs, no explicit transaction wrapping for search writes | None | `phpbb_search_wordlist`, `phpbb_search_wordmatch`, `phpbb_search_results` (native); relies on DB engine FULLTEXT for MySQL/PG |
| **Plugin System** | No repository — `MetadataAccessor` and `SchemaEngine` interact with DB directly; PDO + tablePrefix injected | `JSON_SET`, `JSON_EXTRACT`, `JSON_REMOVE`, `JSON_CONTAINS_PATH` for metadata; `INFORMATION_SCHEMA` queries for introspection; DDL generation (CREATE/ALTER/DROP TABLE) | N/A — infrastructure service, not entity-based | N/A | `SchemaExecutor` wraps each DDL operation in own transaction; step-based resumable execution | Central to its purpose: `JSON_SET(COALESCE(metadata, '{}'), :path, :value)` for atomic partial JSON update; batch `JSON_EXTRACT` with `IN (...)` for N+1 avoidance | `phpbb_plugins` (state tracking); any `phpbb_*` table with `metadata JSON` column; plugin-declared tables |

## 2. Common Patterns

### 2.1 Repository Pattern

**Structure:**
- Every domain service defines `*RepositoryInterface` in a `contract/` or `Contract/` directory
- Implementation class lives in `repository/` or `Repository/` directory
- Constructor receives `\PDO $db` (and optionally `string $tablePrefix`)
- Interface declares CRUD + domain-specific query methods
- Return types are domain entities (not arrays, not stdClass)

**Universal Repository Methods:**
```
findById(int $id): ?Entity
findByIds(array $ids): Entity[]       // keyed by ID
create(array $data): int              // returns new ID
update(int $id, array $data): void
delete(int $id): void
```

**Pattern: `array $data` for create/update (not entity object)**
All repositories accept `array<string, mixed>` for writes — column→value maps. This decouples the entity (readonly, immutable) from the write path.

### 2.2 Entity Hydration

**Consistent approach across all services:**
- Entities are `final class` (or `final readonly class`) with constructor promotion
- All properties are `public readonly`
- A `hydrate(array $row): Entity` method on the repository maps DB row columns to constructor args
- Value objects composed inside entities (e.g., `ForumStats`, `ForumLastPost`, `ForumPruneSettings`)
- Enums used for type-safe integer mappings (backed enums: `enum Visibility: int`)

**Example hydration signature:**
```php
public function hydrate(array $row): Forum;
```

### 2.3 Pagination

**Two strategies used:**

1. **Two-phase pagination** (Threads — for large result sets):
   - Phase 1: `SELECT id FROM table WHERE ... ORDER BY ... LIMIT ? OFFSET ?`
   - Phase 2: `SELECT * FROM table WHERE id IN (...)`
   - Avoids loading full rows for offset skip
   - Used for topic lists, post lists

2. **Simple offset pagination** (Users, Search):
   - `SELECT ... LIMIT :limit OFFSET :offset`
   - Separate `SELECT COUNT(*)` for total
   - Wrapped in `PaginatedResult<T>` DTO: `{ items: T[], total: int, page: int, perPage: int }`

**No cursor-based pagination found in any service.**

### 2.4 Transaction Management

| Pattern | Used By | Description |
|---------|---------|-------------|
| **Implicit (auto-commit)** | Users, Drafts | Single-statement writes don't need explicit transactions |
| **Advisory locks** | Hierarchy (TreeService) | `GET_LOCK('hierarchy_tree')` before nested set mutations; released in `finally` |
| **In-transaction sync** | Threads (CounterService) | Counter updates within same `beginTransaction()`/`commit()` as the primary insert/update |
| **Post-commit events** | All services | Domain events dispatched AFTER commit; listeners run outside the original transaction |
| **Step-based resumable** | Plugin Schema | Each DDL operation is one step with own transaction; supports resuming on failure |

**Critical rule:** Events are dispatched AFTER transaction commit. This is consistent across all services.

### 2.5 Prepared Statements

**Universal pattern:**
- All services use PDO prepared statements (`$pdo->prepare()` + `->execute()`)
- No raw string interpolation of user input
- Visibility SQL fragments (`$visibilitySql`) are generated by the service (not user input), passed as trusted SQL
- `IN (...)` clauses built programmatically with placeholder arrays

### 2.6 Counter Operations

**Atomic increment/decrement pattern:**
```sql
UPDATE phpbb_topics SET topic_posts_approved = topic_posts_approved + 1 WHERE topic_id = ?
UPDATE phpbb_users SET user_posts = user_posts + 1 WHERE user_id = ?
```
Never read-then-write for counters.

## 3. Table & Naming Conventions

### 3.1 Table Names
- **Prefix**: `phpbb_` (configurable via `$tablePrefix`)
- **Case**: `snake_case`
- **Plural**: Yes (`phpbb_topics`, `phpbb_posts`, `phpbb_users`, `phpbb_groups`)
- **Join tables**: entity names concatenated (`phpbb_user_group`, `phpbb_forums_track`, `phpbb_forums_watch`)

### 3.2 Column Names
- **Case**: `snake_case`
- **ID columns**: `{entity_singular}_id` (e.g., `topic_id`, `post_id`, `user_id`, `forum_id`)
- **Foreign keys**: Same name as referenced PK (`forum_id` in topics table references `phpbb_forums.forum_id`)
- **Booleans**: `tinyint(1)` — named descriptively (`topic_bumped`, `topic_attachment`, `topic_reported`)
- **Timestamps**: `int unsigned` (Unix timestamps, not DATETIME)
- **Counters**: `int unsigned NOT NULL DEFAULT 0`
- **Text fields**: `varchar(255)` for short strings, `MEDIUMTEXT` for long content
- **Enums**: Stored as `tinyint` with PHP-side backed enums

### 3.3 Index Naming
- Format: `idx_{descriptive_name}` or composite column hint (e.g., `fid_time_moved`, `forum_vis_last`)
- No universal convention — some legacy-style, some modern `idx_` prefix

### 3.4 Table Prefix Handling
- `$tablePrefix` string injected into repositories
- Tables referenced as `{$this->tablePrefix}topics` (prefix includes the underscore)
- OR as constant: `$this->tablePrefix . 'topics'`

## 4. Plugin System Integration Requirements

### 4.1 What SchemaCompiler Expects from DB Service

| Requirement | Detail |
|-------------|--------|
| **PDO instance** | Raw `\PDO` object for DDL execution |
| **Table prefix** | `string $tablePrefix` — prepended to all plugin table names |
| **Engine detection** | Need to know MySQL vs PostgreSQL to select `DdlGeneratorInterface` implementation |
| **Column type mapping** | Must translate YAML types (`uint`, `varchar`, `text`, `json`, `bool`, `bigint`, `decimal`, `datetime`) to platform-specific SQL (see type table in Plugin System HLD) |

### 4.2 What SchemaIntrospector Needs

| Requirement | Detail |
|-------------|--------|
| **INFORMATION_SCHEMA access** | `SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?` |
| **Table existence check** | `tableExists(string $tableName): bool` |
| **Current table state** | Returns `TableDefinition` (columns with types, nullable, defaults, auto_increment) |
| **Index introspection** | Read current indexes from `INFORMATION_SCHEMA.STATISTICS` or equivalent |
| **Database name** | To scope INFORMATION_SCHEMA queries to current database |

### 4.3 What DdlGenerator Requires

| Requirement | Detail |
|-------------|--------|
| **Platform detection** | PDO driver name → select `MysqlDdlGenerator` or `PostgresDdlGenerator` |
| **Type mapping table** | `uint`→`INT UNSIGNED` (MySQL) or `INTEGER CHECK (col >= 0)` (PostgreSQL) |
| **Table prefix** | Applied during DDL generation for fully-qualified table names |
| **JSON column support** | MySQL: `JSON`; PostgreSQL: `JSONB` |
| **Auto-increment** | MySQL: `AUTO_INCREMENT`; PostgreSQL: `SERIAL` or `GENERATED ALWAYS AS IDENTITY` |

### 4.4 Plugin Table Relationship to Core Tables

| Aspect | Convention |
|--------|-----------|
| **Foreign keys** | **Loose coupling** — no FK constraints declared in YAML schema. Referential integrity managed by event listeners (cascade on delete events) |
| **Shared columns** | Plugin tables reference core IDs (e.g., `topic_id`, `user_id`, `forum_id`) as `uint` columns without FK |
| **Cleanup** | Plugin uninstall drops plugin tables (`DROP TABLE`); metadata columns cleaned via `JSON_REMOVE` |
| **Cross-table queries** | Plugin repositories handle own JOINs; never modify core table structure (except adding `metadata JSON` column, which is a core migration) |

### 4.5 MetadataAccessor DB Requirements

| Operation | SQL Pattern |
|-----------|-------------|
| **Read single key** | `SELECT JSON_EXTRACT(metadata, '$.vendor.plugin.key') FROM {table} WHERE {pk} = ?` |
| **Write single key** | `UPDATE {table} SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.vendor.plugin.key', ?) WHERE {pk} = ?` |
| **Batch read** | `SELECT {pk}, JSON_EXTRACT(metadata, '$.vendor.plugin.key') FROM {table} WHERE {pk} IN (...)` |
| **Remove plugin keys** | `UPDATE {table} SET metadata = JSON_REMOVE(metadata, '$.vendor.plugin') WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.vendor.plugin')` |
| **Virtual column index** | `ALTER TABLE ADD COLUMN _meta_x GENERATED ALWAYS AS (JSON_EXTRACT(...)) VIRTUAL; CREATE INDEX ...` |

## 5. Constraints & Non-Negotiables

### The DB Service MUST Support:

1. **Single PDO instance shared across services** — all services receive `\PDO` via constructor injection; no per-service connections
2. **Table prefix injection** — every service that touches DB needs `string $tablePrefix`; the DB service must provide this
3. **Prepared statements only** — the existing architecture assumes parameterized queries exclusively
4. **Platform abstraction for DDL** — SchemaEngine needs MySQL vs PostgreSQL detection and platform-specific DDL generators
5. **JSON column operations** — `JSON_SET`, `JSON_EXTRACT`, `JSON_REMOVE`, `JSON_CONTAINS_PATH`, `COALESCE(metadata, '{}')` must work
6. **Advisory locks** — `GET_LOCK()` / `RELEASE_LOCK()` used by Hierarchy TreeService; must be exposed or accessible
7. **Atomic counter updates** — `col = col + ?` pattern must be available directly via PDO
8. **Transaction control** — `beginTransaction()`, `commit()`, `rollback()` used explicitly by some services
9. **INFORMATION_SCHEMA access** — required by SchemaIntrospector for plugin table management
10. **Batch IN() queries** — all services use `WHERE id IN (...)` patterns; need helper for placeholder generation
11. **No ORM** — no Doctrine, no Eloquent. Raw PDO with manual hydration is the established pattern
12. **Unix timestamps** — all time columns are `int unsigned`, not DATETIME/TIMESTAMP
13. **MySQL 8.0+ and PostgreSQL support** — two platforms required (based on DDL generator implementations)
14. **`COALESCE` and `IFNULL` support** — used in JSON operations and counter queries
15. **Resumable DDL execution** — SchemaExecutor uses step-based approach; connection must remain valid across steps

### The DB Service MUST NOT:

1. **Add an ORM layer** — contradicts all existing service designs
2. **Abstract away SQL** — services write raw SQL; a query builder is optional sugar, not required
3. **Own table schemas** — each service owns its own schema; DB service provides infrastructure only
4. **Manage application-level transactions** — services control their own begin/commit boundaries
5. **Cache query results** — caching is a separate concern (phpbb\cache service)

### Connection Configuration Expected:

- DSN-based (`mysql:host=...;dbname=...;charset=utf8mb4` or `pgsql:...`)
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_EMULATE_PREPARES => false`
- `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
- UTF-8 (utf8mb4 for MySQL)

### Query Patterns That Must Work:

```php
// Basic CRUD
$stmt = $this->db->prepare("INSERT INTO {$this->tablePrefix}topics (...) VALUES (...)");
$stmt->execute([...]);
$id = (int) $this->db->lastInsertId();

// Batch lookup
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $this->db->prepare("SELECT * FROM {$this->tablePrefix}users WHERE user_id IN ($placeholders)");
$stmt->execute($ids);

// Atomic counter
$stmt = $this->db->prepare("UPDATE {$this->tablePrefix}topics SET topic_posts_approved = topic_posts_approved + ? WHERE topic_id = ?");
$stmt->execute([$delta, $topicId]);

// Advisory lock
$this->db->exec("SELECT GET_LOCK('hierarchy_tree', 10)");
// ... mutations ...
$this->db->exec("SELECT RELEASE_LOCK('hierarchy_tree')");

// JSON operations
$stmt = $this->db->prepare("UPDATE {$this->tablePrefix}topics SET metadata = JSON_SET(COALESCE(metadata, '{}'), ?, CAST(? AS JSON)) WHERE topic_id = ?");

// Two-phase pagination
$stmt = $this->db->prepare("SELECT post_id FROM {$this->tablePrefix}posts WHERE topic_id = ? AND {$visibilitySql} ORDER BY post_id ASC LIMIT ? OFFSET ?");
```

# Query Builder API Design Patterns — Industry Analysis

## 1. API Design Comparison

### Doctrine DBAL QueryBuilder

**Architecture**: Mutable builder, single class with mode switching (SELECT/INSERT/UPDATE/DELETE).

```php
// SELECT
$qb = $conn->createQueryBuilder();
$qb->select('u.id', 'u.name')
   ->from('users', 'u')
   ->where('u.active = :active')
   ->andWhere('u.age > :age')
   ->orderBy('u.name', 'ASC')
   ->setParameter('active', 1, ParameterType::INTEGER)
   ->setParameter('age', 18, ParameterType::INTEGER);

$result = $qb->executeQuery();

// INSERT
$qb->insert('users')
   ->setValue('name', ':name')
   ->setValue('email', ':email')
   ->setParameter('name', 'John')
   ->setParameter('email', 'john@example.com');

// Expression builder for complex conditions
$qb->where(
    $qb->expr()->andX(
        $qb->expr()->eq('u.type', ':type'),
        $qb->expr()->orX(
            $qb->expr()->gt('u.age', ':min_age'),
            $qb->expr()->isNull('u.deleted_at')
        )
    )
);
```

**Key Design Decisions**:
- Single `QueryBuilder` class handles all query types (mode enum internally)
- `expr()` returns `ExpressionBuilder` for complex conditions
- Parameters are named (`:name`) with explicit type constants
- Joins specify full condition as string: `$qb->innerJoin('u', 'posts', 'p', 'p.user_id = u.id')`
- No automatic identifier quoting by default (platform-aware quoting available)
- `getSQL()` returns raw SQL string, `executeQuery()`/`executeStatement()` runs it
- Mutable — each method call modifies the builder in place and returns `$this`

**Strengths**: Mature, battle-tested, close to SQL semantics, explicit.
**Weaknesses**: Mutable state, verbose for simple queries, no type safety on column names.

---

### Laravel Query Builder (Illuminate\Database\Query\Builder)

**Architecture**: Mutable builder with grammar-based SQL compilation. Separate Grammar classes per engine.

```php
// SELECT with various where clauses
$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->where('active', true)
    ->where('age', '>', 18)
    ->whereIn('role', ['admin', 'moderator'])
    ->whereNotNull('email_verified_at')
    ->whereBetween('created_at', [$start, $end])
    ->whereDate('birthday', '2000-01-01')
    ->orderBy('name')
    ->limit(10)
    ->offset(20)
    ->get();

// Subquery in WHERE
$users = DB::table('users')
    ->whereIn('id', function ($query) {
        $query->select('user_id')
              ->from('posts')
              ->where('published', true);
    })
    ->get();

// Aggregates
$count = DB::table('users')->where('active', true)->count();
$maxAge = DB::table('users')->max('age');

// INSERT
DB::table('users')->insert([
    ['name' => 'John', 'email' => 'john@ex.com'],
    ['name' => 'Jane', 'email' => 'jane@ex.com'],
]);

// Upsert
DB::table('users')->upsert(
    [['email' => 'john@ex.com', 'name' => 'John Updated']],
    ['email'],        // unique columns
    ['name']          // columns to update on conflict
);

// Chunk processing
DB::table('users')->orderBy('id')->chunk(100, function ($users) {
    foreach ($users as $user) { /* process */ }
});

// Lazy collection (generator-based)
DB::table('users')->orderBy('id')->lazy()->each(function ($user) {
    // Memory-efficient processing
});
```

**Key Design Decisions**:
- **Grammar pattern**: Abstract `Grammar` class with `MySqlGrammar`, `PostgresGrammar`, `SQLiteGrammar` subclasses
- Each grammar compiles builder state to SQL string for its engine
- `where('column', 'value')` implicitly uses `=` operator
- `where('column', '>', 'value')` — operator as second param
- Closures for grouping: `->where(fn($q) => $q->where(...)->orWhere(...))`
- Automatic parameter binding (positional `?` internally)
- `DB::raw()` for escape hatch — returns `Expression` object that bypasses quoting
- Pagination built-in: `paginate(15)` returns `LengthAwarePaginator`
- Connection-aware: builder knows which connection to execute on

**Multi-Engine Handling** (Grammar classes):
```php
// MySqlGrammar
protected function wrapValue($value): string {
    return '`' . str_replace('`', '``', $value) . '`';
}
// PostgresGrammar  
protected function wrapValue($value): string {
    return '"' . str_replace('"', '""', $value) . '"';
}

// LIMIT compilation differs:
// MySQL: LIMIT 10 OFFSET 20
// SQL Server: OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY
```

**Strengths**: Extremely ergonomic API, great DX, handles engine differences transparently, rich feature set.
**Weaknesses**: Mutable state, string-based column references (no compile-time safety), magic operators.

---

### CakePHP Database Query

**Architecture**: Type-aware query builder with function builder and automatic identifier quoting.

```php
$query = $connection->newQuery();
$query->select(['id', 'name', 'email'])
    ->from('users')
    ->where(['active' => true, 'age >' => 18])
    ->order(['name' => 'ASC'])
    ->limit(10)
    ->offset(20);

// Type-safe functions
$query->select(['count' => $query->func()->count('*')]);
$query->select(['year' => $query->func()->year('created_at')]);

// Expression system
$query->where(function (QueryExpression $exp) {
    return $exp->or([
        $exp->eq('role', 'admin'),
        $exp->and([
            $exp->gte('age', 21),
            $exp->isNotNull('verified_at'),
        ]),
    ]);
});

// Automatic type casting
$query->where(['created_at >' => new DateTime('2024-01-01')]); // Auto-formatted per driver

// Subquery
$subquery = $connection->newQuery()
    ->select(['user_id'])
    ->from('posts')
    ->where(['published' => true]);
$query->where(['id IN' => $subquery]);
```

**Key Design Decisions**:
- Array-based conditions: `['column >' => $value]` — operator embedded in key
- `QueryExpression` class for complex conditions
- Built-in type mapping: PHP types → SQL types per driver
- Function builder: `$query->func()->count()`, `->sum()`, `->coalesce()`
- Automatic identifier quoting enabled by default
- `TypeMap` system binds column names to PHP types for automatic casting

**Strengths**: Strong type system, automatic identifier quoting, good expression API.
**Weaknesses**: Array-based where syntax can be confusing, less common in industry.

---

### Cycle Database (Spiral Framework)

**Architecture**: Modern PHP 8+ typed builders with fragment system.

```php
$select = $database->select()
    ->from('users')
    ->columns('id', 'name', 'email')
    ->where('active', true)
    ->where('age', '>', 18)
    ->orderBy('name')
    ->limit(10)
    ->offset(20);

// Fragment system for raw SQL
$select->columns(new Fragment('COUNT(*) as total'));
$select->where('created_at', '>', new Fragment('NOW()'));

// Parameterized fragments
$select->where('id', 'IN', new Fragment('(SELECT user_id FROM posts WHERE active = ?)', [true]));

// Insert
$database->insert('users')
    ->values(['name' => 'John', 'email' => 'john@ex.com'])
    ->run();

// Batch insert
$insert = $database->insert('users');
foreach ($rows as $row) {
    $insert->values($row);
}
$insert->run();
```

**Key Design Decisions**:
- Separate builder classes: `SelectQuery`, `InsertQuery`, `UpdateQuery`, `DeleteQuery`
- `Fragment` class as escape hatch (with parameter support)
- Driver-aware compilation (MySQL, Postgres, SQLite, SQL Server)
- PHP 8 union types, enums where applicable
- Immutable-style API (returns new instance on modification)

**Strengths**: Modern PHP patterns, clean separation, fragment system.
**Weaknesses**: Smaller ecosystem, less documentation.

---

## 2. Recommended Fluent API Pattern (PHP 8.2+)

### Design Principles

Based on analysis of all four frameworks, the recommended approach combines:

1. **Separate builder classes per query type** (Cycle pattern) — better type safety
2. **Grammar/Compiler pattern** (Laravel) — best multi-engine approach
3. **Expression builder** (Doctrine + CakePHP) — composable conditions
4. **Fragment/Raw system** (Cycle) — safe escape hatch with params

### Recommended Architecture

```
QueryFactory (creates builders)
├── SelectQuery (fluent builder)
├── InsertQuery (fluent builder)
├── UpdateQuery (fluent builder)
├── DeleteQuery (fluent builder)
├── Expression (condition tree)
│   ├── Comparison (eq, neq, gt, lt, gte, lte)
│   ├── InList / NotInList
│   ├── IsNull / IsNotNull
│   ├── Between
│   ├── Like
│   ├── Exists
│   └── Composite (AND / OR groups)
├── Raw (escape hatch with bound params)
└── Compiler (SQL generation)
    ├── MySQLCompiler
    ├── PostgreSQLCompiler
    └── SQLiteCompiler
```

### Immutable vs Mutable Decision

**Recommendation: Mutable with explicit clone support**

Rationale:
- All major frameworks use mutable builders (industry standard)
- Immutable builders create excessive object allocations for complex queries
- Provide `clone()` for branching: `$base = $qb->select(...)->from(...); $variant = clone $base;`
- PHP's copy-on-write semantics make cloning cheap

---

## 3. Expression & Condition System Design

### Comparison: Expression Approaches

| Framework | Approach | Example |
|-----------|----------|---------|
| Doctrine | `$expr->eq('col', ':param')` | Explicit, verbose |
| Laravel | `->where('col', '=', $val)` | Concise, magic |
| CakePHP | `['col >' => $val]` | Array-based |
| Cycle | `->where('col', '>', $val)` | Similar to Laravel |

### Recommended Expression Design

```php
// Simple conditions (shorthand)
$query->where('active', true);           // active = true
$query->where('age', '>', 18);           // age > 18
$query->where('role', Operator::In, ['admin', 'mod']);

// Expression builder for complex conditions
$query->where(function (Expression $expr): Expression {
    return $expr->and(
        $expr->eq('type', 'premium'),
        $expr->or(
            $expr->gt('age', 21),
            $expr->isNull('restriction'),
        ),
    );
});

// Nested conditions via closure (Laravel-style grouping)
$query->where('active', true)
      ->where(function (SelectQuery $q) {
          $q->where('role', 'admin')
            ->orWhere('permissions', '>', 5);
      });
```

### Operator Enum (PHP 8.1+)

```php
enum Operator: string {
    case Eq = '=';
    case Neq = '!=';
    case Gt = '>';
    case Gte = '>=';
    case Lt = '<';
    case Lte = '<=';
    case Like = 'LIKE';
    case NotLike = 'NOT LIKE';
    case In = 'IN';
    case NotIn = 'NOT IN';
    case Between = 'BETWEEN';
    case IsNull = 'IS NULL';
    case IsNotNull = 'IS NOT NULL';
    case Exists = 'EXISTS';
}
```

---

## 4. Parameter Binding Strategy

### Comparison

| Framework | Binding Style | Type Handling |
|-----------|--------------|---------------|
| Doctrine | Named (`:name`) | Explicit `ParameterType` constants |
| Laravel | Positional (`?`) internal | Automatic via PDO |
| CakePHP | Automatic | TypeMap system |
| Cycle | Positional, auto | Driver-aware |

### Recommended Strategy

**Internal positional binding with automatic type detection + explicit override**

```php
// Automatic — type inferred from PHP value
$query->where('age', '>', 18);        // int → PDO::PARAM_INT
$query->where('active', true);        // bool → engine-specific (1/0 or TRUE/FALSE)
$query->where('name', 'John');        // string → PDO::PARAM_STR (escaped)
$query->where('data', null);          // null → IS NULL transformation

// Explicit type override
$query->where('id', $value, ParamType::Integer);

// Named parameters for repeated use
$query->whereRaw('created_at > :cutoff AND updated_at > :cutoff', [
    'cutoff' => $date,
]);
```

### Type Detection Rules

```php
enum ParamType {
    case String;
    case Integer;
    case Float;
    case Boolean;
    case Null;
    case Binary;   // LOB
    case DateTime; // Auto-format per engine
    case Json;     // Auto-encode
}

// Detection:
// - int|float → ParamType::Integer|Float
// - bool → ParamType::Boolean (compiled per engine)
// - null → transformed to IS NULL/IS NOT NULL
// - string → ParamType::String (escaped)
// - DateTimeInterface → ParamType::DateTime (formatted per engine)
// - array → transformed to IN clause
// - JsonSerializable → ParamType::Json
```

### SQL Injection Prevention

All four frameworks prevent injection through:
1. **Never interpolating values into SQL** — always use parameter binding
2. **Identifier quoting** — column/table names wrapped in engine-specific quotes
3. **Raw expressions clearly marked** — `Raw`, `Fragment`, `DB::raw()` are explicit opt-ins
4. **Whitelist operators** — only allowed operators compiled

---

## 5. Multi-Engine SQL Generation

### Grammar/Compiler Pattern (from Laravel)

This is the cleanest approach for multi-engine support:

```php
abstract class Compiler
{
    abstract protected function quoteIdentifier(string $name): string;
    abstract protected function compileLimit(int $limit, int $offset): string;
    abstract protected function compileBooleanValue(bool $value): string;
    abstract protected function compileConcat(array $parts): string;
    
    public function compileSelect(SelectQuery $query): CompiledQuery
    {
        $sql = 'SELECT ' . $this->compileColumns($query->columns)
             . ' FROM ' . $this->compileFrom($query->from)
             . $this->compileJoins($query->joins)
             . $this->compileWheres($query->wheres)
             . $this->compileGroupBy($query->groups)
             . $this->compileHaving($query->havings)
             . $this->compileOrderBy($query->orders)
             . $this->compileLimit($query->limit, $query->offset);
        
        return new CompiledQuery($sql, $query->getBindings());
    }
}
```

### Engine-Specific Differences

```php
class MySQLCompiler extends Compiler
{
    protected function quoteIdentifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
    
    protected function compileLimit(int $limit, int $offset): string {
        if ($limit === 0 && $offset === 0) return '';
        $sql = " LIMIT {$limit}";
        if ($offset > 0) $sql .= " OFFSET {$offset}";
        return $sql;
    }
    
    protected function compileBooleanValue(bool $value): string {
        return $value ? '1' : '0';
    }
    
    protected function compileConcat(array $parts): string {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }
    
    protected function compileUpsert(InsertQuery $query): string {
        // INSERT ... ON DUPLICATE KEY UPDATE ...
    }
}

class PostgreSQLCompiler extends Compiler
{
    protected function quoteIdentifier(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }
    
    protected function compileLimit(int $limit, int $offset): string {
        $sql = '';
        if ($limit > 0) $sql .= " LIMIT {$limit}";
        if ($offset > 0) $sql .= " OFFSET {$offset}";
        return $sql;
    }
    
    protected function compileBooleanValue(bool $value): string {
        return $value ? 'TRUE' : 'FALSE';
    }
    
    protected function compileConcat(array $parts): string {
        return implode(' || ', $parts);
    }
    
    protected function compileUpsert(InsertQuery $query): string {
        // INSERT ... ON CONFLICT (...) DO UPDATE SET ...
    }
}

class SQLiteCompiler extends Compiler
{
    protected function quoteIdentifier(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }
    
    protected function compileLimit(int $limit, int $offset): string {
        // Same as MySQL
        if ($limit === 0 && $offset === 0) return '';
        $sql = " LIMIT {$limit}";
        if ($offset > 0) $sql .= " OFFSET {$offset}";
        return $sql;
    }
    
    protected function compileBooleanValue(bool $value): string {
        return $value ? '1' : '0';
    }
    
    protected function compileConcat(array $parts): string {
        return implode(' || ', $parts);
    }
    
    protected function compileUpsert(InsertQuery $query): string {
        // INSERT ... ON CONFLICT (...) DO UPDATE SET ...  (SQLite 3.24+)
    }
}
```

### Date/Time Function Mapping

```php
// Engine function registry pattern
interface FunctionCompiler {
    public function now(): string;
    public function dateFormat(string $column, string $format): string;
    public function year(string $column): string;
    public function dateDiff(string $col1, string $col2): string;
}

// MySQL: NOW(), DATE_FORMAT(col, '%Y-%m-%d'), YEAR(col), DATEDIFF(col1, col2)
// PG:    NOW(), TO_CHAR(col, 'YYYY-MM-DD'), EXTRACT(YEAR FROM col), col1 - col2
// SQLite: datetime('now'), strftime('%Y-%m-%d', col), strftime('%Y', col), julianday(col1) - julianday(col2)
```

---

## 6. Insert/Update/Delete Patterns

### INSERT Patterns

```php
// Single insert
$db->insert('users')
    ->columns('name', 'email', 'active')
    ->values('John', 'john@ex.com', true)
    ->execute();

// Batch insert (multi-row VALUES)
$db->insert('users')
    ->columns('name', 'email')
    ->values('John', 'john@ex.com')
    ->values('Jane', 'jane@ex.com')
    ->values('Bob', 'bob@ex.com')
    ->execute();

// Insert from array
$db->insert('users')
    ->row(['name' => 'John', 'email' => 'john@ex.com'])
    ->row(['name' => 'Jane', 'email' => 'jane@ex.com'])
    ->execute();

// Insert from SELECT (INSERT INTO ... SELECT ...)
$db->insert('archive_users')
    ->columns('name', 'email')
    ->fromSelect(
        $db->select('name', 'email')
            ->from('users')
            ->where('deleted', true)
    )
    ->execute();

// Upsert / ON CONFLICT
$db->insert('users')
    ->row(['email' => 'john@ex.com', 'name' => 'John', 'login_count' => 1])
    ->onConflict(['email'])
    ->doUpdate(['name', 'login_count'])  // columns to update
    ->execute();
// MySQL: INSERT INTO users (...) VALUES (...) ON DUPLICATE KEY UPDATE name=VALUES(name), login_count=VALUES(login_count)
// PG/SQLite: INSERT INTO users (...) VALUES (...) ON CONFLICT (email) DO UPDATE SET name=EXCLUDED.name, login_count=EXCLUDED.login_count
```

### UPDATE Patterns

```php
// Simple update
$db->update('users')
    ->set('name', 'John Doe')
    ->set('updated_at', Raw::now())
    ->where('id', 42)
    ->execute();

// Conditional update (increment)
$db->update('posts')
    ->set('view_count', new Raw('view_count + 1'))
    ->where('id', $postId)
    ->execute();

// Bulk update with CASE
$db->update('users')
    ->setRaw('rank = CASE id WHEN 1 THEN :r1 WHEN 2 THEN :r2 END', [
        'r1' => 'admin', 'r2' => 'moderator'
    ])
    ->whereIn('id', [1, 2])
    ->execute();

// Update with JOIN (engine-specific compilation)
$db->update('posts', 'p')
    ->join('users', 'u', 'u.id = p.author_id')
    ->set('p.author_name', new Raw('u.display_name'))
    ->where('u.name_changed', true)
    ->execute();
```

### DELETE Patterns

```php
// Simple delete
$db->delete('users')
    ->where('id', 42)
    ->execute();

// Delete with subquery
$db->delete('sessions')
    ->where('user_id', Operator::In,
        $db->select('id')->from('users')->where('banned', true)
    )
    ->execute();

// Truncate (separate method, not query builder)
$db->truncate('temp_data');

// Delete with LIMIT (MySQL-specific, compiled conditionally)
$db->delete('logs')
    ->where('created_at', '<', $cutoff)
    ->limit(1000)
    ->execute();
```

---

## 7. Subquery & Advanced Features

### Subquery Patterns

```php
// Subquery in WHERE (IN clause)
$query->where('user_id', Operator::In,
    $db->select('id')->from('users')->where('active', true)
);
// WHERE user_id IN (SELECT id FROM users WHERE active = 1)

// Subquery in FROM (derived table)
$sub = $db->select('user_id', new Raw('COUNT(*) as post_count'))
    ->from('posts')
    ->groupBy('user_id');

$query->from($sub, 'post_stats')
    ->where('post_stats.post_count', '>', 10);
// SELECT * FROM (SELECT user_id, COUNT(*) as post_count FROM posts GROUP BY user_id) AS post_stats WHERE post_stats.post_count > 10

// Subquery in SELECT (scalar subquery)
$query->select('u.*')
    ->selectSub(
        $db->select(new Raw('COUNT(*)'))->from('posts', 'p')->whereRaw('p.user_id = u.id'),
        'post_count'
    )
    ->from('users', 'u');
// SELECT u.*, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS post_count FROM users u

// EXISTS subquery
$query->whereExists(
    $db->select(new Raw('1'))
        ->from('subscriptions', 's')
        ->whereRaw('s.user_id = u.id')
        ->where('s.active', true)
);
```

### JOIN Patterns

```php
// Inner join
$query->join('posts', 'p', 'p.user_id = u.id');

// Left join with complex condition
$query->leftJoin('subscriptions', 's', function (JoinCondition $on) {
    $on->on('s.user_id', '=', 'u.id')
       ->on('s.active', '=', true);
});

// Self-join
$query->from('categories', 'c')
    ->leftJoin('categories', 'parent', 'parent.id = c.parent_id');

// Cross join
$query->from('colors')->crossJoin('sizes');

// Lateral join (PG-specific, compiled conditionally)
$query->joinLateral(
    $db->select('*')->from('posts')->whereRaw('posts.user_id = u.id')->limit(3),
    'recent_posts'
);
```

### Aggregate & Grouping

```php
// Aggregates as terminal operations
$count = $query->from('users')->where('active', true)->count();
$max   = $query->from('users')->max('age');
$avg   = $query->from('orders')->avg('total');
$sum   = $query->from('orders')->where('status', 'completed')->sum('total');

// Group by with having
$query->select('role', new Raw('COUNT(*) as cnt'))
    ->from('users')
    ->groupBy('role')
    ->having('cnt', '>', 5);

// Window functions (raw for now, typed API possible later)
$query->select('*', new Raw('ROW_NUMBER() OVER (PARTITION BY department_id ORDER BY salary DESC) as rank'))
    ->from('employees');
```

### UNION Patterns

```php
$admins = $db->select('id', 'name', new Raw("'admin' as source"))
    ->from('admins');

$users = $db->select('id', 'name', new Raw("'user' as source"))
    ->from('users');

$combined = $admins->union($users)->orderBy('name');
$combinedAll = $admins->unionAll($users);
```

---

## 8. Performance Patterns

### Prepared Statement Caching

```php
// Laravel approach: connection-level statement cache
// Doctrine approach: explicit prepare + execute separation
// Recommended: auto-cache by SQL hash

class Connection {
    private array $statementCache = [];
    
    public function execute(CompiledQuery $compiled): Result {
        $hash = md5($compiled->sql);
        
        if (!isset($this->statementCache[$hash])) {
            $this->statementCache[$hash] = $this->pdo->prepare($compiled->sql);
        }
        
        $stmt = $this->statementCache[$hash];
        $stmt->execute($compiled->bindings);
        return new Result($stmt);
    }
}
```

### Lazy Execution

```php
// Query is not executed until terminal method called
$query = $db->select('*')->from('users')->where('active', true);
// No SQL executed yet

// Terminal methods trigger execution:
$rows = $query->fetchAll();       // Execute + fetch all
$row = $query->fetchOne();        // Execute + fetch first
$col = $query->fetchColumn('id'); // Execute + fetch single column
$sql = $query->toSQL();           // Compile without executing (for debugging)

// Inspection without execution
echo $query->toSQL();    // SELECT * FROM users WHERE active = ?
echo $query->explain();  // EXPLAIN SELECT * FROM users WHERE active = ?
```

### Streaming / Chunked Results

```php
// Generator-based iteration (memory efficient)
foreach ($query->cursor() as $row) {
    // Fetches one row at a time via PDO cursor
    process($row);
}

// Chunk processing (for batch operations)
$query->chunk(500, function (array $rows): bool {
    foreach ($rows as $row) {
        process($row);
    }
    return true; // continue; false = stop
});

// Chunk by ID (avoids OFFSET performance issue)
$query->chunkById(500, function (array $rows) {
    // Uses WHERE id > $lastId LIMIT 500
    // Much faster than OFFSET for large tables
}, 'id');
```

### Query Profiling

```php
// Built-in explain support
$plan = $query->explain(); // Returns EXPLAIN output

// Query logging for development
$db->enableQueryLog();
// ... run queries ...
$log = $db->getQueryLog(); // [{sql, bindings, time}, ...]
```

---

## 9. Proposed Interface Sketch (PHP 8.2+)

```php
<?php
namespace phpbb\db\query;

use phpbb\db\query\expression\Expression;
use phpbb\db\query\compiler\Compiler;

/**
 * Query factory — entry point for creating query builders.
 */
interface QueryFactory
{
    public function select(string|Raw ...$columns): SelectQuery;
    public function insert(string $table): InsertQuery;
    public function update(string $table, ?string $alias = null): UpdateQuery;
    public function delete(string $table): DeleteQuery;
    public function raw(string $sql, array $bindings = []): Raw;
    public function expr(): ExpressionBuilder;
}

/**
 * SELECT query builder.
 */
interface SelectQuery extends Executable
{
    // Column selection
    public function select(string|Raw ...$columns): static;
    public function addSelect(string|Raw ...$columns): static;
    public function selectSub(SelectQuery $query, string $alias): static;
    public function distinct(): static;
    
    // FROM
    public function from(string|SelectQuery $table, ?string $alias = null): static;
    
    // JOINs
    public function join(string $table, string $alias, string|\Closure $condition): static;
    public function leftJoin(string $table, string $alias, string|\Closure $condition): static;
    public function rightJoin(string $table, string $alias, string|\Closure $condition): static;
    public function crossJoin(string $table, ?string $alias = null): static;
    
    // WHERE conditions
    public function where(string|Expression|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function orWhere(string|Expression|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function whereIn(string $column, array|SelectQuery $values): static;
    public function whereNotIn(string $column, array|SelectQuery $values): static;
    public function whereNull(string $column): static;
    public function whereNotNull(string $column): static;
    public function whereBetween(string $column, mixed $min, mixed $max): static;
    public function whereExists(SelectQuery $subquery): static;
    public function whereRaw(string $sql, array $bindings = []): static;
    
    // GROUP BY / HAVING
    public function groupBy(string ...$columns): static;
    public function having(string $column, mixed $operatorOrValue = null, mixed $value = null): static;
    
    // ORDER BY
    public function orderBy(string $column, string $direction = 'ASC'): static;
    
    // LIMIT / OFFSET
    public function limit(int $limit): static;
    public function offset(int $offset): static;
    
    // UNION
    public function union(SelectQuery $query): static;
    public function unionAll(SelectQuery $query): static;
    
    // Aggregates (terminal)
    public function count(string $column = '*'): int;
    public function max(string $column): mixed;
    public function min(string $column): mixed;
    public function avg(string $column): float;
    public function sum(string $column): int|float;
    
    // Result methods (terminal)
    public function fetchAll(): array;
    public function fetchOne(): ?array;
    public function fetchColumn(string $column): array;
    public function fetchScalar(): mixed;
    
    // Iteration
    /** @return \Generator<array> */
    public function cursor(): \Generator;
    public function chunk(int $size, \Closure $callback): void;
    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void;
}

/**
 * INSERT query builder.
 */
interface InsertQuery extends Executable
{
    public function columns(string ...$columns): static;
    public function values(mixed ...$values): static;
    public function row(array $data): static;
    public function rows(array $rows): static;
    public function fromSelect(SelectQuery $query): static;
    public function onConflict(array $columns): static;
    public function doUpdate(array $columns): static;
    public function doNothing(): static;
}

/**
 * UPDATE query builder.
 */
interface UpdateQuery extends Executable
{
    public function set(string $column, mixed $value): static;
    public function setRaw(string $expression, array $bindings = []): static;
    public function increment(string $column, int|float $amount = 1): static;
    public function decrement(string $column, int|float $amount = 1): static;
    
    // WHERE (same as SelectQuery)
    public function where(string|Expression|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function whereIn(string $column, array|SelectQuery $values): static;
    
    // JOIN for UPDATE (engine-specific)
    public function join(string $table, string $alias, string $condition): static;
    
    public function limit(int $limit): static;
}

/**
 * DELETE query builder.
 */
interface DeleteQuery extends Executable
{
    public function where(string|Expression|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function whereIn(string $column, array|SelectQuery $values): static;
    public function limit(int $limit): static;
}

/**
 * Shared executable behavior.
 */
interface Executable
{
    /** Compile to SQL + bindings without executing */
    public function toSQL(): string;
    public function getBindings(): array;
    
    /** Execute and return affected rows (INSERT/UPDATE/DELETE) or Result (SELECT) */
    public function execute(): int;
}

/**
 * Expression builder for complex conditions.
 */
interface ExpressionBuilder
{
    public function eq(string $column, mixed $value): Expression;
    public function neq(string $column, mixed $value): Expression;
    public function gt(string $column, mixed $value): Expression;
    public function gte(string $column, mixed $value): Expression;
    public function lt(string $column, mixed $value): Expression;
    public function lte(string $column, mixed $value): Expression;
    public function like(string $column, string $pattern): Expression;
    public function in(string $column, array $values): Expression;
    public function notIn(string $column, array $values): Expression;
    public function isNull(string $column): Expression;
    public function isNotNull(string $column): Expression;
    public function between(string $column, mixed $min, mixed $max): Expression;
    public function exists(SelectQuery $subquery): Expression;
    
    public function and(Expression ...$conditions): Expression;
    public function or(Expression ...$conditions): Expression;
    public function not(Expression $condition): Expression;
}

/**
 * Raw SQL fragment with parameter bindings.
 */
final class Raw
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {}
    
    public static function now(): static { return new static('NOW()'); }
    public static function currentTimestamp(): static { return new static('CURRENT_TIMESTAMP'); }
}

/**
 * Compiler interface — one implementation per database engine.
 */
interface Compiler
{
    public function compileSelect(SelectQuery $query): CompiledQuery;
    public function compileInsert(InsertQuery $query): CompiledQuery;
    public function compileUpdate(UpdateQuery $query): CompiledQuery;
    public function compileDelete(DeleteQuery $query): CompiledQuery;
    public function quoteIdentifier(string $identifier): string;
}

/**
 * Result of compilation — SQL string + ordered bindings.
 */
final class CompiledQuery
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
        public readonly array $types = [],
    ) {}
}
```

### Usage Example (How It All Fits Together)

```php
// Get query factory from DI container
$qf = $container->get(QueryFactory::class);

// Simple select
$users = $qf->select('id', 'username', 'email')
    ->from('phpbb_users', 'u')
    ->where('user_type', 0)
    ->where('user_inactive_reason', 0)
    ->orderBy('username')
    ->limit(25)
    ->offset(50)
    ->fetchAll();

// Complex forum query with joins and subqueries
$topics = $qf->select('t.topic_id', 't.topic_title', 'u.username')
    ->selectSub(
        $qf->select($qf->raw('COUNT(*)'))->from('phpbb_posts', 'p')->whereRaw('p.topic_id = t.topic_id'),
        'reply_count'
    )
    ->from('phpbb_topics', 't')
    ->join('phpbb_users', 'u', 'u.user_id = t.topic_poster')
    ->leftJoin('phpbb_forums', 'f', 'f.forum_id = t.forum_id')
    ->where('f.forum_id', $forumId)
    ->where('t.topic_visibility', 1)
    ->where(function (SelectQuery $q) {
        $q->where('t.topic_type', 0)
          ->orWhere('t.topic_type', 1); // Normal or sticky
    })
    ->orderBy('t.topic_type', 'DESC')
    ->orderBy('t.topic_last_post_time', 'DESC')
    ->limit($topicsPerPage)
    ->offset(($page - 1) * $topicsPerPage)
    ->fetchAll();

// Batch insert with upsert
$qf->insert('phpbb_sessions')
    ->row(['session_id' => $sid, 'user_id' => $userId, 'session_time' => time()])
    ->onConflict(['session_id'])
    ->doUpdate(['user_id', 'session_time'])
    ->execute();

// Update with increment
$qf->update('phpbb_topics')
    ->increment('topic_views')
    ->set('topic_last_view_time', time())
    ->where('topic_id', $topicId)
    ->execute();
```

---

## 10. Key Takeaways for phpBB Database Service

### Must-Have Features
1. **Laravel-style where shorthand** — `where('col', $val)` and `where('col', '>', $val)` — most ergonomic
2. **Compiler per engine** — cleanest multi-engine architecture
3. **Automatic parameter binding** — never expose raw value interpolation
4. **Expression builder** — for complex queries that outgrow simple where clauses
5. **Raw escape hatch** — always needed but clearly marked as "you're on your own"
6. **Separate builder classes** — SelectQuery, InsertQuery, etc. for type safety

### Nice-to-Have Features
1. **Cursor/generator iteration** — for phpBB cleanup tasks with large datasets
2. **Chunk by ID** — for efficient batch processing
3. **Query logging** — for debugging (dev mode only)
4. **EXPLAIN integration** — for performance analysis
5. **Upsert support** — for sessions, stats, counters

### phpBB-Specific Considerations
- Table prefix support built into compiler (`phpbb_` prefix configurable)
- Must handle existing `$db->sql_escape()` migration path
- Support phpBB's `$db->sql_in_set()` pattern as `whereIn()`
- Binary column handling for session IDs
- UTF-8 string handling awareness
- Must coexist with legacy `$db->sql_query()` during migration period

# Doctrine DBAL 4 QueryBuilder – Capability Assessment

**DBAL version**: 4.4.3 (installed)
**Constraint in composer.json**: `"doctrine/dbal": "^4.0"`

---

## Entry Point

```php
// via Connection (injected as Doctrine\DBAL\Connection)
$qb = $this->connection->createQueryBuilder();

// sub-query builder (chained)
$sub = $qb->sub(); // returns fresh QueryBuilder for embedding
```

`Connection::createQueryBuilder()` is confirmed at `vendor/doctrine/dbal/src/Connection.php:1379`.

---

## ExpressionBuilder methods (via `$qb->expr()`)

| Method | Description |
|---|---|
| `eq($x, $y)` | `x = y` |
| `neq($x, $y)` | `x <> y` |
| `lt($x, $y)` | `x < y` |
| `lte($x, $y)` | `x <= y` |
| `gt($x, $y)` | `x > y` |
| `gte($x, $y)` | `x >= y` |
| `isNull($x)` | `x IS NULL` |
| `isNotNull($x)` | `x IS NOT NULL` |
| `like($x, $pattern, $escape)` | `x LIKE pattern [ESCAPE e]` |
| `notLike($x, $pattern, $escape)` | `x NOT LIKE pattern` |
| `in($x, $y)` | `x IN (y1, y2, …)` |
| `notIn($x, $y)` | `x NOT IN (y1, y2, …)` |
| `and(...$exprs)` | AND composite |
| `or(...$exprs)` | OR composite |
| `comparison($x, $op, $y)` | arbitrary `x OP y` |
| `literal($input)` | quoted literal (uses `$conn->quote()`) |

No `raw()` or `expr()->raw()` method exists. **Raw SQL is passed directly as strings** to `where()`, `select()`, `set()`, etc.

---

## Capability Matrix

| # | SQL Pattern | QB Method(s) | Portable? | Notes / Example |
|---|---|---|---|---|
| 1 | Simple SELECT with WHERE (`=`, `IN`, `<`, `>`) | `select()`, `from()`, `where()` / `andWhere()`, `expr()->eq/gt/lt/in()` | ✅ Yes | `->where($qb->expr()->eq('u.user_id', ':id'))->setParameter('id', $id)` |
| 2 | SELECT with JOIN (INNER, LEFT) | `innerJoin()` / `leftJoin()` / `rightJoin()` | ✅ Yes | `->innerJoin('fromAlias', 'table', 'alias', 'alias.col = fromAlias.col')` |
| 3 | SELECT with subquery in WHERE | `$qb->sub()` cast to string in `where()` | ✅ Yes | `$sub = $qb->sub()->select('c.id')->from(...)`; `->where($qb->expr()->in('conversation_id', (string) $sub))` |
| 4 | SELECT with GROUP BY + aggregate (`COALESCE`, `SUM`, `COUNT`) | `groupBy()` / `addGroupBy()`, raw string in `select()` | ✅ Yes | `->select('COALESCE(SUM(filesize), 0)')` – aggregate functions passed as plain strings |
| 5 | INSERT (single row) | `insert()`, `values(['col' => ':param'])`, `setValue()`, `setParameter()` | ✅ Yes | `->insert('table')->values(['col' => ':v'])->setParameter('v', $val)` |
| 6 | INSERT with `ON DUPLICATE KEY UPDATE` (MySQL only) | ❌ No native QB support | ❌ No | Must stay as raw `connection->executeStatement($sql, $params)` |
| 7 | UPDATE with `GREATEST(0, x - n)` (MySQL only) | `update()`, `set('col', 'GREATEST(0, col - :n)')` | ⚠️ MySQL only | QB supports arbitrary expressions in `set()` as plain strings – function call is raw passthrough |
| 8 | UPDATE with affectedRows check | `update()->executeStatement()` returns `int\|string` row count | ✅ Yes | `$affected = $qb->update(...)->set(...)->where(...)->executeStatement(); return $affected > 0;` |
| 9 | DELETE with WHERE | `delete()`, `where()` / `andWhere()` | ✅ Yes | `->delete('table')->where($qb->expr()->eq('id', ':id'))` |
| 10 | `HEX(id)` / `UNHEX(:id)` in SELECT / WHERE | Raw string in `select()` / `where()` | ❌ MySQL only | `->select('HEX(id) AS id')`, `->where('id = UNHEX(:id)')` – raw functions as strings, no QB helper |
| 11 | Dynamic IN clause with array of IDs | `expr()->in('col', ':ids')` + `setParameter('ids', $arr, ArrayParameterType::INTEGER)` | ✅ Yes | DBAL 4 expands the array automatically; supports `INTEGER`, `STRING`, `BINARY`, `ASCII` variants |
| 12 | LIMIT + OFFSET pagination | `setMaxResults(int)`, `setFirstResult(int)` | ✅ Yes | `->setMaxResults($perPage)->setFirstResult(($page - 1) * $perPage)` |
| 13 | Raw expression passthrough (dialect-specific calls) | Pass plain PHP string to `where()`, `select()`, `set()`, `having()` | ⚠️ Dialect-locked | No `expr()->raw()` method; simply pass a string: `->where('YEAR(created_at) = :y')` |

---

## ArrayParameterType enum (DBAL 4)

Located at `vendor/doctrine/dbal/src/ArrayParameterType.php`:

```php
enum ArrayParameterType {
    case INTEGER;  // array of ints
    case STRING;   // array of strings
    case ASCII;    // array of ASCII strings
    case BINARY;   // array of binary strings
}
```

Usage for dynamic IN:

```php
$qb->select('*')
   ->from('phpbb_users')
   ->where($qb->expr()->in('user_id', ':ids'))
   ->setParameter('ids', [1, 2, 3], ArrayParameterType::INTEGER);
```

DBAL internally expands `:ids` into positional placeholders on execution.

---

## Patterns Requiring Raw SQL Passthrough

### 1. `INSERT … ON DUPLICATE KEY UPDATE` (MySQL-only)

QB has no `onConflict()` or `onDuplicateKeyUpdate()` method in DBAL 4. The generated `getSQLForInsert()` is a plain `INSERT INTO … VALUES(…)`.

**Workaround**: keep as raw `connection->executeStatement()`:

```php
$this->connection->executeStatement(
    'INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending)
     VALUES (:groupId, :userId, :isLeader, :pending)
     ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0',
    ['groupId' => $groupId, 'userId' => $userId, 'isLeader' => $isLeader, 'pending' => $pending],
);
```

**Affected file**: `src/phpbb/user/Repository/DbalGroupRepository.php:103`

---

### 2. `HEX()` / `UNHEX()` column functions (MySQL binary columns)

QB has no typed binary-column helpers. These MySQL functions must be inlined as raw strings.

**Workaround**: use raw strings in `select()` and `where()` – this is valid in QB:

```php
$qb->select('HEX(id) AS id', 'asset_type', 'HEX(parent_id) AS parent_id', '...')
   ->from(self::TABLE)
   ->where('id = UNHEX(:id)')
   ->setParameter('id', $id);
```

**Affected file**: `src/phpbb/storage/Repository/DbalStoredFileRepository.php:40–45`

---

### 3. `GREATEST(0, col - :n)` in UPDATE SET (MySQL-only)

QB supports arbitrary expressions in `set()`, so the QB can be used here – but the SQL generated is not portable. Mark as "QB-compatible, MySQL-locked":

```php
$qb->update(self::TABLE)
   ->set('used_bytes', 'GREATEST(0, used_bytes - :bytes)')
   ->set('updated_at', ':now')
   ->where($qb->expr()->eq('uploader_id', ':uid'))
   ->setParameter('bytes', $bytes)
   ->setParameter('now', $now)
   ->setParameter('uid', $uid);
```

**Affected file**: `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php:78`

---

## Additional QB Features Available in DBAL 4.4

| Feature | Method | Notes |
|---|---|---|
| SELECT DISTINCT | `distinct()` | `->distinct()->select(...)` |
| UNION / UNION ALL | `union()`, `addUnion()` | Separate query type |
| Common Table Expressions (WITH) | `with('name', $subQb)` | Supported |
| FOR UPDATE | `forUpdate()` | With `ConflictResolutionMode` |
| Result caching | `enableResultCache(QueryCacheProfile)` | Optional |
| Named parameters | `createNamedParameter($val)` | Auto-generates `:dcValueN` |
| Positional parameters | `createPositionalParameter($val)` | Auto-generates `?` |
| Fetch shortcuts | `fetchAssociative()`, `fetchOne()`, `fetchAllAssociative()`, etc. | Chainable on QB directly |
| Subquery builder | `$qb->sub()` | Returns fresh QB; cast to string for embedding |

---

## Summary

- **10 of 13** SQL patterns used in this codebase can be migrated to QueryBuilder directly.
- **2 patterns** must remain as raw `connection->executeStatement()` strings because they use MySQL-specific syntax with no QB equivalent: `ON DUPLICATE KEY UPDATE` and binary `HEX()`/`UNHEX()` calls (though `HEX`/`UNHEX` *can* be inlined into QB string positions — the semantics are MySQL-only but QB won't object).
- **1 pattern** (`GREATEST`) is QB-compatible but MySQL-locked by the SQL expression itself.
- There is **no `expr()->raw()` method** in DBAL 4 `ExpressionBuilder`. The idiom is to pass plain PHP strings directly to `where()`, `select()`, `set()`, and `having()`.

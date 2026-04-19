# Multi-Engine Type Mapping & SQL Dialect Reference

## 1. Abstract Type Mapping Table

### Core Types

| Abstract Type | MySQL 8+ | PostgreSQL 14+ | SQLite | Notes |
|---|---|---|---|---|
| `smallint` | SMALLINT | SMALLINT | INTEGER | SQLite has no distinct small int |
| `integer` | INT | INTEGER | INTEGER | All 32-bit signed |
| `bigint` | BIGINT | BIGINT | INTEGER | SQLite stores as 64-bit internally |
| `uint` | INT UNSIGNED | INTEGER CHECK(col >= 0) | INTEGER | PG/SQLite need CHECK constraint |
| `ubigint` | BIGINT UNSIGNED | BIGINT CHECK(col >= 0) | INTEGER | PG/SQLite need CHECK constraint |
| `bool` | TINYINT(1) | BOOLEAN | INTEGER | MySQL has no native bool; use 0/1 |
| `string(N)` | VARCHAR(N) | VARCHAR(N) | TEXT | SQLite ignores length constraint |
| `char(N)` | CHAR(N) | CHAR(N) | TEXT | SQLite treats as TEXT |
| `text` | MEDIUMTEXT | TEXT | TEXT | MySQL MEDIUMTEXT = 16MB |
| `longtext` | LONGTEXT | TEXT | TEXT | MySQL LONGTEXT = 4GB; PG TEXT unlimited |
| `decimal(P,S)` | DECIMAL(P,S) | NUMERIC(P,S) | REAL | SQLite has no exact decimal; REAL is float |
| `float` | FLOAT | REAL | REAL | Single precision |
| `double` | DOUBLE | DOUBLE PRECISION | REAL | SQLite REAL is always 8-byte float |
| `json` | JSON | JSONB | TEXT | SQLite: text + json functions |
| `blob` | LONGBLOB | BYTEA | BLOB | |
| `binary(N)` | BINARY(N) | BYTEA | BLOB | PG BYTEA is variable-length |
| `timestamp` | INT UNSIGNED | INTEGER | INTEGER | Unix timestamp as integer (portable) |
| `datetime` | DATETIME | TIMESTAMP | TEXT | Native datetime; SQLite uses ISO-8601 text |
| `date` | DATE | DATE | TEXT | SQLite: ISO-8601 text |
| `uuid` | CHAR(36) | UUID | TEXT | Or BINARY(16) in MySQL for performance |
| `enum(values)` | ENUM('a','b','c') | VARCHAR(N) CHECK(col IN (...)) | TEXT CHECK(col IN (...)) | MySQL ENUM is non-standard |
| `serial` | BIGINT UNSIGNED AUTO_INCREMENT | BIGSERIAL | INTEGER PRIMARY KEY | See auto-increment section |

### Edge Cases & Limitations

#### SQLite Type Affinity
- SQLite uses **type affinity** not strict types. Any column can store any type.
- `INTEGER PRIMARY KEY` is special: becomes the rowid alias (auto-increment behavior).
- Length constraints (VARCHAR(255)) are parsed but **not enforced**.
- `STRICT` tables (SQLite 3.37+) enforce types: INTEGER, REAL, TEXT, BLOB, ANY.

#### MySQL UNSIGNED
- PostgreSQL and SQLite have no native UNSIGNED types.
- Workaround: CHECK constraint `CHECK(col >= 0)` — but this doesn't change storage size.
- MySQL UNSIGNED INT: 0 to 4,294,967,295 vs signed INT: -2,147,483,648 to 2,147,483,647.
- For abstraction: use BIGINT on PG/SQLite when MySQL uses UNSIGNED INT to avoid overflow.

#### Boolean Handling
- MySQL: `TINYINT(1)` — stores 0 or 1, `TRUE`/`FALSE` are aliases for 1/0.
- PostgreSQL: native `BOOLEAN` — stores `true`/`false`.
- SQLite: `INTEGER` — 0 or 1.
- Abstraction must normalize to 0/1 integers or true/false depending on engine.

#### Text/String Length
- MySQL VARCHAR max: 65,535 bytes (row-level limit shared across columns).
- PostgreSQL VARCHAR: up to 1GB (effectively unlimited).
- SQLite: no length enforcement, TEXT is unlimited.
- For abstraction: enforce length limits in application layer for SQLite.

#### DECIMAL Precision
- MySQL DECIMAL(P,S): exact, P up to 65 digits.
- PostgreSQL NUMERIC(P,S): exact, P up to 1000 digits.
- SQLite REAL: 8-byte IEEE 754 float — **not exact**. Cannot represent monetary values precisely.
- Workaround for SQLite: store as INTEGER (cents) or TEXT.

#### JSON Storage
- MySQL JSON: validated on insert, stored in binary format internally.
- PostgreSQL JSONB: binary storage, supports indexing, most powerful JSON support.
- SQLite: plain TEXT column; `json_valid()` function available (3.38+) but not enforced on insert.

---

## 2. DDL Syntax Comparison

### CREATE TABLE

```sql
-- MySQL 8+
CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id),
    UNIQUE KEY idx_username (username),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PostgreSQL 14+
CREATE TABLE users (
    user_id BIGINT GENERATED ALWAYS AS IDENTITY,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    created_at INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
);
CREATE UNIQUE INDEX idx_username ON users (username);
CREATE INDEX idx_email ON users (email);

-- SQLite
CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    email TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX idx_username ON users (username);
CREATE INDEX idx_email ON users (email);
```

**Key Differences:**
| Feature | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| Inline index in CREATE TABLE | Yes (KEY/INDEX) | No | No |
| Engine specification | `ENGINE=InnoDB` | N/A | N/A |
| Charset/collation in DDL | Yes | No (set at DB level) | No |
| IF NOT EXISTS | Yes | Yes | Yes |

---

### ALTER TABLE ADD COLUMN

```sql
-- MySQL 8+
ALTER TABLE users ADD COLUMN bio MEDIUMTEXT DEFAULT NULL AFTER email;
ALTER TABLE users ADD COLUMN age INT UNSIGNED DEFAULT NULL;

-- PostgreSQL 14+
ALTER TABLE users ADD COLUMN bio TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN age INTEGER DEFAULT NULL CHECK(age >= 0);
-- No AFTER/FIRST positioning in PostgreSQL

-- SQLite (3.2.0+)
ALTER TABLE users ADD COLUMN bio TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN age INTEGER DEFAULT NULL;
```

**Limitations:**
| Feature | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| AFTER/FIRST positioning | Yes | No | No |
| Add with NOT NULL (no default) | Yes | Yes | **No** (requires DEFAULT or NULL) |
| Add multiple columns in one ALTER | Yes | Yes (multiple ADD COLUMN) | No (one at a time) |

---

### ALTER TABLE DROP COLUMN

```sql
-- MySQL 8+
ALTER TABLE users DROP COLUMN bio;

-- PostgreSQL 14+
ALTER TABLE users DROP COLUMN bio;
ALTER TABLE users DROP COLUMN bio CASCADE; -- drops dependent objects

-- SQLite (3.35.0+, 2021-03-12)
ALTER TABLE users DROP COLUMN bio;
```

**Limitations:**
| Feature | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| Drop column | Yes | Yes | Yes (3.35+) |
| CASCADE option | No | Yes | No |
| Drop if column is in index | Must drop index first | CASCADE handles | Must rebuild table |
| Drop PRIMARY KEY column | No | No | No |

**SQLite < 3.35.0:** Requires full table rebuild:
```sql
-- Table rebuild pattern for old SQLite
CREATE TABLE users_new AS SELECT user_id, username, email FROM users;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
-- Then recreate indexes
```

---

### ALTER TABLE MODIFY/ALTER COLUMN (Type Change)

```sql
-- MySQL 8+
ALTER TABLE users MODIFY COLUMN username VARCHAR(500) NOT NULL;
ALTER TABLE users CHANGE COLUMN old_name new_name VARCHAR(255); -- rename + change type

-- PostgreSQL 14+
ALTER TABLE users ALTER COLUMN username TYPE VARCHAR(500);
ALTER TABLE users ALTER COLUMN username SET NOT NULL;
ALTER TABLE users ALTER COLUMN username DROP NOT NULL;
ALTER TABLE users ALTER COLUMN username SET DEFAULT 'unknown';

-- SQLite
-- ❌ NOT SUPPORTED — requires full table rebuild
```

**SQLite Table Rebuild Pattern:**
```sql
BEGIN TRANSACTION;
CREATE TABLE users_new (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,  -- changed type/constraints here
    email TEXT NOT NULL
);
INSERT INTO users_new SELECT user_id, username, email FROM users;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
-- Recreate all indexes
COMMIT;
```

**Comparison:**
| Operation | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| Change column type | `MODIFY COLUMN` | `ALTER COLUMN ... TYPE` | Table rebuild |
| Add NOT NULL | `MODIFY COLUMN` | `ALTER COLUMN ... SET NOT NULL` | Table rebuild |
| Drop NOT NULL | `MODIFY COLUMN` | `ALTER COLUMN ... DROP NOT NULL` | Table rebuild |
| Change default | `ALTER COLUMN ... SET DEFAULT` | `ALTER COLUMN ... SET DEFAULT` | Table rebuild |
| Drop default | `ALTER COLUMN ... DROP DEFAULT` | `ALTER COLUMN ... DROP DEFAULT` | Table rebuild |

---

### CREATE INDEX / DROP INDEX

```sql
-- MySQL 8+
CREATE INDEX idx_email ON users (email);
CREATE UNIQUE INDEX idx_username ON users (username);
CREATE INDEX idx_name_email ON users (username, email);  -- composite
CREATE INDEX idx_json ON users ((CAST(data->>'$.age' AS UNSIGNED)));  -- expression/functional
DROP INDEX idx_email ON users;  -- requires table name

-- PostgreSQL 14+
CREATE INDEX idx_email ON users (email);
CREATE UNIQUE INDEX idx_username ON users (username);
CREATE INDEX idx_name_email ON users (username, email);
CREATE INDEX idx_json ON users USING GIN (data);  -- GIN for JSONB
CREATE INDEX CONCURRENTLY idx_email ON users (email);  -- non-blocking
DROP INDEX idx_email;  -- no table name needed

-- SQLite
CREATE INDEX idx_email ON users (email);
CREATE UNIQUE INDEX idx_username ON users (username);
CREATE INDEX idx_name_email ON users (username, email);
-- No functional/expression indexes (until 3.9.0 for indexed expressions)
DROP INDEX idx_email;  -- no table name needed
```

**Comparison:**
| Feature | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| IF NOT EXISTS | Yes (8.0+) | Yes | Yes |
| Partial index (WHERE) | No | Yes | Yes (3.15+) |
| Expression/functional index | Yes (8.0.13+) | Yes | Yes (3.9.0+) |
| GIN/GiST index types | No | Yes | No |
| CONCURRENTLY (non-locking) | No (uses ALGORITHM=INPLACE) | Yes | N/A (single writer) |
| Index on JSON | Via generated column | GIN on JSONB | No |
| DROP requires table name | Yes | No | No |
| FULLTEXT index | Yes (InnoDB) | Yes (tsvector + GIN) | Yes (FTS5 extension) |

---

### ADD FOREIGN KEY / DROP FOREIGN KEY

```sql
-- MySQL 8+
ALTER TABLE posts ADD CONSTRAINT fk_posts_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE posts DROP FOREIGN KEY fk_posts_user;

-- PostgreSQL 14+
ALTER TABLE posts ADD CONSTRAINT fk_posts_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE posts DROP CONSTRAINT fk_posts_user;

-- SQLite
-- ❌ Cannot add FK after table creation via ALTER TABLE
-- Must be defined in CREATE TABLE:
CREATE TABLE posts (
    post_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE
);
-- FK enforcement must be enabled per connection:
PRAGMA foreign_keys = ON;
```

**Comparison:**
| Feature | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| Add FK via ALTER | Yes | Yes | **No** |
| Drop FK via ALTER | `DROP FOREIGN KEY` | `DROP CONSTRAINT` | **No** |
| FK enforcement | Always on (InnoDB) | Always on | **Off by default** (PRAGMA) |
| Deferred constraints | No | Yes (`DEFERRABLE`) | Yes (`DEFERRABLE`) |
| Self-referencing FK | Yes | Yes | Yes |

---

### RENAME TABLE / RENAME COLUMN

```sql
-- MySQL 8+
RENAME TABLE old_name TO new_name;
ALTER TABLE users RENAME COLUMN old_col TO new_col;  -- 8.0+

-- PostgreSQL 14+
ALTER TABLE old_name RENAME TO new_name;
ALTER TABLE users RENAME COLUMN old_col TO new_col;

-- SQLite (3.25.0+ for RENAME COLUMN)
ALTER TABLE old_name RENAME TO new_name;
ALTER TABLE users RENAME COLUMN old_col TO new_col;  -- 3.25.0+
```

**Comparison:**
| Feature | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| Rename table | `RENAME TABLE` or `ALTER TABLE ... RENAME TO` | `ALTER TABLE ... RENAME TO` | `ALTER TABLE ... RENAME TO` |
| Rename column | `ALTER TABLE ... RENAME COLUMN` (8.0+) | `ALTER TABLE ... RENAME COLUMN` | `ALTER TABLE ... RENAME COLUMN` (3.25+) |
| Updates FK references on rename | No | Yes | Yes (if FK enabled) |

---

## 3. Schema Introspection Queries

### List All Tables

```sql
-- MySQL 8+
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME;

-- Or simpler:
SHOW TABLES;

-- PostgreSQL 14+
SELECT tablename
FROM pg_catalog.pg_tables
WHERE schemaname = 'public'
ORDER BY tablename;

-- Or via information_schema:
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_type = 'BASE TABLE'
ORDER BY table_name;

-- SQLite
SELECT name
FROM sqlite_master
WHERE type = 'table'
  AND name NOT LIKE 'sqlite_%'
ORDER BY name;
```

---

### Get Column Definitions

```sql
-- MySQL 8+
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    COLUMN_TYPE,          -- includes length, unsigned, etc.
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY,           -- PRI, UNI, MUL
    EXTRA,                -- auto_increment, etc.
    CHARACTER_MAXIMUM_LENGTH,
    NUMERIC_PRECISION,
    NUMERIC_SCALE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;

-- Or simpler:
SHOW FULL COLUMNS FROM users;
DESCRIBE users;

-- PostgreSQL 14+
SELECT
    column_name,
    data_type,
    udt_name,                    -- underlying type name
    is_nullable,
    column_default,
    character_maximum_length,
    numeric_precision,
    numeric_scale,
    identity_generation          -- ALWAYS or BY DEFAULT (for GENERATED)
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'users'
ORDER BY ordinal_position;

-- More detailed via pg_catalog:
SELECT
    a.attname AS column_name,
    pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type,
    NOT a.attnotnull AS is_nullable,
    pg_get_expr(d.adbin, d.adrelid) AS column_default,
    a.attnum AS ordinal_position
FROM pg_catalog.pg_attribute a
LEFT JOIN pg_catalog.pg_attrdef d ON (a.attrelid = d.adrelid AND a.attnum = d.adnum)
WHERE a.attrelid = 'users'::regclass
  AND a.attnum > 0
  AND NOT a.attisdropped
ORDER BY a.attnum;

-- SQLite
PRAGMA table_info('users');
-- Returns: cid, name, type, notnull, dflt_value, pk

-- More detailed (SQLite 3.26+):
PRAGMA table_xinfo('users');
-- Returns: cid, name, type, notnull, dflt_value, pk, hidden
```

---

### Get Indexes

```sql
-- MySQL 8+
SELECT
    INDEX_NAME,
    COLUMN_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    INDEX_TYPE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

-- Or simpler:
SHOW INDEX FROM users;

-- PostgreSQL 14+
SELECT
    i.relname AS index_name,
    a.attname AS column_name,
    ix.indisunique AS is_unique,
    ix.indisprimary AS is_primary,
    am.amname AS index_type,
    pg_get_indexdef(ix.indexrelid) AS index_definition
FROM pg_catalog.pg_index ix
JOIN pg_catalog.pg_class t ON t.oid = ix.indrelid
JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid
JOIN pg_catalog.pg_am am ON am.oid = i.relam
JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
WHERE t.relname = 'users'
ORDER BY i.relname;

-- Simpler via pg_indexes:
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'users';

-- SQLite
PRAGMA index_list('users');
-- Returns: seq, name, unique, origin, partial

-- Get columns in an index:
PRAGMA index_info('idx_username');
-- Returns: seqno, cid, name
```

---

### Get Foreign Keys

```sql
-- MySQL 8+
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'posts'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- With actions:
SELECT
    rc.CONSTRAINT_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
  ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
  AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
  AND rc.TABLE_NAME = 'posts';

-- PostgreSQL 14+
SELECT
    tc.constraint_name,
    kcu.column_name,
    ccu.table_name AS referenced_table,
    ccu.column_name AS referenced_column,
    rc.update_rule,
    rc.delete_rule
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
  ON tc.constraint_name = kcu.constraint_name
JOIN information_schema.constraint_column_usage ccu
  ON tc.constraint_name = ccu.constraint_name
JOIN information_schema.referential_constraints rc
  ON tc.constraint_name = rc.constraint_name
WHERE tc.table_name = 'posts'
  AND tc.constraint_type = 'FOREIGN KEY';

-- SQLite
PRAGMA foreign_key_list('posts');
-- Returns: id, seq, table, from, to, on_update, on_delete, match
```

---

### Get Table Creation DDL

```sql
-- MySQL 8+
SHOW CREATE TABLE users;
-- Returns complete CREATE TABLE statement with indexes, FKs, engine, charset

-- PostgreSQL 14+
-- No single command. Use pg_dump or reconstruct from catalog:
-- pg_dump -t users --schema-only dbname
-- Or use function:
SELECT
    'CREATE TABLE ' || relname || E'\n(\n' ||
    array_to_string(
        array_agg('    ' || column_name || ' ' || data_type || 
            CASE WHEN is_nullable = 'NO' THEN ' NOT NULL' ELSE '' END ||
            CASE WHEN column_default IS NOT NULL THEN ' DEFAULT ' || column_default ELSE '' END
        ),
        E',\n'
    ) || E'\n);'
FROM information_schema.columns
WHERE table_name = 'users'
  AND table_schema = 'public'
GROUP BY relname;
-- (Simplified — real implementation needs pg_catalog for full fidelity)

-- SQLite
SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users';
-- Returns the original CREATE TABLE statement
```

---

## 4. Engine Limitations Matrix

### ALTER TABLE Capabilities

| Operation | MySQL 8+ | PostgreSQL 14+ | SQLite |
|---|---|---|---|
| ADD COLUMN | ✅ | ✅ | ✅ (with restrictions) |
| DROP COLUMN | ✅ | ✅ | ✅ (3.35+) |
| RENAME COLUMN | ✅ | ✅ | ✅ (3.25+) |
| MODIFY COLUMN type | ✅ | ✅ | ❌ (table rebuild) |
| ADD NOT NULL | ✅ | ✅ | ❌ (table rebuild) |
| DROP NOT NULL | ✅ | ✅ | ❌ (table rebuild) |
| SET DEFAULT | ✅ | ✅ | ❌ (table rebuild) |
| ADD CONSTRAINT | ✅ | ✅ | ❌ (table rebuild) |
| DROP CONSTRAINT | ✅ | ✅ | ❌ (table rebuild) |
| ADD FOREIGN KEY | ✅ | ✅ | ❌ (must be in CREATE TABLE) |
| RENAME TABLE | ✅ | ✅ | ✅ |
| Change column order | ✅ (AFTER/FIRST) | ❌ | ❌ |

### Data Type Limitations

| Feature | MySQL 8+ | PostgreSQL 14+ | SQLite |
|---|---|---|---|
| UNSIGNED integers | ✅ Native | ❌ (CHECK constraint) | ❌ (CHECK constraint) |
| Native BOOLEAN | ❌ (TINYINT(1)) | ✅ | ❌ (INTEGER) |
| ENUM type | ✅ Native | ❌ (CHECK constraint) | ❌ (CHECK constraint) |
| Arrays | ❌ | ✅ (ARRAY type) | ❌ |
| UUID type | ❌ (CHAR/BINARY) | ✅ Native | ❌ (TEXT) |
| Native JSON type | ✅ | ✅ (JSONB) | ❌ (TEXT + functions) |
| NUMERIC exact precision | ✅ | ✅ | ❌ (REAL only) |
| BINARY fixed-length | ✅ | ❌ (BYTEA is variable) | ❌ (BLOB) |

### Operational Limitations

| Feature | MySQL 8+ | PostgreSQL 14+ | SQLite |
|---|---|---|---|
| Concurrent DDL | Limited (InnoDB online DDL) | ✅ (CONCURRENTLY) | ❌ (single writer) |
| Transactional DDL | ❌ (implicit commit) | ✅ | ✅ |
| Schema-qualified tables | Database.Table | Schema.Table | N/A |
| Max columns per table | 4096 | 1600 | 2000 |
| Max row size | 65,535 bytes | 1.6 TB | 1 GB (practical) |
| Full-text search | Built-in (InnoDB) | Built-in (tsvector) | FTS5 extension |
| Partial indexes | ❌ | ✅ | ✅ (3.15+) |
| Generated/computed columns | ✅ (STORED/VIRTUAL) | ✅ (STORED only) | ✅ (STORED/VIRTUAL, 3.31+) |

### Transaction & Locking

| Feature | MySQL 8+ | PostgreSQL 14+ | SQLite |
|---|---|---|---|
| DDL in transactions | ❌ (auto-commits) | ✅ (rollback DDL) | ✅ (rollback DDL) |
| Table-level locks for DDL | Yes (metadata lock) | Yes (ACCESS EXCLUSIVE) | File-level lock |
| Online index creation | Yes (ALGORITHM=INPLACE) | Yes (CONCURRENTLY) | N/A |
| Concurrent writers | Yes (row-level locking) | Yes (MVCC) | ❌ (WAL helps readers) |

---

## 5. JSON Support Comparison

### Type & Storage

| Feature | MySQL 8+ | PostgreSQL 14+ | SQLite |
|---|---|---|---|
| Column type | `JSON` | `JSON` or `JSONB` | `TEXT` |
| Storage format | Binary (internal) | Binary (JSONB) or text (JSON) | Plain text |
| Validation on insert | ✅ | ✅ | ❌ (unless CHECK + json_valid) |
| Preserves key order | No | JSON: Yes, JSONB: No | Yes (it's text) |
| Duplicate keys | Last wins | JSON: preserved, JSONB: last wins | N/A |

### Query Operators

| Operation | MySQL 8+ | PostgreSQL 14+ | SQLite (3.38+) |
|---|---|---|---|
| Extract value (text) | `col->>'$.key'` | `col->>'key'` | `col->>'$.key'` |
| Extract value (json) | `col->'$.key'` | `col->'key'` | `col->'$.key'` |
| Nested path | `col->>'$.a.b.c'` | `col->'a'->'b'->>'c'` | `col->>'$.a.b.c'` |
| Array element | `col->>'$[0]'` | `col->>0` | `col->>'$[0]'` |
| Contains | `JSON_CONTAINS(col, '"val"', '$.key')` | `col @> '{"key":"val"}'` | No operator |
| Key exists | `JSON_CONTAINS_PATH(col, 'one', '$.key')` | `col ? 'key'` | `json_type(col, '$.key') IS NOT NULL` |
| Set value | `JSON_SET(col, '$.key', val)` | `jsonb_set(col, '{key}', '"val"')` | `json_set(col, '$.key', val)` |
| Remove key | `JSON_REMOVE(col, '$.key')` | `col - 'key'` | `json_remove(col, '$.key')` |
| Array append | `JSON_ARRAY_APPEND(col, '$', val)` | `col \|\| '["val"]'` | `json_insert(col, '$[#]', val)` |
| Aggregate to array | `JSON_ARRAYAGG(col)` | `json_agg(col)` / `jsonb_agg(col)` | `json_group_array(col)` |
| Aggregate to object | `JSON_OBJECTAGG(k, v)` | `json_object_agg(k, v)` | `json_group_object(k, v)` |

### Indexing JSON

```sql
-- MySQL 8+: Requires generated column for functional index
ALTER TABLE users ADD COLUMN age_gen INT
    GENERATED ALWAYS AS (CAST(data->>'$.age' AS UNSIGNED)) STORED;
CREATE INDEX idx_age ON users (age_gen);

-- Or functional index (MySQL 8.0.13+):
CREATE INDEX idx_age ON users ((CAST(data->>'$.age' AS UNSIGNED)));

-- PostgreSQL 14+: GIN index on entire JSONB column
CREATE INDEX idx_data ON users USING GIN (data);
-- Supports @>, ?, ?|, ?& operators

-- Partial expression index:
CREATE INDEX idx_age ON users ((data->>'age'));
-- Or for integer comparison:
CREATE INDEX idx_age ON users (((data->>'age')::int));

-- SQLite: No native JSON indexing
-- Workaround: generated column (3.31+)
-- Or: maintain a separate indexed column updated via triggers
```

### JSON Functions Availability

| Function | MySQL 8+ | PostgreSQL 14+ | SQLite 3.38+ |
|---|---|---|---|
| `json_valid()` | `JSON_VALID(str)` | N/A (validation on insert) | `json_valid(str)` |
| `json_type()` | `JSON_TYPE(col, path)` | `jsonb_typeof(col)` | `json_type(col, path)` |
| `json_array_length()` | `JSON_LENGTH(col, path)` | `jsonb_array_length(col)` | `json_array_length(col, path)` |
| `json_each()` (table-valued) | `JSON_TABLE(...)` | `jsonb_each(col)` / `jsonb_array_elements()` | `json_each(col)` |
| Path language | JSONPath (`$.key`) | PostgreSQL paths (`{key}` or arrows) | JSONPath (`$.key`) |

---

## 6. Auto-Increment / Sequences Handling

### Syntax Comparison

```sql
-- MySQL 8+
CREATE TABLE items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (id)
);
-- Auto-increment value persists across restarts (InnoDB in 8.0+)
-- Can set starting value: AUTO_INCREMENT=1000

-- PostgreSQL 14+ (Modern: GENERATED)
CREATE TABLE items (
    id BIGINT GENERATED ALWAYS AS IDENTITY,
    PRIMARY KEY (id)
);
-- Or: GENERATED BY DEFAULT AS IDENTITY (allows manual inserts)

-- PostgreSQL (Legacy: SERIAL)
CREATE TABLE items (
    id BIGSERIAL PRIMARY KEY
);
-- SERIAL creates a sequence object behind the scenes

-- SQLite
CREATE TABLE items (
    id INTEGER PRIMARY KEY AUTOINCREMENT
);
-- Without AUTOINCREMENT: reuses deleted rowids
-- With AUTOINCREMENT: monotonically increasing, never reuses
```

### Behavior Differences

| Feature | MySQL 8+ | PostgreSQL 14+ | SQLite |
|---|---|---|---|
| Keyword | `AUTO_INCREMENT` | `GENERATED AS IDENTITY` or `SERIAL` | `AUTOINCREMENT` (optional) |
| Column type requirement | Any integer type | Any integer type | Must be `INTEGER PRIMARY KEY` |
| Multiple auto-inc per table | ❌ (one per table) | ✅ (multiple IDENTITY columns) | ❌ (only INTEGER PRIMARY KEY) |
| Gap-free | ❌ (gaps on rollback/delete) | ❌ (gaps on rollback/delete) | ❌ (gaps on delete) |
| Get last inserted ID | `LAST_INSERT_ID()` | `RETURNING id` or `currval()` | `last_insert_rowid()` |
| Reset counter | `ALTER TABLE t AUTO_INCREMENT=1` | `ALTER SEQUENCE ... RESTART` | Delete from sqlite_sequence |
| Max value behavior | Error | Error | Error |
| Sequence object | Implicit | Explicit (for SERIAL) / Implicit (IDENTITY) | sqlite_sequence table |

### Portable Auto-Increment Pattern

For maximum portability, the abstraction layer should:

```
Abstract: column type = 'serial' or 'bigserial'
→ MySQL:      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
→ PostgreSQL: BIGINT GENERATED ALWAYS AS IDENTITY
→ SQLite:     INTEGER PRIMARY KEY AUTOINCREMENT
```

**Getting the last inserted ID:**
- MySQL: `SELECT LAST_INSERT_ID()`
- PostgreSQL: Use `INSERT ... RETURNING id` (preferred) or `SELECT currval(pg_get_serial_sequence('table', 'col'))`
- SQLite: `SELECT last_insert_rowid()`

---

## 7. Recommended Abstractions

### Where to Abstract (Hide Engine Differences)

| Area | Abstraction Strategy |
|---|---|
| **Type mapping** | Abstract type enum → engine-specific DDL renderer |
| **AUTO_INCREMENT** | Single `serial`/`bigserial` type → engine-specific syntax |
| **Boolean** | Store as integer 0/1; cast to native bool on PG only |
| **UNSIGNED** | Use CHECK constraints on PG/SQLite; document range limitations |
| **VARCHAR length** | Enforce in DDL for MySQL/PG; validate in app for SQLite |
| **ENUM** | Always use CHECK constraints (portable); MySQL can additionally use ENUM |
| **Index creation** | Separate from CREATE TABLE; use `CREATE INDEX` statements for all engines |
| **Foreign keys** | Define in CREATE TABLE for all engines (only portable option for SQLite) |
| **JSON access** | Provide engine-specific expression builders for JSON path access |
| **Schema introspection** | Engine-specific introspector classes behind common interface |
| **Table rebuild (SQLite)** | Detect ALTER limitations and use rebuild pattern automatically |

### Where to Expose Engine Differences (Don't Abstract)

| Area | Reason |
|---|---|
| **GIN indexes (PG)** | Essential for JSONB performance; no MySQL/SQLite equivalent |
| **CONCURRENTLY (PG)** | Important for production; not available on others |
| **ENGINE=InnoDB (MySQL)** | Engine-specific metadata |
| **PRAGMA (SQLite)** | Connection-level settings (foreign_keys, journal_mode) |
| **Partial indexes** | PG/SQLite only; important for performance |
| **Generated columns** | Syntax differs significantly; expose per-engine |
| **Full-text search** | Completely different implementations per engine |
| **Transactional DDL** | MySQL auto-commits DDL; PG/SQLite can rollback |

### Recommended Interface Design

```
AbstractColumn {
    name: string
    type: AbstractType (integer, bigint, string, text, bool, etc.)
    nullable: bool
    default: mixed|null
    unsigned: bool (emulated via CHECK on PG/SQLite)
    length: int|null (for string/char)
    precision: int|null (for decimal)
    scale: int|null (for decimal)
    autoIncrement: bool
}

AbstractIndex {
    name: string
    columns: string[]
    unique: bool
    type: 'btree'|'hash'|'gin'|'fulltext'
}

AbstractForeignKey {
    name: string
    columns: string[]
    referencedTable: string
    referencedColumns: string[]
    onDelete: 'CASCADE'|'SET NULL'|'RESTRICT'|'NO ACTION'
    onUpdate: 'CASCADE'|'SET NULL'|'RESTRICT'|'NO ACTION'
}

AbstractTable {
    name: string
    columns: AbstractColumn[]
    indexes: AbstractIndex[]
    foreignKeys: AbstractForeignKey[]
    primaryKey: string[]
}
```

### Migration Strategy for SQLite Limitations

Since SQLite cannot perform many ALTER TABLE operations, the abstraction layer must implement a **table rebuild** strategy:

```
1. BEGIN TRANSACTION
2. CREATE TABLE temp_<table> (new schema)
3. INSERT INTO temp_<table> SELECT ... FROM <table>
4. DROP TABLE <table>
5. ALTER TABLE temp_<table> RENAME TO <table>
6. Recreate all indexes
7. Recreate all triggers  
8. COMMIT
```

This should be **automatically triggered** when the migration detects:
- Column type change on SQLite
- Adding NOT NULL without default on SQLite
- Adding/dropping constraints on SQLite
- Any ALTER TABLE operation not in the supported set

### Connection Initialization

Each engine needs connection-level setup:

```sql
-- MySQL: Set session variables
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- PostgreSQL: Set search path
SET search_path TO public;
-- Optionally set timezone:
SET timezone TO 'UTC';

-- SQLite: Enable features
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;      -- Better concurrent read performance
PRAGMA busy_timeout = 5000;     -- Wait 5s on lock instead of failing immediately
PRAGMA synchronous = NORMAL;    -- Good balance of safety & speed with WAL
```

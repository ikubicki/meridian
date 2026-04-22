# Doctrine DBAL 4.x — Fact Sheet for phpBB Rewrite (Symfony 8 / PHP 8.2+)

Scope: DBAL-only (no ORM). Stack target: Symfony 8, PHP 8.2+, MariaDB/MySQL prod,
SQLite in tests. Entities are readonly value objects. Latest stable at time of
writing: **4.4.3** (2026-03-20). Sources are cited inline.

---

## 1. Core capabilities

- **QueryBuilder**: fluent SELECT / INSERT / UPDATE / DELETE, plus UNION and
  CTEs via `with()`. Expressions via `expr()->and/or/eq/...`, joins
  (`innerJoin/leftJoin/rightJoin`), `groupBy/having/orderBy`. Pagination via
  `setFirstResult()` / `setMaxResults()`. Subquery helper (`QueryBuilder` accepts
  another builder as selectable/FROM source) — enhanced in 4.4.0.
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/query-builder.html
- **Parameter binding**: `createNamedParameter()` / `createPositionalParameter()`;
  type-aware via `ParameterType::*` enum and `ArrayParameterType::INTEGER|STRING|
  ASCII|BINARY` for `IN (?)` expansion (list binding).
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/data-retrieval-and-manipulation.html
- **Execution / fetch API** on `Doctrine\DBAL\Connection`: `prepare()`,
  `executeQuery()` (returns `Result`), `executeStatement()` (returns affected
  rows, typed `int|string`), plus fetch helpers: `fetchAssociative()`,
  `fetchAllAssociative()`, `fetchNumeric()`, `fetchAllNumeric()`,
  `fetchAllKeyValue()`, `fetchAllAssociativeIndexed()`, `fetchOne()`,
  `fetchFirstColumn()`, and streaming `iterateAssociative()` / `iterateNumeric()`
  / `iterateKeyValue()` / `iterateColumn()`.
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/data-retrieval-and-manipulation.html
- **CRUD helpers**: `Connection::insert($table, $values, $types)`,
  `::update($table, $values, $where, $types)`, `::delete($table, $where, $types)`
  — table/column names are NOT escaped (must be trusted); values are.
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/security.html
- **Transactions**: `beginTransaction() / commit() / rollBack()`. Nested
  transactions use SAVEPOINTs unconditionally in 4.x (BC break vs 3.x).
  Savepoints mandatory; configuring it is deprecated.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md (“Upgrade to 4.0 → remove support for transaction nesting without savepoints”)
- **Result caching**: PSR-6 cache via `QueryCacheProfile` / `enableResultCache()`
  on QueryBuilder.
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/query-builder.html
- **Primary/Replica routing**: `PrimaryReadReplicaConnection` — writes and
  post-write reads go to primary, reads to random replica; configured via
  Symfony `replicas:` block (`keep_replica: true` to stay on replica after
  writes).
  Source: https://symfony.com/doc/current/doctrine/dbal.html
- **Schema introspection + SchemaManager**: list/introspect tables, columns,
  indexes, FKs, sequences, views; diff & generate DDL. 4.x marks many schema
  internals `@internal`; public APIs shift to `introspect*()` / `editor()` /
  `NamedObject::getObjectName()` patterns in 4.3–4.4.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **Types system**: built-in types for integers (smallint/integer/bigint),
  decimal/float, string/text/ascii_string, binary/blob, boolean, date/time in
  mutable and `_immutable` variants, guid, `json` and `jsonb` (`JSON_OBJECT` /
  `JSONB_OBJECT` added in 4.4.0), `simple_array`, `enum`. Custom types via
  `Type::addType()` and Symfony `dbal.types` config.
  Sources: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html ,
  https://symfony.com/doc/current/doctrine/dbal.html

---

## 2. Symfony integration

- **Package**: `doctrine/doctrine-bundle` is the Symfony integration bundle for
  both Doctrine ORM and DBAL. DBAL-only usage is supported: omit the `orm:` key
  in `config/packages/doctrine.yaml`. Official Symfony DBAL-only guide:
  https://symfony.com/doc/current/doctrine/dbal.html
- **Autowiring**: inject `Doctrine\DBAL\Connection` directly into constructors;
  DoctrineBundle registers it as `database_connection`.
  ```php
  public function __construct(private Connection $connection) {}
  ```
  Source: https://symfony.com/doc/current/doctrine/dbal.html
- **Configuration example** (DBAL-only):
  ```yaml
  # config/packages/doctrine.yaml
  doctrine:
      dbal:
          url: '%env(resolve:DATABASE_URL)%'
          # or explicit: driver: pdo_mysql, host, port, dbname, user, password, charset, serverVersion
  ```
  `DATABASE_URL` pattern: `mysql://user:pass@host:3306/db?serverVersion=8.0.37`.
  Sources: https://symfony.com/doc/current/doctrine/dbal.html ,
  https://symfony.com/bundles/DoctrineBundle/current/configuration.html
- **Custom types registration** (Symfony):
  ```yaml
  doctrine:
      dbal:
          types:
              custom_first: App\Type\CustomFirst
  ```
  Source: https://symfony.com/doc/current/doctrine/dbal.html
- **Multiple connections**: DoctrineBundle supports named connections
  (`doctrine.dbal.connections`). ⚠ unverified in detail for Symfony 8, but
  DoctrineBundle has supported it for years.
- **CLI integration**: `bin/console dbal:run-sql "SELECT 1"` is provided by
  DoctrineBundle (suggests `symfony/console` at the library level).
  Source: https://symfony.com/doc/current/doctrine/dbal.html
- **Symfony 8 compatibility**: DBAL 4.3.4+ / 4.4.0 declare
  `symfony/cache: ^6.3.8|^7.0|^8.0` and `symfony/console: ^5.4|^6.3|^7.0|^8.0`
  in require-dev. 4.4.0 PR title mentions “Test against stable Symfony 8”.
  Source: https://repo.packagist.org/p2/doctrine/dbal.json

---

## 3. PHP & dependency footprint

- **PHP requirement**: `php: ^8.2` for DBAL `^4.x` (since 4.0.0, 2024-02-03).
  3.x requires `^7.4 || ^8.0` (for migrations still on old PHP).
  Source: https://repo.packagist.org/p2/doctrine/dbal.json (4.4.3 `require` block)
- **Runtime `require`** (4.4.3) — surprisingly small:
  - `php: ^8.2`
  - `doctrine/deprecations: ^1.1.5`
  - `psr/cache: ^1|^2|^3`
  - `psr/log: ^1|^2|^3`
  - **No** `doctrine/cache`, **no** `doctrine/event-manager`, **no**
    `doctrine/common`, **no** `ext-pdo` (driver-specific extensions are optional,
    picked per chosen driver).
  Source: https://packagist.org/packages/doctrine/dbal ,
  https://repo.packagist.org/p2/doctrine/dbal.json
- **Suggests**: `symfony/console` (for built-in CLI helpers if used outside
  Symfony).
- **License**: MIT. Source: Packagist metadata above.
- **Popularity / maintenance signal**: 585M+ installs, 9.7k stars, 6.4k
  dependents, actively maintained (releases roughly every 1–2 months; 4.4.3 on
  2026-03-20, 3.10.5 patch line still shipping). Maintainers: Benjamin Eberlei,
  Sergei Morozov, Guilherme Blanco, Jonathan Wage, Roman Borschel.
  Source: https://packagist.org/packages/doctrine/dbal
- **Autoload footprint**: PSR-4 `Doctrine\\DBAL\\` → `src/`. Single package,
  no mandatory sub-packages beyond the PSR cache/log interfaces.
- **Transitive total** when adding `doctrine/dbal` + `doctrine/doctrine-bundle`
  to a Symfony 8 app: adds DBAL itself + `doctrine/deprecations` +
  `doctrine/persistence` (pulled by DoctrineBundle) + `doctrine/instantiator`
  (⚠ unverified — DoctrineBundle historically requires `doctrine/persistence`;
  Symfony 8 / DoctrineBundle ≥ 2.13 may have dropped instantiator). Verify with
  `composer why` after install.

---

## 4. Portability & SQL features

- **Supported vendors & minimum versions** (4.4.x):
  MySQL 8.0+ (also MySQL 8.4), MariaDB 10.5.2+ (10.5 itself deprecated — use
  10.6+), PostgreSQL 12+, Oracle 18c+, Microsoft SQL Server 2017+, IBM Db2
  (11.1+), SQLite (all currently supported).
  Sources: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/platforms.html ,
  https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md (4.0/4.1/4.3 sections)
- **Available drivers** (select one in config):
  `pdo_mysql`, `mysqli`, `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`,
  `pdo_oci`, `oci8`, `pdo_sqlsrv`, `sqlsrv`, `ibm_db2`.
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html
- **Platform auto-detection**: DBAL picks a `*Platform` class based on the
  `serverVersion` parameter; in 4.0+ partial version numbers are forbidden —
  use full `X.Y.Z` (e.g. `8.0.37`). The `mariadb-` prefix hack was removed.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md (Upgrade to 4.0)
- **DSN / URL parsing**: `DsnParser` class (the `url` connection parameter was
  removed in 4.0 — Symfony still accepts `url:` because DoctrineBundle parses
  it itself). Standalone usage:
  ```php
  $dsnParser = new DsnParser(['mysql' => 'pdo_mysql']);
  $params    = $dsnParser->parse('mysql://user:pass@host/db');
  $conn      = DriverManager::getConnection($params);
  ```
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md (Upgrade to 4.0)
- **Cross-vendor SQL abstraction**: LIMIT/OFFSET rewritten per platform;
  `modifyLimitQuery` handled by the platform; JSON falls back to TEXT on
  vendors without native JSON; `boolean` rendered as `TINYINT(1)` on MySQL and
  `BOOLEAN` on PostgreSQL; identity columns emitted as PostgreSQL
  `GENERATED BY DEFAULT AS IDENTITY` (BC break in 4.0 — no longer `SERIAL`).
  Sources: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html ,
  https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **SELECT ... FOR UPDATE**: `QueryBuilder::forUpdate()` replaces the removed
  `AbstractPlatform::getForUpdateSQL()` helper.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **BIGINT**: cast to PHP `int` when within `PHP_INT_MAX` (otherwise string).
  BC break vs 3.x (always string).
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **JSON / JSONB**: native on PostgreSQL/MySQL/SQL Server; `Types::JSON` and
  (new in 4.4.0) `Types::JSON_OBJECT`, `Types::JSONB_OBJECT`. For older
  vendors, DBAL stores as TEXT.
- **CTEs (`WITH`)**: supported by QueryBuilder `with()` method — delegates to
  the platform. Support across vendors: PostgreSQL, SQL Server, SQLite 3.8.3+,
  MariaDB 10.2.1+, MySQL 8.0+ (⚠ unverified for Oracle syntax specifics).
- **Middleware pattern**: wrap connections/drivers via `Configuration::
  setMiddlewares([...])` — this is the supported extension mechanism (replaces
  the removed event-based hooks).
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md (3.5)

---

## 5. Testing story

- **In-memory SQLite**: first-class. Two equivalent forms:
  ```yaml
  # doctrine.yaml (DBAL params)
  driver: pdo_sqlite
  memory: true
  ```
  or DSN `pdo-sqlite:///:memory:`.
  Source: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html
- **Dedicated test env**: standard Symfony pattern — override `DATABASE_URL`
  in `.env.test` to an in-memory SQLite or a disposable file DB. Fixtures /
  schema setup is typically run in a `setUp()` helper via
  `$connection->executeStatement($ddl)` or `SchemaManager::createSchema()` /
  `createSchemaManager()->createTable(...)`.
- **Testing guidelines page**: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/testing.html
  ⚠ unverified content details (page exists in the nav; not fetched). The
  internal test suite runs against every supported vendor in CI.
- **Portability caveats for SQLite tests**:
  - No native `BOOLEAN` — stored/compared as INTEGER; DBAL handles conversion
    via the `boolean` type, **but** raw SQL comparisons must use `0/1`.
  - `DATETIME`/`TIME` stored as TEXT; beware of string comparison of dates.
  - FK enforcement off by default — enable via
    `AbstractSQLiteDriver\Middleware\EnableForeignKeys` middleware.
    Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md (3.5)
  - Schema differences may cause tests to pass on SQLite and fail on MySQL;
    run CI against production-equivalent MariaDB for critical flows.
- **Custom functions in SQLite** (e.g. `REGEXP`): register on the native
  connection obtained via `$connection->getNativeConnection()
  ->sqliteCreateFunction(...)` — the `userDefinedFunctions` driver option was
  removed in 4.0. Source: UPGRADE.md (Upgrade to 4.0).
- **Mocking**: because `Connection` is a final-ish concrete class with many
  methods, full mocks are impractical; integration tests against SQLite are
  the idiomatic approach. Alternatively, repositories can be hidden behind
  interfaces (recommended — matches phpBB project’s existing pattern).

---

## 6. Known drawbacks / criticisms

- **Steep BC-break history for anything beyond DML**. UPGRADE.md for 4.x is
  **~128 KB / 3060 lines** of deprecations and removals, concentrated in
  Schema/Platform/SchemaManager APIs (column editors, `NamedObject::
  getObjectName()`, `introspect*()` renames, `TableDiff` changes). If you only
  use Connection + QueryBuilder + fetch helpers this barely touches you; if
  you use Schema Tool / SchemaManager, expect churn every minor release.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **Removed in 4.0 — callers must adapt**: `fetch()`, `fetchAll()`, `exec()`,
  `executeUpdate()`, `query()`, `FetchMode`, `url` connection parameter,
  `Connection::PARAM_*_ARRAY`, `Statement::bindParam()`, transaction nesting
  without savepoints, SQLite UDF registration, SQL Logger (`DebugStack`).
  Migrating a codebase written against DBAL 2.x/3.x is non-trivial.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **Overhead vs raw PDO**: adds a wrapper layer (parameter normalization,
  type conversion, platform dispatch, middleware chain). For tight hot paths
  (high-QPS lookups, forum `viewtopic`-style pagination) the overhead is
  measurable but generally small (microseconds per query). ⚠ unverified with a
  current benchmark; avoid quoting a specific number. Recommendation: keep
  very hot paths on direct `Connection::executeQuery()` with positional params
  (skip QueryBuilder) to minimize overhead.
- **Schema manager surface churn** (see above) means migrations/tooling code
  written today may need updates when moving from 4.4 → 4.5 → 5.0. Deprecations
  are runtime (via `doctrine/deprecations`) and noisy in tests unless
  suppressed.
- **Event Manager removed** as an extension point (3.5+). Extension is now via
  **driver middleware** — a cleaner model but not backward-compatible with
  existing event listeners.
  Source: https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md
- **Portability compromises**: certain features either don’t exist on one
  platform (e.g. SQLite) or are faked. JSON operators, window functions,
  upserts (`ON CONFLICT` / `ON DUPLICATE KEY`) are **not** abstracted — you
  write vendor-specific SQL through `executeStatement()` when needed.
- **`@internal` creep**: many useful platform/schema methods are now
  `@internal`, meaning relying on them is officially unsupported even if
  technically accessible.
- **Not an ORM, but some schema classes carry ORM-ish weight** (e.g.
  `Table`, `Column`, `Comparator`) — code size is larger than a thin wrapper
  like Aura.Sql. ⚠ unverified package size in MB.

---

## 7. Comparative stance (DBAL vs raw PDO vs lightweight alternatives)

Constraints restated: entities are immutable VOs → no ORM; Symfony 8 / PHP 8.2+.

| Dimension | Raw PDO (current) | **Doctrine DBAL 4.x** | Aura.Sql (ext-pdo decorator) | latitude/latitude (QB only) | Cycle DBAL |
|---|---|---|---|---|---|
| ORM coupling | none | none (DBAL-only mode) | none | none | none |
| Runtime deps (besides PHP) | `ext-pdo` | `doctrine/deprecations`, `psr/cache`, `psr/log` | tiny | tiny | `spiral/database` stack |
| Symfony integration | manual factory | first-class via DoctrineBundle | manual | manual | manual |
| QueryBuilder | no | yes (rich) | no (just PDO++) | yes (focused) | yes |
| Schema introspection / diff | no | yes | no | no | yes |
| Multi-vendor abstraction | no (vendor SQL) | yes | no | limited | yes |
| IN-list binding | manual CSV build | `ArrayParameterType` | no | partial | yes |
| Primary/replica routing | manual | built-in | no | no | no |
| Result caching (PSR-6) | manual | built-in | no | no | no |
| BC / release churn | PHP-stable | high in schema APIs | very stable | low | moderate |
| Perf overhead vs PDO | baseline | small wrapper overhead | ~zero | small | moderate |

- **Why DBAL wins for this project**: the stack is Symfony 8 + DoctrineBundle
  is already the “blessed path”; autowiring `Connection` into repositories is
  trivial; `ArrayParameterType` eliminates the home-grown IN-list plumbing;
  `PrimaryReadReplicaConnection` is useful if phpBB ever introduces read
  replicas; PSR-6 result caching integrates with Symfony cache pools;
  immutable VO entities are unaffected because there’s no ORM identity map.
- **Why you might stay on raw PDO**: the current footprint is 4 repositories,
  ~720 LOC, already works against MariaDB prod + SQLite in tests; PDO has
  **zero** BC-break risk and essentially zero wrapper overhead; tests pass.
  Migration cost = rewriting 4 repositories + `PdoFactory` + retraining
  contributors on DBAL types/QueryBuilder + adopting the deprecation-heavy
  upgrade cadence.
- **Hybrid option**: introduce DBAL for **new** features (where QueryBuilder,
  CTE support, type-safe `insert/update` helpers, and Symfony autowiring pay
  off) while keeping the existing PDO repositories until a natural rewrite.
  Both layers can coexist on the same underlying PDO connection if you reuse
  the native connection (DBAL exposes it via `$connection->getNativeConnection()`).
- **Aura.Sql** (https://github.com/auraphp/Aura.Sql): minimal PDO decorator;
  adds array binding and profiler. No QueryBuilder, no types system, no
  portability. Good if you want to stay raw-SQL and only fix the IN-list
  pain — low cost, low reward.
- **latitude/latitude** (https://github.com/shadowhand/latitude): pure SQL
  query-builder, vendor-neutral. Pairs with raw PDO. Lighter than DBAL but
  loses type system, schema tooling, and Symfony wiring.
- **Cycle DBAL** (https://github.com/cycle/database): modern DBAL from the
  Spiral ecosystem. Comparable feature scope, but not Symfony-native —
  integration is DIY. Mostly interesting if you dislike Doctrine’s BC policy.
- ⚠ unverified: current DBAL-4 performance delta vs raw PDO under phpBB-like
  load. Recommend a micro-benchmark of `viewtopic` query paths before
  committing to a full migration.

---

## Summary recommendation for the decision memo

For a Symfony 8 / PHP 8.2+ rewrite with DBAL-only usage, immutable VO entities,
MariaDB prod + SQLite tests, and an existing 4-repository PDO surface,
**Doctrine DBAL 4.x is a strong, idiomatic fit** — small runtime deps (4
packages), first-class Symfony autowiring, rich QueryBuilder with list-binding
and CTEs, PSR-6 result caching, and SQLite-in-memory test support.

Main costs are (a) a one-time port of the PDO repositories and
`PdoFactory`, and (b) accepting Doctrine’s aggressive deprecation cadence on
Schema/Platform APIs (largely avoidable if you stay in
`Connection` + `QueryBuilder` territory). Migration risk is lowered by the
fact that the codebase is small (~720 LOC, 4 repositories) and by DBAL’s
ability to expose the native PDO handle for incremental migration.

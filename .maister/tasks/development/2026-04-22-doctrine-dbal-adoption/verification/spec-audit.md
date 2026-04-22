# Specification Audit — M4: Adopt Doctrine DBAL 4

**Date:** 2026-04-22
**Auditor role:** Senior auditor, evidence-based, independent verification
**Specification:** `.maister/tasks/development/2026-04-22-doctrine-dbal-adoption/implementation/spec.md`
**Supporting artifacts:**
- `implementation/requirements.md`
- `analysis/scope-clarifications.md`
- `.maister/tasks/research/2026-04-22-doctrine-dbal-vs-pdo/outputs/research-report.md`

**Ground-truth codebase files read:**
- `src/phpbb/auth/Contract/RefreshTokenRepositoryInterface.php`
- `src/phpbb/user/Contract/UserRepositoryInterface.php`
- `src/phpbb/user/Contract/BanRepositoryInterface.php`
- `src/phpbb/user/Contract/GroupRepositoryInterface.php`
- `src/phpbb/user/Repository/PdoBanRepository.php`
- `src/phpbb/user/Repository/PdoGroupRepository.php`
- `src/phpbb/user/Repository/PdoUserRepository.php`
- `src/phpbb/config/services.yaml`
- `src/phpbb/config/bundles.php`
- `phpunit.xml`
- `composer.json`

**DBAL 4.x source verified from:** `doctrine/dbal` GitHub (main/4.x branch)

---

## Overall Verdict

**⚠️ PASS-WITH-CONCERNS**

The spec is architecturally sound, the DBAL 4.x API choices are correct, all 28 interface methods across 4 contracts are covered, and the boundary rules are clean. However, two high-severity gaps in the method-mapping tables would force implementers to reverse-engineer intent from PDO source code rather than the spec itself. These gaps must be resolved before implementation begins.

---

## Critical Issues

*(Block implementation if unresolved)*

### CRIT-1 — `create()` re-fetch pattern unspecified in method tables

**Spec references:**
- §3.3 `DbalBanRepository` method table, row for `create(array $data): Ban`
- §3.5 `DbalUserRepository` method table, row for `create(array $data): User`

**Ground truth — `PdoBanRepository::create()` (lines 90-104):**
```php
$stmt->execute([...]);
$newId = (int) $this->pdo->lastInsertId();
return $this->findById($newId) ?? throw new \RuntimeException('Failed to retrieve newly created ban.');
```

**Spec notes column for both create() methods:**
```
executeStatement(SQL, [named params]) + (int) $this->connection->lastInsertId()
```

**Gap:** The `+ (int) $this->connection->lastInsertId()` notation documents only the ID extraction. The mandatory post-INSERT re-fetch (`findById($newId) ?? throw new \RuntimeException(...)`) that converts `void` to the required entity return type is **absent**. An implementer reading only the spec table would build a method that either returns `void` (type error) or discards the entity requirement.

The "Reusable Components" section §Existing Code Leveraged mentions "Hydration logic copy verbatim" but not the re-fetch pattern, which is structurally different from hydration.

**Severity:** Critical — causes PHP type error at the interface boundary (`Ban`/`User` return type vs `void`).

**Recommendation:** Add to each `create()` row Notes:  
`… + findById($newId) ?? throw new \RuntimeException('Failed to retrieve newly created [entity].')`

---

## High-Severity Warnings

*(Should be fixed before implementation starts)*

### HIGH-1 — `findAll()` SQL construction not specified for `DbalGroupRepository`

**Spec reference:** §3.4, method table row `findAll(?GroupType $type = null): array`

**Spec notes:** `executeQuery(SQL, $params)->fetchAllAssociative()` where `$params = $type ? [':type' => $type->value] : []`

**Ground truth — `PdoGroupRepository::findAll()` (lines 44-57):** Two separate `prepare()` calls with different SQL strings — `WHERE group_type = :type` added only when `$type !== null`. The spec documents the params-array switch but never shows that **the SQL string itself is also conditional**. An implementer who builds a single fixed SQL with `WHERE group_type = :type` and then passes `$params = []` when `$type === null` would generate broken SQL (named param `:type` referenced but not bound, causing DBAL to throw `MissingNamedParameter`).

**Note:** DBAL's `ExpandArrayParameters` throws `MissingNamedParameter` when a param in SQL has no bound value. This makes the bug explicit.

**Severity:** High — implementation-breaking if implementer follows the spec literally for the no-type path.

**Recommendation:** Add explicit pseudocode:
```
$sql    = 'SELECT * FROM phpbb_groups';
$params = [];
if ($type !== null) {
    $sql   .= ' WHERE group_type = :type';
    $params = [':type' => $type->value];
}
$sql .= ' ORDER BY group_name ASC';
```

---

### HIGH-2 — Keyed return structure (`array<int, Entity>`) not covered in `findByIds()` / `findDisplayByIds()`

**Spec reference:** §3.5, method table rows for `findByIds()` and `findDisplayByIds()`

**Spec notes:**
- `findByIds`: `executeQuery(SQL, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative()` — no further detail
- `findDisplayByIds`: "Same pattern as findByIds"

**Interface contracts:**
```php
/** @return array<int, User> keyed by user id */
public function findByIds(array $ids): array;

/** @return array<int, UserDisplayDTO> keyed by user id */
public function findDisplayByIds(array $ids): array;
```

**Ground truth — `PdoUserRepository`:**
```php
// findByIds():
foreach ($stmt->fetchAll() as $row) {
    $user = $this->hydrate($row);
    $result[$user->id] = $user;      // ← keyed structure
}

// findDisplayByIds():
$dto = new UserDisplayDTO(...);      // ← inline DTO, NOT using hydrate()
$result[$dto->id] = $dto;
```

**Gap 1:** `fetchAllAssociative()` returns a list (0-indexed). The conversion to `array<int, Entity>` keyed by entity ID is not described.

**Gap 2:** `findDisplayByIds()` does **not** reuse the `private hydrate()` method — it instantiates `UserDisplayDTO` inline from a SELECT limited to 4 columns. The spec's instruction "Same pattern as findByIds" (plus implied `hydrate()` reuse) is incorrect for this method.

**Severity:** High — silent behavioral bug (wrong return structure; calling code uses `$users[$userId]` keying).

**Recommendation:** For both methods, add a hydration note:
- `findByIds`: `→ key result by $entity->id`
- `findDisplayByIds`: `→ inline UserDisplayDTO; key by $dto->id. Does NOT use private hydrate().`

---

## Medium-Severity Warnings

*(Should address; non-blocking but implementation-risky)*

### WARN-1 — Empty-array behavior of `findByIds()` / `findDisplayByIds()` unspecified

**Spec reference:** §3.5, `findByIds()` and `findDisplayByIds()` rows.

**PDO behavior:** `PdoUserRepository` has explicit guards:
```php
if ($ids === []) { return []; }
```
Required because `PDO` would generate `IN ()` — invalid SQL.

**DBAL 4 behavior (verified from `ExpandArrayParametersTest.php`):**
```
'Named: Empty "integer" array (DDC-1978)' =>
    IN (:foo) with ['foo' => []] → IN (NULL)    // returns 0 rows, no crash
```

DBAL 4 silently converts empty `ArrayParameterType` to `IN (NULL)`, returning 0 rows — semantically correct but executes a round-trip to SQLite/MariaDB.

**Gap:** The spec neither says to keep the guard (defensive) nor to remove it (rely on DBAL). The audit question in the brief asks explicitly about this. No answer is given.

**Severity:** Medium — no bug in DBAL 4, but unresolved: keeps or removes 4 LOC per method, and the test suite's edge-case coverage note (AC-9) doesn't mention the empty-array case.

**Recommendation:** Add one sentence to §3.5: "The empty-array early-return guard from `PdoUserRepository` is retained for clarity; DBAL 4 natively handles `IN (NULL)` but the guard avoids the round-trip."

---

### WARN-2 — `requirements.md` contains invalid DBAL 4 DSN syntax

**Spec reference:** `implementation/requirements.md`, §Technical Considerations

**Problem text:**
```
DbalConnectionFactory musi budować DSN ręcznie:
`mysql://user:pass@host/db?serverVersion=10.11&charset=utf8mb4`
```

Two errors:
1. The `url` connection parameter was **removed in DBAL 4.0** ([UPGRADE.md L581](https://github.com/doctrine/dbal/blob/main/UPGRADE.md)). Passing a URL string in the params array would fail at runtime.
2. `serverVersion=10.11` is a partial version number — DBAL 4 requires 3 parts (`10.11.0`); for MariaDB, the format must be `mariadb-10.11.0`.

**Spec §2.2** correctly uses the params array with `'serverVersion' => 'mariadb-10.11.0'`. The requirements doc is a stale draft predating the spec and contradicts it, but it is still in the task folder and could mislead implementers who cross-reference it.

**Severity:** Medium — spec is correct; requirements doc is misleading artefact.

**Recommendation:** Annotate the requirements.md technical consideration with `[SUPERSEDED — see spec §2.2]`.

---

### WARN-3 — `$port` not wired in `services.yaml` AFTER snippet

**Spec reference:** §6.1, `services.yaml` AFTER, `Doctrine\DBAL\Connection` block.

**Observed gap:** `DbalConnectionFactory::create()` accepts:
```php
create(string $host, string $dbname, string $user, string $password, string $port = '')
```

The spec's AFTER services.yaml passes `$host`, `$dbname`, `$user`, `$password` — no `$port`. Port defaults to `''` → `(int)'' === 0` → falls back to `3306` per the factory logic.

**Existing PDO pattern (services.yaml current):** Also omits port — consistent behavior. The current MariaDB in docker-compose uses the default port, so this is intentional.

**Risk:** If production ever uses a non-standard port via `PHPBB_DB_PORT`, the factory would silently ignore it. The spec never acknowledges this limitation.

**Severity:** Medium — works for the known deployment; undocumented assumption.

**Recommendation:** Add a comment to the AFTER services.yaml block: `# Port uses factory default (3306); add %env(PHPBB_DB_PORT)% if non-standard port needed`.

---

### WARN-4 — `pdo_sqlite` extension dependency not documented

**Spec reference:** §4 Integration Test Harness; §2.2 SQLite DSN.

**Gap:** The integration tests require `ext-pdo_sqlite`. The spec §2.1 states "pdo_sqlite driver support is bundled with pdo_sqlite" — this is circular and imprecise. The PHP `pdo_sqlite` extension is usually compiled in but not guaranteed. Neither `composer.json` nor `phpunit.xml` enforces it.

**Severity:** Medium — CI could fail silently on environments without the extension.

**Recommendation:** Add to `composer.json` require-dev: `"ext-pdo_sqlite": "*"`. This documents the dependency and fails fast on missing extension.

---

## Low-Severity / Informational Findings

### INFO-1 — `lastInsertId()` return type description inaccurate

**Spec (audit question):** "Returns `string`. Cast needed."

**DBAL 4 source (`Connection.php` line 927-936):**
```php
public function lastInsertId(): int|string
```

In DBAL 4 the method returns `int|string`, not `string` alone. The `(int)` cast in the spec is correct for both types, but the description is imprecise.

**Impact:** None — `(int) $this->connection->lastInsertId()` works for `int|string`. ✅

---

### INFO-2 — `transactional(Closure $func)` confirmed available in DBAL 4 ✅

**Spec reference:** §5 upsert pseudocode.

**Verified from `Connection.php` L940 (DBAL 4 main branch):**
```php
public function transactional(Closure $func): mixed
{
    $this->beginTransaction();
    ...
}
```

Also verified from `Functional/ConnectionTest.php` and `TransactionTest.php` — active tests use `transactional()` in DBAL 4 with the same closure signature shown in the spec. ✅

---

### INFO-3 — `ArrayParameterType::INTEGER` is the correct DBAL 4 constant ✅

**Verified from `ArrayParameterType.php`:**
```php
enum ArrayParameterType
{
    case INTEGER;   // ← correct
    case STRING;
    ...
}
```

`Connection::PARAM_INT_ARRAY` was removed in DBAL 4.0. Spec correctly uses the enum. ✅

---

### INFO-4 — `fetchAssociative()` / `fetchAllAssociative()` / `fetchOne()` API usage all correct ✅

From `Result` class and `Connection` shorthand methods:
- `fetchAssociative()` returns `array|false` — spec handles `false → null` correctly in all nullable methods
- `fetchAllAssociative()` returns `array` — used without false-check for list returns
- `fetchOne()` returns `mixed|false` — spec's `!== false` pattern for ban checks correct (SQL `SELECT 1` returns `"1"` when found)

---

### INFO-5 — `executeStatement()` vs `executeQuery()` distinction correct ✅

Spec correctly uses:
- `executeStatement()` for DML (INSERT/UPDATE/DELETE) — returns `int|numeric-string` (affected rows)
- `executeQuery()` → `Result` for SELECT — correct throughout

---

### INFO-6 — `MariaDBPlatform extends MySQLPlatform` hierarchy verified ✅

**Spec §5:** "Covers both MySQL and MariaDB (MariaDBPlatform extends MySQLPlatform)"

**Verified from DBAL 4 `Platforms/` directory and `TransactionTest.php`** which imports `AbstractMySQLPlatform` as the common base. `instanceof MySQLPlatform` evaluates true for `MariaDBPlatform` instances. The `serverVersion: 'mariadb-10.11.0'` prefix triggers `MariaDBPlatform` instantiation.

The spec's upsert branch check `instanceof Doctrine\DBAL\Platforms\MySQLPlatform` is correct for both production (MariaDB 10.11) and any future MySQL. ✅

---

### INFO-7 — `serverVersion` format `'mariadb-10.11.0'` verified ✅

DBAL 4 UPGRADE.md requires 3-part version numbers. The prefix `mariadb-` followed by `10.11.0` satisfies both the 3-number requirement and the MariaDB platform detection. ✅

---

### INFO-8 — Named parameter `:isLeader` used twice in ON DUPLICATE KEY UPDATE is safe ✅

**Spec §5 upsert SQL:**
```sql
VALUES (:groupId, :userId, :isLeader, 0)
ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0
```

**Verified from `ExpandArrayParametersTest.php` test case 'Named: With the same name arg':**  
DBAL's `ExpandArrayParameters` correctly expands a named param that appears multiple times — the value is rebound at each occurrence. ✅

---

### INFO-9 — Empty array `ArrayParameterType` → `IN (NULL)` (no crash) ✅

**Verified from `ExpandArrayParametersTest.php`:**
```
'Named: Empty "integer" array (DDC-1978)':
    WHERE foo IN (:foo) + ['foo' => []]  →  WHERE foo IN (NULL)
```

`IN (NULL)` matches no rows → returns empty array. No crash, no invalid SQL. The PDO guard is beneficial for clarity/performance but not required for correctness in DBAL 4.

---

### INFO-10 — `phpunit.xml` change is effectively a no-op ✅

The current `phpunit.xml` already contains all three env vars the spec proposes to add. The only change is a code comment. ✅

---

### INFO-11 — All 28 interface methods are mapped in the spec ✅

| Contract | Methods | Spec coverage |
|---|---|---|
| `RefreshTokenRepositoryInterface` | 6 | 6/6 ✅ |
| `BanRepositoryInterface` | 7 | 7/7 ✅ (re-fetch gap in `create()` — see CRIT-1) |
| `GroupRepositoryInterface` | 5 | 5/5 ✅ (SQL gap in `findAll()` — see HIGH-1) |
| `UserRepositoryInterface` | 10 | 10/10 ✅ (keying gap — see HIGH-2; re-fetch gap in `create()` — see CRIT-1) |

---

### INFO-12 — `NotFoundException` not needed in current design ✅

**Audit question:** Is `RepositoryException` sufficient, or should we also have `NotFoundException`?

All four contracts return nullable (`?Entity`) for lookups — callers handle null themselves. No interface method throws on "not found". The only `RuntimeException` sites are internal consistency failures in `create()` (post-INSERT re-fetch returns null — a bug in the database, not a domain condition). A `NotFoundException` would be appropriate only if a future interface changes `findById()` to `@throws NotFoundException` instead of returning `?Entity`.

The spec's §9 rationale is sound. Single `RepositoryException` is sufficient for M4. ✅

---

### INFO-13 — Contract freeze boundary is respected ✅

The spec's §7 "What Stays Frozen" table lists all 4 contracts, all entities, all DTOs, all enums, all 6 consuming services, and hydration logic. No proposed change touches any of these. Confirmed against spec body: no interface, entity, or service method is modified in any section. ✅

---

### INFO-14 — `bundles.php` unchanged, DoctrineBundle not registered ✅

Current `bundles.php`:
```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class     => ['all' => true],
];
```
Spec explicitly states no change. DoctrineBundle absent. ✅

---

## Summary Matrix

| ID | Category | Severity | Status |
|---|---|---|---|
| CRIT-1 | `create()` re-fetch pattern missing from method tables | 🔴 Critical | Must fix |
| HIGH-1 | `findAll()` SQL string construction not shown | 🟠 High | Must fix |
| HIGH-2 | Keyed return structure not described; `findDisplayByIds()` doesn't use `hydrate()` | 🟠 High | Must fix |
| WARN-1 | Empty-array edge case unaddressed | 🟡 Medium | Should fix |
| WARN-2 | `requirements.md` contains invalid DBAL 4 URL format | 🟡 Medium | Should fix |
| WARN-3 | `$port` not wired in services.yaml | 🟡 Medium | Should fix |
| WARN-4 | `ext-pdo_sqlite` not in `composer.json` | 🟡 Medium | Should fix |
| INFO-1 | `lastInsertId()` return type `int\|string` not `string` | 🔵 Info | Optional |
| INFO-2 | `transactional()` available in DBAL 4 | 🔵 Info | Confirmed ✅ |
| INFO-3 | `ArrayParameterType::INTEGER` correct | 🔵 Info | Confirmed ✅ |
| INFO-4 | `fetchAssociative`/`fetchOne` API usage correct | 🔵 Info | Confirmed ✅ |
| INFO-5 | `executeStatement()`/`executeQuery()` distinction correct | 🔵 Info | Confirmed ✅ |
| INFO-6 | `MariaDBPlatform extends MySQLPlatform` confirmed | 🔵 Info | Confirmed ✅ |
| INFO-7 | `serverVersion: 'mariadb-10.11.0'` format correct | 🔵 Info | Confirmed ✅ |
| INFO-8 | Named param reuse in ON DUPLICATE KEY safe | 🔵 Info | Confirmed ✅ |
| INFO-9 | Empty array → `IN (NULL)` in DBAL 4 | 🔵 Info | Confirmed ✅ |
| INFO-10 | `phpunit.xml` change is no-op | 🔵 Info | Confirmed ✅ |
| INFO-11 | All 28 interface methods covered | 🔵 Info | Confirmed ✅ |
| INFO-12 | `NotFoundException` not needed | 🔵 Info | Confirmed ✅ |
| INFO-13 | Contract freeze boundary respected | 🔵 Info | Confirmed ✅ |
| INFO-14 | `bundles.php` unchanged | 🔵 Info | Confirmed ✅ |

---

## Recommended Spec Patches

The following targeted edits to `spec.md` would promote this audit to **PASS**:

**1. §3.3 and §3.5 `create()` row Notes** — append to both:
> `→ $newId = (int) $this->connection->lastInsertId(); → return $this->findById($newId) ?? throw new \RuntimeException('...');`

**2. §3.4 `findAll()` row Notes** — replace current:
> `executeQuery(SQL, $params)->fetchAllAssociative()`  

with:
> `Build SQL conditionally: base SELECT + append WHERE group_type = :type when $type !== null. executeQuery($sql, $params)->fetchAllAssociative()`

**3. §3.5 `findByIds()` row Notes** — add:
> `→ foreach, key result by $user->id. Early-return if $ids === [] retained for clarity.`

**4. §3.5 `findDisplayByIds()` row Notes** — replace "Same pattern as findByIds":
> `Same IN-list binding. Returns inline UserDisplayDTO (does NOT use generic hydrate()). Key by $dto->id.`

**5. §6.1 services.yaml AFTER comment** — add one line:
> `# Port: defaults to 3306; wire %env(PHPBB_DB_PORT)% if needed`

**6. `composer.json` changes section (§6.4)** — add:
> `"ext-pdo_sqlite": "*"` to `require-dev`

---

*Audit complete. Verdict: ⚠️ PASS-WITH-CONCERNS — 1 critical, 2 high issues require spec patches before implementation starts.*

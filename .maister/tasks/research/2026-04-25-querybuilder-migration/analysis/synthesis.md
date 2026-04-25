# Research Synthesis — QueryBuilder Migration

**Research question**: How should we replace all hand-written SQL queries with Doctrine DBAL QueryBuilder across the `phpbb\` namespace?  
**Date**: 2026-04-25  
**Sources integrated**: repositories-raw-sql-inventory.md · services-raw-sql-inventory.md · dbal-capabilities-assessment.md · tests-sql-patterns.md

---

## 1. Executive Summary

Every repository and service class under `src/phpbb/` was audited. A clear, actionable migration path exists for the overwhelming majority of queries. The main findings are:

- **16 files** contain raw SQL (12 `Dbal*Repository` + 4 non-repository service classes).
- **~85 % of individual query sites** can be replaced directly with QueryBuilder using standard DBAL 4 API.
- **3 categories of exceptions** must remain as raw `executeStatement`/`executeQuery` calls or be resolved via schema changes: MySQL `ON DUPLICATE KEY UPDATE`, `HEX()`/`UNHEX()` binary column functions, and `GREATEST()`.
- **2 critical security defects** exist in unguarded dynamic `UPDATE` field names (`DbalMessageRepository`, `DbalParticipantRepository`) — these must be fixed regardless of the QueryBuilder migration.
- **No DDL changes** are needed in the test suite. The SQLite integration harness is already dialect-clean.
- **2 test classes** must be rewritten from mock-based to SQLite integration style; one of those has a blocking prerequisite (the `GREATEST()` fix).

---

## 2. Cross-Source Analysis

### 2.1 Validated Findings (confirmed across multiple sources)

| Claim | Confirming sources | Confidence |
|---|---|---|
| 85 %+ of queries are portable ANSI SQL | repositories inventory + services inventory + DBAL capability matrix | High |
| `ON DUPLICATE KEY UPDATE` has no QB equivalent in DBAL 4 | DBAL capability assessment | High |
| `HEX()`/`UNHEX()` are MySQL-only; affect all 6 methods of `DbalStoredFileRepository` | repositories inventory | High |
| `GREATEST()` is MySQL-only; affects only `decrementUsage` | repositories inventory + DBAL assessment + test findings | High |
| SQLite DDL in tests is already dialect-clean | test patterns file | High |
| `DbalMessageRepository::update` and `DbalParticipantRepository::update` have unguarded field-name injection risk | repositories inventory | High |
| `DbalUserRepository::update` and `DbalUserRepository::search` already use QueryBuilder | repositories inventory | High |
| Two mock-based test classes (`DbalStoredFileRepositoryTest`, `DbalStorageQuotaRepositoryTest`) need rewriting | test patterns file | High |

### 2.2 Contradictions Resolved

**`DbalStoredFileRepository` QB feasibility**: The repositories inventory labels all 6 `DbalStoredFileRepository` methods as "non-portable", but the DBAL capability assessment notes that QB *can* accept raw MySQL function strings (e.g., `->where('id = UNHEX(:id)')`). Resolution: QB can *wrap* these calls, but the underlying SQL remains MySQL-locked. The correct classification is "QB-compatible, MySQL-locked" — not "cannot use QB at all". Migrating them to QB is optional value; fixing the root cause (column type change to `CHAR(36)`) is the high-value path.

**`COALESCE(SUM(...), 0)` portability**: Both `COALESCE` and `SUM` are standard SQL. The services inventory correctly flags them as portable; the DBAL assessment confirms raw strings in `select()` work. No contradiction — both sources agree.

### 2.3 Evidence Quality Assessment

- **High confidence**: All repository inventory findings are derived from direct code inspection of actual source files.
- **High confidence**: DBAL capability assessment is derived from installed vendor source code (`vendor/doctrine/dbal`).
- **Medium confidence**: Test rewrite recommendations for `DbalStorageQuotaRepository` depend on the `GREATEST()` fix, which may have further downstream implications not yet assessed.

---

## 3. Patterns Identified

### Pattern 1 — Simple CRUD (easy QB migration) · **Prevalence: very high (70 %+ of all query sites)**

`SELECT … WHERE`, `INSERT … VALUES`, `DELETE … WHERE`, `UPDATE … SET … WHERE` with fixed column names and named parameters. All use standard ANSI SQL operators. Found across all 12 repositories and all 4 service classes.

**Quality**: High (well-structured, parameterised values, no injection risk).  
**QB mapping**: Direct 1:1 replacement.

---

### Pattern 2 — Pagination (COUNT + LIMIT/OFFSET) · **Prevalence: high (5 repository files)**

Two-query pagination: a `SELECT COUNT(*)` query and a `SELECT … LIMIT :limit OFFSET :offset` query. `LIMIT`/`OFFSET` are bound with `ParameterType::INTEGER`. Found in `DbalTopicRepository`, `DbalPostRepository`, `DbalConversationRepository`, `DbalMessageRepository`, and implicitly in `DbalUserRepository::search`.

**Quality**: Correct parameterisation; already idiomatic.  
**QB mapping**: `->setMaxResults($perPage)->setFirstResult($offset)` — DBAL translates to dialect-correct SQL.

---

### Pattern 3 — Dynamic SET with whitelist (medium QB migration) · **Prevalence: medium (3 files)**

`UPDATE SET <dynamic columns>` assembled by looping over a PHP array. Two sub-variants:
- **Whitelisted** (`DbalConversationRepository::update`, `DbalForumRepository::update`, `DbalUserRepository::update`): safe, loop iterates over explicit `$allowed` arrays.
- **Unwhitelisted** (`DbalMessageRepository::update`, `DbalParticipantRepository::update`): **security defect** — field names originate from caller without filtering.

**Quality**: Mixed. Whitelisted variants are correct; unwhitelisted variants are a security risk.  
**QB mapping**: `$qb->set($field, ':' . $field)` loop. Whitelist must be enforced before migration.

---

### Pattern 4 — MySQL-specific (no full QB equivalent) · **Prevalence: low (3 files, ~15 % of queries)**

- `ON DUPLICATE KEY UPDATE` — `DbalGroupRepository::addMember` (MySQL branch only; non-MySQL fallback already exists).
- `HEX()`/`UNHEX()` — `DbalStoredFileRepository` (all 6 methods). Root cause: binary UUID column.
- `GREATEST(0, col - :n)` — `DbalStorageQuotaRepository::decrementUsage` (1 method).

**Quality**: These patterns reflect deliberate MySQL optimisations or schema decisions. No injection risk.  
**QB handling**: Inline as raw string in QB expression positions, or address root cause (column type change).

---

### Pattern 5 — Multi-JOIN SELECT with dynamic IN · **Prevalence: low (1 file, 4 queries)**

`AuthorizationService` builds 4 queries with 3–4 table JOINs and a dynamic `IN ($placeholders)` list using positional `?` placeholders. Currently uses `Connection::fetchOne($raw, $params)`.

**Quality**: Correct parameterisation for values; dynamic IN list uses `array_fill`/positional placeholders, which is safe.  
**QB mapping**: Medium effort. Replace positional `?` with `ArrayParameterType::INTEGER` + `$qb->expr()->in()`. Extract a private `buildAclJoin($qb)` helper to avoid repeating join/where boilerplate.

---

### Pattern 6 — Check-then-write upsert · **Prevalence: low (2 files)**

`TrackingService::markForumRead` and `DbalMessageRepository::deletePerUser` both implement application-level upsert: SELECT to check existence, then UPDATE or INSERT. This pattern is correct (and portable) but verbose.

**Quality**: Functional; no security risk. Slight race-condition risk on concurrent writes (pre-existing, not introduced by QB migration).  
**QB mapping**: Both the SELECT check and the conditional UPDATE/INSERT are straightforwardly expressible in QB.

---

## 4. Key Insights

### Insight 1 — Migration is safe and mostly straightforward
**Evidence**: 10+ of 12 repositories use only ANSI SQL patterns fully supported by DBAL 4 QueryBuilder.  
**Implication**: Migration can be executed file-by-file with low risk; no cross-cutting architectural change is required.  
**Confidence**: High.

### Insight 2 — `DbalStoredFileRepository` is the only truly blocked file
**Evidence**: All 6 methods rely on `HEX()`/`UNHEX()`; the root cause is storing UUIDs as binary columns.  
**Implication**: QB migration of this file is possible (raw strings in QB expressions) but provides minimal benefit unless the column type is changed to `CHAR(36)`. Changing the column type requires a schema migration and is a separate, higher-value task.  
**Recommendation**: Defer `DbalStoredFileRepository` until the schema migration is planned; keep as raw SQL in the meantime.  
**Confidence**: High.

### Insight 3 — Two security defects must be fixed immediately, independently of the QB migration
**Evidence**: `DbalMessageRepository::update` and `DbalParticipantRepository::update` accept field names without whitelisting. Although the values are parameterised, an attacker controlling the `$fields` array keys (via a compromised API layer) could inject arbitrary column names into the `SET` clause.  
**Implication**: These fixes should be treated as security patches — apply a field-name whitelist array before the QB migration begins.  
**Confidence**: High.

### Insight 4 — Test suite is structurally ready; two test classes need rewriting
**Evidence**: 10/12 repository test classes already use real SQLite integration; no DDL changes needed. Only `DbalStoredFileRepositoryTest` and `DbalStorageQuotaRepositoryTest` remain mock-based.  
**Implication**: Converting the mock tests to integration tests is needed for correctness but has one blocker: `GREATEST()` must be made portable before `DbalStorageQuotaRepositoryTest` can run against SQLite.  
**Confidence**: High.

### Insight 5 — `AuthorizationService` is the highest-complexity service migration
**Evidence**: 4 multi-JOIN queries with dynamic IN lists, all using positional `?` placeholders.  
**Implication**: Requires the most careful refactoring; `ArrayParameterType` must be introduced and a shared private builder helper is recommended to avoid duplication.  
**Confidence**: High.

---

## 5. Relationships and Dependencies

```
Migration prerequisite chain:

[Security fix] Add whitelist to DbalMessageRepository::update
     └──> [QB migration] DbalMessageRepository::update

[Security fix] Add whitelist to DbalParticipantRepository::update
     └──> [QB migration] DbalParticipantRepository::update

[Portability fix] Replace GREATEST() with CASE WHEN in DbalStorageQuotaRepository::decrementUsage
     └──> [Test conversion] DbalStorageQuotaRepositoryTest → SQLite integration
          └──> [QB migration] DbalStorageQuotaRepository full

[Schema migration] phpbb_stored_files.id → CHAR(36)
     └──> [QB migration] DbalStoredFileRepository (remove HEX/UNHEX)
          └──> [Test conversion] DbalStoredFileRepositoryTest → SQLite integration

[Independent — no blockers]
  DbalUserRepository (partially done), DbalGroupRepository, DbalBanRepository,
  DbalRefreshTokenRepository, DbalForumRepository, DbalTopicRepository,
  DbalPostRepository, DbalConversationRepository, DbalMessageRepository,
  DbalParticipantRepository, TrackingService, SubscriptionService,
  QuotaService, AuthorizationService
```

---

## 6. Gaps and Uncertainties

| Gap | Impact | Notes |
|---|---|---|
| `DbalGroupRepository::addMember` MySQL branch has no unit-test coverage | Medium | Covered only by E2E tests; acceptable for now |
| `TrackingService` upsert race condition | Low | Pre-existing, not introduced by this migration |
| `DbalStoredFileRepository` column type (binary vs. CHAR(36)) | High | Schema migration needed for full portability; out of scope for QB migration alone |
| `AuthorizationService` — no tests exist (service-level) | Medium | QueryBuilder migration is pure refactor risk; need tests first or migrate very carefully |

---

## 7. Conclusions

**Primary**: The QueryBuilder migration is feasible with high confidence for ~85 % of all query sites. A structured, file-by-file approach starting with the simplest repositories and ending with the complex ones (AuthorizationService, DbalStoredFileRepository) is the correct strategy.

**Secondary**: Two security fixes must be applied before any migration work begins. The `GREATEST()` portability fix is a prerequisite for the storage quota test conversion, not for the migration itself.

**Confidence level for this research**: **HIGH** — all findings are based on direct code inspection of installed source files; no speculation.

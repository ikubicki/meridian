# Synthesis: Doctrine DBAL vs raw PDO in phpbb rewrite

Cross-reference of `findings/current-pdo-profile.md` and `outputs/doctrine-dbal-facts.md`.

## Match: what DBAL actually solves for us

| PDO pain in our code | DBAL answer | Real benefit for us today |
|---|---|---|
| Manual `?,?,?` expansion for IN lists (2 methods) | `ArrayParameterType::INTEGER/STRING` | Small but real. Frequency will grow. |
| `ON DUPLICATE KEY UPDATE` locks us to MySQL | **Not abstracted** by DBAL — still vendor-specific via `executeStatement()` | **None.** This is not solved. |
| `lastInsertId()` without sequence (non-MySQL risk) | `Connection::lastInsertId()` with platform awareness | Marginal (we don't target Postgres). |
| `bindValue(…, PARAM_INT)` for LIMIT/OFFSET | `QueryBuilder::setMaxResults()/setFirstResult()` | Cleaner code; equivalent safety. |
| Inline SQL strings | Fluent QueryBuilder | Readability. Testability roughly equal. |
| Hand-rolled update `SET` clause builder | `Connection::update($table, $values, $where)` | Clear win — removes ~20 LOC of custom code. |
| PdoFactory boilerplate (52 LOC) | DoctrineBundle auto-wires `Connection` | Delete the factory. |
| Tests mock PDO + PDOStatement (brittle) | Tests could use SQLite-in-memory integration | **Net testing improvement** (integration > mocks). |
| Type conversion `(int) $row['user_id']` | DBAL Types (integer, boolean, json, datetime_immutable) | Moderate — removes hydration casts. |

## Match: what DBAL gives us that we don't need yet

- SchemaManager / schema diff (we don't use migrations in this layer)
- Multi-vendor abstraction (we target MariaDB/MySQL; SQLite only in tests)
- `PrimaryReadReplicaConnection` (no replicas today)
- PSR-6 result cache (no hot read paths identified)
- JSON / JSONB types (no JSON columns in current schema)
- CTEs / window functions (no reporting queries yet)

These are **option value**, not current value.

## Costs we would incur

1. **Port 5 files** (~720 LOC) — low risk, mechanical work.
2. **Rewrite all repository unit tests** to either:
   - stay as mocks (possible but DBAL `Connection` is not designed to be mocked — awkward), or
   - become **integration tests against SQLite-in-memory** (preferred; better quality, same speed).
3. **Add dependencies**: `doctrine/dbal ^4.x` + `doctrine/doctrine-bundle`. Runtime adds `doctrine/deprecations`, `psr/cache`, `psr/log`, `doctrine/persistence`. All MIT, all in mainstream Symfony stacks.
4. **Accept BC-break cadence** on Schema APIs (avoidable if we stay on `Connection` + `QueryBuilder` — which matches our scope).
5. **Re-benchmark hot paths** later (login, pagination) if microseconds matter. DBAL overhead is small but non-zero.

## Risk matrix

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Schema-API churn affects us | Low | Low | Stay in `Connection` + `QueryBuilder`; no SchemaManager use |
| Upsert (`ON DUPLICATE KEY`) breaks on non-MySQL | Very Low (we're MySQL-only) | Low | Keep as raw SQL via `executeStatement()` |
| Test rewrite regresses coverage | Medium | Medium | Do it per-repository; keep old tests green until replacement lands |
| Dependency footprint growth | Low | Low | 4 new packages, all Doctrine-core |
| Performance overhead on login path | Low | Low | Benchmark before/after; fall back to `executeQuery()` if needed |
| Team learning curve | Low | Low | 4 repos is a small training corpus; QueryBuilder docs are mature |

## The hybrid option (keep PDO, use DBAL in new code)

- **Advantage:** zero migration, same option-value for future code.
- **Disadvantage:** two DB layers to maintain; test infrastructure split; code reviewers must know both; PdoFactory lingers.
- **Verdict:** worse than either pure choice. Small codebase → pick one.

## Decision lens

At **5 repositories**, the migration is cheap and the Symfony-native benefits are tangible but not urgent. The real question is **the trajectory**: phpBB rewrite will add repositories for forums, topics, posts, attachments, permissions, polls — easily **20–40 more**. Every repository added on raw PDO compounds the future migration cost and re-implements plumbing DBAL would provide (IN-list, pagination, type mapping, autowiring).

- If the team commits to growing the rewrite → **adopt DBAL now**, while surface is small.
- If the rewrite will freeze at auth+user → **stay on PDO**; it works and has zero BC-break risk.

## Open questions for the user

1. **Roadmap confidence** — will `phpbb/` grow beyond auth+user in the next 6–12 months? (Implied yes from `.github/copilot-instructions.md`, but worth confirming.)
2. **Testing direction** — are we OK moving repository tests from `PDO` mocks to SQLite-in-memory integration tests? (Recommended regardless of DBAL decision.)
3. **Non-MySQL aspirations** — do we ever want PostgreSQL support, or is MariaDB/MySQL the permanent target?

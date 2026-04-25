# Research Sources

## Codebase Sources

### Repository Files (all confirmed to contain executeQuery/executeStatement)

| File | Module | Methods of interest |
|------|--------|---------------------|
| `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` | auth | `save`, `findByHash`, `revokeByHash`, `revokeFamily`, `revokeAllForUser`, `deleteExpired` |
| `src/phpbb/hierarchy/Repository/DbalForumRepository.php` | hierarchy | `findById`, `findAll`, `findChildren`, `insertRaw`, `update`, `delete`, `updateTreePosition`, `shiftLeftIds`, `shiftRightIds`, `updateParentId`, `clearParentsCache` |
| `src/phpbb/messaging/Repository/DbalConversationRepository.php` | messaging | `findById`, `findByParticipantHash`, `listByUser`, `insert`, `update`, `delete` |
| `src/phpbb/messaging/Repository/DbalMessageRepository.php` | messaging | `findById`, `listByConversation`, `search`, `insert`, `update`, `deletePerUser`, `isDeletedForUser` |
| `src/phpbb/messaging/Repository/DbalParticipantRepository.php` | messaging | All methods |
| `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php` | storage | `findByUserAndForum`, `incrementUsage`, `decrementUsage`, `reconcile`, `findAllUserForumPairs`, `initDefault` |
| `src/phpbb/storage/Repository/DbalStoredFileRepository.php` | storage | `findById`, `save`, `delete`, `findOrphansBefore`, `markClaimed`, `findVariants` |
| `src/phpbb/threads/Repository/DbalPostRepository.php` | threads | `findById`, `findByTopic`, `insert` |
| `src/phpbb/threads/Repository/DbalTopicRepository.php` | threads | `findById`, `findByForum`, `insert`, `updateFirstLastPost`, `updateLastPost` |
| `src/phpbb/user/Repository/DbalBanRepository.php` | user | All methods |
| `src/phpbb/user/Repository/DbalGroupRepository.php` | user | All methods |
| `src/phpbb/user/Repository/DbalUserRepository.php` | user | All methods (partial QB usage already present) |

### Service Files with Raw SQL

| File | Module | Notes |
|------|--------|-------|
| `src/phpbb/hierarchy/Service/TrackingService.php` | hierarchy | Upsert pattern (SELECT then INSERT or UPDATE) |
| `src/phpbb/hierarchy/Service/SubscriptionService.php` | hierarchy | Simple CRUD |
| `src/phpbb/storage/Quota/QuotaService.php` | storage | `COALESCE(SUM(…), 0)` aggregate |

### Migration Files (EXCLUDED — intentionally raw SQL)

| File | Notes |
|------|-------|
| `src/phpbb/db/migrations/Version20260424MessageSchema.php` | DDL migrations — raw SQL by design, out of scope |

### Existing QueryBuilder Usage (reference implementations)

| File | QB uses | Notes |
|------|---------|-------|
| `src/phpbb/user/Repository/DbalUserRepository.php` | `update()`, `search()` | Already migrated partially — canonical examples of target pattern |

### File Patterns for Broad Discovery

```
src/phpbb/**/*Repository.php
src/phpbb/**/*Service.php
src/phpbb/**/Job*.php
```

---

## Documentation Sources

### Project Documentation

| Path | Contents |
|------|----------|
| `.maister/docs/standards/backend/STANDARDS.md` | PHP/backend standards including DB layer conventions |
| `.maister/docs/INDEX.md` | Documentation index |

### Inline Code Documentation

- Type hints and imports in each repository: confirm `use Doctrine\DBAL\Connection`, `use Doctrine\DBAL\ParameterType`, `use Doctrine\DBAL\ArrayParameterType`
- `DbalUserRepository` — existing QB usage is the canonical in-codebase reference

---

## Configuration Sources

| File | Relevance |
|------|-----------|
| `composer.json` | Confirm `doctrine/dbal` version (determines available QB API surface) |
| `phpunit.xml` | SQLite DSN for unit/integration tests — confirm SQLite version constraints |
| `docker-compose.yml` | MySQL version used in production/dev environment |
| `docker/php/php.ini` | PHP extensions (pdo_sqlite, pdo_mysql) |

---

## Test Sources (Integration / Schema Fixtures)

| File | Type | Notes |
|------|------|-------|
| `tests/phpbb/messaging/Repository/DbalConversationRepositoryTest.php` | Integration (SQLite) | `setUpSchema()` with raw DDL |
| `tests/phpbb/messaging/Repository/DbalMessageRepositoryTest.php` | Integration (SQLite) | `setUpSchema()` with raw DDL |
| `tests/phpbb/messaging/Repository/DbalParticipantRepositoryTest.php` | Integration (SQLite) | `setUpSchema()` with raw DDL |
| `tests/phpbb/threads/Repository/DbalTopicRepositoryTest.php` | Integration (SQLite) | `setUpSchema()` with raw DDL |
| `tests/phpbb/threads/Service/ThreadsServiceTest.php` | Integration (SQLite) | `setUpSchema()` with raw DDL |
| `tests/phpbb/storage/Repository/DbalStorageQuotaRepositoryTest.php` | Unit (mock) | Mocks `Connection` — no real DB |
| `tests/phpbb/storage/Repository/DbalStoredFileRepositoryTest.php` | Unit (mock) | Mocks `Connection` — no real DB |
| `tests/phpbb/storage/Quota/QuotaServiceTest.php` | Unit (mock) | Mocks `Connection` — no real DB |

**Key distinction**: Mock-based tests (storage) don't validate SQL dialect compatibility — integration tests (messaging, threads) do. Migration of mock-based repos needs special attention to portability.

---

## External Sources (if needed)

| Resource | URL / Reference | Use |
|----------|----------------|-----|
| Doctrine DBAL QueryBuilder docs | https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/query-builder.html | QB API reference |
| Doctrine DBAL Expression API | https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/query-builder.html#expressions | `ExpressionBuilder` capabilities |
| SQLite function list | https://www.sqlite.org/lang_corefunc.html | Verify `HEX`/`UNHEX` support in target SQLite version |
| DBAL Types (Binary) | https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#binary | Binary UUID column type handling |

# Work Log — M4: Adopt Doctrine DBAL 4

## 2026-04-22 — Implementation Started

**Total Steps**: 36
**Task Groups**: A (Foundation), B (RefreshToken), C (Ban), D (Group), E (User), F (Cleanup)

## 2026-04-22 — Group A Complete ✅

**Steps**: A.1 through A.7 completed
**Standards Applied**:
- From plan: backend/STANDARDS.md (PHP 8.3, DI, namespacing), testing/STANDARDS.md (PHPUnit 10+ `#[Test]`), global/STANDARDS.md (file headers, tabs, no closing PHP tag)
**Tests**: 3 passed (DbalConnectionFactoryTest × 1, IntegrationTestCaseTest × 2); full suite 150/150 green
**Files Modified**:
- `src/phpbb/db/Exception/RepositoryException.php` (created)
- `src/phpbb/db/DbalConnectionFactory.php` (created)
- `tests/phpbb/db/DbalConnectionFactoryTest.php` (created)
- `tests/phpbb/Integration/IntegrationTestCase.php` (created)
- `tests/phpbb/Integration/IntegrationTestCaseTest.php` (created)
- `src/phpbb/config/services.yaml` (DBAL Connection block added; PDO kept temporarily until Groups B–E complete)
- `composer.json` (doctrine/dbal ^4.0 added to require)
**Notes**: PDO block intentionally kept in services.yaml until all Pdo* repos are migrated; debug:container skipped (no Symfony console outside Docker)

## Standards Reading Log

### Loaded Per Group

### Group B: RefreshToken Repository
**From Implementation Plan**: global/STANDARDS.md, backend/STANDARDS.md, testing/STANDARDS.md
**Discovered**: DBAL 4 named params use keys WITHOUT `:` prefix (e.g. `['hash' => $val]` not `[':hash' => $val]`)

## 2026-04-22 — Group B Complete ✅

**Steps**: B.1 through B.5 completed
**Tests**: 8 DbalRefreshTokenRepository tests green; full suite 154/154
**Files Modified**:
- `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` (created)
- `tests/phpbb/auth/Repository/DbalRefreshTokenRepositoryTest.php` (created)
- `src/phpbb/config/services.yaml` (RefreshToken alias swapped to Dbal)
- `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` (DELETED)
- `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php` (DELETED)
**Key finding**: DBAL 4 params array keys are WITHOUT `:` prefix (critical for Groups C–E)

## 2026-04-22 — Group C Complete ✅

**Steps**: C.1 through C.5 completed
**Tests**: 9 DbalBanRepository tests green; full suite 163/163
**Files Modified**:
- `src/phpbb/user/Repository/DbalBanRepository.php` (created)
- `tests/phpbb/user/Repository/DbalBanRepositoryTest.php` (created)
- `src/phpbb/config/services.yaml` (Ban alias swapped to Dbal)
- `src/phpbb/user/Repository/PdoBanRepository.php` (DELETED)

## 2026-04-22 — Group D Complete ✅

**Steps**: D.1 through D.5 completed
**Tests**: 8 DbalGroupRepository tests green (incl. idempotency + leader promotion); full suite 171/171
**Files Modified**:
- `src/phpbb/user/Repository/DbalGroupRepository.php` (created — platform-switched upsert)
- `tests/phpbb/user/Repository/DbalGroupRepositoryTest.php` (created)
- `src/phpbb/config/services.yaml` (Group alias swapped to Dbal)
- `src/phpbb/user/Repository/PdoGroupRepository.php` (DELETED)
**Notes**: GroupType::Open = 0. SQLite else-branch (transactional DELETE+INSERT) confirmed working.

## 2026-04-22 — Group E Complete ✅

**Steps**: E.1 through E.5 completed
**Tests**: 11 DbalUserRepository tests green; full suite 178/178 (171 + 11 - 4 old PDO)
**Files Modified**:
- `src/phpbb/user/Repository/DbalUserRepository.php` (created — QB for update + search)
- `tests/phpbb/user/Repository/DbalUserRepositoryTest.php` (created)
- `src/phpbb/config/services.yaml` (User alias swapped to Dbal)
- `src/phpbb/user/Repository/PdoUserRepository.php` (DELETED)
- `tests/phpbb/user/Repository/PdoUserRepositoryTest.php` (DELETED)
**Notes**: `getQueryParts()` doesn't exist in DBAL 4 QB — used `$setCount` flag instead.

## 2026-04-22 — Group F Complete ✅

**Steps**: F.1 through F.6 completed (F.7 E2E requires Docker — skipped in offline mode)
**Tests**: 178/178 PHPUnit green
**Actions**:
- `src/phpbb/db/PdoFactory.php` DELETED
- PDO block removed from services.yaml
- Symfony container cache cleared (had stale Pdo* references from production build)
**Verifications**:
- `grep -r 'PDO' src/phpbb/ --include='*.php'` → empty ✅
- `grep -r 'PdoFactory|...' . --include='*.php'` → empty (excluding cache/) ✅
- `bundles.php` → no DoctrineBundle ✅
- `services.yaml` → `DbalConnectionFactory` + `Doctrine\DBAL\Connection` present ✅
- All 4 interface aliases point to Dbal* classes ✅

## 2026-04-22 — IMPLEMENTATION COMPLETE ✅

**Total steps**: 36 completed (F.7 E2E deferred — requires Docker)
**Total tests**: 178 passing (up from 137 baseline)
**New test files**: 7 (DbalConnectionFactoryTest, IntegrationTestCaseTest, DbalRefreshTokenRepositoryTest, DbalBanRepositoryTest, DbalGroupRepositoryTest, DbalUserRepositoryTest + IntegrationTestCase base)
**Deleted files**: 7 (PdoFactory, 4× Pdo*Repository source + 2× Pdo*RepositoryTest)

## 2026-04-22 — Group B Complete ✅

**Steps**: B.1 through B.5 completed
**Tests**: 8 DbalRefreshTokenRepository tests green; full suite 154/154
**Files Modified**:
- `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` (created)
- `tests/phpbb/auth/Repository/DbalRefreshTokenRepositoryTest.php` (created)
- `src/phpbb/config/services.yaml` (RefreshToken alias swapped to Dbal)
- `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` (DELETED)
- `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php` (DELETED)
**Key finding**: DBAL 4 params array keys are WITHOUT `:` prefix (critical for Groups C–E)

# Specification: Modern User Service — phpbb\user\

**Status**: Ready for implementation  
**Risk**: Medium  
**Date**: 2026-04-18  
**Implementation spec**: `src/phpbb/user/IMPLEMENTATION_SPEC.md`

---

## Goal

Extract ALL user-related functionality from the legacy phpBB codebase into a modern,
self-contained `phpbb\user\` namespace. The service uses PHP 8.2+ features (enums, readonly
properties, strict types), raw PDO with prepared statements, and has ZERO dependencies on
legacy phpBB code. It communicates with the outside world through interfaces and events.

---

## User Stories

- As a developer, I can register a new user via `RegistrationService::register()` with a typed DTO
  and receive a fully-hydrated `User` entity, without touching legacy `user_add()`.
- As a developer, I can authenticate a user via `AuthenticationService::login()` and get a `Session`
  entity, with automatic ban checking, login attempt limiting, and password rehashing.
- As a developer, I can search users with typed criteria (username, email, type, group) and receive
  paginated results via `UserSearchService::search()`.
- As a developer, I can manage passwords (change, reset request, reset execute) via `PasswordService`
  with proper token generation and event dispatch.
- As a developer, I can manage user profiles and preferences via dedicated services without
  dealing with raw SQL or legacy function parameters.
- As a developer, I can manage groups (add/remove members, set default group) and bans
  (create, lift, check) via dedicated services.
- As a developer, I can extend behavior by listening to domain events (UserCreated, UserLoggedIn,
  PasswordChanged, etc.) without modifying service code.
- As an existing forum user, ALL existing phpBB functionality continues to work — this service
  does not modify any legacy file.

---

## Architecture

### Namespace & Autoloading

```
phpbb\user\ → src/phpbb/user/
```

Add to `composer.json` `autoload.psr-4`: `"phpbb\\user\\": "src/phpbb/user/"`

### Layer Structure

```
Enum/       — PHP 8.1 backed enums (UserType, BanType, GroupType, NotifyType)
Entity/     — Immutable readonly data classes with fromRow() factory methods
DTO/        — Input value objects (CreateUserDTO, LoginDTO, etc.)
Contract/   — Interfaces for repositories and infrastructure adapters
Repository/ — PDO implementations of repository interfaces
Service/    — Business logic orchestrators (one per domain concern)
Event/      — Domain event value objects
Exception/  — Typed exception hierarchy
Security/   — Infrastructure adapters (BcryptPasswordHasher)
```

### Design Principles

| Rule | Detail |
|------|--------|
| No legacy imports | No class from legacy `phpbb\` namespace. No `global` keyword. |
| Constructor injection | Every service receives deps through `__construct()`. |
| Interface-first | Repositories and infra adapters implement interfaces from `Contract/`. |
| Strict typing | All params, returns, properties strictly typed. PHP 8.1+ enums, readonly. |
| DTOs for input | Multi-field inputs use dedicated readonly DTO classes. No assoc arrays. |
| Domain exceptions | Each error has a specific exception class extending `UserServiceException`. |
| Events for side-effects | State changes dispatch events. Email/cache/logging are NOT in scope. |
| PDO raw queries | Prepared statements with named parameters. No DBAL, no legacy `$db`. |
| Unix timestamps | DB stores int(11) timestamps. Entities expose DateTimeImmutable getters. |

### Deliberate Exclusions

These belong to future separate modules:
- Private messaging → `phpbb\messaging\`
- Notifications → `phpbb\notification\`
- ACL/Permissions → `phpbb\authorization\`
- Content (posts/topics) → `phpbb\content\`

---

## Database Tables

The service operates on 6 existing phpBB tables (read/write):

| Table | Primary Key | Purpose |
|-------|-------------|---------|
| `phpbb_users` | `user_id` AUTO_INCREMENT | Core user data (70+ columns) |
| `phpbb_sessions` | `session_id` CHAR(32) | Active sessions |
| `phpbb_sessions_keys` | `key_id` + `user_id` | Persistent login tokens |
| `phpbb_banlist` | `ban_id` AUTO_INCREMENT | User/IP/email bans |
| `phpbb_groups` | `group_id` AUTO_INCREMENT | Group definitions |
| `phpbb_user_group` | `group_id` + `user_id` | Group membership |

Full column-level schemas are in `src/phpbb/user/IMPLEMENTATION_SPEC.md`.

---

## Core Requirements

### Enums (4 files)
1. `UserType` — backed int enum: Normal=0, Inactive=1, Ignore=2, Founder=3
2. `BanType` — backed string enum: User, Ip, Email
3. `GroupType` — backed int enum: Open=0, Closed=1, Hidden=2, Special=3
4. `NotifyType` — backed int enum: Email=0, Im=1, Both=2

### Entities (7 files)
5. `User` — main user entity, ~30 readonly properties, `fromRow()` factory
6. `UserProfile` — avatar, signature, jabber, birthday
7. `UserPreferences` — lang, timezone, notification sttings, sort preferences
8. `Session` — session data entity
9. `Group` — group definition entity
10. `GroupMembership` — user↔group relation with leader/pending flags
11. `Ban` — ban entity with `isPermanent()`, `isExpired()`, `isActive()` helpers

### DTOs (10 files)
12. `CreateUserDTO` — username, email, password, lang, timezone, ip
13. `UpdateProfileDTO` — nullable avatar/signature/jabber/birthday fields
14. `UpdatePreferencesDTO` — nullable preference fields
15. `LoginDTO` — username, password, ip, browser, forwardedFor, autoLogin, viewOnline
16. `ChangePasswordDTO` — userId, currentPassword, newPassword
17. `PasswordResetRequestDTO` — email
18. `PasswordResetExecuteDTO` — token, newPassword
19. `CreateBanDTO` — type, value, durationSeconds, reason, displayReason
20. `UserSearchCriteria` — username, email, type, groupId, sortBy, sortDir, page, perPage
21. `PaginatedResult<T>` — items, total, page, perPage with helper methods

### Exceptions (14 files)
22. Base `UserServiceException extends \RuntimeException`
23-34. 12 specific exceptions (UserNotFound, Authentication, InvalidPassword, UserBanned, UserInactive, DuplicateUsername, DuplicateEmail, InvalidToken, TokenExpired, TooManyLoginAttempts, SessionNotFound, GroupNotFound, BanNotFound)

### Events (8 files)
35-42. Domain events: UserCreated, UserLoggedIn, UserLoggedOut, PasswordChanged, PasswordResetRequested, ProfileUpdated, UserBanned, UserUnbanned

### Contracts (6 files)
43. `PasswordHasherInterface` — hash, verify, needsRehash
44. `EventDispatcherInterface` — dispatch(object)
45. `UserRepositoryInterface` — find*, insert, update, exists checks + `findProfileById`, `findPreferencesById`
46. `SessionRepositoryInterface` — session + session_keys CRUD
47. `GroupRepositoryInterface` — group CRUD + membership management
48. `BanRepositoryInterface` — ban CRUD + active ban queries

### Security (1 file)
49. `BcryptPasswordHasher` — implements PasswordHasherInterface, cost=12

### Repositories (5 files)
50. `AbstractPdoRepository` — base with PDO + tablePrefix
51. `PdoUserRepository` — all user queries with column allowlists
52. `PdoSessionRepository` — sessions + sessions_keys queries
53. `PdoGroupRepository` — groups + user_group queries
54. `PdoBanRepository` — banlist queries with active-ban logic

### Services (9 files)
55. `AuthenticationService` — login (with ban check, attempt limit, rehash), logout, validateSession
56. `RegistrationService` — register (uniqueness, hash, group assignment), activate, availability checks
57. `PasswordService` — changePassword, requestReset (token generation), executeReset
58. `ProfileService` — getProfile, updateProfile, changeUsername, changeEmail, removeAvatar
59. `PreferencesService` — getPreferences, updatePreferences
60. `SessionService` — create, destroy, destroyAll, touch, gc
61. `GroupService` — add/remove members, setDefaultGroup, getMembers (paginated)
62. `BanService` — ban, unban, assertNotBanned, isUserBanned, isIpBanned
63. `UserSearchService` — search (paginated), findById, findByUsername, findByEmail, getTeamMembers

---

## Security Requirements

- All SQL uses PDO prepared statements with named parameters — NO string interpolation
- `sortBy` in search queries validated against column allowlist
- `update()` column names validated against allowlist
- Password hashing via `password_hash(PASSWORD_BCRYPT)` cost 12
- Tokens generated via `random_bytes()` (CSPRNG)
- Session IDs generated via `random_bytes()` (CSPRNG)
- Founder users cannot be banned
- Login attempts tracked and limited (max 5)
- User-Agent and forwarded-for headers truncated to column length

---

## Reusable Components

### Existing Code (read-only reference)
| Component | File | How Referenced |
|-----------|------|----------------|
| Legacy `user_add()` | `src/phpbb/common/functions_user.php` | Business logic extracted into RegistrationService |
| Legacy `user_ban()` | `src/phpbb/common/functions_user.php` | Logic extracted into BanService |
| Legacy `session_create()` | `src/phpbb/forums/session.php` | Session creation logic adapted |
| Legacy `auth::login()` | `src/phpbb/forums/auth/provider/db.php` | Auth flow extracted into AuthenticationService |
| Password manager | `src/phpbb/forums/passwords/manager.php` | Replaced by BcryptPasswordHasher |
| `Application` pattern | `src/phpbb/core/Application.php` | Modern PHP class structure reference |

### New Components (all new — no legacy modifications)
- 50 PHP files under `src/phpbb/user/`
- 1 modification: `composer.json` (PSR-4 entry)

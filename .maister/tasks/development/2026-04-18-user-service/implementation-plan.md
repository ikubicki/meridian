# Implementation Plan: Modern User Service — phpbb\user\

**Spec**: `spec.md`  
**Implementation spec**: `src/phpbb/user/IMPLEMENTATION_SPEC.md`  
**Date**: 2026-04-18  
**Risk**: Medium  
**Testing approach**: Syntax validation via `php -l`, integration smoke tests via PHP scripts.

---

## Overview

| Metric | Value |
|--------|-------|
| Task Groups | 10 |
| New files | ~50 |
| Modified files | 1 (composer.json) |
| Total checklist items | ~85 |
| Acceptance tests | Per-group syntax + instantiation checks |

---

## Execution Order

1. **Group 1** — Foundation (PSR-4 autoload) — no deps
2. **Group 2** — Enums — depends on Group 1
3. **Group 3** — Exceptions — depends on Group 1
4. **Group 4** — Events — depends on Groups 1, 2
5. **Group 5** — Entities — depends on Groups 1, 2
6. **Group 6** — DTOs — depends on Groups 1, 2
7. **Group 7** — Contracts — depends on Groups 5, 6
8. **Group 8** — Security (BcryptPasswordHasher) — depends on Group 7
9. **Group 9** — Repositories — depends on Groups 5, 6, 7
10. **Group 10** — Services — depends on everything above

---

## Group 1: Foundation

**Dependencies**: None  
**Files**: 1 modified  

### Tasks

- [ ] 1.1 **MOD `composer.json`** — add `"phpbb\\user\\": "src/phpbb/user/"` to `autoload.psr-4`
  - Insert alphabetically among existing entries
- [ ] 1.2 **Run `composer dump-autoload`** inside Docker container
  - `docker compose exec app composer dump-autoload`
  - Verify: `vendor/composer/autoload_psr4.php` contains `phpbb\\user\\`
- [ ] 1.3 **CREATE directory structure**
  - Create all directories: `src/phpbb/user/{Enum,Entity,DTO,Contract,Repository,Service,Event,Exception,Security}`

### Acceptance Criteria
- `composer dump-autoload` exits 0
- `vendor/composer/autoload_psr4.php` contains `phpbb\\user\\` entry

---

## Group 2: Enums

**Dependencies**: Group 1  
**Files**: 4 new  

### Tasks

- [ ] 2.1 **CREATE `src/phpbb/user/Enum/UserType.php`**
  - `enum UserType: int` with cases Normal=0, Inactive=1, Ignore=2, Founder=3
  - `declare(strict_types=1)`, namespace `phpbb\user\Enum`, no closing tag

- [ ] 2.2 **CREATE `src/phpbb/user/Enum/BanType.php`**
  - `enum BanType: string` with cases User='user', Ip='ip', Email='email'

- [ ] 2.3 **CREATE `src/phpbb/user/Enum/GroupType.php`**
  - `enum GroupType: int` with cases Open=0, Closed=1, Hidden=2, Special=3

- [ ] 2.4 **CREATE `src/phpbb/user/Enum/NotifyType.php`**
  - `enum NotifyType: int` with cases Email=0, Im=1, Both=2

- [ ] 2.5 **Syntax check**: `php -l` all 4 files

### Acceptance Criteria
- All 4 files pass `php -l`
- Each enum is backed (int or string) with exact values from spec

---

## Group 3: Exceptions

**Dependencies**: Group 1  
**Files**: 14 new  

### Tasks

- [ ] 3.1 **CREATE `src/phpbb/user/Exception/UserServiceException.php`**
  - Abstract class extending `\RuntimeException`
  - Namespace `phpbb\user\Exception`

- [ ] 3.2 **CREATE all 12 specific exception classes** (each extends `UserServiceException`):
  - `UserNotFoundException` (message: 'User not found', code: 404)
  - `AuthenticationException` (message: 'Invalid username or password', code: 401)
  - `InvalidPasswordException` (message: 'Current password is incorrect', code: 401)
  - `UserBannedException` (message: 'User is banned', code: 403)
  - `UserInactiveException` (message: 'User account is inactive', code: 403)
  - `DuplicateUsernameException` (message: 'Username already taken', code: 409)
  - `DuplicateEmailException` (message: 'Email already registered', code: 409)
  - `InvalidTokenException` (message: 'Invalid token', code: 400)
  - `TokenExpiredException` (message: 'Token has expired', code: 400)
  - `TooManyLoginAttemptsException` (message: 'Too many login attempts', code: 429)
  - `SessionNotFoundException` (message: 'Session not found', code: 404)
  - `GroupNotFoundException` (message: 'Group not found', code: 404)
  - `BanNotFoundException` (message: 'Ban not found', code: 404)

  Each file: `declare(strict_types=1)`, proper namespace, `final class`, constructor with default message/code and optional `?\Throwable $previous`.

- [ ] 3.3 **Syntax check**: `php -l` all 14 files

### Acceptance Criteria
- All 14 files pass `php -l`
- Each exception has default message and HTTP-like status code
- All extend `UserServiceException`

---

## Group 4: Events

**Dependencies**: Groups 1, 2  
**Files**: 8 new  

### Tasks

- [ ] 4.1 **CREATE all 8 event classes** as `final readonly class` in `phpbb\user\Event`:

  | File | Constructor params |
  |------|-------------------|
  | `UserCreatedEvent.php` | `int $userId, string $username, string $email, int $timestamp` |
  | `UserLoggedInEvent.php` | `int $userId, string $sessionId, string $ip, int $timestamp` |
  | `UserLoggedOutEvent.php` | `int $userId, string $sessionId, int $timestamp` |
  | `PasswordChangedEvent.php` | `int $userId, int $timestamp` |
  | `PasswordResetRequestedEvent.php` | `int $userId, string $email, string $token, int $timestamp` |
  | `ProfileUpdatedEvent.php` | `int $userId, array $changedFields, int $timestamp` |
  | `UserBannedEvent.php` | `int $banId, BanType $type, string $value, ?int $until, int $timestamp` |
  | `UserUnbannedEvent.php` | `int $banId, int $timestamp` |

  Each: `declare(strict_types=1)`, namespace `phpbb\user\Event`, public promoted readonly properties.
  Note: `UserBannedEvent` imports `phpbb\user\Enum\BanType`, `ProfileUpdatedEvent` uses `array` (not readonly).
  For `ProfileUpdatedEvent`: use `public readonly int $userId, public readonly array $changedFields, public readonly int $timestamp`.

- [ ] 4.2 **Syntax check**: `php -l` all 8 files

### Acceptance Criteria
- All 8 files pass `php -l`
- Each event is a `final readonly class` (except if `array` property prevents it — then `final class` with readonly on individual properties)

---

## Group 5: Entities

**Dependencies**: Groups 1, 2  
**Files**: 7 new  

### Tasks

- [ ] 5.1 **CREATE `src/phpbb/user/Entity/User.php`**
  - `final class` with ~30 `public readonly` constructor-promoted properties
  - `static fromRow(array $row): self` — map ALL columns per IMPLEMENTATION_SPEC.md mapping table
  - Helper methods: `isFounder()`, `isActive()`, `isInactive()`, `getRegisteredAt()`, `getLastVisit()`, `getLastActive()`
  - DateTimeImmutable getters convert from unix timestamps
  - `UserType::from((int)$row['user_type'])` for enum conversion
  - Empty string → null for `resetToken`, `activationKey`

- [ ] 5.2 **CREATE `src/phpbb/user/Entity/UserProfile.php`**
  - Properties: userId, avatar, avatarType, avatarWidth, avatarHeight, signature, signatureBbcodeUid, signatureBbcodeBitfield, jabber, birthday
  - `static fromRow(array $row): self` with column mapping from spec

- [ ] 5.3 **CREATE `src/phpbb/user/Entity/UserPreferences.php`**
  - Properties: userId, lang, timezone, dateformat, style, notifyType (NotifyType enum), notifyOnReply (bool), notifyOnPm (bool), allowPm (bool), allowViewOnline (bool), allowViewEmail (bool), allowMassEmail (bool), topicShowDays, topicSortbyType, topicSortbyDir, postShowDays, postSortbyType, postSortbyDir
  - `static fromRow(array $row): self`

- [ ] 5.4 **CREATE `src/phpbb/user/Entity/Session.php`**
  - Properties: id (string), userId, lastVisit, start, time, ip, browser, forwardedFor, page, viewOnline (bool), autoLogin (bool), isAdmin (bool), forumId
  - `static fromRow(array $row): self`

- [ ] 5.5 **CREATE `src/phpbb/user/Entity/Group.php`**
  - Properties: id, name, type (GroupType enum), description, colour, rank, founderManage (bool), skipAuth (bool), display (bool), receivePm (bool), messageLimit, maxRecipients, legend
  - `static fromRow(array $row): self`

- [ ] 5.6 **CREATE `src/phpbb/user/Entity/GroupMembership.php`**
  - Properties: groupId, userId, isLeader (bool), isPending (bool)
  - `static fromRow(array $row): self`

- [ ] 5.7 **CREATE `src/phpbb/user/Entity/Ban.php`**
  - Properties: id, type (BanType enum), userId, ip, email, start, end, isExclude (bool), reason, displayReason
  - `static fromRow(array $row): self` — detect BanType from row values (ban_userid > 0 → User, ban_ip != '' → Ip, else Email)
  - Helpers: `isPermanent()`, `isExpired()`, `isActive()`

- [ ] 5.8 **Syntax check**: `php -l` all 7 files

### Acceptance Criteria
- All 7 files pass `php -l`
- Each `fromRow()` maps DB column names to camelCase properties
- Enum properties use `Enum::from()` for conversion
- Bool properties cast `(bool)` from DB tinyint values

---

## Group 6: DTOs

**Dependencies**: Groups 1, 2  
**Files**: 10 new  

### Tasks

- [ ] 6.1 **CREATE all 10 DTO classes** as `final readonly class` in `phpbb\user\DTO`:

  | File | Constructor params |
  |------|-------------------|
  | `CreateUserDTO.php` | `string $username, string $email, string $password, string $lang = 'en', string $timezone = 'UTC', string $ip = ''` |
  | `LoginDTO.php` | `string $username, string $password, string $ip = '', string $browser = '', string $forwardedFor = '', bool $autoLogin = false, bool $viewOnline = true` |
  | `ChangePasswordDTO.php` | `int $userId, string $currentPassword, string $newPassword` |
  | `PasswordResetRequestDTO.php` | `string $email` |
  | `PasswordResetExecuteDTO.php` | `string $token, string $newPassword` |
  | `UpdateProfileDTO.php` | `?string $avatar = null, ?string $avatarType = null, ?int $avatarWidth = null, ?int $avatarHeight = null, ?string $signature = null, ?string $jabber = null, ?string $birthday = null` |
  | `UpdatePreferencesDTO.php` | `?string $lang = null, ?string $timezone = null, ?string $dateformat = null, ?int $style = null, ?NotifyType $notifyType = null, ?bool $notifyOnReply = null, ?bool $notifyOnPm = null, ?bool $allowPm = null, ?bool $allowViewOnline = null, ?bool $allowViewEmail = null, ?bool $allowMassEmail = null` |
  | `CreateBanDTO.php` | `BanType $type, string $value, ?int $durationSeconds = null, string $reason = '', string $displayReason = ''` |
  | `UserSearchCriteria.php` | `?string $username = null, ?string $email = null, ?UserType $type = null, ?int $groupId = null, string $sortBy = 'username', string $sortDir = 'ASC', int $page = 1, int $perPage = 25` with `getOffset(): int` method |
  | `PaginatedResult.php` | `array $items, int $total, int $page, int $perPage` with `totalPages()`, `hasNextPage()`, `hasPreviousPage()` methods. Add `@template T` PHPDoc. |

  Each: `declare(strict_types=1)`, namespace `phpbb\user\DTO`, no validation logic.
  DTOs importing enums: `UpdatePreferencesDTO` → NotifyType, `CreateBanDTO` → BanType, `UserSearchCriteria` → UserType.

- [ ] 6.2 **Syntax check**: `php -l` all 10 files

### Acceptance Criteria
- All 10 files pass `php -l`
- All classes are `final readonly class`
- No validation logic inside DTOs

---

## Group 7: Contracts (Interfaces)

**Dependencies**: Groups 5, 6  
**Files**: 6 new  

### Tasks

- [ ] 7.1 **CREATE `src/phpbb/user/Contract/PasswordHasherInterface.php`**
  - Methods: `hash(string $password): string`, `verify(string $password, string $hash): bool`, `needsRehash(string $hash): bool`

- [ ] 7.2 **CREATE `src/phpbb/user/Contract/EventDispatcherInterface.php`**
  - Methods: `dispatch(object $event): void`

- [ ] 7.3 **CREATE `src/phpbb/user/Contract/UserRepositoryInterface.php`**
  - Methods: `findById(int $id): ?User`, `findByUsername(string $username): ?User`, `findByUsernameClean(string $usernameClean): ?User`, `findByEmail(string $email): ?User`, `findByResetToken(string $token): ?User`, `findByActivationKey(string $key): ?User`, `findProfileById(int $userId): ?UserProfile`, `findPreferencesById(int $userId): ?UserPreferences`, `search(UserSearchCriteria $criteria): PaginatedResult`, `insert(array $data): int`, `update(int $userId, array $data): void`, `incrementLoginAttempts(int $userId): void`, `resetLoginAttempts(int $userId): void`, `usernameExists(string $usernameClean): bool`, `emailExists(string $email): bool`
  - Import: `User`, `UserProfile`, `UserPreferences` from Entity, `UserSearchCriteria`, `PaginatedResult` from DTO

- [ ] 7.4 **CREATE `src/phpbb/user/Contract/SessionRepositoryInterface.php`**
  - Methods: `findById(string $sessionId): ?Session`, `findByUserId(int $userId): array`, `insert(array $data): void`, `updateTime(string $sessionId, int $time, string $page): void`, `delete(string $sessionId): void`, `deleteByUserId(int $userId): void`, `deleteExpired(int $maxLifetime): int`, `insertKey(string $keyId, int $userId, string $ip): void`, `findKey(string $keyId, int $userId): ?array`, `deleteKey(string $keyId, int $userId): void`, `deleteKeysByUserId(int $userId): void`
  - Import: `Session` from Entity

- [ ] 7.5 **CREATE `src/phpbb/user/Contract/GroupRepositoryInterface.php`**
  - Methods: `findById(int $groupId): ?Group`, `findByName(string $name): ?Group`, `findAll(): array`, `getGroupsForUser(int $userId): array`, `getMemberships(int $userId): array`, `getMemberCount(int $groupId): int`, `getMembers(int $groupId, int $limit, int $offset): array`, `addMember(int $groupId, int $userId, bool $pending = false): void`, `removeMember(int $groupId, int $userId): void`, `isMember(int $groupId, int $userId): bool`, `setLeader(int $groupId, int $userId, bool $isLeader): void`, `updatePendingStatus(int $groupId, int $userId, bool $pending): void`
  - Import: `Group`, `GroupMembership` from Entity
  - Return type docs: `getGroupsForUser()` returns `Group[]`, `getMemberships()` returns `GroupMembership[]`, `getMembers()` returns `User[]`

- [ ] 7.6 **CREATE `src/phpbb/user/Contract/BanRepositoryInterface.php`**
  - Methods: `findById(int $banId): ?Ban`, `findActiveByUserId(int $userId): ?Ban`, `findActiveByIp(string $ip): ?Ban`, `findActiveByEmail(string $email): ?Ban`, `findAll(BanType $type, bool $activeOnly = true): array`, `insert(array $data): int`, `delete(int $banId): void`
  - "Active" definition in SQL: `ban_exclude = 0 AND (ban_end = 0 OR ban_end > UNIX_TIMESTAMP())`

- [ ] 7.7 **Syntax check**: `php -l` all 6 files

### Acceptance Criteria
- All 6 files pass `php -l`
- Each interface only uses types from Entity/, DTO/, and PHP builtins
- No implementation details in interfaces

---

## Group 8: Security

**Dependencies**: Group 7  
**Files**: 1 new  

### Tasks

- [ ] 8.1 **CREATE `src/phpbb/user/Security/BcryptPasswordHasher.php`**
  - `final class` implementing `PasswordHasherInterface`
  - Constructor: `private readonly int $cost = 12`
  - `hash()`: `password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->cost])`
  - `verify()`: `password_verify($password, $hash)`
  - `needsRehash()`: `password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost])`

- [ ] 8.2 **Syntax check**: `php -l`

### Acceptance Criteria
- File passes `php -l`
- Uses `PASSWORD_BCRYPT` with configurable cost
- Implements all 3 interface methods

---

## Group 9: Repositories

**Dependencies**: Groups 5, 6, 7  
**Files**: 5 new  

### SECURITY CRITICAL — All repositories MUST:
- Use PDO prepared statements with named `:params` for ALL queries
- Validate `sortBy` against column allowlist before use in ORDER BY
- Validate column names in `update()` against allowlist before use in SET clause
- NEVER interpolate user input into SQL strings

### Tasks

- [ ] 9.1 **CREATE `src/phpbb/user/Repository/AbstractPdoRepository.php`**
  - `abstract class` with constructor: `protected readonly \PDO $pdo, protected readonly string $tablePrefix = 'phpbb_'`
  - Helper method `table(string $name): string` returns `$this->tablePrefix . $name`

- [ ] 9.2 **CREATE `src/phpbb/user/Repository/PdoUserRepository.php`**
  - Extends `AbstractPdoRepository`, implements `UserRepositoryInterface`
  - Define `private const ALLOWED_COLUMNS` allowlist for `update()` (see IMPLEMENTATION_SPEC.md)
  - Define `private const ALLOWED_SORT` allowlist: `['username', 'user_regdate', 'user_posts', 'user_last_active', 'user_email']`
  - `search()`: build WHERE clauses dynamically for non-null criteria fields; use `LIKE :param` for username/email; validate sortBy; run COUNT + SELECT with LIMIT/OFFSET; return `PaginatedResult`
  - `insert()`: dynamic INSERT from `$data` array; return `(int) $this->pdo->lastInsertId()`
  - `update()`: validate keys against ALLOWED_COLUMNS, build dynamic UPDATE SET; use WHERE user_id = :id
  - `findProfileById()`: SELECT profile columns, return `UserProfile::fromRow()`
  - `findPreferencesById()`: SELECT preference columns, return `UserPreferences::fromRow()`
  - All find methods return `Entity::fromRow()` or null

- [ ] 9.3 **CREATE `src/phpbb/user/Repository/PdoSessionRepository.php`**
  - Extends `AbstractPdoRepository`, implements `SessionRepositoryInterface`
  - Tables: `{prefix}sessions` and `{prefix}sessions_keys`
  - `deleteExpired()`: return `$stmt->rowCount()`
  - `findKey()`: returns raw `?array` (not an entity)

- [ ] 9.4 **CREATE `src/phpbb/user/Repository/PdoGroupRepository.php`**
  - Extends `AbstractPdoRepository`, implements `GroupRepositoryInterface`
  - Tables: `{prefix}groups` and `{prefix}user_group`
  - `getGroupsForUser()`: JOIN groups ↔ user_group WHERE user_pending = 0
  - `getMembers()`: JOIN users ↔ user_group WHERE user_pending = 0, ORDER BY username ASC
  - `getMemberCount()`: COUNT with user_pending = 0

- [ ] 9.5 **CREATE `src/phpbb/user/Repository/PdoBanRepository.php`**
  - Extends `AbstractPdoRepository`, implements `BanRepositoryInterface`
  - "Active" SQL condition: `ban_exclude = 0 AND (ban_end = 0 OR ban_end > :now)` — pass `time()` as `:now`
  - `findAll()`: filter by type (IF ban_userid > 0 for User, etc.) + optional activeOnly flag

- [ ] 9.6 **Syntax check**: `php -l` all 5 files

### Acceptance Criteria
- All 5 files pass `php -l`
- ZERO raw SQL interpolation — all values via prepared statement parameters
- Column allowlists defined and enforced in PdoUserRepository
- All find methods return null or entity; never throw on not-found

---

## Group 10: Services

**Dependencies**: All groups above  
**Files**: 9 new  

### Tasks

- [ ] 10.1 **CREATE `src/phpbb/user/Service/SessionService.php`**
  - Constructor: `SessionRepositoryInterface $sessions`
  - `create()`: generate session_id via `md5(random_bytes(16) . $ip . $browser . time())`, INSERT, optionally create persistent key, return Session entity
  - `findById()`, `destroy()` (throw SessionNotFoundException), `destroyAllForUser()`, `touch()`, `gc()`

- [ ] 10.2 **CREATE `src/phpbb/user/Service/BanService.php`**
  - Constructor: `BanRepositoryInterface $bans, UserRepositoryInterface $users, EventDispatcherInterface $events`
  - `ban()`: resolve user ID for BanType::User (find by username_clean), prevent banning founders, insert ban, dispatch UserBannedEvent
  - `unban()`: find ban, delete, dispatch UserUnbannedEvent
  - `assertNotBanned()`: check userId, ip, email — throw UserBannedException if any active ban found
  - `isUserBanned()`, `isIpBanned()`, `getActiveBans()`

- [ ] 10.3 **CREATE `src/phpbb/user/Service/AuthenticationService.php`**
  - Constructor: `UserRepositoryInterface $users, SessionService $sessions, BanService $bans, PasswordHasherInterface $hasher, EventDispatcherInterface $events`
  - `login(LoginDTO)`: find by username_clean → assertNotBanned → check attempts (max 5) → check inactive → verify password (increment on fail) → reset attempts → rehash if needed → update last visit → create session → dispatch UserLoggedInEvent → return Session
  - `logout(string $sessionId)`: find session → destroy → dispatch UserLoggedOutEvent
  - `validateSession(string $sessionId): ?User`: find session → find user → return or null

- [ ] 10.4 **CREATE `src/phpbb/user/Service/RegistrationService.php`**
  - Constructor: `UserRepositoryInterface $users, GroupRepositoryInterface $groups, PasswordHasherInterface $hasher, EventDispatcherInterface $events`
  - `register(CreateUserDTO)`: check uniqueness → find REGISTERED group → hash password → generate form_salt → insert user with ALL default columns → add to group → dispatch UserCreatedEvent → return User
  - `activateByKey(string)`: find by actkey → validate expiration → update type to Normal → clear actkey
  - `usernameAvailable()`, `emailAvailable()`

- [ ] 10.5 **CREATE `src/phpbb/user/Service/PasswordService.php`**
  - Constructor: `UserRepositoryInterface $users, PasswordHasherInterface $hasher, EventDispatcherInterface $events`
  - `changePassword(ChangePasswordDTO)`: find user → verify current password → hash new → update → dispatch PasswordChangedEvent
  - `requestReset(PasswordResetRequestDTO)`: find by email → generate token (64 hex chars) → set expiration (24h) → update → dispatch PasswordResetRequestedEvent → return token
  - `executeReset(PasswordResetExecuteDTO)`: find by token → check expiration → hash new password → clear token → dispatch PasswordChangedEvent

- [ ] 10.6 **CREATE `src/phpbb/user/Service/ProfileService.php`**
  - Constructor: `UserRepositoryInterface $users, EventDispatcherInterface $events`
  - `getProfile(int)`: delegate to `users->findProfileById()`
  - `updateProfile(int, UpdateProfileDTO)`: build update array from non-null DTO fields → update → dispatch ProfileUpdatedEvent
  - `changeUsername(int, string)`: check uniqueness → update username + username_clean
  - `changeEmail(int, string)`: check uniqueness → update email
  - `removeAvatar(int)`: clear avatar fields

- [ ] 10.7 **CREATE `src/phpbb/user/Service/PreferencesService.php`**
  - Constructor: `UserRepositoryInterface $users`
  - `getPreferences(int)`: delegate to `users->findPreferencesById()`
  - `updatePreferences(int, UpdatePreferencesDTO)`: build update array from non-null DTO fields (map enum values via `->value`) → update

- [ ] 10.8 **CREATE `src/phpbb/user/Service/GroupService.php`**
  - Constructor: `GroupRepositoryInterface $groups, UserRepositoryInterface $users`
  - `getGroupsForUser(int)`, `getMemberships(int)`, `addToGroup()` (check group exists, check not already member), `removeFromGroup()`, `setDefaultGroup()` (check membership, update user group_id + colour), `isInGroup()`, `getMembers()` (paginated via PaginatedResult)

- [ ] 10.9 **CREATE `src/phpbb/user/Service/UserSearchService.php`**
  - Constructor: `UserRepositoryInterface $users, GroupRepositoryInterface $groups`
  - `search(UserSearchCriteria)`: delegate to repository
  - `findById(int)`: throw UserNotFoundException if null
  - `findByUsername(string)`, `findByEmail(string)`: return nullable
  - `getTeamMembers()`: find ADMINISTRATORS + GLOBAL_MODERATORS groups, merge members, deduplicate by user_id

- [ ] 10.10 **Syntax check**: `php -l` all 9 files

### Acceptance Criteria
- All 9 files pass `php -l`
- Each service receives only interfaces/services via constructor — no PDO, no legacy classes
- AuthenticationService follows the exact 10-step login flow from spec
- RegistrationService sets ALL required default column values on insert
- All state-changing methods dispatch appropriate events
- No `global`, no static state, no legacy imports

---

## Final Validation

After all groups complete:

- [ ] **F.1** Run `composer dump-autoload` — verify no errors
- [ ] **F.2** Run `php -l` on ALL ~50 files: `find src/phpbb/user -name '*.php' -exec php -l {} \;`
- [ ] **F.3** Verify zero `global` keyword: `grep -r 'global ' src/phpbb/user/` should return nothing
- [ ] **F.4** Verify zero legacy imports: `grep -r 'use phpbb\\' src/phpbb/user/ | grep -v 'use phpbb\\user\\'` should return nothing
- [ ] **F.5** Verify no SQL interpolation: `grep -rn '\$.*\.\s*\$' src/phpbb/user/Repository/` — review any matches
- [ ] **F.6** Count files: `find src/phpbb/user -name '*.php' | wc -l` should be ~50

---

## Reference

Full implementation details (property mappings, SQL queries, column allowlists, complete method bodies) are in:
**`src/phpbb/user/IMPLEMENTATION_SPEC.md`**

Consult this file for every class implementation. It contains:
- Exact `fromRow()` column→property mappings for every Entity
- SQL query patterns for every Repository method
- Complete business logic flow for every Service method
- Column allowlists for `update()` and `sortBy`
- Constructor signatures for every class

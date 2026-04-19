# High-Level Design: `phpbb\user` Service

## Design Overview

**Business context**: The User service is the most consumed service in the new phpBB architecture — every other service depends on it. Legacy user management is scattered across ~11,000 LOC of procedural code (`session.php`, `functions_user.php`, `profilefields/manager.php`, `auth.php`), tightly coupled to the global `$user` object, untestable, and un-reusable. This service replaces all user data management with a clean, standalone PSR-4 service.

**Chosen approach**: A **data-focused User service** (`phpbb\user`) that owns all user state — CRUD, profile, preferences, groups, bans (permanent + shadow), and counters — but **does NOT own authentication**. Login/logout, sessions, and API tokens belong to `phpbb\auth`. The service uses **JSON columns** for both extensible profile fields (`profile_fields JSON`) and user preferences (`preferences JSON`), replacing legacy dynamic `pf_*` columns and the `user_options` bitfield. Extensibility follows the project convention: **DecoratorPipeline on user-facing CRUD** (registration, profile, search) and **domain events for lifecycle operations** (bans, groups, deletion). Shadow banning is implemented as a **separate `phpbb_shadow_bans` table** with binary user-level scope. User deletion supports **three modes** (retain/remove/soft) via event-driven cascade.

**Key decisions:**
- **Auth/User boundary** (ADR-001): User service is purely data management — Auth owns all authentication, sessions, and tokens
- **JSON profile fields** (ADR-002): Single `profile_fields JSON` column per user replaces legacy dynamic `pf_*` columns
- **JSON preferences** (ADR-003): Single `preferences JSON` column replaces `user_options` bitfield, typed via `UserPreferences` DTO
- **Full shadow ban** (ADR-004): Binary user-level shadow ban via separate `phpbb_shadow_bans` table, content filtered by response decorator in Threads
- **Three delete modes** (ADR-005): Retain (anonymize + reassign), Remove (hard delete + cascade events), Soft (deactivate + anonymize PII)
- **UserDisplayDTO** (ADR-006): Lightweight readonly DTO for batch cross-service lookups (id, username, colour, avatarUrl)
- **Decorators + Events** (ADR-007): DecoratorPipeline on register/profile/search; events on ban/group/delete/password
- **Group cascade rules** (ADR-008): Default group propagates colour (always), rank (if 0), avatar (if empty); reassignment priority hard-coded

---

## Architecture

### System Context (C4 Level 1)

```
                         ┌──────────────────────┐
                         │   Admin (ACP)         │
                         │   End User (Web/SPA)  │
                         │   API Consumer        │
                         └──────────┬────────────┘
                                    │ HTTP
                                    ▼
                         ┌──────────────────────┐
                         │   phpBB App Layer     │
                         │   REST Controllers    │
                         │   + Auth Middleware    │
                         └──────────┬────────────┘
                                    │ PHP method calls
                                    ▼
┌──────────────┐       ┌─────────────────────────────────┐       ┌──────────────┐
│ phpbb\auth   │◄──────│        phpbb\user               │──────►│ phpbb\threads│
│              │       │      (User Data Service)        │       │ (events)     │
│ imports:     │       │                                 │       └──────────────┘
│  User entity │       │  User CRUD, Profile, Prefs,     │
│  Group entity│       │  Groups, Bans, Shadow Bans,     │       ┌──────────────┐
│  GroupRepo   │       │  Counters, Search, Delete       │──────►│phpbb\notify  │
│  UserRepo    │       │                                 │       │ (events)     │
└──────────────┘       └──────────┬──────────────────────┘       └──────────────┘
                                  │              ▲
                                  │ PDO          │ Event listeners
                                  ▼              │
                       ┌──────────────────┐   ┌──┴───────────────┐
                       │     MySQL / DB   │   │  Plugin Event     │
                       │  phpbb_users     │   │  Listeners +      │
                       │  phpbb_groups    │   │  Decorators       │
                       │  phpbb_shadow_*  │   └──────────────────┘
                       └──────────────────┘
```

**External systems and their relationship to User service:**

| System | Relationship | Data Exchanged |
|--------|-------------|----------------|
| **phpbb\auth** | Hard import (entities + repositories) | `User` entity (id, type), `Group` entity (skipAuth), `GroupMembership`, `UserRepositoryInterface`, `GroupRepositoryInterface` |
| **phpbb\threads** | Bidirectional events | ← `PostCreatedEvent` etc. (counter updates); → denormalized username/colour at post write time |
| **phpbb\notifications** | Batch data consumer | `findDisplayByIds(int[])` → `UserDisplayDTO[]` |
| **phpbb\messaging** | Batch data + group checks | `findDisplayByIds(int[])`, `getMembershipsForUser(int)` for PM rules |
| **phpbb\hierarchy** | Minimal read | `user_lastmark` (int) from `User` entity |

### Container Overview (C4 Level 2)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                            phpbb\user                                        │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │                  Service Layer (Facade + Domain Logic)                  │  │
│  │                                                                        │  │
│  │  Request DTO ──► DecoratorPipeline ──► Service Logic                   │  │
│  │       ──► Domain Event ──► EventDispatcher ──► ResponseDecorator       │  │
│  │       ──► Response DTO / Entity returned to caller                     │  │
│  └──┬────────┬──────────┬──────────┬────────┬────────┬──────────┬────────┘  │
│     │        │          │          │        │        │          │            │
│  ┌──▼──┐ ┌──▼───┐  ┌───▼──┐  ┌───▼──┐ ┌──▼───┐ ┌──▼────┐ ┌──▼─────┐     │
│  │Regis│ │Profil│  │Prefs │  │Group │ │Ban   │ │Shadow │ │Admin   │     │
│  │trati│ │eSvc  │  │Svc   │  │Svc   │ │Svc   │ │BanSvc │ │UserSvc │     │
│  │onSvc│ │      │  │      │  │      │ │      │ │       │ │        │     │
│  │     │ │JSON  │  │JSON  │  │CRUD  │ │perm  │ │shadow │ │delete  │     │
│  │creat│ │field │  │prefs │  │memb  │ │ban   │ │ban    │ │deact   │     │
│  │e    │ │s     │  │      │  │lead  │ │exclu │ │filter │ │type    │     │
│  └──┬──┘ └──┬───┘  └──┬───┘  └──┬───┘ └──┬───┘ └──┬────┘ └──┬─────┘     │
│     │        │         │         │        │        │         │            │
│  ┌──▼──┐ ┌──▼───┐  ┌──▼───┐  ┌──▼──────┐                                 │
│  │Passw│ │User  │  │User  │  │Counter  │                                 │
│  │ordSv│ │Searc │  │Displ │  │Svc(evts)│                                 │
│  │c    │ │hSvc  │  │aySvc │  │         │                                 │
│  └──┬──┘ └──┬───┘  └──┬───┘  └──┬──────┘                                 │
│     │        │         │         │                                         │
│     └────────┴────┬────┴─────────┘                                         │
│                   │                                                         │
│            ┌──────▼──────┐                                                  │
│            │     PDO     │                                                  │
│            └─────────────┘                                                  │
│                                                                              │
│  ┌──────────────────────┐  ┌──────────────────────────────┐                 │
│  │ DecoratorPipeline    │  │ EventDispatcher               │                 │
│  │                      │  │                               │                 │
│  │ RequestDecorators    │  │ UserCreatedEvent              │                 │
│  │ ResponseDecorators   │  │ UserBannedEvent               │                 │
│  │ on: register,        │  │ UserGroupChangedEvent         │                 │
│  │ getProfile, search   │  │ UserDeletedEvent              │                 │
│  └──────────────────────┘  │ ... (20+ events)              │                 │
│                            └──────────────────────────────┘                 │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Container responsibilities:**

| Container | Responsibility |
|-----------|----------------|
| **RegistrationService** | Create new users, username/email availability, activation by key |
| **PasswordService** | Hash passwords, generate/execute reset tokens (Auth CALLS these) |
| **ProfileService** | Read/write avatar, signature, birthday, jabber + JSON profile fields |
| **PreferencesService** | Read/write JSON preferences (replacing bitfield) |
| **GroupService** | Group CRUD, membership add/remove/approve, leader management, default group cascade |
| **BanService** | Permanent bans (user/IP/email), ban exclude/whitelist, active ban checks |
| **ShadowBanService** | Shadow ban CRUD, `isShadowBanned()` check for Threads decorator |
| **UserSearchService** | Search by criteria, find by ID/username/email, team members |
| **UserDisplayService** | Batch `findDisplayByIds(int[])` → `UserDisplayDTO[]` for cross-service consumers |
| **AdminUserService** | Admin delete (3 modes), deactivate, type change, username change |
| **UserCounterService** | Event subscriber: handles `PostCreatedEvent` etc. from Threads → updates `user_posts`, `user_lastpost_time` |
| **DecoratorPipeline** | Ordered chain of request/response decorators on register, profile, search |
| **EventDispatcher** | Symfony EventDispatcher for 20+ domain events |

---

## Service Decomposition

### Internal Structure

```
phpbb\user\
├── Entity\
│   ├── User                    # Core identity (~15 fields)
│   ├── UserProfile             # Profile data + JSON profile fields
│   ├── UserPreferences         # Typed DTO from JSON preferences column
│   ├── Group                   # Group definition
│   ├── GroupMembership         # User↔Group join with leader/pending
│   ├── Ban                     # Permanent ban record
│   └── ShadowBan              # Shadow ban record
├── DTO\
│   ├── UserDisplayDTO          # Lightweight batch display (id, username, colour, avatarUrl)
│   ├── CreateUserDTO           # Registration input
│   ├── UpdateProfileDTO        # Profile update (avatar, sig, birthday, profileFields JSON)
│   ├── UpdatePreferencesDTO    # Preferences update (typed JSON)
│   ├── ChangePasswordDTO       # userId, currentPassword, newPassword
│   ├── PasswordResetRequestDTO # email
│   ├── PasswordResetExecuteDTO # token, newPassword
│   ├── CreateBanDTO            # type, value, duration, reasons
│   ├── ShadowBanDTO            # userId, reason, givenBy, duration
│   ├── UserSearchCriteria      # Filters + pagination + sort
│   ├── PaginatedResult<T>      # Generic paginated response
│   └── DeleteUserDTO           # userId, deleteMode (retain/remove/soft)
├── Enum\
│   ├── UserType                # Normal(0), Inactive(1), Bot(2), Founder(3)
│   ├── BanType                 # User, Ip, Email
│   ├── GroupType               # Open(0), Closed(1), Hidden(2), Special(3)
│   ├── DeleteMode              # Retain, Remove, Soft
│   └── InactiveReason          # Register(1), Profile(2), Manual(3), Remind(4)
├── Contract\
│   ├── UserRepositoryInterface
│   ├── GroupRepositoryInterface
│   ├── BanRepositoryInterface
│   ├── ShadowBanRepositoryInterface
│   └── PasswordHasherInterface
├── Service\
│   ├── RegistrationService
│   ├── PasswordService
│   ├── ProfileService
│   ├── PreferencesService
│   ├── GroupService
│   ├── BanService
│   ├── ShadowBanService
│   ├── UserSearchService
│   ├── UserDisplayService
│   ├── AdminUserService
│   └── UserCounterService     # Event subscriber for Threads events
├── Decorator\
│   ├── RequestDecoratorInterface
│   ├── ResponseDecoratorInterface
│   └── DecoratorPipeline
├── Event\                      # 20+ domain events (see catalog)
├── Exception\                  # 14+ typed exceptions with HTTP codes
├── Security\
│   └── Argon2PasswordHasher
└── Repository\                 # PDO implementations of contracts
```

### Services — Public Methods

| Service | Method | Description |
|---------|--------|-------------|
| **RegistrationService** | `register(CreateUserDTO): User` | Create user, add to groups, dispatch event |
| | `activateByKey(string $key): User` | Activate user by activation key |
| | `usernameAvailable(string): bool` | Check username uniqueness (+ disallowed patterns) |
| | `emailAvailable(string): bool` | Check email uniqueness (+ banned emails) |
| **PasswordService** | `hashPassword(string): string` | Hash with Argon2id |
| | `verifyPassword(string $plain, string $hash): bool` | Constant-time verify (Auth calls this) |
| | `needsRehash(string $hash): bool` | Check if hash needs upgrade |
| | `requestReset(PasswordResetRequestDTO): void` | Generate reset token, dispatch event |
| | `executeReset(PasswordResetExecuteDTO): void` | Validate token, update hash, dispatch event |
| | `changePassword(ChangePasswordDTO): void` | Verify old, set new, dispatch event |
| **ProfileService** | `getProfile(int $userId): UserProfile` | Load profile + JSON fields (decorated) |
| | `updateProfile(int $userId, UpdateProfileDTO): UserProfile` | Update profile + JSON fields |
| | `changeUsername(int $userId, string $new): void` | Validate + update + dispatch event |
| | `changeEmail(int $userId, string $new): void` | Validate + update |
| | `removeAvatar(int $userId): void` | Clear avatar fields |
| **PreferencesService** | `getPreferences(int $userId): UserPreferences` | Load + parse JSON prefs |
| | `updatePreferences(int $userId, UpdatePreferencesDTO): UserPreferences` | Merge + save JSON prefs |
| **GroupService** | `listGroups(?GroupType $filter): Group[]` | List groups optionally filtered by type |
| | `getGroup(int $groupId): Group` | Single group lookup |
| | `getGroupsForUser(int $userId): Group[]` | User's active memberships |
| | `getMembers(int $groupId, PaginationDTO): PaginatedResult<GroupMembership>` | Paginated members |
| | `addToGroup(int $groupId, int $userId, bool $leader, bool $pending): void` | Add membership |
| | `removeFromGroup(int $groupId, int $userId): void` | Remove + cascade default group |
| | `setDefaultGroup(int $userId, int $groupId): void` | Set default + cascade properties |
| | `approveMember(int $groupId, int $userId): void` | Approve pending membership |
| | `requestJoin(int $groupId, int $userId): void` | User requests to join (creates pending) |
| | `leave(int $groupId, int $userId): void` | User leaves group voluntarily |
| **BanService** | `ban(CreateBanDTO): Ban` | Create ban (user/IP/email), force logout |
| | `unban(int $banId): void` | Remove ban by ID |
| | `isUserBanned(int $userId): bool` | Check active bans (respects exclude) |
| | `isIpBanned(string $ip): bool` | Check IP against ban list |
| | `isEmailBanned(string $email): bool` | Check email against ban patterns |
| | `assertNotBanned(int $userId, string $ip, string $email): void` | Combined check, throws on ban |
| | `getActiveBans(?BanType $filter): PaginatedResult<Ban>` | List active bans |
| **ShadowBanService** | `apply(ShadowBanDTO): ShadowBan` | Apply shadow ban |
| | `remove(int $shadowBanId): void` | Remove shadow ban |
| | `isShadowBanned(int $userId): bool` | Quick boolean check (used by Threads decorator) |
| | `getShadowBan(int $userId): ?ShadowBan` | Full shadow ban record |
| | `listActive(): PaginatedResult<ShadowBan>` | Admin listing |
| **UserSearchService** | `search(UserSearchCriteria): PaginatedResult<User>` | Filtered + paginated search (decorated) |
| | `findById(int $userId): ?User` | Single user lookup |
| | `findByUsername(string): ?User` | By clean username |
| | `findByEmail(string): ?User` | By email |
| | `getTeamMembers(): User[]` | Admins + global mods |
| **UserDisplayService** | `findDisplayByIds(int[] $ids): UserDisplayDTO[]` | Batch display (keyed by user_id) |
| **AdminUserService** | `delete(DeleteUserDTO): void` | Three-mode delete + dispatch events |
| | `deactivate(int $userId, InactiveReason): void` | Set inactive + dispatch event |
| | `activate(int $userId): void` | Set active + dispatch event |
| | `changeType(int $userId, UserType): void` | Change user type |
| **UserCounterService** | _(event subscriber — no public API)_ | Listens to Threads events, updates user_posts / user_lastpost_time |

---

## Entity Model

### User (Core Identity)

| Property | Type | Source Column | Required By |
|----------|------|---------------|-------------|
| `id` | `int` | `user_id` | ALL services |
| `type` | `UserType` | `user_type` | Auth (founder), REST API (active check) |
| `username` | `string` | `username` | Threads (denormalize), Notifications, Messaging |
| `usernameClean` | `string` | `username_clean` | Login, uniqueness, search |
| `email` | `string` | `user_email` | Registration, password reset, ban check |
| `passwordHash` | `string` | `user_password` | Auth (verify), Password service |
| `colour` | `string` | `user_colour` | Threads (denormalize poster colour), display |
| `defaultGroupId` | `int` | `group_id` | Auth (implicit), group cascade |
| `avatarUrl` | `string` | `user_avatar` | Notifications, Messaging (display) |
| `registeredAt` | `int` | `user_regdate` | Profile display |
| `lastmark` | `int` | `user_lastmark` | Hierarchy (tracking baseline) |
| `posts` | `int` | `user_posts` | Counter updates, rank calc |
| `lastPostTime` | `int` | `user_lastpost_time` | Threads event updates |
| `isNew` | `bool` | `user_new` | NEWLY_REGISTERED group transition |
| `rank` | `int` | `user_rank` | Display (0 = use post count rank) |
| `ip` | `string` | `user_ip` | Registration IP (admin view only) |
| `loginAttempts` | `int` | `user_login_attempts` | Auth throttling |
| `inactiveReason` | `InactiveReason` | `user_inactive_reason` | Admin, activation flow |
| `formSalt` | `string` | `user_form_salt` | CSRF token generation |
| `activationKey` | `?string` | `user_actkey` | Registration activation |
| `resetToken` | `?string` | `reset_token` | Password reset flow |
| `resetTokenExpiry` | `?int` | `reset_token_expiration` | Password reset flow |

**Excluded:** `user_permissions`, `user_perm_from` (owned by Auth via direct PDO).

### UserProfile

| Property | Type | Source |
|----------|------|--------|
| `userId` | `int` | `user_id` |
| `avatar` | `string` | `user_avatar` |
| `avatarType` | `string` | `user_avatar_type` |
| `avatarWidth` | `int` | `user_avatar_width` |
| `avatarHeight` | `int` | `user_avatar_height` |
| `signature` | `string` | `user_sig` |
| `signatureBbcodeUid` | `string` | `user_sig_bbcode_uid` |
| `signatureBbcodeBitfield` | `string` | `user_sig_bbcode_bitfield` |
| `birthday` | `string` | `user_birthday` |
| `jabber` | `string` | `user_jabber` |
| `profileFields` | `array` (JSON) | `profile_fields` column (new) |

`profileFields` stores an associative array: `{"location": "Warsaw", "interests": "PHP", "website": "https://..."}`. Schema-free — plugins add keys without DDL.

### UserPreferences (typed DTO from JSON)

| Property | Type | Default | Legacy Source |
|----------|------|---------|---------------|
| `language` | `string` | `'en'` | `user_lang` |
| `timezone` | `string` | `'UTC'` | `user_timezone` |
| `dateFormat` | `string` | `'D M d, Y g:i a'` | `user_dateformat` |
| `style` | `int` | `1` | `user_style` |
| `viewImages` | `bool` | `true` | bit 0 of `user_options` |
| `viewFlash` | `bool` | `true` | bit 1 |
| `viewSmilies` | `bool` | `true` | bit 2 |
| `viewSignatures` | `bool` | `true` | bit 3 |
| `viewAvatars` | `bool` | `true` | bit 4 |
| `viewCensors` | `bool` | `true` | bit 5 |
| `attachSignature` | `bool` | `true` | bit 6 |
| `enableBbcode` | `bool` | `true` | bit 8 |
| `enableSmilies` | `bool` | `true` | bit 9 |
| `sigBbcode` | `bool` | `true` | bit 15 |
| `sigSmilies` | `bool` | `true` | bit 16 |
| `sigLinks` | `bool` | `true` | bit 17 |
| `topicShowDays` | `int` | `0` | `user_topic_show_days` |
| `topicSortByType` | `string` | `'t'` | `user_topic_sortby_type` |
| `topicSortByDir` | `string` | `'d'` | `user_topic_sortby_dir` |
| `postShowDays` | `int` | `0` | `user_post_show_days` |
| `postSortByType` | `string` | `'t'` | `user_post_sortby_type` |
| `postSortByDir` | `string` | `'a'` | `user_post_sortby_dir` |
| `autoSubscribe` | `bool` | `false` | `user_notify` |
| `notifyOnPm` | `bool` | `true` | `user_notify_pm` |
| `allowPm` | `bool` | `true` | `user_allow_pm` |
| `allowViewOnline` | `bool` | `true` | `user_allow_viewonline` |
| `allowViewEmail` | `bool` | `true` | `user_allow_viewemail` |
| `allowMassEmail` | `bool` | `true` | `user_allow_massemail` |

Stored as a single `preferences JSON` column. Defaults applied in PHP — only non-default values stored (sparse).

### Group

| Property | Type | Source Column |
|----------|------|---------------|
| `id` | `int` | `group_id` |
| `name` | `string` | `group_name` |
| `type` | `GroupType` | `group_type` |
| `description` | `string` | `group_desc` |
| `colour` | `string` | `group_colour` |
| `rank` | `int` | `group_rank` |
| `avatar` | `string` | `group_avatar` |
| `avatarType` | `string` | `group_avatar_type` |
| `avatarWidth` | `int` | `group_avatar_width` |
| `avatarHeight` | `int` | `group_avatar_height` |
| `display` | `bool` | `group_display` |
| `legend` | `int` | `group_legend` |
| `skipAuth` | `bool` | `group_skip_auth` |
| `founderManage` | `bool` | `group_founder_manage` |
| `receivePm` | `bool` | `group_receive_pm` |
| `messageLimit` | `int` | `group_message_limit` |
| `maxRecipients` | `int` | `group_max_recipients` |
| `sigChars` | `int` | `group_sig_chars` |

### GroupMembership

| Property | Type | Source Column |
|----------|------|---------------|
| `groupId` | `int` | `group_id` |
| `userId` | `int` | `user_id` |
| `isLeader` | `bool` | `group_leader` |
| `isPending` | `bool` | `user_pending` |

### Ban

| Property | Type | Source Column |
|----------|------|---------------|
| `id` | `int` | `ban_id` |
| `type` | `BanType` | derived from columns |
| `userId` | `int` | `ban_userid` |
| `ip` | `string` | `ban_ip` |
| `email` | `string` | `ban_email` |
| `start` | `int` | `ban_start` |
| `end` | `int` | `ban_end` (0 = permanent) |
| `exclude` | `bool` | `ban_exclude` |
| `reason` | `string` | `ban_reason` |
| `displayReason` | `string` | `ban_give_reason` |

### ShadowBan (NEW)

| Property | Type | Source Column |
|----------|------|---------------|
| `id` | `int` | `shadow_ban_id` |
| `userId` | `int` | `user_id` |
| `scope` | `string` | `scope` (always `'full'` in v1) |
| `forumId` | `int` | `forum_id` (0 in v1) |
| `start` | `int` | `ban_start` |
| `end` | `int` | `ban_end` (0 = permanent) |
| `reason` | `string` | `ban_reason` |
| `givenBy` | `int` | `ban_given_by` |
| `createdAt` | `int` | `created_at` |

### UserDisplayDTO

| Property | Type | Source |
|----------|------|--------|
| `id` | `int` | `user_id` |
| `username` | `string` | `username` |
| `colour` | `string` | `user_colour` |
| `avatarUrl` | `string` | `user_avatar` |

Lightweight, readonly. Projected from `SELECT user_id, username, user_colour, user_avatar FROM phpbb_users WHERE user_id IN (...)`. No password, no email, no profile.

---

## Repository Interfaces

### UserRepositoryInterface

| Method | Return | Used By |
|--------|--------|---------|
| `findById(int $userId)` | `?User` | Auth, REST API, internal services |
| `findByIds(array $userIds)` | `User[]` (keyed by id) | Internal services |
| `findByUsername(string $usernameClean)` | `?User` | Login, search |
| `findByEmail(string $email)` | `?User` | Password reset, registration |
| `create(array $data)` | `User` | RegistrationService |
| `update(int $userId, array $data)` | `void` | Profile, Preferences, Admin |
| `delete(int $userId)` | `void` | AdminUserService |
| `search(UserSearchCriteria $criteria)` | `PaginatedResult<User>` | UserSearchService |
| `incrementPostCount(int $userId)` | `void` | UserCounterService |
| `decrementPostCount(int $userId)` | `void` | UserCounterService |
| `updateLastPostTime(int $userId, int $ts)` | `void` | UserCounterService |
| `findDisplayByIds(array $userIds)` | `UserDisplayDTO[]` | UserDisplayService |
| `getLastmark(int $userId)` | `int` | Hierarchy |

### GroupRepositoryInterface

| Method | Return | Used By |
|--------|--------|---------|
| `findById(int $groupId)` | `?Group` | Auth, GroupService |
| `findAll(?GroupType $filter)` | `Group[]` | GroupService |
| `getMembershipsForUser(int $userId)` | `GroupMembership[]` | Auth, Messaging, GroupService |
| `getMembers(int $groupId, int $offset, int $limit)` | `PaginatedResult<GroupMembership>` | GroupService |
| `addMembership(int $groupId, int $userId, bool $leader, bool $pending)` | `void` | GroupService |
| `removeMembership(int $groupId, int $userId)` | `void` | GroupService |
| `updateMembership(int $groupId, int $userId, array $data)` | `void` | GroupService |
| `getSpecialGroupByName(string $name)` | `?Group` | GroupService (default reassignment) |
| `getUserCountForGroup(int $groupId)` | `int` | GroupService |

### BanRepositoryInterface

| Method | Return |
|--------|--------|
| `findById(int $banId)` | `?Ban` |
| `findActiveBansForUser(int $userId)` | `Ban[]` |
| `findActiveBansForIp(string $ip)` | `Ban[]` |
| `findActiveBansForEmail(string $email)` | `Ban[]` |
| `create(array $data)` | `Ban` |
| `delete(int $banId)` | `void` |
| `deleteExpired()` | `int` (rows deleted) |
| `getActiveBans(?BanType $filter, int $offset, int $limit)` | `PaginatedResult<Ban>` |

### ShadowBanRepositoryInterface

| Method | Return |
|--------|--------|
| `findByUserId(int $userId)` | `?ShadowBan` |
| `findActive()` | `PaginatedResult<ShadowBan>` |
| `create(array $data)` | `ShadowBan` |
| `delete(int $shadowBanId)` | `void` |
| `isUserShadowBanned(int $userId)` | `bool` |

### PasswordHasherInterface

| Method | Return |
|--------|--------|
| `hash(string $password)` | `string` |
| `verify(string $password, string $hash)` | `bool` |
| `needsRehash(string $hash)` | `bool` |

Implementation: `Argon2PasswordHasher` using `password_hash(PASSWORD_ARGON2ID)`.

---

## Domain Events

### Events Dispatched by User Service

| Event | Payload | Consumed By |
|-------|---------|-------------|
| `UserCreatedEvent` | `int $userId, string $username, UserType $type` | Notifications (welcome) |
| `UserActivatedEvent` | `int $userId` | (future) |
| `UserDeactivatedEvent` | `int $userId, InactiveReason $reason` | (future) |
| `UserDeletedEvent` | `int $userId, DeleteMode $mode` | Auth (clear cache), Threads (cascade), Messaging (cascade), Notifications (cascade), Hierarchy (cascade) |
| `UsernameChangedEvent` | `int $userId, string $oldUsername, string $newUsername` | Threads (update denormalized names) |
| `ProfileUpdatedEvent` | `int $userId, array $changedFields` | (extensions) |
| `PreferencesUpdatedEvent` | `int $userId` | (extensions) |
| `PasswordChangedEvent` | `int $userId` | Auth (invalidate sessions except current) |
| `PasswordResetRequestedEvent` | `int $userId, string $email` | Email service (sends reset link) |
| `UserBannedEvent` | `int $userId, BanType $type, int $banId` | Auth (kill sessions) |
| `UserUnbannedEvent` | `int $userId, int $banId` | (extensions) |
| `UserShadowBannedEvent` | `int $userId, int $shadowBanId` | Notifications (suppress for user) |
| `UserShadowBanRemovedEvent` | `int $userId` | (extensions) |
| `UserGroupChangedEvent` | `int $userId, int $groupId, string $action` | Auth (clear permission cache) |
| `DefaultGroupChangedEvent` | `int $userId, int $oldGroupId, int $newGroupId` | (extensions) |
| `UserTypeChangedEvent` | `int $userId, UserType $old, UserType $new` | Auth (founder handling) |

`action` in `UserGroupChangedEvent`: `'joined'`, `'left'`, `'approved'`, `'role_changed'` (leader toggle).

### Events Consumed by User Service

| Event | Source | Handler Action |
|-------|--------|----------------|
| `PostCreatedEvent` | Threads | If visibility=Approved & countsTowardPostCount: increment `user_posts`, update `user_lastpost_time`. Check `user_new` → NEWLY_REGISTERED transition. |
| `PostSoftDeletedEvent` | Threads | Decrement `user_posts` for posterId |
| `PostRestoredEvent` | Threads | Increment `user_posts` for posterId |
| `VisibilityChangedEvent` | Threads | ± `user_posts` based on old→new visibility state |
| `TopicDeletedEvent` | Threads | Batch recalculate `user_posts` for all `allPosterIds[]` |

---

## REST API Endpoints

### Users (7 endpoints)

| Method | Path | Auth | Service | Description |
|--------|------|------|---------|-------------|
| GET | `/api/v1/users/me` | Bearer | `UserSearchService::findById()` | Current user profile (self tier) |
| PATCH | `/api/v1/users/me/profile` | Bearer | `ProfileService::updateProfile()` | Update own profile |
| PATCH | `/api/v1/users/me/preferences` | Bearer | `PreferencesService::update()` | Update own preferences |
| GET | `/api/v1/users/{id}` | Public (limited) | `UserSearchService::findById()` | Public user profile |
| GET | `/api/v1/users` | Public (limited) | `UserSearchService::search()` | Search/list users |
| PATCH | `/api/v1/admin/users/{id}` | Admin | `AdminUserService::update()` | Admin edit user |
| DELETE | `/api/v1/admin/users/{id}` | Admin | `AdminUserService::delete()` | Admin delete user (mode in body) |

### Registration & Password (6 endpoints)

| Method | Path | Auth | Service | Description |
|--------|------|------|---------|-------------|
| POST | `/api/v1/auth/signup` | Public | `RegistrationService::register()` | Create account |
| POST | `/api/v1/auth/activate` | Public | `RegistrationService::activateByKey()` | Activate by key |
| GET | `/api/v1/auth/check` | Public | `RegistrationService` | Username/email availability |
| POST | `/api/v1/auth/password/reset-request` | Public | `PasswordService::requestReset()` | Request password reset |
| POST | `/api/v1/auth/password/reset` | Public | `PasswordService::executeReset()` | Execute reset with token |
| POST | `/api/v1/auth/password/change` | Bearer | `PasswordService::changePassword()` | Change own password |

**Note:** Login/logout/token endpoints are owned by `phpbb\auth`, not this service.

### Groups (8 endpoints)

| Method | Path | Auth | Service | Description |
|--------|------|------|---------|-------------|
| GET | `/api/v1/groups` | Public | `GroupService::listGroups()` | List all visible groups |
| GET | `/api/v1/groups/{id}` | Public | `GroupService::getGroup()` | Single group details |
| GET | `/api/v1/groups/{id}/members` | Public/Members | `GroupService::getMembers()` | Paginated membership |
| POST | `/api/v1/groups/{id}/members` | Admin/Leader | `GroupService::addToGroup()` | Add member |
| DELETE | `/api/v1/groups/{id}/members/{userId}` | Admin/Leader | `GroupService::removeFromGroup()` | Remove member |
| POST | `/api/v1/groups/{id}/join` | Bearer | `GroupService::requestJoin()` | Request to join |
| POST | `/api/v1/groups/{id}/leave` | Bearer | `GroupService::leave()` | Leave group |
| POST | `/api/v1/groups/{id}/members/{userId}/approve` | Leader | `GroupService::approveMember()` | Approve pending |

### Bans (3 endpoints)

| Method | Path | Auth | Service |
|--------|------|------|---------|
| GET | `/api/v1/admin/bans` | Admin | `BanService::getActiveBans()` |
| POST | `/api/v1/admin/bans` | Admin | `BanService::ban()` |
| DELETE | `/api/v1/admin/bans/{id}` | Admin | `BanService::unban()` |

### Shadow Bans (3 endpoints)

| Method | Path | Auth | Service |
|--------|------|------|---------|
| GET | `/api/v1/admin/shadow-bans` | Admin | `ShadowBanService::listActive()` |
| POST | `/api/v1/admin/shadow-bans` | Admin | `ShadowBanService::apply()` |
| DELETE | `/api/v1/admin/shadow-bans/{id}` | Admin | `ShadowBanService::remove()` |

### Privacy Model — 4-Tier Data Visibility

| Tier | Viewer | Visible Fields |
|------|--------|---------------|
| **Public** | Anyone | username, avatar, colour, registeredAt, posts, public profile fields |
| **Authenticated** | Logged-in user | Above + lastActive, members-only profile fields |
| **Self** | Own profile | Above + email, preferences, full profile fields |
| **Admin** | Admin/moderator | Above + IP, shadow ban status, inactive reason, all fields |

---

## Extension Points

### Decorated Operations (Request + Response Decorators via DecoratorPipeline)

| Operation | Request Decorator Use Case | Response Decorator Use Case |
|-----------|---------------------------|----------------------------|
| `RegistrationService::register()` | CAPTCHA validation, terms acceptance, referral codes, DNSBL check | Enrich created user with welcome data |
| `ProfileService::getProfile()` | — | Add badges, reputation score, custom extension fields |
| `UserSearchService::search()` | Custom filter criteria from plugins | Add extra display data to results |

### Event-Only Operations (no decorators)

| Operation | Event |
|-----------|-------|
| Ban/unban | `UserBannedEvent`, `UserUnbannedEvent` |
| Shadow ban/remove | `UserShadowBannedEvent`, `UserShadowBanRemovedEvent` |
| Group membership changes | `UserGroupChangedEvent` |
| Password changes | `PasswordChangedEvent` |
| User delete/deactivate | `UserDeletedEvent`, `UserDeactivatedEvent` |
| Username change | `UsernameChangedEvent` |

### DecoratorPipeline Contract

```php
interface RequestDecoratorInterface
{
    public function supports(object $request): bool;
    public function decorateRequest(object $request): object;
    public function getPriority(): int;
}

interface ResponseDecoratorInterface
{
    public function supports(object $response): bool;
    public function decorateResponse(object $response, object $request): object;
    public function getPriority(): int;
}
```

Pipeline collects all tagged decorators, sorts by priority, runs `decorateRequest()` before service logic, runs `decorateResponse()` after. DTOs support `withExtra(string $key, mixed $value): static` for decorator-added data.

---

## Shadow Ban Design

### Table Schema

```sql
CREATE TABLE phpbb_shadow_bans (
    shadow_ban_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    scope           ENUM('full','forum') DEFAULT 'full',
    forum_id        INT UNSIGNED DEFAULT 0,
    ban_start       INT UNSIGNED NOT NULL,
    ban_end         INT UNSIGNED DEFAULT 0,     -- 0 = permanent
    ban_reason      VARCHAR(255) DEFAULT '',
    ban_given_by    INT UNSIGNED NOT NULL,
    created_at      INT UNSIGNED NOT NULL,
    KEY idx_user_id (user_id),
    KEY idx_scope_forum (scope, forum_id)
);
```

**v1 implementation**: Only `scope='full'` and `forum_id=0`. Schema supports per-forum scoping for v2.

### Behavioral Specification

| Behavior | Description |
|----------|-------------|
| **Login** | Shadow-banned user logs in normally — no error, no indication |
| **Posting** | Posts are created normally in DB (visible to themselves in their own view) |
| **Visibility** | Other users cannot see shadow-banned user's posts — filtered by `ShadowBanResponseDecorator` in Threads |
| **Notifications** | Shadow-banned user's actions do NOT generate notifications for others |
| **Search** | Shadow-banned user's content excluded from search results for others |
| **Profile** | Profile remains accessible (shadow ban is invisible to the user) |
| **Admin** | Shadow ban status visible only at `/api/v1/admin/shadow-bans` and admin user view |
| **Expiry** | `ban_end > 0` allows timed shadow bans; cron cleans expired entries |

### Integration with Threads Service

```
Client ──► GET /api/v1/topics/{id}/posts
  ──► Threads::getPosts()
  ──► ShadowBanResponseDecorator::decorate(PostListResponse, RequestContext)
       │
       ├── calls ShadowBanService::isShadowBanned(post.authorId)
       ├── if viewer == banned user: show their own posts (unaware)
       └── if viewer != banned user: filter out shadow-banned posts
  ──► return filtered response
```

The decorator lives in `phpbb\threads` but calls `phpbb\user\Service\ShadowBanService::isShadowBanned()`. This keeps ownership clear: User owns shadow ban state, Threads owns content filtering.

### Integration with Notifications

Notification service checks `isShadowBanned(actorId)` before creating notifications triggered by user actions. If shadow-banned, notifications are silently suppressed.

---

## Cross-Service Contracts

### Auth ← User (Hard Import)

| Import | Type | Usage |
|--------|------|-------|
| `Entity\User` | Value object | `isGranted(User $user, ...)` — `$user->id`, `$user->type` |
| `Enum\UserType` | Type safety | `UserType::Founder` for Layer 5 override |
| `Entity\Group` | Value object | `$group->skipAuth` flag |
| `Entity\GroupMembership` | Value object | `$membership->isPending`, `$membership->isLeader` |
| `Contract\UserRepositoryInterface` | Service call | `findById(int)` — once per request for token→user hydration |
| `Contract\GroupRepositoryInterface` | Service call | `getMembershipsForUser(int)` — on ACL cache miss |

Auth directly reads/writes `user_permissions` and `user_perm_from` via its own PDO — bypasses User entity (ADR in Auth service).

Auth listens to `UserGroupChangedEvent` → clears permission cache for affected user.

Auth calls `PasswordService::verifyPassword()` during login flow.

### Threads ↔ User (Bidirectional Events)

**Threads → User** (User's `UserCounterService` subscribes):

| Threads Event | User Handler |
|---------------|-------------|
| `PostCreatedEvent` | Increment `user_posts`, update `user_lastpost_time`, check `user_new` transition |
| `PostSoftDeletedEvent` | Decrement `user_posts` |
| `PostRestoredEvent` | Increment `user_posts` |
| `VisibilityChangedEvent` | ±`user_posts` based on old→new |
| `TopicDeletedEvent` | Batch recalculate for `allPosterIds[]` |

**User → Threads** (data at write time): Threads reads `User::$username` and `User::$colour` to denormalize into post/topic rows.

**Shadow ban**: Threads registers `ShadowBanResponseDecorator` that calls `ShadowBanService::isShadowBanned(int)`.

### Notifications ← User (Batch Display)

| Need | Method |
|------|--------|
| Batch display data (10-50 users) | `UserDisplayService::findDisplayByIds(int[])` → `UserDisplayDTO[]` |
| Fields: id, username, avatarUrl, colour | Optimized SELECT projection |

`phpbb_user_notifications` table is owned by Notifications service, NOT User.

### Messaging ← User (Identity + Groups)

| Need | Method |
|------|--------|
| Participant display data | `UserDisplayService::findDisplayByIds(int[])` |
| PM rule evaluation (`sender_group`) | `GroupRepositoryInterface::getMembershipsForUser(int)` |

### Hierarchy ← User (Minimal)

| Need | Method |
|------|--------|
| `user_lastmark` timestamp | `User::$lastmark` property (already on User entity) |

No dedicated method needed — Hierarchy loads the User entity (or calls `getLastmark(int)`).

---

## Database Schema

### Tables Owned by User Service

| Table | Status | Purpose |
|-------|--------|---------|
| `phpbb_users` | Existing (modified) | Core user data — add `profile_fields JSON`, `preferences JSON` columns |
| `phpbb_groups` | Existing | Group definitions (21 columns) |
| `phpbb_user_group` | Existing | User↔Group membership (add composite unique index) |
| `phpbb_banlist` | Existing | Permanent bans (user/IP/email) |
| `phpbb_shadow_bans` | **NEW** | Shadow ban records |
| `phpbb_disallow` | Existing | Disallowed username patterns |
| `phpbb_ranks` | Existing (read-only) | Rank definitions (for display) |

### Tables NOT Owned (referenced only)

| Table | Owner | Relationship |
|-------|-------|-------------|
| `phpbb_sessions` | Auth Service | FK to `user_id` |
| `phpbb_sessions_keys` | Auth Service | FK to `user_id` |
| `phpbb_api_tokens` | Auth Service | FK to `user_id` |
| `phpbb_user_notifications` | Notifications Service | FK to `user_id` |
| `phpbb_acl_users` | Auth Service | FK to `user_id` |
| `phpbb_acl_groups` | Auth Service | FK to `group_id` |
| `phpbb_login_attempts` | Auth Service | login tracking |

### Schema Changes

#### New Columns on `phpbb_users`

```sql
ALTER TABLE phpbb_users
    ADD COLUMN profile_fields JSON DEFAULT NULL
        COMMENT 'Extensible profile data replacing pf_* columns',
    ADD COLUMN preferences JSON DEFAULT NULL
        COMMENT 'User preferences replacing user_options bitfield + individual pref columns';
```

`profile_fields` example: `{"location": "Warsaw", "interests": "PHP", "website": "https://example.com"}`

`preferences` example: `{"viewImages": true, "viewSmilies": false, "language": "pl", "timezone": "Europe/Warsaw", "topicSortByType": "t"}`

Only non-default values are stored. NULL means "use all defaults".

#### New Table: `phpbb_shadow_bans`

```sql
CREATE TABLE phpbb_shadow_bans (
    shadow_ban_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    scope           ENUM('full','forum') DEFAULT 'full',
    forum_id        INT UNSIGNED DEFAULT 0,
    ban_start       INT UNSIGNED NOT NULL,
    ban_end         INT UNSIGNED DEFAULT 0,
    ban_reason      VARCHAR(255) DEFAULT '',
    ban_given_by    INT UNSIGNED NOT NULL,
    created_at      INT UNSIGNED NOT NULL,
    KEY idx_user_id (user_id),
    KEY idx_scope_forum (scope, forum_id)
);
```

#### Index Fix on `phpbb_user_group`

```sql
-- Add composite unique index to prevent duplicate memberships (legacy quirk)
ALTER TABLE phpbb_user_group
    ADD UNIQUE INDEX idx_group_user (group_id, user_id);
```

### Migration Plan for Legacy Columns

| Legacy Source | New Target | Migration |
|--------------|------------|-----------|
| `phpbb_profile_fields_data.pf_*` columns | `phpbb_users.profile_fields` JSON | One-time script: read all `pf_*` values per user, serialize as JSON object, write to `profile_fields` |
| `user_options` bitfield (INT) | `phpbb_users.preferences` JSON | One-time script: decompose bitfield using known bit positions, merge with `user_lang`, `user_timezone`, `user_dateformat`, etc., serialize as JSON |
| `user_topic_show_days`, `user_topic_sortby_type`, etc. (16 individual preference columns) | `phpbb_users.preferences` JSON | Merged into same JSON during migration |

Legacy columns retained during transition period for backward compatibility. Dropped in Phase 3+.

---

## Design Decisions Summary

| # | Decision | ADR | Chosen | Alternatives Considered |
|---|----------|-----|--------|------------------------|
| 1 | Auth/User scope boundary | ADR-001 | Auth owns sessions+tokens; User is data-only | Unified in User, Split, Token-only |
| 2 | Profile field storage | ADR-002 | JSON column per user | Dynamic columns, EAV rows, Hybrid |
| 3 | Preferences storage | ADR-003 | JSON column replacing bitfield | Keep bitfield + getters, Individual columns, JSON+bitfield hybrid |
| 4 | Shadow ban scope | ADR-004 | Full user-level, separate table | Scoped per-forum, Auto-queue, Gradated levels |
| 5 | Delete cascade mode | ADR-005 | Three modes (retain/remove/soft) | Soft only, Hard delete events, Two-phase |
| 6 | Cross-service display data | ADR-006 | UserDisplayDTO (lightweight DTO) | Full User entity, Interface on User, Field-selection |
| 7 | Extension model | ADR-007 | Decorators on CRUD + Events for lifecycle | Decorators everywhere, Events only, Tagged registries only |
| 8 | Group cascade rules | ADR-008 | Colour always; rank if 0; avatar if empty | Full cascade, No cascade, Config-driven |

See [decision-log.md](decision-log.md) for full MADR-format records.

---

## Concrete Examples

### Example 1: User Registration (Decorator Pipeline)

**Given** a new user submits registration with username "JanKowalski", email "jan@example.com", password "Str0ng!Pass"
**When** `RegistrationService::register(CreateUserDTO)` is called
**Then**:
1. `DecoratorPipeline` runs request decorators (e.g., CAPTCHA plugin validates token)
2. Service validates username uniqueness (`username_clean` + disallowed patterns + group names)
3. Service validates email uniqueness (+ banned email patterns)
4. Password hashed via `PasswordService::hashPassword()` (Argon2id)
5. INSERT into `phpbb_users` with defaults (`user_type=0`, `user_regdate=time()`, `preferences=NULL`, `profile_fields=NULL`)
6. INSERT into `phpbb_user_group` for REGISTERED group (+ NEWLY_REGISTERED if configured)
7. `UserCreatedEvent` dispatched
8. `DecoratorPipeline` runs response decorators
9. Returns `User` entity

### Example 2: Shadow Ban Content Filtering

**Given** user #42 is shadow-banned (`phpbb_shadow_bans` row exists, `scope='full'`, `ban_end=0`)
**When** user #99 requests `GET /api/v1/topics/5/posts`
**Then**:
1. Threads service loads posts for topic 5 (including posts by user #42)
2. `ShadowBanResponseDecorator` iterates post authors
3. Calls `ShadowBanService::isShadowBanned(42)` → returns `true`
4. Since viewer (99) ≠ banned user (42), posts by #42 are filtered from response
5. User #99 sees topic without #42's posts

**When** user #42 (the shadow-banned user) requests the same topic:
**Then** their own posts are included (they are unaware of the ban)

### Example 3: Group Default Cascade on Removal

**Given** user #10 has `default_group_id = 5` (ADMINISTRATORS), and is also in REGISTERED (id=2) and GLOBAL_MODERATORS (id=4)
**When** admin removes user #10 from ADMINISTRATORS group
**Then**:
1. `GroupService::removeFromGroup(5, 10)` deletes membership row
2. User's default group is now invalid — cascade reassignment triggers
3. Priority check: ADMINISTRATORS(5) ✗ (just removed) → GLOBAL_MODERATORS(4) ✓ (still member)
4. `setDefaultGroup(10, 4)` called
5. User's `user_colour` ← `group_colour` of GLOBAL_MODERATORS (always)
6. User's `user_rank` ← `group_rank` of GLOBAL_MODERATORS (only if current rank = 0)
7. User's `user_avatar` ← `group_avatar` of GLOBAL_MODERATORS (only if user has no avatar)
8. `DefaultGroupChangedEvent(10, 5, 4)` dispatched
9. `UserGroupChangedEvent(10, 5, 'left')` dispatched → Auth clears permission cache

---

## Out of Scope

| Item | Reason | Where It Belongs |
|------|--------|-----------------|
| Login/logout | Auth service owns authentication flow | `phpbb\auth` |
| Session management | Auth service owns session lifecycle | `phpbb\auth` |
| API token management | Auth service owns token CRUD | `phpbb\auth` |
| Authorization/ACL | Auth service owns permission model | `phpbb\auth` |
| Notification subscriptions | `phpbb_user_notifications` owned by Notifications | `phpbb\notifications` |
| Private message storage | Messaging owns PM tables | `phpbb\messaging` |
| Forum/topic subscriptions | Hierarchy owns watch tables | `phpbb\hierarchy` |
| OAuth2/OIDC | Deferred to future research | Future phase |
| User merge | Complex cascade, deferred | Phase 3+ |
| WebAuthn/Passkeys | Out of scope for v1 | Future phase |
| Content formatting (BBCode in signature) | Formatting service responsibility | Shared utility |
| Per-forum shadow bans | v2 feature — schema ready, logic deferred | v2 |

---

## Success Criteria

1. **Auth integration works**: Auth can import `User` entity, call `findById()`, call `getMembershipsForUser()`, and resolve permissions — all within 1 request cycle
2. **Cross-service events flow**: `UserGroupChangedEvent` triggers Auth cache clear; `PostCreatedEvent` increments `user_posts` correctly
3. **Shadow ban is invisible**: Shadow-banned user can post and view own posts; other users see filtered content; no error/indicator exposed
4. **Three delete modes function**: Retain anonymizes + reassigns, Remove dispatches cascade events, Soft deactivates + anonymizes PII
5. **JSON profile fields extensible**: Plugins can add profile field keys without schema changes; fields round-trip through API correctly
6. **JSON preferences typed**: `UserPreferences` DTO provides named boolean/string properties with defaults; migration from bitfield is lossless
7. **Batch display performant**: `findDisplayByIds([1..50])` returns `UserDisplayDTO[]` from a single optimized SELECT

# User Service — Implementation Specification

## Overview

Create a modern PHP 8.2+ User Service under `src/phpbb/user/` with namespace `phpbb\user`.
This service encapsulates ALL user-related functionality extracted from the legacy phpBB codebase.
It has ZERO dependencies on legacy code — no globals, no `$phpbb_app_container`, no phpBB constants.

**Runtime**: PHP 8.2.30, MySQL 8.x
**PSR-4 mapping**: Add `"phpbb\\user\\": "src/phpbb/user/"` to `composer.json` autoload.psr-4

---

## Guiding principles

| Rule | Detail |
|------|--------|
| No legacy imports | Do not use any class from `phpbb\` (legacy namespace). No `global` keyword. |
| Constructor injection | Every service receives its deps through `__construct()`. |
| Interface-first | Repositories and infra adapters implement interfaces defined in `Contract/`. |
| Typed everywhere | All parameters, returns, and properties are strictly typed. Use PHP 8.1+ enums, readonly, union types, named args. |
| DTOs for input | All multi-field inputs use dedicated DTO classes. No associative arrays. |
| Domain exceptions | Each error case has a specific exception class. Never throw generic `\Exception`. |
| Events for side-effects | State changes dispatch events via `EventDispatcherInterface`. Email sending, cache invalidation, logging are NOT in scope — consumers listen to events. |
| No business logic in entities | Entities are data holders with simple computed getters. |
| PDO for database | Use raw PDO with prepared statements. No DBAL, no legacy `$db`. |
| Unix timestamps | The database stores all dates as `int(11) unsigned` unix timestamps. Entity classes expose `\DateTimeImmutable` getters that convert from/to int. Repositories convert between the two. |

---

## Database schemas

All tables use prefix `phpbb_`. Repositories receive the table prefix via constructor.

### phpbb_users (primary)

| Column | Type | Notes |
|--------|------|-------|
| user_id | int(10) unsigned AUTO_INCREMENT PK | |
| user_type | tinyint(2) DEFAULT 0 | 0=NORMAL, 1=INACTIVE, 2=IGNORE, 3=FOUNDER |
| group_id | mediumint(8) unsigned DEFAULT 3 | Default group FK |
| username | varchar(255) | Display name |
| username_clean | varchar(255) UNIQUE | Lowercased for lookups |
| user_password | varchar(255) | Bcrypt/argon2 hash |
| user_email | varchar(100) | |
| user_regdate | int(11) unsigned DEFAULT 0 | Registration unix timestamp |
| user_ip | varchar(40) | Registration IP |
| user_lastvisit | int(11) unsigned DEFAULT 0 | |
| user_last_active | int(11) unsigned DEFAULT 0 | |
| user_lastpost_time | int(11) unsigned DEFAULT 0 | |
| user_lastpage | varchar(200) | |
| user_last_search | int(11) unsigned DEFAULT 0 | |
| user_posts | mediumint(8) unsigned DEFAULT 0 | Post count |
| user_warnings | tinyint(4) DEFAULT 0 | |
| user_login_attempts | tinyint(4) DEFAULT 0 | Failed login counter |
| user_inactive_reason | tinyint(2) DEFAULT 0 | |
| user_inactive_time | int(11) unsigned DEFAULT 0 | |
| user_lang | varchar(30) | e.g. 'en' |
| user_timezone | varchar(100) | e.g. 'UTC' |
| user_dateformat | varchar(64) DEFAULT 'd M Y H:i' | |
| user_style | mediumint(8) unsigned DEFAULT 0 | Theme ID |
| user_rank | mediumint(8) unsigned DEFAULT 0 | |
| user_colour | varchar(6) | Hex colour |
| user_avatar | varchar(255) | Avatar file/url |
| user_avatar_type | varchar(255) | 'upload', 'remote', 'gravatar' |
| user_avatar_width | smallint(4) unsigned DEFAULT 0 | |
| user_avatar_height | smallint(4) unsigned DEFAULT 0 | |
| user_sig | mediumtext | Signature text |
| user_sig_bbcode_uid | varchar(8) | |
| user_sig_bbcode_bitfield | varchar(255) | |
| user_new_privmsg | int(4) DEFAULT 0 | |
| user_unread_privmsg | int(4) DEFAULT 0 | |
| user_last_privmsg | int(11) unsigned DEFAULT 0 | |
| user_notify | tinyint(1) unsigned DEFAULT 0 | |
| user_notify_pm | tinyint(1) unsigned DEFAULT 1 | |
| user_notify_type | tinyint(4) DEFAULT 0 | 0=EMAIL, 1=IM, 2=BOTH |
| user_allow_pm | tinyint(1) unsigned DEFAULT 1 | |
| user_allow_viewonline | tinyint(1) unsigned DEFAULT 1 | |
| user_allow_viewemail | tinyint(1) unsigned DEFAULT 1 | |
| user_allow_massemail | tinyint(1) unsigned DEFAULT 1 | |
| user_options | int(11) unsigned DEFAULT 230271 | Bitfield |
| user_form_salt | varchar(32) | CSRF salt |
| user_new | tinyint(1) unsigned DEFAULT 1 | Newly registered flag |
| user_actkey | varchar(32) | Activation key |
| user_actkey_expiration | int(11) unsigned DEFAULT 0 | |
| reset_token | varchar(64) | Password reset token |
| reset_token_expiration | int(11) unsigned DEFAULT 0 | |
| user_newpasswd | varchar(255) | |
| user_passchg | int(11) unsigned DEFAULT 0 | Last password change timestamp |
| user_birthday | varchar(10) | DD-MM-YYYY or empty |
| user_permissions | mediumtext | Cached ACL bitstring |
| user_perm_from | mediumint(8) unsigned DEFAULT 0 | |
| user_jabber | varchar(255) | |
| user_message_rules | tinyint(1) unsigned DEFAULT 0 | |
| user_full_folder | int(11) DEFAULT -3 | |
| user_emailtime | int(11) unsigned DEFAULT 0 | |
| user_topic_show_days | smallint(4) unsigned DEFAULT 0 | |
| user_topic_sortby_type | varchar(1) DEFAULT 't' | |
| user_topic_sortby_dir | varchar(1) DEFAULT 'd' | |
| user_post_show_days | smallint(4) unsigned DEFAULT 0 | |
| user_post_sortby_type | varchar(1) DEFAULT 't' | |
| user_post_sortby_dir | varchar(1) DEFAULT 'a' | |
| user_last_confirm_key | varchar(10) | |
| user_reminded | tinyint(4) DEFAULT 0 | |
| user_reminded_time | int(11) unsigned DEFAULT 0 | |
| user_last_warning | int(11) unsigned DEFAULT 0 | |

### phpbb_sessions

| Column | Type | Notes |
|--------|------|-------|
| session_id | char(32) PK | MD5 hex string |
| session_user_id | int(10) unsigned DEFAULT 0 | FK to phpbb_users |
| session_last_visit | int(11) unsigned DEFAULT 0 | |
| session_start | int(11) unsigned DEFAULT 0 | |
| session_time | int(11) unsigned DEFAULT 0 | Last activity |
| session_ip | varchar(40) | |
| session_browser | varchar(150) | User-Agent |
| session_forwarded_for | varchar(255) | X-Forwarded-For |
| session_page | varchar(255) | Current page |
| session_viewonline | tinyint(1) unsigned DEFAULT 1 | |
| session_autologin | tinyint(1) unsigned DEFAULT 0 | |
| session_admin | tinyint(1) unsigned DEFAULT 0 | |
| session_forum_id | mediumint(8) unsigned DEFAULT 0 | |

### phpbb_sessions_keys

| Column | Type | Notes |
|--------|------|-------|
| key_id | char(32) PK | |
| user_id | int(10) unsigned PK DEFAULT 0 | Composite PK |
| last_ip | varchar(40) | |
| last_login | int(11) unsigned DEFAULT 0 | |

### phpbb_banlist

| Column | Type | Notes |
|--------|------|-------|
| ban_id | int(10) unsigned AUTO_INCREMENT PK | |
| ban_userid | int(10) unsigned DEFAULT 0 | 0 if not user ban |
| ban_ip | varchar(40) | Empty if not IP ban |
| ban_email | varchar(100) | Empty if not email ban |
| ban_start | int(11) unsigned DEFAULT 0 | |
| ban_end | int(11) unsigned DEFAULT 0 | 0 = permanent |
| ban_exclude | tinyint(1) unsigned DEFAULT 0 | 1 = whitelist entry |
| ban_reason | varchar(255) | Admin-only reason |
| ban_give_reason | varchar(255) | Shown to user |

### phpbb_groups

| Column | Type | Notes |
|--------|------|-------|
| group_id | mediumint(8) unsigned AUTO_INCREMENT PK | |
| group_type | tinyint(4) DEFAULT 1 | 0=OPEN, 1=CLOSED, 2=HIDDEN, 3=SPECIAL |
| group_name | varchar(255) | |
| group_desc | text | |
| group_colour | varchar(6) | |
| group_rank | mediumint(8) unsigned DEFAULT 0 | |
| group_founder_manage | tinyint(1) unsigned DEFAULT 0 | |
| group_skip_auth | tinyint(1) unsigned DEFAULT 0 | |
| group_display | tinyint(1) unsigned DEFAULT 0 | |
| group_avatar | varchar(255) | |
| group_avatar_type | varchar(255) | |
| group_avatar_width | smallint(4) unsigned DEFAULT 0 | |
| group_avatar_height | smallint(4) unsigned DEFAULT 0 | |
| group_sig_chars | mediumint(8) unsigned DEFAULT 0 | |
| group_receive_pm | tinyint(1) unsigned DEFAULT 0 | |
| group_message_limit | mediumint(8) unsigned DEFAULT 0 | |
| group_max_recipients | mediumint(8) unsigned DEFAULT 0 | |
| group_legend | mediumint(8) unsigned DEFAULT 0 | |
| group_desc_bitfield | varchar(255) | |
| group_desc_options | int(11) unsigned DEFAULT 7 | |
| group_desc_uid | varchar(8) | |

### phpbb_user_group

| Column | Type | Notes |
|--------|------|-------|
| group_id | mediumint(8) unsigned | Composite index |
| user_id | int(10) unsigned | Composite index |
| group_leader | tinyint(1) unsigned DEFAULT 0 | |
| user_pending | tinyint(1) unsigned DEFAULT 1 | 0=approved, 1=pending |

---

## File structure

Create ALL files listed below. Every file is a single PHP class/interface/enum.
Use `declare(strict_types=1);` in every file. No closing `?>` tag.

```
src/phpbb/user/
├── Enum/
│   ├── UserType.php
│   ├── BanType.php
│   ├── GroupType.php
│   └── NotifyType.php
├── Entity/
│   ├── User.php
│   ├── UserProfile.php
│   ├── UserPreferences.php
│   ├── Session.php
│   ├── Group.php
│   ├── GroupMembership.php
│   └── Ban.php
├── DTO/
│   ├── CreateUserDTO.php
│   ├── UpdateProfileDTO.php
│   ├── UpdatePreferencesDTO.php
│   ├── LoginDTO.php
│   ├── ChangePasswordDTO.php
│   ├── PasswordResetRequestDTO.php
│   ├── PasswordResetExecuteDTO.php
│   ├── CreateBanDTO.php
│   ├── UserSearchCriteria.php
│   └── PaginatedResult.php
├── Contract/
│   ├── PasswordHasherInterface.php
│   ├── EventDispatcherInterface.php
│   ├── UserRepositoryInterface.php
│   ├── SessionRepositoryInterface.php
│   ├── GroupRepositoryInterface.php
│   └── BanRepositoryInterface.php
├── Repository/
│   ├── PdoUserRepository.php
│   ├── PdoSessionRepository.php
│   ├── PdoGroupRepository.php
│   └── PdoBanRepository.php
├── Service/
│   ├── AuthenticationService.php
│   ├── RegistrationService.php
│   ├── PasswordService.php
│   ├── ProfileService.php
│   ├── PreferencesService.php
│   ├── SessionService.php
│   ├── GroupService.php
│   ├── BanService.php
│   └── UserSearchService.php
├── Event/
│   ├── UserCreatedEvent.php
│   ├── UserLoggedInEvent.php
│   ├── UserLoggedOutEvent.php
│   ├── PasswordChangedEvent.php
│   ├── PasswordResetRequestedEvent.php
│   ├── ProfileUpdatedEvent.php
│   ├── UserBannedEvent.php
│   └── UserUnbannedEvent.php
├── Exception/
│   ├── UserNotFoundException.php
│   ├── AuthenticationException.php
│   ├── InvalidPasswordException.php
│   ├── UserBannedException.php
│   ├── UserInactiveException.php
│   ├── DuplicateUsernameException.php
│   ├── DuplicateEmailException.php
│   ├── InvalidTokenException.php
│   ├── TokenExpiredException.php
│   ├── TooManyLoginAttemptsException.php
│   ├── SessionNotFoundException.php
│   ├── GroupNotFoundException.php
│   └── BanNotFoundException.php
└── Security/
    └── BcryptPasswordHasher.php
```

Total: ~50 files.

---

## Implementation details per file

### Enum/UserType.php

```php
namespace phpbb\user\Enum;

enum UserType: int
{
    case Normal = 0;
    case Inactive = 1;
    case Ignore = 2;
    case Founder = 3;
}
```

### Enum/BanType.php

```php
enum BanType: string
{
    case User = 'user';
    case Ip = 'ip';
    case Email = 'email';
}
```

### Enum/GroupType.php

```php
enum GroupType: int
{
    case Open = 0;
    case Closed = 1;
    case Hidden = 2;
    case Special = 3;
}
```

### Enum/NotifyType.php

```php
enum NotifyType: int
{
    case Email = 0;
    case Im = 1;
    case Both = 2;
}
```

---

### Entity/User.php

Immutable data class. Construct from DB row via static `fromRow(array $row): self`.

```php
namespace phpbb\user\Entity;

use phpbb\user\Enum\UserType;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $usernameClean,
        public readonly string $email,
        public readonly UserType $type,
        public readonly int $groupId,
        public readonly string $passwordHash,
        public readonly string $formSalt,
        public readonly int $loginAttempts,
        public readonly int $posts,
        public readonly int $warnings,
        public readonly string $ip,
        public readonly string $colour,
        public readonly string $lang,
        public readonly string $timezone,
        public readonly string $dateformat,
        public readonly int $style,
        public readonly int $rank,
        public readonly bool $isNew,
        public readonly int $registeredAt,     // unix timestamp
        public readonly int $lastVisit,        // unix timestamp
        public readonly int $lastActive,       // unix timestamp
        public readonly int $lastPostTime,     // unix timestamp
        public readonly int $passwordChangedAt, // unix timestamp
        public readonly int $inactiveReason,
        public readonly int $inactiveTime,     // unix timestamp
        public readonly ?string $resetToken,
        public readonly int $resetTokenExpiration, // unix timestamp
        public readonly ?string $activationKey,
        public readonly int $activationKeyExpiration, // unix timestamp
        public readonly int $options,          // bitfield
    ) {}

    public static function fromRow(array $row): self
    {
        // Map all DB column names to constructor params
        // user_id -> id, username -> username, etc.
        // Cast types appropriately: (int), (string), UserType::from()
    }

    public function isFounder(): bool
    {
        return $this->type === UserType::Founder;
    }

    public function isActive(): bool
    {
        return $this->type === UserType::Normal || $this->type === UserType::Founder;
    }

    public function isInactive(): bool
    {
        return $this->type === UserType::Inactive;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->registeredAt);
    }

    public function getLastVisit(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->lastVisit);
    }

    public function getLastActive(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->lastActive);
    }
}
```

**fromRow() mapping** (column → property):
- `user_id` → `id`
- `username` → `username`
- `username_clean` → `usernameClean`
- `user_email` → `email`
- `user_type` → `type` (use `UserType::from((int)$row['user_type'])`)
- `group_id` → `groupId`
- `user_password` → `passwordHash`
- `user_form_salt` → `formSalt`
- `user_login_attempts` → `loginAttempts`
- `user_posts` → `posts`
- `user_warnings` → `warnings`
- `user_ip` → `ip`
- `user_colour` → `colour`
- `user_lang` → `lang`
- `user_timezone` → `timezone`
- `user_dateformat` → `dateformat`
- `user_style` → `style`
- `user_rank` → `rank`
- `user_new` → `isNew` (cast to bool)
- `user_regdate` → `registeredAt`
- `user_lastvisit` → `lastVisit`
- `user_last_active` → `lastActive`
- `user_lastpost_time` → `lastPostTime`
- `user_passchg` → `passwordChangedAt`
- `user_inactive_reason` → `inactiveReason`
- `user_inactive_time` → `inactiveTime`
- `reset_token` → `resetToken` (empty string → null)
- `reset_token_expiration` → `resetTokenExpiration`
- `user_actkey` → `activationKey` (empty string → null)
- `user_actkey_expiration` → `activationKeyExpiration`
- `user_options` → `options`

### Entity/UserProfile.php

```php
final class UserProfile
{
    public function __construct(
        public readonly int $userId,
        public readonly string $avatar,
        public readonly string $avatarType,
        public readonly int $avatarWidth,
        public readonly int $avatarHeight,
        public readonly string $signature,
        public readonly string $signatureBbcodeUid,
        public readonly string $signatureBbcodeBitfield,
        public readonly string $jabber,
        public readonly string $birthday,
    ) {}

    public static function fromRow(array $row): self { /* map user_avatar -> avatar, etc. */ }
}
```

**fromRow() mapping**: `user_avatar` → `avatar`, `user_avatar_type` → `avatarType`, `user_avatar_width` → `avatarWidth`, `user_avatar_height` → `avatarHeight`, `user_sig` → `signature`, `user_sig_bbcode_uid` → `signatureBbcodeUid`, `user_sig_bbcode_bitfield` → `signatureBbcodeBitfield`, `user_jabber` → `jabber`, `user_birthday` → `birthday`.

### Entity/UserPreferences.php

```php
final class UserPreferences
{
    public function __construct(
        public readonly int $userId,
        public readonly string $lang,
        public readonly string $timezone,
        public readonly string $dateformat,
        public readonly int $style,
        public readonly NotifyType $notifyType,
        public readonly bool $notifyOnReply,
        public readonly bool $notifyOnPm,
        public readonly bool $allowPm,
        public readonly bool $allowViewOnline,
        public readonly bool $allowViewEmail,
        public readonly bool $allowMassEmail,
        public readonly int $topicShowDays,
        public readonly string $topicSortbyType,
        public readonly string $topicSortbyDir,
        public readonly int $postShowDays,
        public readonly string $postSortbyType,
        public readonly string $postSortbyDir,
    ) {}

    public static function fromRow(array $row): self { /* map columns */ }
}
```

**fromRow() mapping**: `user_lang` → `lang`, `user_timezone` → `timezone`, `user_dateformat` → `dateformat`, `user_style` → `style`, `user_notify_type` → `notifyType` (NotifyType::from()), `user_notify` → `notifyOnReply` (bool), `user_notify_pm` → `notifyOnPm` (bool), `user_allow_pm` → `allowPm` (bool), `user_allow_viewonline` → `allowViewOnline` (bool), `user_allow_viewemail` → `allowViewEmail` (bool), `user_allow_massemail` → `allowMassEmail` (bool), `user_topic_show_days` → `topicShowDays`, `user_topic_sortby_type` → `topicSortbyType`, `user_topic_sortby_dir` → `topicSortbyDir`, `user_post_show_days` → `postShowDays`, `user_post_sortby_type` → `postSortbyType`, `user_post_sortby_dir` → `postSortbyDir`.

### Entity/Session.php

```php
final class Session
{
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public readonly int $lastVisit,
        public readonly int $start,
        public readonly int $time,
        public readonly string $ip,
        public readonly string $browser,
        public readonly string $forwardedFor,
        public readonly string $page,
        public readonly bool $viewOnline,
        public readonly bool $autoLogin,
        public readonly bool $isAdmin,
        public readonly int $forumId,
    ) {}

    public static function fromRow(array $row): self { /* map session_* columns */ }
}
```

**fromRow() mapping**: `session_id` → `id`, `session_user_id` → `userId`, `session_last_visit` → `lastVisit`, `session_start` → `start`, `session_time` → `time`, `session_ip` → `ip`, `session_browser` → `browser`, `session_forwarded_for` → `forwardedFor`, `session_page` → `page`, `session_viewonline` → `viewOnline` (bool), `session_autologin` → `autoLogin` (bool), `session_admin` → `isAdmin` (bool), `session_forum_id` → `forumId`.

### Entity/Group.php

```php
final class Group
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly GroupType $type,
        public readonly string $description,
        public readonly string $colour,
        public readonly int $rank,
        public readonly bool $founderManage,
        public readonly bool $skipAuth,
        public readonly bool $display,
        public readonly bool $receivePm,
        public readonly int $messageLimit,
        public readonly int $maxRecipients,
        public readonly int $legend,
    ) {}

    public static function fromRow(array $row): self { /* map group_* columns */ }
}
```

**fromRow() mapping**: `group_id` → `id`, `group_name` → `name`, `group_type` → `type` (GroupType::from()), `group_desc` → `description`, `group_colour` → `colour`, `group_rank` → `rank`, `group_founder_manage` → `founderManage` (bool), `group_skip_auth` → `skipAuth` (bool), `group_display` → `display` (bool), `group_receive_pm` → `receivePm` (bool), `group_message_limit` → `messageLimit`, `group_max_recipients` → `maxRecipients`, `group_legend` → `legend`.

### Entity/GroupMembership.php

```php
final class GroupMembership
{
    public function __construct(
        public readonly int $groupId,
        public readonly int $userId,
        public readonly bool $isLeader,
        public readonly bool $isPending,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            groupId: (int) $row['group_id'],
            userId: (int) $row['user_id'],
            isLeader: (bool) $row['group_leader'],
            isPending: (bool) $row['user_pending'],
        );
    }
}
```

### Entity/Ban.php

```php
final class Ban
{
    public function __construct(
        public readonly int $id,
        public readonly BanType $type,
        public readonly int $userId,
        public readonly string $ip,
        public readonly string $email,
        public readonly int $start,
        public readonly int $end,           // 0 = permanent
        public readonly bool $isExclude,    // whitelist entry
        public readonly string $reason,
        public readonly string $displayReason,
    ) {}

    public static function fromRow(array $row): self { /* map ban_* columns */ }

    public function isPermanent(): bool
    {
        return $this->end === 0;
    }

    public function isExpired(): bool
    {
        return !$this->isPermanent() && $this->end < time();
    }

    public function isActive(): bool
    {
        return !$this->isExclude && !$this->isExpired();
    }
}
```

**fromRow() mapping**: `ban_id` → `id`, detect type from row (if `ban_userid > 0` → BanType::User, elif `ban_ip !== ''` → BanType::Ip, else BanType::Email), `ban_userid` → `userId`, `ban_ip` → `ip`, `ban_email` → `email`, `ban_start` → `start`, `ban_end` → `end`, `ban_exclude` → `isExclude` (bool), `ban_reason` → `reason`, `ban_give_reason` → `displayReason`.

---

### DTO classes

All DTOs are simple `readonly` classes with public constructor. No validation logic inside DTOs — validation is in Services.

### DTO/CreateUserDTO.php

```php
final readonly class CreateUserDTO
{
    public function __construct(
        public string $username,
        public string $email,
        public string $password,
        public string $lang = 'en',
        public string $timezone = 'UTC',
        public string $ip = '',
    ) {}
}
```

### DTO/LoginDTO.php

```php
final readonly class LoginDTO
{
    public function __construct(
        public string $username,
        public string $password,
        public string $ip = '',
        public string $browser = '',
        public string $forwardedFor = '',
        public bool $autoLogin = false,
        public bool $viewOnline = true,
    ) {}
}
```

### DTO/ChangePasswordDTO.php

```php
final readonly class ChangePasswordDTO
{
    public function __construct(
        public int $userId,
        public string $currentPassword,
        public string $newPassword,
    ) {}
}
```

### DTO/PasswordResetRequestDTO.php

```php
final readonly class PasswordResetRequestDTO
{
    public function __construct(
        public string $email,
    ) {}
}
```

### DTO/PasswordResetExecuteDTO.php

```php
final readonly class PasswordResetExecuteDTO
{
    public function __construct(
        public string $token,
        public string $newPassword,
    ) {}
}
```

### DTO/UpdateProfileDTO.php

```php
final readonly class UpdateProfileDTO
{
    public function __construct(
        public ?string $avatar = null,
        public ?string $avatarType = null,
        public ?int $avatarWidth = null,
        public ?int $avatarHeight = null,
        public ?string $signature = null,
        public ?string $jabber = null,
        public ?string $birthday = null,
    ) {}
}
```

### DTO/UpdatePreferencesDTO.php

```php
final readonly class UpdatePreferencesDTO
{
    public function __construct(
        public ?string $lang = null,
        public ?string $timezone = null,
        public ?string $dateformat = null,
        public ?int $style = null,
        public ?NotifyType $notifyType = null,
        public ?bool $notifyOnReply = null,
        public ?bool $notifyOnPm = null,
        public ?bool $allowPm = null,
        public ?bool $allowViewOnline = null,
        public ?bool $allowViewEmail = null,
        public ?bool $allowMassEmail = null,
    ) {}
}
```

### DTO/CreateBanDTO.php

```php
final readonly class CreateBanDTO
{
    public function __construct(
        public BanType $type,
        public string $value,           // username, IP, or email depending on type
        public ?int $durationSeconds = null, // null = permanent
        public string $reason = '',
        public string $displayReason = '',
    ) {}
}
```

### DTO/UserSearchCriteria.php

```php
final readonly class UserSearchCriteria
{
    public function __construct(
        public ?string $username = null,        // LIKE search
        public ?string $email = null,           // LIKE search
        public ?UserType $type = null,          // filter by user type
        public ?int $groupId = null,            // filter by group membership
        public string $sortBy = 'username',     // 'username', 'user_regdate', 'user_posts', 'user_last_active'
        public string $sortDir = 'ASC',         // 'ASC' or 'DESC'
        public int $page = 1,
        public int $perPage = 25,
    ) {}

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
```

**IMPORTANT**: `sortBy` must be validated against an allowlist in the repository to prevent SQL injection:
```php
private const ALLOWED_SORT = ['username', 'user_regdate', 'user_posts', 'user_last_active', 'user_email'];
```

### DTO/PaginatedResult.php

```php
/** @template T */
final readonly class PaginatedResult
{
    /**
     * @param array<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        return (int) ceil($this->total / max(1, $this->perPage));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
```

---

### Contract interfaces

### Contract/PasswordHasherInterface.php

```php
interface PasswordHasherInterface
{
    public function hash(string $password): string;
    public function verify(string $password, string $hash): bool;
    public function needsRehash(string $hash): bool;
}
```

### Contract/EventDispatcherInterface.php

```php
interface EventDispatcherInterface
{
    public function dispatch(object $event): void;
}
```

### Contract/UserRepositoryInterface.php

```php
interface UserRepositoryInterface
{
    public function findById(int $id): ?User;
    public function findByUsername(string $username): ?User;
    public function findByUsernameClean(string $usernameClean): ?User;
    public function findByEmail(string $email): ?User;
    public function findByResetToken(string $token): ?User;
    public function findByActivationKey(string $key): ?User;
    public function search(UserSearchCriteria $criteria): PaginatedResult;
    public function insert(array $data): int;         // returns user_id
    public function update(int $userId, array $data): void;
    public function incrementLoginAttempts(int $userId): void;
    public function resetLoginAttempts(int $userId): void;
    public function usernameExists(string $usernameClean): bool;
    public function emailExists(string $email): bool;
}
```

### Contract/SessionRepositoryInterface.php

```php
interface SessionRepositoryInterface
{
    public function findById(string $sessionId): ?Session;
    public function findByUserId(int $userId): array;
    public function insert(array $data): void;
    public function updateTime(string $sessionId, int $time, string $page): void;
    public function delete(string $sessionId): void;
    public function deleteByUserId(int $userId): void;
    public function deleteExpired(int $maxLifetime): int; // returns deleted count

    // Session keys (persistent login)
    public function insertKey(string $keyId, int $userId, string $ip): void;
    public function findKey(string $keyId, int $userId): ?array;
    public function deleteKey(string $keyId, int $userId): void;
    public function deleteKeysByUserId(int $userId): void;
}
```

### Contract/GroupRepositoryInterface.php

```php
interface GroupRepositoryInterface
{
    public function findById(int $groupId): ?Group;
    public function findByName(string $name): ?Group;
    public function findAll(): array;
    public function getGroupsForUser(int $userId): array;       // returns Group[]
    public function getMemberships(int $userId): array;          // returns GroupMembership[]
    public function getMemberCount(int $groupId): int;
    public function getMembers(int $groupId, int $limit, int $offset): array; // returns User[]
    public function addMember(int $groupId, int $userId, bool $pending = false): void;
    public function removeMember(int $groupId, int $userId): void;
    public function isMember(int $groupId, int $userId): bool;
    public function setLeader(int $groupId, int $userId, bool $isLeader): void;
    public function updatePendingStatus(int $groupId, int $userId, bool $pending): void;
}
```

### Contract/BanRepositoryInterface.php

```php
interface BanRepositoryInterface
{
    public function findById(int $banId): ?Ban;
    public function findActiveByUserId(int $userId): ?Ban;
    public function findActiveByIp(string $ip): ?Ban;
    public function findActiveByEmail(string $email): ?Ban;
    public function findAll(BanType $type, bool $activeOnly = true): array;
    public function insert(array $data): int;   // returns ban_id
    public function delete(int $banId): void;
}
```

**IMPORTANT for ban queries**: "active" means `ban_exclude = 0 AND (ban_end = 0 OR ban_end > UNIX_TIMESTAMP())`.

---

### Repository implementations

All repositories extend a base pattern:

```php
abstract class AbstractPdoRepository
{
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly string $tablePrefix = 'phpbb_',
    ) {}
}
```

Each repository receives `\PDO` and `string $tablePrefix` in constructor. ALL queries use prepared statements with named parameters (`:param`). NEVER interpolate values into SQL strings.

### Repository/PdoUserRepository.php

Implements `UserRepositoryInterface`. Table: `{$this->tablePrefix}users`.

- `findById()`: `SELECT * FROM {prefix}users WHERE user_id = :id`
- `findByUsername()`: `SELECT * FROM {prefix}users WHERE username = :username`
- `findByUsernameClean()`: `SELECT * FROM {prefix}users WHERE username_clean = :clean`
- `findByEmail()`: `SELECT * FROM {prefix}users WHERE user_email = :email`
- `findByResetToken()`: `SELECT * WHERE reset_token = :token AND reset_token_expiration > :now`
- `findByActivationKey()`: `SELECT * WHERE user_actkey = :key AND user_actkey_expiration > :now`
- `search()`: Build SELECT with optional WHERE clauses from criteria. Validate `sortBy` against allowlist. Use `LIMIT :limit OFFSET :offset`. Run COUNT(*) query for total. Return `PaginatedResult<User>`.
- `insert()`: `INSERT INTO ... (...columns...) VALUES (...:params...)`. Use `$this->pdo->lastInsertId()`.
- `update()`: Build `UPDATE ... SET col1=:col1, col2=:col2 WHERE user_id = :id` dynamically from `$data` array keys. Validate keys against allowlist of column names.
- `incrementLoginAttempts()`: `UPDATE {prefix}users SET user_login_attempts = user_login_attempts + 1 WHERE user_id = :id`
- `resetLoginAttempts()`: `UPDATE {prefix}users SET user_login_attempts = 0 WHERE user_id = :id`
- `usernameExists()`: `SELECT 1 FROM {prefix}users WHERE username_clean = :clean LIMIT 1`
- `emailExists()`: `SELECT 1 FROM {prefix}users WHERE user_email = :email LIMIT 1`

**Column allowlist for update()**: `['username', 'username_clean', 'user_email', 'user_password', 'user_type', 'group_id', 'user_avatar', 'user_avatar_type', 'user_avatar_width', 'user_avatar_height', 'user_sig', 'user_sig_bbcode_uid', 'user_sig_bbcode_bitfield', 'user_lang', 'user_timezone', 'user_dateformat', 'user_style', 'user_colour', 'user_jabber', 'user_birthday', 'user_notify', 'user_notify_pm', 'user_notify_type', 'user_allow_pm', 'user_allow_viewonline', 'user_allow_viewemail', 'user_allow_massemail', 'user_new', 'user_actkey', 'user_actkey_expiration', 'reset_token', 'reset_token_expiration', 'user_inactive_reason', 'user_inactive_time', 'user_login_attempts', 'user_form_salt', 'user_lastvisit', 'user_last_active', 'user_passchg', 'user_options', 'user_rank', 'user_ip']`

### Repository/PdoSessionRepository.php

Implements `SessionRepositoryInterface`. Tables: `{prefix}sessions`, `{prefix}sessions_keys`.

- `findById()`: `SELECT * FROM {prefix}sessions WHERE session_id = :id`
- `findByUserId()`: `SELECT * FROM {prefix}sessions WHERE session_user_id = :uid`
- `insert()`: INSERT into sessions table. Session ID is provided (not auto-generated).
- `updateTime()`: `UPDATE {prefix}sessions SET session_time = :time, session_page = :page WHERE session_id = :id`
- `delete()`: `DELETE FROM {prefix}sessions WHERE session_id = :id`
- `deleteByUserId()`: `DELETE FROM {prefix}sessions WHERE session_user_id = :uid`
- `deleteExpired()`: `DELETE FROM {prefix}sessions WHERE session_time < :cutoff`. Return `rowCount()`.
- `insertKey()`: INSERT into sessions_keys
- `findKey()`: `SELECT * FROM {prefix}sessions_keys WHERE key_id = :kid AND user_id = :uid`
- `deleteKey()`: DELETE from sessions_keys by key_id + user_id
- `deleteKeysByUserId()`: DELETE from sessions_keys by user_id

### Repository/PdoGroupRepository.php

Implements `GroupRepositoryInterface`. Tables: `{prefix}groups`, `{prefix}user_group`.

- `getGroupsForUser()`: `SELECT g.* FROM {prefix}groups g INNER JOIN {prefix}user_group ug ON g.group_id = ug.group_id WHERE ug.user_id = :uid AND ug.user_pending = 0`
- `getMemberships()`: `SELECT * FROM {prefix}user_group WHERE user_id = :uid`
- `getMembers()`: `SELECT u.* FROM {prefix}users u INNER JOIN {prefix}user_group ug ON u.user_id = ug.user_id WHERE ug.group_id = :gid AND ug.user_pending = 0 ORDER BY u.username ASC LIMIT :limit OFFSET :offset`
- `addMember()`: `INSERT INTO {prefix}user_group (group_id, user_id, user_pending, group_leader) VALUES (:gid, :uid, :pending, 0)`
- `removeMember()`: `DELETE FROM {prefix}user_group WHERE group_id = :gid AND user_id = :uid`
- `isMember()`: `SELECT 1 FROM {prefix}user_group WHERE group_id = :gid AND user_id = :uid AND user_pending = 0 LIMIT 1`

### Repository/PdoBanRepository.php

Implements `BanRepositoryInterface`. Table: `{prefix}banlist`.

- `findActiveByUserId()`: `SELECT * FROM {prefix}banlist WHERE ban_userid = :uid AND ban_exclude = 0 AND (ban_end = 0 OR ban_end > :now) LIMIT 1`
- `findActiveByIp()`: same pattern with `ban_ip = :ip`
- `findActiveByEmail()`: same pattern with `ban_email = :email`
- `findAll()`: filter by type + optional active flag
- `insert()`: INSERT INTO banlist
- `delete()`: `DELETE FROM {prefix}banlist WHERE ban_id = :id`

---

### Security/BcryptPasswordHasher.php

Implements `PasswordHasherInterface`.

```php
final class BcryptPasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private readonly int $cost = 12,
    ) {}

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }
}
```

---

### Event classes

All events are simple value objects. Pattern:

```php
namespace phpbb\user\Event;

final readonly class UserCreatedEvent
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $email,
        public int $timestamp,
    ) {}
}
```

Events to implement:

| Class | Constructor params |
|-------|--------------------|
| UserCreatedEvent | int $userId, string $username, string $email, int $timestamp |
| UserLoggedInEvent | int $userId, string $sessionId, string $ip, int $timestamp |
| UserLoggedOutEvent | int $userId, string $sessionId, int $timestamp |
| PasswordChangedEvent | int $userId, int $timestamp |
| PasswordResetRequestedEvent | int $userId, string $email, string $token, int $timestamp |
| ProfileUpdatedEvent | int $userId, array $changedFields, int $timestamp |
| UserBannedEvent | int $banId, BanType $type, string $value, ?int $until, int $timestamp |
| UserUnbannedEvent | int $banId, int $timestamp |

---

### Exception classes

All exceptions extend a base class:

```php
namespace phpbb\user\Exception;

abstract class UserServiceException extends \RuntimeException {}
```

Each specific exception extends `UserServiceException` with no additional logic needed — just the class declaration:

```php
final class UserNotFoundException extends UserServiceException
{
    public function __construct(string $message = 'User not found', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
```

Exception list with default messages and HTTP-like codes:

| Class | Message | Code |
|-------|---------|------|
| UserNotFoundException | User not found | 404 |
| AuthenticationException | Invalid username or password | 401 |
| InvalidPasswordException | Current password is incorrect | 401 |
| UserBannedException | User is banned | 403 |
| UserInactiveException | User account is inactive | 403 |
| DuplicateUsernameException | Username already taken | 409 |
| DuplicateEmailException | Email already registered | 409 |
| InvalidTokenException | Invalid token | 400 |
| TokenExpiredException | Token has expired | 400 |
| TooManyLoginAttemptsException | Too many login attempts | 429 |
| SessionNotFoundException | Session not found | 404 |
| GroupNotFoundException | Group not found | 404 |
| BanNotFoundException | Ban not found | 404 |

---

### Service implementations

### Service/AuthenticationService.php

```php
namespace phpbb\user\Service;

final class AuthenticationService
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly SessionService $sessions,
        private readonly BanService $bans,
        private readonly PasswordHasherInterface $hasher,
        private readonly EventDispatcherInterface $events,
    ) {}

    /**
     * Authenticate user and create session.
     *
     * @throws AuthenticationException       if username not found or password wrong
     * @throws UserBannedException           if user is banned
     * @throws UserInactiveException         if account is inactive
     * @throws TooManyLoginAttemptsException if too many failed attempts
     */
    public function login(LoginDTO $dto): Session
    {
        // 1. Find user by username_clean (strtolower of username)
        $user = $this->users->findByUsernameClean(strtolower($dto->username));
        if ($user === null) {
            throw new AuthenticationException();
        }

        // 2. Check ban status
        $this->bans->assertNotBanned($user->id, $dto->ip, $user->email);

        // 3. Check login attempts
        if ($user->loginAttempts >= self::MAX_LOGIN_ATTEMPTS) {
            throw new TooManyLoginAttemptsException();
        }

        // 4. Check inactive
        if ($user->isInactive()) {
            throw new UserInactiveException();
        }

        // 5. Verify password
        if (!$this->hasher->verify($dto->password, $user->passwordHash)) {
            $this->users->incrementLoginAttempts($user->id);
            throw new AuthenticationException();
        }

        // 6. Reset login attempts
        $this->users->resetLoginAttempts($user->id);

        // 7. Rehash if needed
        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->update($user->id, [
                'user_password' => $this->hasher->hash($dto->password),
            ]);
        }

        // 8. Update last visit
        $now = time();
        $this->users->update($user->id, [
            'user_lastvisit' => $now,
            'user_last_active' => $now,
            'user_ip' => $dto->ip,
        ]);

        // 9. Create session
        $session = $this->sessions->create(
            userId: $user->id,
            ip: $dto->ip,
            browser: $dto->browser,
            forwardedFor: $dto->forwardedFor,
            persist: $dto->autoLogin,
            viewOnline: $dto->viewOnline,
        );

        // 10. Dispatch event
        $this->events->dispatch(new UserLoggedInEvent(
            userId: $user->id,
            sessionId: $session->id,
            ip: $dto->ip,
            timestamp: $now,
        ));

        return $session;
    }

    /**
     * @throws SessionNotFoundException
     */
    public function logout(string $sessionId): void
    {
        $session = $this->sessions->findById($sessionId);
        $this->sessions->destroy($sessionId);

        $this->events->dispatch(new UserLoggedOutEvent(
            userId: $session->userId,
            sessionId: $sessionId,
            timestamp: time(),
        ));
    }

    /**
     * Validate session and return associated user.
     * Returns null if session invalid/expired.
     */
    public function validateSession(string $sessionId): ?User
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            return null;
        }

        return $this->users->findById($session->userId);
    }
}
```

### Service/RegistrationService.php

```php
final class RegistrationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly GroupRepositoryInterface $groups,
        private readonly PasswordHasherInterface $hasher,
        private readonly EventDispatcherInterface $events,
    ) {}

    /**
     * @throws DuplicateUsernameException
     * @throws DuplicateEmailException
     */
    public function register(CreateUserDTO $dto): User
    {
        // 1. Validate uniqueness
        $clean = strtolower($dto->username);
        if ($this->users->usernameExists($clean)) {
            throw new DuplicateUsernameException();
        }
        if ($this->users->emailExists($dto->email)) {
            throw new DuplicateEmailException();
        }

        // 2. Find REGISTERED group
        $registeredGroup = $this->groups->findByName('REGISTERED');
        $groupId = $registeredGroup ? $registeredGroup->id : 2; // fallback

        // 3. Hash password
        $hash = $this->hasher->hash($dto->password);

        // 4. Generate form salt (32 char random hex)
        $formSalt = bin2hex(random_bytes(16));

        // 5. Insert user
        $now = time();
        $userId = $this->users->insert([
            'user_type'       => UserType::Normal->value,
            'group_id'        => $groupId,
            'username'        => $dto->username,
            'username_clean'  => $clean,
            'user_password'   => $hash,
            'user_email'      => $dto->email,
            'user_regdate'    => $now,
            'user_ip'         => $dto->ip,
            'user_lang'       => $dto->lang,
            'user_timezone'   => $dto->timezone,
            'user_dateformat' => 'd M Y H:i',
            'user_style'      => 1,
            'user_form_salt'  => $formSalt,
            'user_new'        => 1,
            'user_options'    => 230271,
            'user_permissions' => '',
            'user_sig'        => '',
            'user_colour'     => '',
            'user_avatar'     => '',
            'user_avatar_type'=> '',
            'user_jabber'     => '',
            'user_birthday'   => '',
            'user_lastpage'   => '',
            'user_last_confirm_key' => '',
            'user_sig_bbcode_uid' => '',
            'user_sig_bbcode_bitfield' => '',
            'user_actkey'     => '',
            'user_newpasswd'  => '',
            'reset_token'     => '',
        ]);

        // 6. Add to REGISTERED group
        $this->groups->addMember($groupId, $userId, pending: false);

        // 7. Load full user
        $user = $this->users->findById($userId);

        // 8. Dispatch event
        $this->events->dispatch(new UserCreatedEvent(
            userId: $userId,
            username: $dto->username,
            email: $dto->email,
            timestamp: $now,
        ));

        return $user;
    }

    /**
     * Activate user account by key.
     *
     * @throws InvalidTokenException
     * @throws TokenExpiredException
     */
    public function activateByKey(string $key): User
    {
        $user = $this->users->findByActivationKey($key);
        if ($user === null) {
            throw new InvalidTokenException();
        }
        if ($user->activationKeyExpiration > 0 && $user->activationKeyExpiration < time()) {
            throw new TokenExpiredException();
        }

        $this->users->update($user->id, [
            'user_type' => UserType::Normal->value,
            'user_actkey' => '',
            'user_actkey_expiration' => 0,
            'user_inactive_reason' => 0,
            'user_inactive_time' => 0,
            'user_new' => 0,
        ]);

        return $this->users->findById($user->id);
    }

    public function usernameAvailable(string $username): bool
    {
        return !$this->users->usernameExists(strtolower($username));
    }

    public function emailAvailable(string $email): bool
    {
        return !$this->users->emailExists($email);
    }
}
```

### Service/PasswordService.php

```php
final class PasswordService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly EventDispatcherInterface $events,
    ) {}

    /**
     * @throws UserNotFoundException
     * @throws InvalidPasswordException
     */
    public function changePassword(ChangePasswordDTO $dto): void
    {
        $user = $this->users->findById($dto->userId);
        if ($user === null) {
            throw new UserNotFoundException();
        }
        if (!$this->hasher->verify($dto->currentPassword, $user->passwordHash)) {
            throw new InvalidPasswordException();
        }

        $now = time();
        $this->users->update($user->id, [
            'user_password' => $this->hasher->hash($dto->newPassword),
            'user_passchg'  => $now,
            'user_login_attempts' => 0,
        ]);

        $this->events->dispatch(new PasswordChangedEvent(
            userId: $user->id,
            timestamp: $now,
        ));
    }

    /**
     * Generate password reset token.
     * Returns the token string (caller sends email).
     *
     * @throws UserNotFoundException
     */
    public function requestReset(PasswordResetRequestDTO $dto): string
    {
        $user = $this->users->findByEmail($dto->email);
        if ($user === null) {
            throw new UserNotFoundException();
        }

        $token = bin2hex(random_bytes(32));  // 64 char hex string
        $expiration = time() + 86400;        // 24 hours

        $this->users->update($user->id, [
            'reset_token' => $token,
            'reset_token_expiration' => $expiration,
        ]);

        $this->events->dispatch(new PasswordResetRequestedEvent(
            userId: $user->id,
            email: $user->email,
            token: $token,
            timestamp: time(),
        ));

        return $token;
    }

    /**
     * Execute password reset with token.
     *
     * @throws InvalidTokenException
     * @throws TokenExpiredException
     */
    public function executeReset(PasswordResetExecuteDTO $dto): void
    {
        $user = $this->users->findByResetToken($dto->token);
        if ($user === null) {
            throw new InvalidTokenException();
        }
        if ($user->resetTokenExpiration > 0 && $user->resetTokenExpiration < time()) {
            throw new TokenExpiredException();
        }

        $now = time();
        $this->users->update($user->id, [
            'user_password' => $this->hasher->hash($dto->newPassword),
            'user_passchg'  => $now,
            'reset_token'   => '',
            'reset_token_expiration' => 0,
            'user_login_attempts' => 0,
        ]);

        $this->events->dispatch(new PasswordChangedEvent(
            userId: $user->id,
            timestamp: $now,
        ));
    }
}
```

### Service/ProfileService.php

```php
final class ProfileService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EventDispatcherInterface $events,
    ) {}

    public function getProfile(int $userId): UserProfile
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new UserNotFoundException();
        }
        // Need raw row for profile fields — add findRawById to repo or query separately
        // Simplification: use a second query for profile columns or extend User entity
        // For this implementation: UserProfile::fromRow() expects the same row as User
        // Solution: Repository internally returns the full row. Add:
        return $this->users->findProfileById($userId);
    }

    /**
     * @throws UserNotFoundException
     */
    public function updateProfile(int $userId, UpdateProfileDTO $dto): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new UserNotFoundException();
        }

        $data = [];
        if ($dto->avatar !== null) $data['user_avatar'] = $dto->avatar;
        if ($dto->avatarType !== null) $data['user_avatar_type'] = $dto->avatarType;
        if ($dto->avatarWidth !== null) $data['user_avatar_width'] = $dto->avatarWidth;
        if ($dto->avatarHeight !== null) $data['user_avatar_height'] = $dto->avatarHeight;
        if ($dto->signature !== null) $data['user_sig'] = $dto->signature;
        if ($dto->jabber !== null) $data['user_jabber'] = $dto->jabber;
        if ($dto->birthday !== null) $data['user_birthday'] = $dto->birthday;

        if (!empty($data)) {
            $this->users->update($userId, $data);
            $this->events->dispatch(new ProfileUpdatedEvent(
                userId: $userId,
                changedFields: array_keys($data),
                timestamp: time(),
            ));
        }
    }

    /**
     * @throws UserNotFoundException
     * @throws DuplicateUsernameException
     */
    public function changeUsername(int $userId, string $newUsername): void
    {
        $clean = strtolower($newUsername);
        if ($this->users->usernameExists($clean)) {
            throw new DuplicateUsernameException();
        }

        $this->users->update($userId, [
            'username' => $newUsername,
            'username_clean' => $clean,
        ]);
    }

    /**
     * @throws UserNotFoundException
     * @throws DuplicateEmailException
     */
    public function changeEmail(int $userId, string $newEmail): void
    {
        if ($this->users->emailExists($newEmail)) {
            throw new DuplicateEmailException();
        }

        $this->users->update($userId, [
            'user_email' => $newEmail,
        ]);
    }

    public function removeAvatar(int $userId): void
    {
        $this->users->update($userId, [
            'user_avatar' => '',
            'user_avatar_type' => '',
            'user_avatar_width' => 0,
            'user_avatar_height' => 0,
        ]);
    }
}
```

**NOTE**: Add `findProfileById(int $userId): ?UserProfile` to `UserRepositoryInterface` and implement in `PdoUserRepository`. Query: `SELECT user_id, user_avatar, user_avatar_type, user_avatar_width, user_avatar_height, user_sig, user_sig_bbcode_uid, user_sig_bbcode_bitfield, user_jabber, user_birthday FROM {prefix}users WHERE user_id = :id`.

### Service/PreferencesService.php

```php
final class PreferencesService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function getPreferences(int $userId): UserPreferences
    {
        return $this->users->findPreferencesById($userId);
    }

    public function updatePreferences(int $userId, UpdatePreferencesDTO $dto): void
    {
        $data = [];
        if ($dto->lang !== null) $data['user_lang'] = $dto->lang;
        if ($dto->timezone !== null) $data['user_timezone'] = $dto->timezone;
        if ($dto->dateformat !== null) $data['user_dateformat'] = $dto->dateformat;
        if ($dto->style !== null) $data['user_style'] = $dto->style;
        if ($dto->notifyType !== null) $data['user_notify_type'] = $dto->notifyType->value;
        if ($dto->notifyOnReply !== null) $data['user_notify'] = (int) $dto->notifyOnReply;
        if ($dto->notifyOnPm !== null) $data['user_notify_pm'] = (int) $dto->notifyOnPm;
        if ($dto->allowPm !== null) $data['user_allow_pm'] = (int) $dto->allowPm;
        if ($dto->allowViewOnline !== null) $data['user_allow_viewonline'] = (int) $dto->allowViewOnline;
        if ($dto->allowViewEmail !== null) $data['user_allow_viewemail'] = (int) $dto->allowViewEmail;
        if ($dto->allowMassEmail !== null) $data['user_allow_massemail'] = (int) $dto->allowMassEmail;

        if (!empty($data)) {
            $this->users->update($userId, $data);
        }
    }
}
```

**NOTE**: Add `findPreferencesById(int $userId): ?UserPreferences` to `UserRepositoryInterface` and implement. Query: `SELECT user_id, user_lang, user_timezone, user_dateformat, user_style, user_notify_type, user_notify, user_notify_pm, user_allow_pm, user_allow_viewonline, user_allow_viewemail, user_allow_massemail, user_topic_show_days, user_topic_sortby_type, user_topic_sortby_dir, user_post_show_days, user_post_sortby_type, user_post_sortby_dir FROM {prefix}users WHERE user_id = :id`.

### Service/SessionService.php

```php
final class SessionService
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
    ) {}

    public function create(
        int $userId,
        string $ip,
        string $browser,
        string $forwardedFor = '',
        bool $persist = false,
        bool $viewOnline = true,
    ): Session {
        $sessionId = md5(random_bytes(16) . $ip . $browser . time());
        $now = time();

        $this->sessions->insert([
            'session_id'            => $sessionId,
            'session_user_id'       => $userId,
            'session_last_visit'    => $now,
            'session_start'         => $now,
            'session_time'          => $now,
            'session_ip'            => $ip,
            'session_browser'       => substr($browser, 0, 150),
            'session_forwarded_for' => substr($forwardedFor, 0, 255),
            'session_page'          => '',
            'session_viewonline'    => (int) $viewOnline,
            'session_autologin'     => (int) $persist,
            'session_admin'         => 0,
            'session_forum_id'      => 0,
        ]);

        if ($persist) {
            $keyId = md5(random_bytes(16));
            $this->sessions->insertKey($keyId, $userId, $ip);
        }

        return $this->sessions->findById($sessionId);
    }

    public function findById(string $sessionId): ?Session
    {
        return $this->sessions->findById($sessionId);
    }

    /**
     * @throws SessionNotFoundException
     */
    public function destroy(string $sessionId): void
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            throw new SessionNotFoundException();
        }

        $this->sessions->delete($sessionId);
        $this->sessions->deleteKeysByUserId($session->userId);
    }

    public function destroyAllForUser(int $userId): void
    {
        $this->sessions->deleteByUserId($userId);
        $this->sessions->deleteKeysByUserId($userId);
    }

    public function touch(string $sessionId, string $page): void
    {
        $this->sessions->updateTime($sessionId, time(), $page);
    }

    /**
     * Remove expired sessions. Returns count deleted.
     */
    public function gc(int $maxLifetimeSeconds = 3600): int
    {
        $cutoff = time() - $maxLifetimeSeconds;
        return $this->sessions->deleteExpired($cutoff);
    }
}
```

### Service/GroupService.php

```php
final class GroupService
{
    public function __construct(
        private readonly GroupRepositoryInterface $groups,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function getGroupsForUser(int $userId): array
    {
        return $this->groups->getGroupsForUser($userId);
    }

    public function getMemberships(int $userId): array
    {
        return $this->groups->getMemberships($userId);
    }

    /**
     * @throws GroupNotFoundException
     */
    public function addToGroup(int $userId, int $groupId, bool $pending = false): void
    {
        if ($this->groups->findById($groupId) === null) {
            throw new GroupNotFoundException();
        }
        if (!$this->groups->isMember($groupId, $userId)) {
            $this->groups->addMember($groupId, $userId, $pending);
        }
    }

    public function removeFromGroup(int $userId, int $groupId): void
    {
        $this->groups->removeMember($groupId, $userId);
    }

    /**
     * Set user's default group.
     */
    public function setDefaultGroup(int $userId, int $groupId): void
    {
        if (!$this->groups->isMember($groupId, $userId)) {
            throw new GroupNotFoundException('User is not a member of this group');
        }

        $group = $this->groups->findById($groupId);
        $this->users->update($userId, [
            'group_id' => $groupId,
            'user_colour' => $group->colour,
        ]);
    }

    public function isInGroup(int $userId, int $groupId): bool
    {
        return $this->groups->isMember($groupId, $userId);
    }

    public function getMembers(int $groupId, int $page = 1, int $perPage = 25): PaginatedResult
    {
        $offset = ($page - 1) * $perPage;
        $members = $this->groups->getMembers($groupId, $perPage, $offset);
        $total = $this->groups->getMemberCount($groupId);

        return new PaginatedResult(
            items: $members,
            total: $total,
            page: $page,
            perPage: $perPage,
        );
    }
}
```

### Service/BanService.php

```php
final class BanService
{
    public function __construct(
        private readonly BanRepositoryInterface $bans,
        private readonly UserRepositoryInterface $users,
        private readonly EventDispatcherInterface $events,
    ) {}

    public function ban(CreateBanDTO $dto): Ban
    {
        $now = time();
        $end = $dto->durationSeconds !== null ? $now + $dto->durationSeconds : 0;

        $data = [
            'ban_userid' => 0,
            'ban_ip'     => '',
            'ban_email'  => '',
            'ban_start'  => $now,
            'ban_end'    => $end,
            'ban_exclude' => 0,
            'ban_reason' => $dto->reason,
            'ban_give_reason' => $dto->displayReason,
        ];

        match ($dto->type) {
            BanType::User => $data['ban_userid'] = $this->resolveUserId($dto->value),
            BanType::Ip => $data['ban_ip'] = $dto->value,
            BanType::Email => $data['ban_email'] = $dto->value,
        };

        $banId = $this->bans->insert($data);
        $ban = $this->bans->findById($banId);

        $this->events->dispatch(new UserBannedEvent(
            banId: $banId,
            type: $dto->type,
            value: $dto->value,
            until: $end > 0 ? $end : null,
            timestamp: $now,
        ));

        return $ban;
    }

    /**
     * @throws BanNotFoundException
     */
    public function unban(int $banId): void
    {
        $ban = $this->bans->findById($banId);
        if ($ban === null) {
            throw new BanNotFoundException();
        }

        $this->bans->delete($banId);
        $this->events->dispatch(new UserUnbannedEvent(
            banId: $banId,
            timestamp: time(),
        ));
    }

    public function isUserBanned(int $userId): ?Ban
    {
        return $this->bans->findActiveByUserId($userId);
    }

    public function isIpBanned(string $ip): ?Ban
    {
        return $this->bans->findActiveByIp($ip);
    }

    /**
     * Check all ban types. Throws if banned.
     *
     * @throws UserBannedException
     */
    public function assertNotBanned(int $userId, string $ip, string $email): void
    {
        $ban = $this->bans->findActiveByUserId($userId)
            ?? $this->bans->findActiveByIp($ip)
            ?? $this->bans->findActiveByEmail($email);

        if ($ban !== null) {
            $msg = $ban->displayReason ?: 'User is banned';
            throw new UserBannedException($msg);
        }
    }

    public function getActiveBans(BanType $type): array
    {
        return $this->bans->findAll($type, activeOnly: true);
    }

    private function resolveUserId(string $username): int
    {
        $user = $this->users->findByUsernameClean(strtolower($username));
        if ($user === null) {
            throw new UserNotFoundException("User '$username' not found");
        }
        if ($user->isFounder()) {
            throw new UserBannedException('Cannot ban a founder');
        }
        return $user->id;
    }
}
```

### Service/UserSearchService.php

```php
final class UserSearchService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly GroupRepositoryInterface $groups,
    ) {}

    public function search(UserSearchCriteria $criteria): PaginatedResult
    {
        return $this->users->search($criteria);
    }

    /**
     * @throws UserNotFoundException
     */
    public function findById(int $id): User
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new UserNotFoundException();
        }
        return $user;
    }

    public function findByUsername(string $username): ?User
    {
        return $this->users->findByUsernameClean(strtolower($username));
    }

    public function findByEmail(string $email): ?User
    {
        return $this->users->findByEmail($email);
    }

    /**
     * Get admin and moderator users.
     * Admins: group ADMINISTRATORS. Mods: group GLOBAL_MODERATORS.
     */
    public function getTeamMembers(): array
    {
        $admins = $this->getGroupMembersByName('ADMINISTRATORS');
        $mods = $this->getGroupMembersByName('GLOBAL_MODERATORS');

        // Merge, deduplicate by user_id
        $team = [];
        foreach (array_merge($admins, $mods) as $user) {
            $team[$user->id] = $user;
        }
        return array_values($team);
    }

    private function getGroupMembersByName(string $groupName): array
    {
        $group = $this->groups->findByName($groupName);
        if ($group === null) {
            return [];
        }
        return $this->groups->getMembers($group->id, 1000, 0);
    }
}
```

---

## composer.json change

Add to `autoload.psr-4`:

```json
"phpbb\\user\\": "src/phpbb/user/"
```

Then run: `composer dump-autoload`

---

## Implementation order

Execute in this exact sequence to satisfy dependencies:

1. **Enums** (4 files) — no dependencies
2. **Entities** (7 files) — depend on Enums
3. **DTOs** (10 files) — depend on Enums
4. **Exceptions** (13 files + base class = 14 files) — no dependencies
5. **Events** (8 files) — depend on Enums
6. **Contracts/Interfaces** (6 files) — depend on Entities, DTOs
7. **Security** (1 file: BcryptPasswordHasher) — depends on PasswordHasherInterface
8. **Repositories** (4 files + abstract base = 5 files) — depend on Entities, Contracts
9. **Services** (9 files) — depend on everything above
10. **composer.json** — add PSR-4 mapping

---

## Validation rules (to implement in Services)

| Field | Rule |
|-------|------|
| username | 3-20 chars, alphanumeric + underscore + dash |
| password | minimum 8 chars |
| email | valid email format (filter_var FILTER_VALIDATE_EMAIL) |
| username_clean | strtolower($username) — used for all lookups |
| session_id | md5 hex string (32 chars) |
| reset_token | 64 char hex string |
| form_salt | 32 char hex string |

---

## Security checklist

- [ ] All SQL uses PDO prepared statements with named parameters
- [ ] `sortBy` in UserSearchCriteria validated against column allowlist
- [ ] `update()` column names validated against allowlist
- [ ] Password hash uses `password_hash(PASSWORD_BCRYPT)` with cost 12
- [ ] Reset tokens use `random_bytes()` (CSPRNG)
- [ ] Session IDs use `random_bytes()` (CSPRNG)  
- [ ] No user input interpolated into SQL
- [ ] Founder users cannot be banned
- [ ] Login attempts tracked and limited

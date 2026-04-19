# Cross-Service Integration Contracts: What Downstream Services Need from `phpbb\user`

**Source**: HLDs and research outputs of Auth, REST API, Notifications, Messaging, Threads, Hierarchy services + Cross-Cutting Assessment  
**Date**: 2026-04-19  
**Confidence**: High (90%+) — all based on explicit interface definitions and documented design decisions

---

## 1. Auth Service (`phpbb\auth`)

**Source**: `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md`

### Imports from `phpbb\user`

| Import | Full Path | Usage |
|--------|-----------|-------|
| `User` entity | `phpbb\user\Entity\User` | Input to `isGranted(User $user, ...)` — all AuthZ checks |
| `UserType` enum | `phpbb\user\Enum\UserType` | Founder detection: `UserType::Founder` in PermissionResolver |
| `Group` entity | `phpbb\user\Entity\Group` | `$group->skipAuth` flag during permission resolution |
| `GroupMembership` entity | `phpbb\user\Entity\GroupMembership` | `$membership->isPending`, `$membership->isLeader` checks |
| `UserRepositoryInterface` | `phpbb\user\Contract\UserRepositoryInterface` | `findById(int)` — resolve user from token claim in AuthorizationSubscriber |
| `GroupRepositoryInterface` | `phpbb\user\Contract\GroupRepositoryInterface` | `getMembershipsForUser(int)` — group lookup in PermissionResolver |

### Properties Accessed on User Entity

| Property | Type | Usage |
|----------|------|-------|
| `$user->id` | `int` | Permission cache key, ACL table lookups |
| `$user->type` | `UserType` enum | Founder override in 5-layer resolution (Layer 5) |
| `$user->groupId` | `int` | Default group (implied by `@param User $user` in `isGranted()`) |

### Methods Called on Repositories

| Repository | Method | SQL Equivalent |
|------------|--------|---------------|
| `UserRepositoryInterface` | `findById(int $userId): ?User` | `SELECT * FROM phpbb_users WHERE user_id = :userId` |
| `GroupRepositoryInterface` | `getMembershipsForUser(int $userId): GroupMembership[]` | `SELECT ug.*, g.group_type, g.group_skip_auth FROM phpbb_user_group ug JOIN phpbb_groups g ON ug.group_id = g.group_id WHERE ug.user_id = :userId AND ug.user_pending = 0` |
| `GroupRepositoryInterface` | `findById(int $groupId): ?Group` | `SELECT * FROM phpbb_groups WHERE group_id = :groupId` |

### Direct DB Access (Bypasses User Service)

Auth accesses `phpbb_users` directly for permission cache columns:

| Column | Direction | Reason |
|--------|-----------|--------|
| `user_permissions` | READ/WRITE | Excluded from User entity by design (ADR-003). Auth owns this column semantically. |
| `user_perm_from` | READ/WRITE | Permission-switch tracking. Reset to 0 on cache clear. |

### Events Consumed

| Event | Purpose |
|-------|---------|
| Group membership changed (from `phpbb\user`) | Clear affected user's permission cache when they join/leave a group |

### Events Dispatched (relevant to User)

| Event | Payload |
|-------|---------|
| `PermissionsClearedEvent` | `?int $userId`, `bool $rolesCacheRebuilt` |
| `PermissionDeniedEvent` | `User $user`, `string $permission`, `?int $forumId`, `string $routeName` |

### Key Assumptions

1. **User entity is lightweight** — provides `id`, `type`, and optionally `groupId`. Does NOT include `user_permissions` column.
2. **`UserType` enum** must include at minimum: `Normal`, `Inactive`, `Founder`, `Bot` (to identify founders for override).
3. **`Group` entity** must expose `skipAuth` boolean (maps to `phpbb_groups.group_skip_auth`).
4. **`GroupMembership`** must expose `isPending`, `isLeader`, and `groupId`.

---

## 2. REST API (`phpbb\api`)

**Source**: `.maister/tasks/research/2026-04-16-phpbb-rest-api/outputs/high-level-design.md`

### Token Authentication (Phase 2)

The `token_auth_subscriber` (priority 16) needs:

1. **Load user from token**: After validating the SHA-256 hashed token against `phpbb_api_tokens`, it loads the user:
   ```
   SELECT * FROM phpbb_users WHERE user_id = <user_id_from_token>
   ```
2. **Hydrate user data**: Sets `$user->data = $user_row` and calls `$auth->acl($user->data)`.

### What User Data is Needed in Token Validation

| Data | Purpose |
|------|---------|
| `user_id` | Token ownership, ACL loading |
| `user_type` | Check if user is active (not banned/inactive) |
| Full user row (`$user->data`) | Legacy compatibility in Phase 1 (`session_begin()` + `acl()`) |

### How API Identifies Current User

- **Phase 1 (session-based)**: `$user->session_begin()` loads session from sid cookie → populates `$user->data`
- **Phase 2 (token-based)**: `token_auth_subscriber` extracts user_id from token table → loads User entity → sets on request attributes

### Token Table Ownership

The `phpbb_api_tokens` table references `user_id` as FK to `phpbb_users.user_id`. This table is managed by the REST API layer, not the User service, but requires:
- `UserRepositoryInterface::findById(int)` for user hydration after token validation

### Assumptions

1. User service must provide a way to check if a user is active/valid (not banned, not deactivated).
2. The API needs `user_id`, `username`, `user_type`, `user_colour` at minimum for request context.

---

## 3. Notifications Service (`phpbb\notifications`)

**Source**: `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md`

### User Events Consumed

Notifications does NOT directly consume user events. It consumes **domain events from other services** (Threads, Messaging) and then determines recipients. The notification type's `findUsersForNotification()` method queries subscription preferences.

### User Preferences Checked

The `phpbb_user_notifications` table stores per-user notification subscription preferences:

| Column | Purpose |
|--------|---------|
| `item_type` | Notification type name (e.g., `notification.type.post`) |
| `item_id` | Specific item subscription (e.g., topic_id for topic watching) |
| `user_id` | The subscribing user |
| `method` | Delivery method name (e.g., `notification.method.board`, `notification.method.email`) |
| `notify` | Whether this subscription is active |

### User Data Needed for Rendering

Notification JSON responses include:

| Field | User Data Source |
|-------|-----------------|
| `avatar_url` | User's avatar path (e.g., `/images/avatars/upload/avatar_42.jpg`) |
| `username` | User's display name |
| `user_id` | Actor identification |
| `user_colour` | For display styling (implied by responders) |

The `NotificationTypeInterface::transformForDisplay()` builds these — it needs to resolve user IDs to display data.

### What Notifications Imports

| Need | Interface/Method |
|------|-----------------|
| Resolve user_id → username, avatar_url | Needs `UserRepositoryInterface::findById(int)` or a batch `findByIds(int[])` |
| User notification method preferences | Queries `phpbb_user_notifications` table directly (owns this table) |

### Assumptions

1. Notification types call into User service to resolve usernames/avatars for display.
2. The `phpbb_user_notifications` table is **owned by Notifications service**, not User. It just references user_id.
3. User service must provide batch user lookup (`findByIds(array $ids)`) for efficient responder resolution.

---

## 4. Messaging Service (`phpbb\messaging`)

**Source**: `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md`

### External Dependencies on User

The architecture diagram states:
> `← phpbb\user (identity)`

Messaging trusts the caller (auth happens externally via middleware):
> "Auth via external middleware (service trusts caller)"

### User Data Used

| Usage | User Property Needed |
|-------|---------------------|
| `sendMessage(senderId, ...)` | `user_id` as `senderId` |
| `replyToConversation(..., senderId, ...)` | `user_id` as `senderId` |
| `messaging_messages.author_id` | FK → `users.user_id` |
| `messaging_conversations.created_by` | FK → `users.user_id` |
| `messaging_participants.user_id` | FK → `users.user_id` |
| Rule check: `sender_group` | Needs user's group membership to evaluate rules like "is_group" |

### Rule Engine: User Group Needs

The `messaging_rules` table has:
- `rule_check = 'sender_group'` — checks if sender belongs to a specific group
- `rule_operator = 'is_group'` — matches group membership
- `rule_group_id` — the target group

This means **Messaging's RuleService needs**: `GroupRepositoryInterface::getMembershipsForUser(int $userId)` — same interface Auth uses.

### User Data for Display

Conversation lists need to show participant names/avatars:
```
"responders" in notification responses: { "user_id": 42, "username": "john_doe", "avatar_url": "..." }
```

Messaging needs batch user resolution for participant preview (3-5 participants per conversation).

### Events Consumed from User

No explicit user-service events are consumed. Messaging processes its own domain events.

### Events Dispatched (potentially consumed by User)

| Event | Relevance |
|-------|-----------|
| `MessageDelivered` | User service could update "last PM received" timestamp |
| `ConversationCreated` | Could update PM-related counters |

### Does it Need `allow_pm`?

The HLD mentions `is_blocked` on the participant level — but the legacy `user_allow_pm` field and group-level `group_receive_pm` are **not referenced** in the new design. The new model handles blocking at the conversation/participant level (`is_blocked`, `left_at`), not at the user profile level.

**However**, the rule system with `rule_action = 'block'` provides equivalent functionality. The legacy `user_allow_pm` toggle could be reimplemented as a default rule or a service-level check before `sendMessage()`.

### Assumptions

1. Messaging uses `user_id` (int) as the sole identifier for participants.
2. Username/avatar resolution for display is needed via batch lookup.
3. Group membership query needed for rule evaluation (`sender_group` checks).
4. No direct dependency on legacy `user_allow_pm` or `group_receive_pm` — blocking is conversation-level.

---

## 5. Threads Service (`phpbb\threads`)

**Source**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`

### System Context Dependency on User

From the architecture diagram:
> `phpbb\user (user_posts via events)`

Threads dispatches events; `phpbb\user` consumes them to update denormalized counters.

### User Data Embedded in Posts

The `Post` entity stores:
- `$posterId` (int) — FK to user_id
- `$posterUsername` (string) — **denormalized copy** at post creation time
- `$posterIp` (string) — poster's IP address

The `Topic` entity stores:
- `$posterId` (int) — topic creator's user_id
- `$firstPosterName` (string) — **denormalized**
- `$firstPosterColour` (string) — **denormalized**
- `$lastPosterId` (int) — last poster's user_id
- `$lastPosterName` (string) — **denormalized**
- `$lastPosterColour` (string) — **denormalized**

### User Counter Updates (via Events)

Threads dispatches events, and `phpbb\user` must listen to:

| Event | User Update Needed |
|-------|-------------------|
| `PostCreatedEvent` | Increment `user_posts` if visibility=Approved AND `countsTowardPostCount=true` |
| `PostSoftDeletedEvent` | Decrement `user_posts` |
| `PostRestoredEvent` | Increment `user_posts` |
| `VisibilityChangedEvent` | Increment/decrement `user_posts` based on old→new visibility |
| `PostCreatedEvent` | Update `user_lastpost_time` |

### Events: User Data in Payload

| Event | User-Related Payload Fields |
|-------|----------------------------|
| `PostCreatedEvent` | `posterId`, `visibility`, `isFirstPost` |
| `TopicCreatedEvent` | `posterId` |
| `PostSoftDeletedEvent` | `posterId`, `actorId` |
| `PostRestoredEvent` | `posterId`, `actorId` |
| `VisibilityChangedEvent` | `actorId`, `affectedPostIds[]` — needs to resolve poster IDs from post data |
| `TopicDeletedEvent` | `allPosterIds[]` — for mass user_posts recalculation |

### User Data Needed at Write Time

When creating a post (`CreateTopicRequest` / `CreateReplyRequest`), Threads needs:
- `userId` — who's posting (int, from authenticated user in request)
- `username` — denormalized into `poster_username` column (for anonymous/guest posts or display without JOIN)
- `userColour` — denormalized into topic first/last poster colour

### Assumptions

1. Threads expects User service to provide an event subscriber that listens to `PostCreatedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`, `VisibilityChangedEvent`.
2. User service must increment/decrement `user_posts` and update `user_lastpost_time`.
3. Threads denormalizes username/colour at write time — needs this data from User entity.
4. User service receives `allPosterIds[]` in `TopicDeletedEvent` for batch counter recalculation.

---

## 6. Hierarchy Service (`phpbb\hierarchy`)

**Source**: `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md`

### Dependency on User

From the architecture diagram:
> `phpbb\user (user_id, lastmark)`

### User Data Used

| Usage | Data Needed |
|-------|-------------|
| `markForumRead(userId, forumId, markTime)` | `user_id` |
| `markAllRead(userId, markTime)` | `user_id` |
| `isForumUnread(userId, forumId)` | `user_id` |
| `getUnreadStatus(userId, forumIds)` | `user_id` |
| `subscribe(userId, forumId)` | `user_id` |
| `user_lastmark` | Global "mark all read" timestamp from phpbb_users table |

### TrackingService

The tracking service uses `user_lastmark` from `phpbb_users` as the global "all forums marked read" baseline:
- If a forum's `mark_time` < `user_lastmark`, default to "read"
- For anonymous users, uses cookie-based tracking (no user service interaction)

### SubscriptionService

`getSubscribers(int $forumId)` returns user IDs that have forum watch enabled — used by Notifications service to determine who should receive "new topic in forum" notifications.

### Moderator Groups

Hierarchy is **ACL-unaware** (ADR-006). It does NOT store moderator groups. The API/display layer applies `f_list`/`f_read` permission filters via `phpbb\auth`. Group-based moderator assignment is entirely in the Auth service's `phpbb_acl_groups` table.

### Assumptions

1. Hierarchy needs only `user_id` (int) and `user_lastmark` (int unix timestamp).
2. No User entity import needed — just the integer scoped ID.
3. `user_lastmark` might be accessed directly from DB or via a lightweight method on User service.

---

## 7. Unified Interface Surface: What User Service MUST Expose

### Entity: `phpbb\user\Entity\User`

```php
final readonly class User
{
    public int $id;               // Required by: ALL services
    public string $username;      // Required by: Threads (denormalize), Notifications (display), Messaging (display)
    public UserType $type;        // Required by: Auth (founder detection), REST API (active check)
    public string $userColour;    // Required by: Threads (denormalize poster colour)
    public int $defaultGroupId;   // Required by: Auth (implicit group context)
    public string $avatarUrl;     // Required by: Notifications (display), Messaging (display)
    public int $lastmark;         // Required by: Hierarchy (tracking baseline)
    public int $posts;            // Required by: Threads (counter updates)
    public int $lastPostTime;     // Required by: Threads (last activity)
    // NOT included: user_permissions, user_perm_from (owned by Auth via direct PDO)
}
```

### Enum: `phpbb\user\Enum\UserType`

```php
enum UserType: int
{
    case Normal   = 0;   // Required by: REST API (active check)
    case Inactive = 1;   // Required by: REST API (reject inactive)
    case Bot      = 2;   // Required by: Auth (special handling), Notifications (exclude from notification)
    case Founder  = 3;   // Required by: Auth (founder override in 5-layer resolution)
}
```

### Entity: `phpbb\user\Entity\Group`

```php
final readonly class Group
{
    public int $id;
    public string $name;
    public int $type;            // GROUP_OPEN, GROUP_CLOSED, GROUP_HIDDEN, GROUP_SPECIAL
    public bool $skipAuth;       // Required by: Auth (group_skip_auth flag)
    public string $colour;
    // Potentially: public bool $receivePm;   // group_receive_pm (legacy, may not be needed)
    // Potentially: public int $messageLimit; // group_message_limit (legacy, may not be needed)
}
```

### Entity: `phpbb\user\Entity\GroupMembership`

```php
final readonly class GroupMembership
{
    public int $groupId;         // Required by: Auth
    public int $userId;
    public bool $isPending;      // Required by: Auth (exclude pending from permission resolution)
    public bool $isLeader;       // Required by: Auth (leader-specific handling)
}
```

### Interface: `phpbb\user\Contract\UserRepositoryInterface`

```php
interface UserRepositoryInterface
{
    /** Required by: Auth (AuthorizationSubscriber), REST API (token validation) */
    public function findById(int $userId): ?User;

    /** Required by: Notifications (batch responder display), Messaging (participant preview) */
    public function findByIds(array $userIds): array;  // keyed by user_id

    /** Required by: Hierarchy (TrackingService needs user_lastmark) */
    public function getLastmark(int $userId): int;
}
```

### Interface: `phpbb\user\Contract\GroupRepositoryInterface`

```php
interface GroupRepositoryInterface
{
    /** Required by: Auth (PermissionResolver), Messaging (RuleService sender_group checks) */
    public function getMembershipsForUser(int $userId): array;  // GroupMembership[]

    /** Required by: Auth (skipAuth flag check) */
    public function findById(int $groupId): ?Group;
}
```

### Interface: `phpbb\user\Contract\UserCounterServiceInterface`

```php
interface UserCounterServiceInterface
{
    /** Required by: Threads events (PostCreatedEvent, PostRestoredEvent) */
    public function incrementPostCount(int $userId): void;

    /** Required by: Threads events (PostSoftDeletedEvent, VisibilityChanged) */
    public function decrementPostCount(int $userId): void;

    /** Required by: Threads events (PostCreatedEvent) */
    public function updateLastPostTime(int $userId, int $timestamp): void;

    /** Required by: Threads events (TopicDeletedEvent with allPosterIds[]) */
    public function batchRecalculatePostCounts(array $userIds): void;
}
```

---

## 8. Event Catalog Requirements

### Events User Service MUST DISPATCH

| Event | Consumed By | Payload |
|-------|-------------|---------|
| `UserGroupChangedEvent` | Auth (clear permission cache) | `int $userId`, `int $groupId`, `string $action` ('joined'/'left'/'role_changed') |
| `UserActivatedEvent` | (future services) | `int $userId` |
| `UserDeactivatedEvent` | (future services) | `int $userId` |
| `UserDeletedEvent` | All services (cascade cleanup) | `int $userId` |

### Events User Service MUST CONSUME

| Event | Dispatched By | Handler Action |
|-------|---------------|---------------|
| `PostCreatedEvent` | Threads | If visibility=Approved && countsTowardPostCount: increment `user_posts`, update `user_lastpost_time` |
| `PostSoftDeletedEvent` | Threads | Decrement `user_posts` for posterId |
| `PostRestoredEvent` | Threads | Increment `user_posts` for posterId |
| `VisibilityChangedEvent` | Threads | Transfer post counts: if Approved→Deleted decrement, if Deleted→Approved increment. Needs `affectedPostIds[]` → resolve poster IDs |
| `TopicDeletedEvent` | Threads | Batch recalculate `user_posts` for all `allPosterIds[]` |

---

## 9. Critical Contract: Auth ↔ User (Resolving Critical Blocker #1)

### The Problem

Auth's `AuthorizationService::isGranted(User $user, string $permission, ?int $forumId = null)` is the single most called interface in the entire system. Every API request goes through it. Auth needs:

1. A `User` entity with `id` and `type` properties
2. A `GroupRepositoryInterface` to resolve user's group memberships
3. Direct PDO access to `user_permissions` column (bypasses User entity)

### The Contract

```
┌──────────────────┐                    ┌──────────────────┐
│   phpbb\auth     │                    │   phpbb\user     │
│                  │                    │                  │
│  AuthorizationSvc│─── imports ───────▶│ Entity\User      │
│                  │    (value object)  │   .id: int       │
│                  │                    │   .type: UserType │
│                  │                    │                  │
│  PermissionResolver─── calls ────────▶│ GroupRepository  │
│                  │                    │   .getMembershipsForUser(int)│
│                  │                    │   .findById(int) │
│                  │                    │                  │
│  AuthorizationSub──── calls ─────────▶│ UserRepository   │
│  (token→User)   │                    │   .findById(int) │
│                  │                    │                  │
│  AclCacheRepo   │─── direct PDO ────▶│ phpbb_users table│
│                  │    (NOT via User)  │   .user_permissions│
│                  │                    │   .user_perm_from│
└──────────────────┘                    └──────────────────┘
```

### Performance Contract

- `UserRepositoryInterface::findById()` — called ONCE per request (in AuthorizationSubscriber). Must be fast (< 1ms with simple SELECT).
- `GroupRepositoryInterface::getMembershipsForUser()` — called ONCE per request ONLY on cache miss (when `user_permissions` is empty). Can be slightly slower (JOIN query).
- `AclCacheRepository` direct access — called on EVERY request (read `user_permissions` column). This is Auth-internal, not via User service interfaces.

### Ownership Boundaries

| Data | Owner | Why |
|------|-------|-----|
| `phpbb_users.*` (most columns) | User Service | Core user identity |
| `phpbb_users.user_permissions` | Auth Service (direct PDO) | Performance-critical cache, semantically part of ACL |
| `phpbb_users.user_perm_from` | Auth Service (direct PDO) | Permission-switch tracking |
| `phpbb_groups.*` | User Service | Group definitions |
| `phpbb_user_group.*` | User Service | Group membership |
| `phpbb_acl_*` (5 tables) | Auth Service | ACL grants, roles |

### Cache Invalidation Contract

When User service changes group membership:
1. User service dispatches `UserGroupChangedEvent`
2. Auth's listener calls `AclCacheService::clearPrefetch($userId)` → clears `user_permissions` to ''
3. Next request triggers lazy rebuild

---

## 10. Unresolved Questions

### 10.1 Token Management Ownership

**Question**: Who creates and manages API tokens (`phpbb_api_tokens`)?  
**Conflict**: REST API HLD defines the table and token validation flow. But is there a "token management service" in User? Or a separate API endpoint for token CRUD?  
**Impact**: User service may need a `TokenService` or the REST API layer owns token lifecycle entirely.

### 10.2 `user_allow_pm` Legacy Field

**Question**: Does the new Messaging service check `phpbb_users.user_allow_pm` before sending messages?  
**Status**: The Messaging HLD does NOT reference it. Blocking is handled at conversation level (`is_blocked`). But the legacy field exists and users expect PM privacy toggle.  
**Impact**: User service may need to expose this as a preference, or it's deprecated in favor of Messaging's rule system.

### 10.3 `group_receive_pm` and `group_message_limit`

**Question**: Are legacy group-level PM restrictions preserved?  
**Status**: Not referenced in Messaging HLD. The rule system provides alternative per-user granularity.  
**Impact**: User service's Group entity may not need these fields. Document as deprecated.

### 10.4 Username/Avatar Resolution — Batch vs Individual

**Question**: Should User service provide a dedicated "display data" DTO for batch resolution, or do callers always use the full User entity?  
**Status**: Notifications and Messaging both need `{user_id, username, avatar_url}` for rendering lists. Loading full User entities for 50 responders is wasteful.  
**Recommendation**: Add `getUserDisplayData(array $userIds): array` returning lightweight `{id, username, colour, avatar_url}` DTOs.

### 10.5 `user_lastmark` — Service Method or Column Access?

**Question**: Should `user_lastmark` be part of the User entity, or accessed via a dedicated method?  
**Status**: Hierarchy's TrackingService needs it. It's a single int column.  
**Recommendation**: Include in User entity. It's cheap and commonly needed.

### 10.6 User Active/Valid Check

**Question**: How do services verify a user is active (not banned, not inactive)?  
**Status**: REST API needs this at token validation time. Auth needs to know if user is valid before permission checks.  
**Recommendation**: User entity should expose `isActive(): bool` based on `user_type != UserType::Inactive` and no active ban.

### 10.7 Content Pipeline's `ContentContext`

**Question**: Threads' `ContentContext` requires `$userId` (author) and `$viewingUserId` (viewer). It also needs viewer preferences: `viewSmilies`, `viewImages`, `viewCensored`. Where do these come from?  
**Status**: These map to `phpbb_users.user_options` bitfield.  
**Impact**: User service must expose user display preferences (possibly as a separate `UserPreferences` VO).

### 10.8 Extension Model Contradiction Impact

**Question**: Does the Notifications service's use of tagged DI services (`notification.type`, `notification.method`) conflict with the events+decorators model used by Threads/Hierarchy/Messaging?  
**Status**: Cross-cutting assessment identifies this as ❌ CRITICAL.  
**Impact on User**: If User service exposes "user event subscribers" (for `PostCreatedEvent` etc.), it uses the shared EventDispatcher pattern. No direct conflict with notification type registration. But if User needs extensibility (custom user fields, custom user types), the extension model matters.

### 10.9 Session Management

**Question**: Does User service own session management (login/logout/session lifecycle), or is that separate?  
**Status**: Auth HLD ADR-001 states: "The `phpbb\user\Service\AuthenticationService` already provides a complete 10-step login flow." This service doesn't exist yet.  
**Impact**: User service scope may include `AuthenticationService` (login/logout/session) separate from `phpbb\auth` (authorization/permissions).

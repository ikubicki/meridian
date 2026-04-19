# Patterns & Literature Review: Users Service

## 1. Shadow Banning

### 1.1 Behavioral Specification

Shadow banning (also: stealth ban, hell ban, ghost ban) is a moderation pattern where a problematic user's content is hidden from everyone except the user themselves, giving the illusion that their participation is normal.

**Core user experience of a shadow-banned user:**
- Posts/comments appear normally **from the banned user's perspective** — they see their own content, receive no error messages
- Other users **cannot see** the banned user's posts (or see them with severely reduced visibility)
- The user receives **no notification** that they've been banned
- Existing content may remain visible (only new content is ghosted) or retroactively hidden
- The banned user can still log in, browse, and interact — only their output is invisible

**Goal:** The user becomes bored/frustrated from lack of engagement and leaves on their own, without creating new accounts (sockpuppets) or escalating behavior through multiple ban-evasion attempts.

**Source:** Wikipedia "Shadow banning" — originated in 1980s BBS (Citadel "twit bit"), popularized by Something Awful (2001), FogBugz (2006), vBulletin ("Tachy goes to Coventry"), Hacker News "hellbanning" (2012), Reddit (pre-2015).

### 1.2 Gradations & Variants

| Level | Name | Behavior |
|-------|------|----------|
| Full shadow ban | Hellban / Ghost | All posts invisible to others, all interactions suppressed |
| Partial / Soft shadow ban | Slow ban / Reduced visibility | Posts delayed or shown to fewer users, reduced in ranking |
| Scoped shadow ban | Subreddit/forum shadow ban | Invisible only in specific areas, visible elsewhere |
| Temporal shadow ban | Rate-limiting | Posts visible but throttled (only first N per day shown publicly) |
| Content-level shadow ban | Keyword/hashtag ghost | Specific posts with certain content hidden, others visible |

**Platform implementations:**

| Platform | Mechanism | Scope |
|----------|-----------|-------|
| **Reddit** | Sitewide shadow ban (admin) + per-subreddit AutoModerator removal | User-level, subreddit-level |
| **Discourse** | "Silence" mode — user cannot create posts/topics/messages, but existing content stays. NOT true shadow ban — user sees an error. Discourse explicitly chose NOT to implement hellbanning. | User-level |
| **vBulletin** | "Tachy goes to Coventry" — global ignore list, posts hidden from all other users | User-level |
| **Hacker News** | "Hellban" flag on account — all submissions/comments invisible to others unless viewer has "showdead" enabled | User-level, with opt-in visibility |
| **Twitter/X** | "Visibility filtering" — delisting from search, trends. User flags: "Do not amplify", "Search Blacklisted", "Trends Blacklisted" | Content-level, account-level |
| **Instagram/Meta** | Hashtag shadowban + account demotion in algorithm | Content-level, account-level |
| **Stack Overflow** | No formal shadow ban — uses post rate limiting, review queues, and "answer ban" (user sees explicit message) | Not true shadow ban |

### 1.3 Implementation Patterns

#### Storage Model Options

**Option A: Flag on user record**
```
phpbb_users.user_shadow_banned TINYINT(1) DEFAULT 0
phpbb_users.user_shadow_ban_start INT(11) DEFAULT 0
phpbb_users.user_shadow_ban_end INT(11) DEFAULT 0  -- 0 = permanent
phpbb_users.user_shadow_ban_reason VARCHAR(255) DEFAULT ''
```
- **Pros:** Fast lookup (already loading user row), simple
- **Cons:** Tight coupling to user table, no audit trail, no exclude mechanism

**Option B: Extension of existing banlist table**
```
phpbb_banlist.ban_type ENUM('user','ip','email','shadow')
-- or --
phpbb_banlist.ban_shadow TINYINT(1) DEFAULT 0
```
- **Pros:** Reuses existing ban infrastructure, supports IP/email shadow bans, has exclude mechanism
- **Cons:** Requires modifying ban check logic everywhere

**Option C: Separate shadow ban table**
```sql
CREATE TABLE phpbb_shadow_bans (
    shadow_ban_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    scope           ENUM('full','forum','content') DEFAULT 'full',
    forum_id        INT UNSIGNED DEFAULT 0,  -- 0 = all forums
    ban_start       INT UNSIGNED NOT NULL,
    ban_end         INT UNSIGNED DEFAULT 0,  -- 0 = permanent
    ban_reason      VARCHAR(255) DEFAULT '',
    ban_given_by    INT UNSIGNED NOT NULL,
    created_at      INT UNSIGNED NOT NULL,
    KEY idx_user_id (user_id),
    KEY idx_scope_forum (scope, forum_id)
);
```
- **Pros:** Clean separation, supports scoping, audit trail, doesn't touch existing ban system
- **Cons:** Additional table, additional query

#### Visibility Check Pattern

The key integration point: **where and how to filter shadow-banned content.**

```php
// Pattern 1: Query-level filter (preferred for performance)
// PostRepository adds condition when fetching posts for display
public function getVisiblePosts(int $topicId, int $viewerId): array
{
    $sql = 'SELECT p.* FROM phpbb_posts p
            WHERE p.topic_id = :topic_id
            AND (p.poster_id NOT IN (SELECT user_id FROM phpbb_shadow_bans WHERE ...)
                 OR p.poster_id = :viewer_id)';
    // Shadow-banned user's posts visible ONLY to themselves
}

// Pattern 2: Post-query filter (simpler, less performant)
// Response decorator strips posts from shadow-banned users
class ShadowBanResponseDecorator implements ResponseDecoratorInterface
{
    public function decorate(object $response): object
    {
        if (!$response instanceof TopicViewResponse) return $response;
        $response->posts = array_filter($response->posts, function($post) {
            return !$this->isShadowBanned($post->posterId) 
                   || $post->posterId === $this->currentUserId;
        });
        return $response;
    }
}
```

#### Detection Prevention

Key challenge: prevent shadow-banned users from discovering they're banned.

1. **No error messages** — all actions succeed from the user's perspective
2. **Personal view consistency** — user sees their own posts normally, with correct counts
3. **Notification suppression** — shadow-banned user still "receives" notifications (stored in DB for their view) but their actions don't generate notifications for others
4. **Counter consistency** — topic reply counts shown to the shadow-banned user include their own posts; shown to others they don't
5. **Logged-out check** — most common detection method. Mitigation: serve the user's own posts even when logged out (via cookie fingerprinting) — complex and often not worth implementing
6. **Different browser check** — hardest to prevent; the "logged out in incognito" test is nearly impossible to fool without extensive fingerprinting

**Pragmatic approach for forums:** Accept that determined users will eventually discover the shadow ban. The goal is to slow down casual trolls, not defeat technically sophisticated users. A simpler implementation that fools casual checks is sufficient.

### 1.4 Integration with Regular Bans & Moderation

| Scenario | Behavior |
|----------|----------|
| Shadow ban + regular ban | Regular ban takes precedence (user can't log in) |
| Shadow ban + moderation queue | Shadow-banned posts skip moderation queue (they're invisible anyway) |
| Shadow ban + notifications | User's actions don't trigger notifications to others; user still receives notifications for content they can see |
| Shadow ban + email digest | User's posts excluded from digest emails sent to others |
| Shadow ban + search | User's posts excluded from search results for others |
| Shadow ban + user profile | User's post count appears normal to them; others see reduced count or no recent activity |
| Shadow ban + quoting | If someone quotes a shadow-banned post before ban: visible. New quotes of ghost posts: shouldn't happen (post invisible) |

### 1.5 Recommendations for This Project

**Recommended approach:** Option C (separate table) + Response Decorator pattern for content filtering.

**Rationale:**
- Clean separation of concerns — shadow ban is a distinct concept from permanent/timed ban
- Scoping support (per-forum shadow ban useful for large communities)
- Response decorator pattern aligns with project convention (Hierarchy + Threads both use decorator pipelines)
- Query-level filtering as optimization available later via `PostRepository` / `TopicRepository` extension

**Recommended scope for v1:**
- Full shadow ban only (all posts invisible to others)
- User-level only (not IP/email)
- Separate `ShadowBanService` within `phpbb\user` namespace
- `ShadowBanCheckDecorator` registered as response decorator on `phpbb\threads`
- Domain event: `UserShadowBannedEvent`, `UserShadowBanRemovedEvent`

---

## 2. PHP 8.2 Decorator / Event Extensibility Patterns

### 2.1 Project Convention: Request/Response Decorator Pipeline

Both `phpbb\hierarchy` and `phpbb\threads` use the **same extensibility model**, establishing the project convention:

```
Request DTO ──► RequestDecoratorChain ──► Service Logic
    ──► Domain Event ──► EventDispatcher ──► ResponseDecorator
    ──► Response DTO returned to caller
```

**Key interfaces (from Hierarchy/Threads):**

```php
interface RequestDecoratorInterface
{
    /** Return the modified request with extra data added */
    public function decorate(object $request): object;
    /** Plugin priority (lower = earlier) */
    public function getPriority(): int;
}

interface ResponseDecoratorInterface
{
    /** Return the modified response with extra data added */
    public function decorate(object $response): object;
    /** Plugin priority (lower = earlier) */
    public function getPriority(): int;
}
```

**Registration via tagged DI services:**
```yaml
services:
    poll.request_decorator:
        class: phpbb\poll\PollRequestDecorator
        tags: ['phpbb.threads.request_decorator']

    poll.response_decorator:
        class: phpbb\poll\PollResponseDecorator
        tags: ['phpbb.threads.response_decorator']

    poll.event_subscriber:
        class: phpbb\poll\PollEventSubscriber
        tags: ['kernel.event_subscriber']
```

**`DecoratorPipeline`** collects tagged decorators at container compilation time, sorts by priority, executes in order.

### 2.2 Notifications' Tagged DI Approach — How It Differs

The `phpbb\notifications` service uses a **different pattern**: a `service_collection` registry that collects tagged services into a typed registry object.

```yaml
phpbb.notifications.type_collection:
    class: phpbb\di\service_collection
    arguments: ['@service_container']
    tags:
        - { name: service_collection, tag: notification.type }

phpbb.notifications.type_registry:
    class: phpbb\notifications\Type\NotificationTypeRegistry
    arguments: ['@phpbb.notifications.type_collection']
```

**Key difference:**
| Aspect | Hierarchy/Threads Decorator | Notifications Tagged Registry |
|--------|---------------------------|-------------------------------|
| **Purpose** | Transform data flowing through pipeline | Register independent implementations of a contract |
| **Execution** | Sequential middleware chain (ordered) | Lookup by key — dispatch selectively |
| **Data flow** | Input → Decorator₁ → Decorator₂ → Output | Registry holds all types; dispatcher selects by name |
| **When to use** | Enriching/transforming DTOs, adding cross-cutting data | Polymorphic extension points (different notification types, delivery methods) |

**The contradiction identified in cross-cutting assessment (§7.1):**
- Hierarchy/Threads use **events + request/response decorators**
- Notifications uses **tagged DI service_collection** (legacy phpBB pattern)

**Resolution:** Both patterns are valid for different use cases. The key insight:
- **Decorators** = middleware pipeline for transforming data on existing operations
- **Tagged registries** = strategy/plugin registry for adding new variants of a concept

### 2.3 Symfony DI Decorator Pattern (Framework-Level)

Symfony provides a built-in `decorates` keyword for service decoration:

```yaml
services:
    App\DecoratingMailer:
        decorates: App\Mailer
        arguments: ['@.inner']
```

Or with PHP 8.2 attributes:
```php
#[AsDecorator(decorates: Mailer::class, priority: 5)]
class DecoratingMailer
{
    public function __construct(
        #[AutowireDecorated] private Mailer $inner,
    ) {}
}
```

**This is a different kind of decorator** — it's *service-level* decoration (wrapping the entire service implementation), not *DTO-level* decoration (enriching request/response data). 

**When to use which:**

| Pattern | Use Case | Example |
|---------|----------|---------|
| Symfony `decorates` | Wrap entire service to add cross-cutting behavior (logging, caching, metrics) | `CachingUserRepository decorates UserRepository` |
| Request/Response DTO Decorators | Plugins adding data to operation inputs/outputs | `ShadowBanResponseDecorator` strips banned user posts |
| Domain Events + Listeners | Side effects triggered by state changes | `UserBannedEvent` → clear sessions, notify admins |
| Tagged Registry | Extensible set of implementations for a concept | Notification types, profile field types, auth providers |

### 2.4 Best Practices: When to Use Events vs Decorators

**Use Events when:**
- The action has already happened (post-commit side effects)
- Multiple independent listeners need to react
- Order doesn't matter (or listener priority is sufficient)
- No return value needed from listeners
- Cross-service communication (loose coupling)

**Use Request Decorators when:**
- Plugins need to inject data BEFORE the operation executes
- The injected data affects the operation outcome
- Data needs to travel through the pipeline (set in request, consumed in event handler)

**Use Response Decorators when:**
- Plugins need to enrich the response with additional data
- Display-time augmentation (add poll results, read status, shadow ban filtering)
- Data is needed by the presentation layer but not by core logic

**Use Symfony Service Decorators when:**
- You want to transparently wrap a service (cache layer, logging, metrics)
- Single decorator wrapping the entire interface
- Not for plugin ecosystems — for infrastructure concerns

**Use Tagged Registries when:**
- Open-ended set of implementations (notification types, profile field types)
- Each implementation is a standalone strategy, not a pipeline stage
- Lookup by key is needed at runtime

### 2.5 Alignment Recommendation for User Service

The `phpbb\user` service should use:

1. **Request/Response Decorators** — for user-facing operations (`getUserProfile()`, `listUsers()`)
   - Example: `AvatarResponseDecorator` enriches profile response with avatar URL
   - Example: `ShadowBanResponseDecorator` on threads service filters banned user content

2. **Domain Events** — for all state changes
   - `UserCreatedEvent`, `UserBannedEvent`, `UserShadowBannedEvent`, `UserActivatedEvent`, etc.
   - Consumed by: notifications, threads (post count), sessions (invalidation), cache

3. **Tagged Registry** — for extensible profile field types
   - `ProfileFieldTypeRegistry` collects profile field type implementations (bool, dropdown, text, url, date)
   - New profile field types added by tagging `phpbb.user.profile_field_type`

4. **Symfony Service Decorator** — for infrastructure wrapping
   - `CachingSessionRepository decorates SessionRepository` — cache session lookups
   - `LoggingAuthenticationService decorates AuthenticationService` — audit log

---

## 3. REST API Design for User Management

### 3.1 Standard Endpoint Catalog

Based on REST API conventions (JSON:API, GitHub API, Discourse API, Flarum API patterns):

#### Users

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/api/v1/users` | List users (paginated, filterable) | Public (limited fields) |
| GET | `/api/v1/users/{id}` | Get user profile | Public (limited) / Auth (full) |
| GET | `/api/v1/users/{id}/profile` | Get extended profile (custom fields) | Public |
| POST | `/api/v1/users` | Register new user | Public |
| PATCH | `/api/v1/users/{id}` | Update user (admin) | Admin |
| DELETE | `/api/v1/users/{id}` | Delete/purge user | Admin |
| GET | `/api/v1/users/me` | Current user (shorthand) | Authenticated |
| PATCH | `/api/v1/users/me` | Update own profile | Authenticated |

#### Preferences

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/api/v1/users/me/preferences` | Get all preferences | Authenticated |
| PATCH | `/api/v1/users/me/preferences` | Update preferences (partial) | Authenticated |
| GET | `/api/v1/users/me/preferences/notifications` | Notification prefs | Authenticated |
| PATCH | `/api/v1/users/me/preferences/notifications` | Update notification prefs | Authenticated |

#### Authentication

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| POST | `/api/v1/auth/login` | Authenticate, get token | Public |
| POST | `/api/v1/auth/logout` | Revoke current token | Authenticated |
| POST | `/api/v1/auth/refresh` | Refresh token (if using refresh tokens) | Authenticated |
| POST | `/api/v1/auth/password/reset` | Request password reset | Public |
| POST | `/api/v1/auth/password/change` | Change password (authenticated) | Authenticated |
| GET | `/api/v1/auth/tokens` | List active tokens | Authenticated |
| DELETE | `/api/v1/auth/tokens/{id}` | Revoke specific token | Authenticated |

#### Groups

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/api/v1/groups` | List groups | Public (limited) |
| GET | `/api/v1/groups/{id}` | Get group details | Public |
| POST | `/api/v1/groups` | Create group | Admin |
| PATCH | `/api/v1/groups/{id}` | Update group | Admin / Leader |
| DELETE | `/api/v1/groups/{id}` | Delete group | Admin |
| GET | `/api/v1/groups/{id}/members` | List group members (paginated) | Public / Members |
| POST | `/api/v1/groups/{id}/members` | Add member(s) to group | Admin / Leader |
| DELETE | `/api/v1/groups/{id}/members/{userId}` | Remove member | Admin / Leader |
| POST | `/api/v1/groups/{id}/join` | Request to join (for open/request groups) | Authenticated |
| POST | `/api/v1/groups/{id}/leave` | Leave group | Authenticated |
| POST | `/api/v1/groups/{id}/members/{userId}/approve` | Approve pending member | Leader |

#### Bans (Admin)

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/api/v1/admin/bans` | List all bans (paginated, filterable) | Admin |
| POST | `/api/v1/admin/bans` | Create ban (user/IP/email) | Admin |
| DELETE | `/api/v1/admin/bans/{id}` | Remove ban | Admin |
| GET | `/api/v1/admin/bans/check/{userId}` | Check if user is banned | Admin |
| POST | `/api/v1/admin/shadow-bans` | Create shadow ban | Admin |
| GET | `/api/v1/admin/shadow-bans` | List shadow bans | Admin |
| DELETE | `/api/v1/admin/shadow-bans/{id}` | Remove shadow ban | Admin |

### 3.2 Pagination Pattern

Following project convention (JSON envelope) and Flarum-style offset pagination:

```json
// GET /api/v1/users?page[offset]=20&page[limit]=10&filter[group]=5&sort=-last_active

{
    "data": [
        { "id": 42, "type": "user", "attributes": { ... } }
    ],
    "meta": {
        "total": 1523,
        "offset": 20,
        "limit": 10
    },
    "links": {
        "self": "/api/v1/users?page[offset]=20&page[limit]=10",
        "first": "/api/v1/users?page[offset]=0&page[limit]=10",
        "prev": "/api/v1/users?page[offset]=10&page[limit]=10",
        "next": "/api/v1/users?page[offset]=30&page[limit]=10",
        "last": "/api/v1/users?page[offset]=1520&page[limit]=10"
    }
}
```

**Key decisions:**
- **Offset-based** pagination (not cursor-based) — forums have stable ordering, simpler to implement
- **Default limit:** 20, **max limit:** 50 (configurable per endpoint)
- **Sort parameter:** `sort=field` (asc) or `sort=-field` (desc), multiple: `sort=-last_active,username`
- **Filter parameter:** `filter[key]=value` — structured filters by attribute

### 3.3 Filtering Pattern

```
GET /api/v1/users?filter[group]=5&filter[type]=active&filter[search]=john
GET /api/v1/users?filter[last_active_after]=2026-01-01&filter[post_count_min]=10
```

Standard filters for user lists:
- `filter[group]` — group ID
- `filter[type]` — `active`, `inactive`, `banned`, `shadow_banned` (admin only)
- `filter[search]` — username partial match
- `filter[last_active_after]` / `filter[last_active_before]` — date range
- `filter[registered_after]` / `filter[registered_before]` — registration date

### 3.4 Privacy Considerations

| Field | Public | Authenticated | Self | Admin |
|-------|--------|---------------|------|-------|
| username | ✓ | ✓ | ✓ | ✓ |
| avatar_url | ✓ | ✓ | ✓ | ✓ |
| user_colour | ✓ | ✓ | ✓ | ✓ |
| registered_date | ✓ | ✓ | ✓ | ✓ |
| post_count | ✓ | ✓ | ✓ | ✓ |
| last_active | configurable | ✓ | ✓ | ✓ |
| profile_fields (public) | ✓ | ✓ | ✓ | ✓ |
| profile_fields (members-only) | ✗ | ✓ | ✓ | ✓ |
| email | ✗ | ✗ | ✓ | ✓ |
| IP addresses | ✗ | ✗ | ✗ | ✓ |
| ban status | ✗ | ✗ | ✓ (own) | ✓ |
| shadow_ban status | ✗ | ✗ | ✗ | ✓ |
| preferences | ✗ | ✗ | ✓ | ✓ |
| session info | ✗ | ✗ | ✓ | ✓ |
| group memberships | depends on group visibility | ✓ | ✓ | ✓ |

### 3.5 Response Shape Convention

Aligned with existing REST API HLD (simple JSON envelope, not full JSON:API spec):

```json
// GET /api/v1/users/42
{
    "data": {
        "id": 42,
        "username": "JohnDoe",
        "username_clean": "johndoe",
        "avatar_url": "/images/avatars/upload/...",
        "colour": "AA0000",
        "registered": 1640995200,
        "last_active": 1713520000,
        "post_count": 847,
        "rank": { "id": 2, "title": "Senior Member" },
        "groups": [
            { "id": 5, "name": "Moderators", "colour": "00AA00" }
        ],
        "profile": {
            "location": "Warsaw",
            "website": "https://example.com"
        }
    }
}
```

---

## 4. Token-Based Session Management

### 4.1 Token Lifecycle

Based on the REST API HLD's ADR-002 (DB opaque tokens, not JWT):

```
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│  Create   │────►│  Validate │────►│  Refresh  │────►│  Revoke   │
│  (login)  │     │  (per-req)│     │ (optional)│     │  (logout) │
└──────────┘     └──────────┘     └──────────┘     └──────────┘
      │                │                │                │
      ▼                ▼                ▼                ▼
 raw_token         hash(token)      new raw_token    is_active=0
 → client          compared to      → client         in DB
                   DB stored hash
```

**Steps:**
1. **Create** — on successful authentication:
   - Generate 32 bytes of `random_bytes()` → base64url encode → `raw_token` (43 chars)
   - Store `hash('sha256', $raw_token)` in DB
   - Return `raw_token` to client in response body
   - Client stores in `Authorization: Bearer <raw_token>` for subsequent requests

2. **Validate** — on every authenticated request:
   - Extract `raw_token` from `Authorization` header
   - Compute `hash('sha256', $raw_token)`
   - Query DB: `WHERE token = :hash AND is_active = 1`
   - Timing-safe comparison not needed (hash comparison in SQL is sufficient — attacker can't observe timing of DB query vs application code)
   - Update `last_used` timestamp

3. **Refresh** (optional):
   - Issue new token, revoke old one atomically
   - OR: extend expiry on existing token (simpler, less secure against token theft)
   
4. **Revoke** — on logout or admin action:
   - Set `is_active = 0` OR delete row
   - Soft-delete preferred (audit trail)

### 4.2 Token Storage Schema

From REST API HLD:

```sql
CREATE TABLE phpbb_api_tokens (
    token_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT(10) UNSIGNED NOT NULL,
    token       CHAR(64) NOT NULL COMMENT 'SHA-256 hex of raw token',
    label       VARCHAR(255) NOT NULL DEFAULT '',
    created     DATETIME NOT NULL,
    expires_at  DATETIME DEFAULT NULL,  -- NULL = no expiry (personal API tokens)
    last_used   DATETIME DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    UNIQUE KEY uidx_token (token),
    KEY idx_user_id (user_id),
    KEY idx_expires_active (is_active, expires_at)  -- for GC
);
```

**Security properties:**
- Raw token NEVER stored — only SHA-256 hash
- Token is 32 random bytes (256 bits of entropy) — brute-force infeasible
- `is_active` flag for soft-revocation
- `last_used` for activity tracking and idle-timeout enforcement
- Index on `(is_active, expires_at)` for efficient GC queries

### 4.3 Session vs Token: When to Use Each

| Aspect | Session (web/browser) | Token (API) |
|--------|----------------------|-------------|
| **Storage** | Session ID in cookie | Token in Authorization header |
| **Lifecycle** | Tied to browser session, auto-expires on close | Explicit creation/revocation |
| **CSRF** | Vulnerable (needs CSRF token) | Not vulnerable (not sent automatically) |
| **XSS** | Cookie HttpOnly protects session ID | Token in JS memory can be stolen by XSS |
| **Multi-device** | One session per browser | Multiple tokens per user |
| **Revocation** | Kill session server-side | Set is_active=0 |
| **Use case** | Traditional web browsing | API clients, SPAs, mobile apps |

**For this project (dual-path approach):**
- **Web (traditional phpBB pages):** Keep session-based auth (existing `phpbb_sessions` table)
- **API:** Token-based auth (new `phpbb_api_tokens` table)
- **SPA frontend:** Use token auth (stores token in memory, refreshes via API)
- **Migration path:** Sessions for Phase 1, tokens for Phase 2 (per REST API HLD)

### 4.4 Garbage Collection

Expired/inactive tokens accumulate. GC strategy:

```php
class TokenGarbageCollector
{
    /**
     * Delete expired and long-inactive tokens.
     * Run via cron or probabilistic trigger on requests.
     */
    public function collect(): int
    {
        // Delete tokens expired more than 7 days ago (grace period for debugging)
        $deleted = $this->db->execute(
            'DELETE FROM phpbb_api_tokens 
             WHERE (expires_at IS NOT NULL AND expires_at < :expired_threshold)
                OR (is_active = 0 AND last_used < :inactive_threshold)',
            [
                'expired_threshold' => new \DateTime('-7 days'),
                'inactive_threshold' => new \DateTime('-30 days'),
            ]
        );
        return $deleted;
    }
}
```

**GC triggers:**
1. **Cron-based** (preferred) — run daily via `bin/phpbbcli.php cron:run`
2. **Probabilistic** — on 1% of API requests, trigger GC (legacy phpBB pattern from `session_gc()`)
3. **Event-based** — on login, clean up user's own expired tokens

**Batch size:** Delete in batches of 1000 to avoid long-running queries holding locks.

---

## 5. Recommendations for This Project

### 5.1 Shadow Banning

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| Storage | Separate `phpbb_shadow_bans` table | Clean separation, supports scoping, audit trail |
| Scope for v1 | Full shadow ban (user-level only) | Simplest to implement, covers 90% of use cases |
| Content filtering | Response decorator on `phpbb\threads` | Aligns with project convention, pluggable |
| Integration | Domain events (`UserShadowBannedEvent`) | Allows sessions, cache, notifications to react |
| Admin API | `/api/v1/admin/shadow-bans` CRUD | Standard REST for management |

### 5.2 Extensibility Model

| Extension Point | Pattern | Example |
|----------------|---------|---------|
| User operations (CRUD, profile, preferences) | Request/Response Decorators | AvatarDecorator, ProfileFieldDecorator |
| State changes | Domain Events | UserCreated, UserBanned, GroupMemberAdded |
| Profile field types | Tagged Registry (`phpbb.user.profile_field_type`) | BoolType, DropdownType, UrlType, custom types |
| Auth providers | Tagged Registry (`phpbb.user.auth_provider`) | LocalAuth, OAuthAuth, LDAPAuth |
| Service infrastructure | Symfony `decorates` | CachingSessionRepo, LoggingAuthService |

### 5.3 REST API

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| URL structure | `/api/v1/users/`, `/api/v1/groups/`, `/api/v1/auth/` | Consistent with existing REST API HLD |
| Pagination | Offset-based with `page[offset]`/`page[limit]` | Simple, sufficient for forum scale |
| Privacy | Tiered visibility (public/auth/self/admin) | Protects PII, exposes public profiles naturally |
| Ban management | Under `/api/v1/admin/bans` | Admin-only, separated from public API |
| Response format | Simple JSON envelope (`{ data, meta, links }`) | Consistent with other API endpoints |

### 5.4 Token/Session

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| Token format | 32-byte random → base64url (43 chars) | Sufficient entropy, compact |
| Storage | SHA-256 hash in DB, raw to client | Industry standard, DB breach doesn't expose tokens |
| Dual auth | Sessions for web, tokens for API | Migration path, legacy compatibility |
| GC | Daily cron + batch deletes of 1000 | Predictable, doesn't impact request latency |
| Expiry | Configurable per-token (default: none for API keys, 24h for session tokens) | Flexible for different client types |
| Refresh | Optional: new token replaces old | Simple rotation without refresh token complexity |

---

## Sources

| Source | Type | Key Information |
|--------|------|-----------------|
| Wikipedia "Shadow banning" | External | History, terminology, platform examples (Reddit, vBulletin, HN, Twitter) |
| FogBugz description (2006) | External | Original behavioral spec: "posts invisible to others, visible to poster" |
| Jeff Atwood "Suspension, Ban or Hellban?" (2011) | External | Coding Horror post on moderation gradations |
| Symfony "How to Decorate Services" docs | External | `decorates`, `#[AsDecorator]`, priority, stacking |
| Flarum API docs | External | JSON:API patterns, pagination (limit/offset), sorting, filtering |
| `phpbb\hierarchy` HLD (project) | Codebase | Request/Response decorator convention, event-driven architecture |
| `phpbb\threads` HLD (project) | Codebase | Plugin extension model (Polls, ReadTracking via decorators + events) |
| `phpbb\notifications` HLD (project) | Codebase | Tagged DI service_collection pattern (contrast with decorators) |
| REST API HLD (project) | Codebase | Token auth schema (ADR-002), route structure, entry points |
| Cross-cutting assessment | Codebase | Extension model contradiction (§7.1), User Service as Critical Blocker #1 |

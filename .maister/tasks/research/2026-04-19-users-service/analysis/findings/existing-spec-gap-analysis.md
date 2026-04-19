# Existing Spec Gap Analysis: `phpbb\user` IMPLEMENTATION_SPEC.md

## Inventory (Existing Spec Contents)

The spec at `src/phpbb/user/IMPLEMENTATION_SPEC.md` is ~1950 lines and defines:

### Enums (4)
| Enum | Values |
|------|--------|
| `UserType` | Normal(0), Inactive(1), Ignore(2), Founder(3) |
| `BanType` | User, Ip, Email |
| `GroupType` | Open(0), Closed(1), Hidden(2), Special(3) |
| `NotifyType` | Email(0), Im(1), Both(2) |

### Entities (7)
| Entity | Key Fields | DB Table |
|--------|-----------|----------|
| `User` | 34 properties (id, username, email, type, passwordHash, loginAttempts, etc.) | `phpbb_users` |
| `UserProfile` | avatar, signature, jabber, birthday | `phpbb_users` (subset) |
| `UserPreferences` | lang, timezone, notify settings, sort prefs | `phpbb_users` (subset) |
| `Session` | id, userId, ip, browser, timestamps, flags | `phpbb_sessions` |
| `Group` | id, name, type, colour, rank, display flags | `phpbb_groups` |
| `GroupMembership` | groupId, userId, isLeader, isPending | `phpbb_user_group` |
| `Ban` | id, type, userId/ip/email, start, end, exclude, reasons | `phpbb_banlist` |

### DTOs (10)
| DTO | Purpose |
|-----|---------|
| `CreateUserDTO` | Registration input |
| `UpdateProfileDTO` | Avatar, signature, jabber, birthday |
| `UpdatePreferencesDTO` | Language, timezone, notify settings |
| `LoginDTO` | Username, password, IP, browser, autoLogin |
| `ChangePasswordDTO` | userId, currentPassword, newPassword |
| `PasswordResetRequestDTO` | email |
| `PasswordResetExecuteDTO` | token, newPassword |
| `CreateBanDTO` | type, value, duration, reasons |
| `UserSearchCriteria` | Filters + pagination + sort |
| `PaginatedResult<T>` | Generic paginated response |

### Services (9)
| Service | Methods |
|---------|---------|
| `AuthenticationService` | login(), logout(), validateSession() |
| `RegistrationService` | register(), activateByKey(), usernameAvailable(), emailAvailable() |
| `PasswordService` | changePassword(), requestReset(), executeReset() |
| `ProfileService` | getProfile(), updateProfile(), changeUsername(), changeEmail(), removeAvatar() |
| `PreferencesService` | getPreferences(), updatePreferences() |
| `SessionService` | create(), findById(), destroy(), destroyAllForUser(), touch(), gc() |
| `GroupService` | getGroupsForUser(), getMemberships(), addToGroup(), removeFromGroup(), setDefaultGroup(), isInGroup(), getMembers() |
| `BanService` | ban(), unban(), isUserBanned(), isIpBanned(), assertNotBanned(), getActiveBans() |
| `UserSearchService` | search(), findById(), findByUsername(), findByEmail(), getTeamMembers() |

### Contracts/Interfaces (6)
- `PasswordHasherInterface` — hash(), verify(), needsRehash()
- `EventDispatcherInterface` — dispatch()
- `UserRepositoryInterface` — 12 methods (CRUD, search, login attempts)
- `SessionRepositoryInterface` — 10 methods (sessions + persistent keys)
- `GroupRepositoryInterface` — 12 methods (membership, leaders, pending)
- `BanRepositoryInterface` — 6 methods (CRUD, active lookups)

### Events (8)
| Event | Trigger |
|-------|---------|
| `UserCreatedEvent` | After registration |
| `UserLoggedInEvent` | After successful login |
| `UserLoggedOutEvent` | After logout |
| `PasswordChangedEvent` | After password change or reset |
| `PasswordResetRequestedEvent` | After reset token generated |
| `ProfileUpdatedEvent` | After profile update |
| `UserBannedEvent` | After ban created |
| `UserUnbannedEvent` | After ban removed |

### Exceptions (13 + 1 base)
All extend `UserServiceException`. Each has HTTP-like codes (401, 403, 404, 409, 429).

### Security (1)
- `BcryptPasswordHasher` — implements `PasswordHasherInterface`

### Database Schemas (5 tables)
- `phpbb_users` (70+ columns documented)
- `phpbb_sessions` (13 columns)
- `phpbb_sessions_keys` (4 columns)
- `phpbb_banlist` (9 columns)
- `phpbb_groups` (21 columns)
- `phpbb_user_group` (4 columns)

---

## Strengths (What's Well-Designed)

| Area | Score (1-5) | Assessment |
|------|-------------|------------|
| **Entity Model** | 4/5 | User entity covers all critical columns from `phpbb_users`. Good use of immutable `readonly` classes, `fromRow()` pattern, `UserType` enum. Deduction: No profile fields, no shadow ban flag. |
| **Services** | 4/5 | Good separation of concerns: auth, registration, password, profile, preferences, session, groups, bans, search. Each has single responsibility. Deduction: No decorator pipeline, no admin user management (delete, deactivate). |
| **Events** | 3/5 | Covers basic lifecycle + ban events. Deduction: Missing group events (added/removed), missing session events, missing preference change events. Not enough granularity for Notifications service listeners. |
| **DTOs** | 4/5 | Clean `readonly` classes. Good use of nullable properties for partial updates. Deduction: No `withExtra()` for decorator pattern. |
| **Exceptions** | 5/5 | Comprehensive, well-named, with HTTP-like codes. Each failure mode has a dedicated exception. Excellent. |
| **Repositories** | 4/5 | Interface-first design, PDO with prepared statements, column allowlists for security. Deduction: No batch operations, no transaction support exposed. |
| **Security** | 4/5 | Good: constant-time password verification via `password_verify()`, login attempt throttling, form salt generation. Deduction: No timing-safe token comparison mentioned. |
| **Guiding Principles** | 5/5 | Excellent: no legacy, constructor injection, interface-first, typed everywhere, events for side-effects, PDO. |

**Overall Spec Quality**: 4.0/5 — solid foundation but needs additions for new requirements.

---

## Gaps

### Gap 1: Shadow Banning ❌

**What's Missing**: The spec has no concept of shadow banning. The current `BanType` enum only has `User`, `Ip`, `Email` — all are "hard" bans that completely block access with an error.

**Impact**: HIGH — Shadow banning is a core requirement. Without it, moderators can't subtly restrict disruptive users. The entire behavioral model (posts visible only to author, reduced discoverability, no error messages) is absent.

**What Needs to Be Added**:
1. **New enum value or separate concept**: `BanMode::Shadow` vs `BanMode::Hard`, or a `ShadowBan` entity separate from `Ban`
2. **Database extension**: New column on `phpbb_users` (e.g., `user_shadow_banned TINYINT(1)`) or a new table `phpbb_shadow_bans` with metadata (applied_at, applied_by, reason, severity level)
3. **`ShadowBanService`** or extension to `BanService`: `applyShadowBan(int $userId, ShadowBanConfig $config): void`, `isShadowBanned(int $userId): bool`, `removeShadowBan(int $userId): void`
4. **`ShadowBanConfig` DTO**: severity (reduced_visibility, posts_hidden_from_others, full_shadow), notification suppression level
5. **NO change to login/auth flow** — shadow-banned users should authenticate normally (no errors)
6. **New events**: `UserShadowBannedEvent`, `UserShadowBanLiftedEvent`
7. **Cross-service contract**: Threads service needs `isShadowBanned(userId)` to adjust visibility; Notifications service needs it to suppress notifications to others about shadow-banned user's actions

**Suggested Resolution Direction**: Separate `ShadowBan` entity and `ShadowBanService` rather than extending the existing `Ban` entity. Shadow bans have fundamentally different behavior (no error, no block, just reduced visibility). The existing ban system's `ban_exclude` flag is for whitelisting, not shadow banning.

---

### Gap 2: Decorator Extensibility ❌

**What's Missing**: The spec uses plain Symfony `EventDispatcherInterface` for events but has NO request/response decorator pipeline. Other services (Hierarchy, Threads) use a `DecoratorPipeline` with `RequestDecoratorInterface` and `ResponseDecoratorInterface` for extensibility.

**Impact**: MEDIUM-HIGH — Without decorators, plugins cannot:
- Add custom fields to registration (e.g., CAPTCHA, terms acceptance, referral codes)
- Enrich user profile responses with plugin-specific data (e.g., reputation scores, badges)
- Transform ban requests (e.g., auto-apply shadow ban rules based on behavior scoring)
- Extend search criteria (e.g., filter by custom profile fields)

**What Needs to Be Added**:
1. **`Decorator/` directory** with:
   - `RequestDecoratorInterface` — `supports(object $request): bool`, `decorateRequest(object $request): object`, `getPriority(): int`
   - `ResponseDecoratorInterface` — `supports(object $response): bool`, `decorateResponse(object $response, object $request): object`, `getPriority(): int`
   - `DecoratorPipeline` — collects and executes decorators in priority order
2. **`withExtra(string $key, mixed $value): static` method** on DTOs (both request and response)
3. **Decorator targets** (which operations get decoration):
   - `CreateUserDTO` / `User` (registration in/out)
   - `UpdateProfileDTO` / `UserProfile` (profile update in/out)
   - `LoginDTO` / `Session` (auth in/out — e.g., 2FA plugin)
   - `UserSearchCriteria` / `PaginatedResult` (search extension)
4. **Service methods modified** to run `$pipeline->decorateRequest($dto)` before logic and `$pipeline->decorateResponse($result, $dto)` after

**Suggested Resolution Direction**: Copy the exact pattern from `phpbb\hierarchy\decorator\` — same interfaces, same pipeline class. Add `withExtra()` to all DTOs. Modify service constructors to accept `DecoratorPipeline` dependency.

---

### Gap 3: REST API Endpoints ❌

**What's Missing**: The spec defines only internal PHP services. No REST controllers, routes, request/response serialization, or API authentication middleware. The existing mock controllers (`api/v1/controller/auth.php`, `api/v1/controller/users.php`) are hardcoded stubs.

**Impact**: HIGH — The entire application is being rebuilt as API-first. Without REST endpoints, the User service is unusable from frontend clients.

**What Needs to Be Added** (REST API surface):

| Method | Endpoint | Service Method | Auth |
|--------|----------|---------------|------|
| POST | `/api/v1/auth/login` | `AuthenticationService::login()` | Public |
| POST | `/api/v1/auth/logout` | `AuthenticationService::logout()` | Bearer |
| POST | `/api/v1/auth/signup` | `RegistrationService::register()` | Public |
| POST | `/api/v1/auth/activate` | `RegistrationService::activateByKey()` | Public |
| POST | `/api/v1/auth/password/reset-request` | `PasswordService::requestReset()` | Public |
| POST | `/api/v1/auth/password/reset` | `PasswordService::executeReset()` | Public |
| POST | `/api/v1/auth/password/change` | `PasswordService::changePassword()` | Bearer |
| GET | `/api/v1/users/me` | `UserSearchService::findById()` | Bearer |
| PATCH | `/api/v1/users/me/profile` | `ProfileService::updateProfile()` | Bearer |
| PATCH | `/api/v1/users/me/preferences` | `PreferencesService::updatePreferences()` | Bearer |
| GET | `/api/v1/users/{id}` | `UserSearchService::findById()` | Bearer |
| GET | `/api/v1/users` | `UserSearchService::search()` | Bearer + permission |
| GET | `/api/v1/users/{id}/groups` | `GroupService::getGroupsForUser()` | Bearer |
| POST | `/api/v1/groups/{id}/members` | `GroupService::addToGroup()` | Bearer + admin |
| DELETE | `/api/v1/groups/{id}/members/{userId}` | `GroupService::removeFromGroup()` | Bearer + admin |
| GET | `/api/v1/groups/{id}/members` | `GroupService::getMembers()` | Bearer |
| POST | `/api/v1/admin/bans` | `BanService::ban()` | Bearer + admin |
| DELETE | `/api/v1/admin/bans/{id}` | `BanService::unban()` | Bearer + admin |
| GET | `/api/v1/admin/bans` | `BanService::getActiveBans()` | Bearer + admin |
| GET | `/api/v1/users/me/sessions` | `SessionService` | Bearer |
| DELETE | `/api/v1/users/me/sessions/{id}` | `SessionService::destroy()` | Bearer |
| GET | `/api/v1/auth/check` | Check username/email availability | Public |

**Suggested Resolution Direction**: Create `src/phpbb/api/v1/controller/` controllers following existing patterns — Symfony Request/JsonResponse, route YAML with `_api_permission` defaults, Bearer token auth via `token_auth_subscriber`. Controllers are thin: validate input → convert to DTO → call service → serialize entity to JSON response.

---

### Gap 4: Profile Fields (Custom/Dynamic) ❌

**What's Missing**: The spec mentions `UserProfile` entity but only models the FIXED profile columns (`user_avatar`, `user_sig`, `user_jabber`, `user_birthday`). It completely ignores the custom profile fields system:
- `phpbb_profile_fields` (24 columns — field definitions with types, validation, visibility)
- `phpbb_profile_fields_data` (EAV-style: `user_id` + dynamic `pf_*` columns)
- `phpbb_profile_fields_lang` (multilingual field labels and dropdown options)
- `phpbb_profile_lang` (field name translations and explanations)

The existing DB has 10 custom fields (location, website, interests, occupation, icq, yahoo, facebook, twitter, skype, youtube).

**Impact**: MEDIUM — Profile fields are user-visible features. Without them, user profiles are incomplete. Social links, location, and custom fields are part of the user-facing product.

**What Needs to Be Added**:
1. **Entities**:
   - `ProfileField` — field definition (field_id, name, type, ident, validation rules, visibility flags, order)
   - `ProfileFieldValue` — user's value for a field (user_id, field_id, value)
   - `ProfileFieldType` enum — String, Text, Url, Bool, Dropdown, Date, Int
2. **Repository**:
   - `ProfileFieldRepositoryInterface` — `getActiveFields()`, `getFieldsForContext(string $context)` (reg, profile, viewtopic, pm, memberlist), `getUserFieldValues(int $userId)`, `setUserFieldValue(int $userId, int $fieldId, string $value)`, `validateValue(ProfileField $field, string $value): bool`
   - `PdoProfileFieldRepository`
3. **Service**:
   - `ProfileFieldService` — `getFieldsForUser(int $userId)`, `updateFieldValues(int $userId, array $values)`, `getVisibleFields(string $context)`, `validateFields(array $values): array (errors)`
4. **DTOs**:
   - `ProfileFieldValueDTO` — `fieldId`, `value`
   - `UpdateProfileFieldsDTO` — `array<ProfileFieldValueDTO>`
5. **Event**: `ProfileFieldsUpdatedEvent`

**Suggested Resolution Direction**: Add a `ProfileField/` subdirectory or integrate into `ProfileService`. The field definitions are admin-managed (read-only for users). The field VALUES are writable by users. Key decision: keep the `pf_*` dynamic column approach or normalize to rows (field_id, user_id, value). Recommendation: normalize to rows for cleanliness (break from legacy schema structure, but keep backward-compatible read from `phpbb_profile_fields_data`).

---

### Gap 5: Token-Based Auth (DB Opaque Tokens) ⚠️

**What's Missing**: The spec's `AuthenticationService::login()` returns a `Session` entity (MD5 session ID stored in `phpbb_sessions`). The REST API architecture decided on **DB opaque tokens** stored in `phpbb_api_tokens` (SHA-256 hashed, `CHAR(64)`, with label + revocation).

The spec's session model is suitable for web (cookie-based) but NOT for API clients. The two auth mechanisms need to coexist or one replaces the other.

**Impact**: HIGH — Fundamental architecture mismatch. The REST API subscriber expects `token → phpbb_api_tokens lookup → user hydration`. The spec's `AuthenticationService` returns `Session` objects from `phpbb_sessions`.

**What Needs to Be Added**:
1. **`ApiToken` entity**: token_id, user_id, tokenHash, label, created, lastUsed, isActive
2. **`ApiTokenRepositoryInterface`**: findByTokenHash(), create(), revoke(), findByUserId(), updateLastUsed()
3. **`ApiTokenService`**: createToken(int $userId, string $label): RawTokenResult, revokeToken(int $tokenId), listTokens(int $userId), validateToken(string $rawToken): ?User
4. **`RawTokenResult` DTO**: rawToken (returned once to client), tokenId, label, created
5. **Alignment decision**: 
   - Option A: `AuthenticationService::login()` ALSO creates an API token and returns it (unify)
   - Option B: Login returns session; separate `POST /api/v1/tokens` creates API tokens (session for web, tokens for API)
   - **Recommended**: Option B — they serve different purposes. Web sessions have GC; API tokens are long-lived + revocable.
6. **Events**: `ApiTokenCreatedEvent`, `ApiTokenRevokedEvent`

**Suggested Resolution Direction**: Add `ApiToken` as a new entity + service alongside sessions. The `token_auth_subscriber` validates tokens via `ApiTokenService::validateToken()`. Web auth still uses sessions. This is additive, not a replacement.

---

### Gap 6: Missing Events for Cross-Service Needs ⚠️

**What's Missing**: The spec's 8 events cover user lifecycle but miss events needed by other services:

| Missing Event | Needed By | Purpose |
|--------------|-----------|---------|
| `UserGroupAddedEvent` | Auth (ACL cache clear), Notifications | User joined group |
| `UserGroupRemovedEvent` | Auth (ACL cache clear), Notifications | User left group |
| `UserDeletedEvent` | ALL services (cleanup) | User account deleted |
| `UserDeactivatedEvent` | Sessions (kill), Notifications | Account deactivated |
| `UserActivatedEvent` | Notifications (welcome) | Account activated |
| `SessionCreatedEvent` | Audit logging | New session started |
| `SessionDestroyedEvent` | Audit logging | Session ended |
| `PreferencesUpdatedEvent` | Notifications (channel prefs) | User changed notification prefs |
| `UsernameChangedEvent` | Threads (denormalized), Search | Username updated |
| `DefaultGroupChangedEvent` | Auth (colour), Display | User's default group changed |

**Impact**: MEDIUM — Without these events, the Auth service can't clear ACL cache when group membership changes, and Notifications can't react to group/preference changes.

**Suggested Resolution Direction**: Add all missing events. Each is a simple `readonly` class with relevant IDs + timestamp. Group events should include `groupId`, `userId`. Username change should include old + new username.

---

### Gap 7: Admin User Management ⚠️

**What's Missing**: The spec handles self-service operations (register, login, update own profile) but has NO admin operations:
- Delete user account (what happens to posts, PMs, groups?)
- Deactivate/reactivate user
- Force password change
- Change user's type (Normal ↔ Inactive ↔ Founder)
- Merge users
- Hard-delete vs soft-delete

**Impact**: MEDIUM — Admin panel needs these operations. Currently `functions_user.php` has `user_delete()` with mode (`retain`/`remove` posts) and extensive cleanup logic.

**Suggested Resolution Direction**: Add `AdminUserService` with: `deleteUser(int $userId, DeleteMode $mode)`, `deactivateUser(int $userId, string $reason)`, `activateUser(int $userId)`, `changeUserType(int $userId, UserType $type)`. Add `DeleteMode` enum: Retain, Remove.

---

## Cross-Service Alignment

### Auth Service (`phpbb\auth`)

| Requirement (from Auth HLD) | Status | Notes |
|------------------------------|--------|-------|
| `User` entity with `id`, `type` (Founder detection) | ✅ | `User::isFounder()` exists |
| `GroupRepositoryInterface::getGroupsForUser(int $userId): Group[]` | ✅ | Defined in spec |
| `User` entity importable (`use phpbb\user\Entity\User`) | ✅ | Namespace correct |
| Auth HLD: `isGranted(User $user, string $permission, ?int $forumId)` | ✅ | Takes `User` entity — compatible |
| Auth HLD uses `User.id` and `User.type` only | ✅ | Available on entity |
| `user_permissions` column excluded from User entity | ✅ | Not in the 34 properties (Auth HLD's ADR-003 satisfied) |
| ACL cache clear on group membership change | ⚠️ | **No `UserGroupAddedEvent`/`UserGroupRemovedEvent`** — Auth can't subscribe to group changes |
| Auth HLD ADR-006: "Import User from phpbb\user" | ✅ | Entity properly namespaced |

**Alignment Score**: 4/5 — Works except for missing group change events.

### Notifications Service (`phpbb\notifications`)

| Requirement | Status | Notes |
|-------------|--------|-------|
| Listen to `UserCreatedEvent` for welcome notification | ✅ | Event exists |
| Listen to user preference changes for notification channel | ❌ | No `PreferencesUpdatedEvent` |
| User preference: `user_notify_type` (0=email, 1=im, 2=both) | ✅ | On `UserPreferences` entity |
| User preference: `user_notify` (topic reply) | ✅ | On `UserPreferences` entity |
| User preference: `user_notify_pm` (PM notification) | ✅ | On `UserPreferences` entity |
| Shadow ban check before sending notification to others | ❌ | No shadow ban API |
| User deletion → cleanup notifications | ❌ | No `UserDeletedEvent` |

**Alignment Score**: 3/5 — Base entities work, but missing events and shadow ban integration.

### Messaging Service (Future)

| Requirement | Status | Notes |
|-------------|--------|-------|
| `user_allow_pm` flag | ✅ | On `UserPreferences` |
| `user_message_rules` flag | ⚠️ | In DB schema but NOT on any entity |
| `user_full_folder` setting | ⚠️ | In DB schema but NOT on any entity |
| `user_new_privmsg` / `user_unread_privmsg` counters | ⚠️ | In DB schema but NOT on entities |
| Group PM settings (`group_receive_pm`, `group_message_limit`, `group_max_recipients`) | ✅ | On `Group` entity |

**Alignment Score**: 3/5 — Missing PM-related columns on entities.

### Threads Service (`phpbb\threads`)

| Requirement | Status | Notes |
|-------------|--------|-------|
| `User.id` for authoring posts | ✅ | Available |
| `User.username` for display | ✅ | Available |
| `User.colour` for display | ✅ | Available |
| `User.posts` counter increment | ⚠️ | Column exists but no `incrementPostCount()` method on service/repo |
| `User.lastPostTime` update | ⚠️ | Column exists but no dedicated method |
| Username change → update denormalized data | ❌ | No `UsernameChangedEvent` |
| Shadow ban → posts visible only to author | ❌ | No shadow ban API |
| User deletion → post anonymization | ❌ | No `UserDeletedEvent` |

**Alignment Score**: 3/5 — Entity is fine for reads, but missing write operations and events needed by Threads.

---

## Summary Table

| Feature | Status | Gap Severity |
|---------|--------|-------------|
| User entity (core fields) | ✅ Covered | — |
| User authentication (login/logout) | ✅ Covered | — |
| Password management (change/reset) | ✅ Covered | — |
| Registration + activation | ✅ Covered | — |
| Profile update (fixed fields) | ✅ Covered | — |
| User preferences | ✅ Covered | — |
| Session management (CRUD, GC) | ✅ Covered | — |
| Group membership (add/remove/list) | ✅ Covered | — |
| Ban system (hard bans) | ✅ Covered | — |
| User search + pagination | ✅ Covered | — |
| Exception hierarchy | ✅ Covered | — |
| Repository interfaces | ✅ Covered | — |
| Guiding principles / architecture | ✅ Covered | — |
| **Shadow banning** | ❌ Missing | HIGH |
| **Decorator extensibility** | ❌ Missing | HIGH |
| **REST API endpoints** | ❌ Missing | HIGH |
| **Custom profile fields** | ❌ Missing | MEDIUM |
| **API token auth** | ❌ Missing | HIGH |
| **Admin user management** | ⚠️ Partial | MEDIUM |
| **Group change events** | ⚠️ Partial | MEDIUM |
| **Cross-service events** | ⚠️ Partial | MEDIUM |
| **PM-related user properties** | ⚠️ Partial | LOW |
| **Post count/time update methods** | ⚠️ Partial | LOW |
| **Username change event** | ❌ Missing | MEDIUM |
| **User deletion flow** | ❌ Missing | MEDIUM |
| **Batch operations** | ⚠️ Partial | LOW |
| **Transaction support** | ⚠️ Partial | LOW |

---

## Key Observations

1. **The spec is an excellent foundation** — entity design, repository pattern, DI, security practices are all solid. The ~50 file structure is clean and follows PHP 8.2 best practices.

2. **The gaps are ADDITIVE, not contradictory** — nothing in the existing spec needs to be removed or rewritten. All gaps can be addressed by adding new files/services/events.

3. **The shadow ban + decorator + REST API gaps are the highest priority** — they represent fundamental requirements that must be designed before implementation begins.

4. **The token-based auth gap is an integration concern** — the existing session system can coexist with API tokens. They serve different authentication contexts (web vs API).

5. **Event coverage needs expansion** — the spec has 8 events; cross-service needs require ~18-20 events total. Each is trivial to add (readonly class with IDs + timestamp).

# Decision Log: `phpbb\user` Service

All decisions in MADR (Markdown Architectural Decision Record) format.
Linked from [high-level-design.md](high-level-design.md).

---

## ADR-001: Scope Boundary — User (Data) vs Auth (Authentication)

### Status
Accepted

### Context
The original research (synthesis §1.3, §5.2) recommended that User service own `AuthenticationService`, `SessionService`, and `ApiTokenService` — placing all user-related operations in one service. However, this creates a 12+ service monolith mixing two fundamentally different concerns: **data management** (CRUD, profile, preferences, groups, bans) and **authentication** (login, sessions, tokens, password verification for login purpose). Auth already exists as a separate service (`phpbb\auth`) focused on authorization (ACL). Merging authentication into User would make User the largest and most complex service in the architecture — every change to session logic could destabilize profile management.

### Decision Drivers
- **Single Responsibility Principle**: Data management and authentication are separate concerns with different change reasons
- **Service size**: User + Auth in one service = 12+ internal services, at the upper limit of cohesion
- **Auth service already exists**: `phpbb\auth` handles ACL, adding AuthN to it keeps all "access control" in one place
- **Password data vs password verification**: User owns the hash (data); Auth uses it for login (flow)
- **Cross-service dependency direction**: Auth already imports User entities — adding auth logic to User creates a bidirectional dependency

### Considered Options
1. **Unified — User owns everything** (sessions, tokens, login, data)
2. **Split — Auth owns AuthN+AuthZ; User owns data** (selected)
3. **Three-way split — User data, Auth AuthZ, separate AuthN service**

### Decision Outcome
Chosen option: **2 — Auth owns all authentication; User is purely data management**, because it keeps both services cohesive and avoids a monolithic User service. Auth already owns ACL (AuthZ); adding AuthN (sessions, tokens, login/logout) is a natural fit. User provides `PasswordService` (hashing, reset tokens) as data operations that Auth calls during login flow.

### Consequences

#### Good
- User service is focused and smaller (~8 services instead of 12+)
- Auth service is the single authority for "who is logged in" (AuthN) and "what can they do" (AuthZ)
- Password data ownership is clean: User owns the hash column, Auth verifies it
- Session/token lifecycle changes don't affect User service
- Clear dependency direction: Auth → User (never reverse)

#### Bad
- Login flow requires cross-service call: Auth calls `PasswordService::verifyPassword()` on User service
- Registration creates the user (User service) but doesn't log them in (Auth must follow up)
- Password change dispatches `PasswordChangedEvent` which Auth must handle (session invalidation)
- Developers must understand the Auth↔User boundary for the login flow

---

## ADR-002: Profile Fields — JSON Column for Extensible Profile Data

### Status
Accepted

### Context
Legacy phpBB uses dynamic `pf_*` columns on `phpbb_profile_fields_data` — one column per custom profile field, requiring ALTER TABLE DDL for each new field. 10 custom fields exist (location, interests, website, facebook, skype, etc.). This is ORM-unfriendly, doesn't scale, and requires DDL for plugin-created fields. The research recommended EAV normalized rows (synthesis §5.3), but evaluated three alternatives: dynamic columns (legacy), EAV rows, JSON column, and hybrid.

### Decision Drivers
- **No DDL for new fields**: Plugins must add profile fields without ALTER TABLE
- **Query simplicity**: Single-row fetch preferred over JOINs
- **PHP 8.2 JSON support**: Native `json_encode`/`json_decode` is mature and fast
- **MySQL 8+ JSON support**: Virtual columns + JSON indexes available for search if needed
- **Migration simplicity**: One-time script to serialize `pf_*` values into JSON object

### Considered Options
1. **Keep dynamic `pf_*` columns** — legacy, requires DDL
2. **EAV normalized rows** — `(field_id, user_id, value)` table; research recommended
3. **JSON column per user** — single `profile_fields JSON` on `phpbb_users` (selected)
4. **Hybrid** — legacy columns for built-in + EAV for plugin-defined

### Decision Outcome
Chosen option: **3 — JSON column**, because it provides zero-DDL extensibility with the simplest possible query model (single row, no JOINs). The JSON column stores `{"location": "Warsaw", "interests": "PHP"}` per user. Plugins add keys without schema changes. The trade-off vs EAV is weaker per-field SQL querying, which is acceptable because profile fields are rarely the subject of WHERE clauses in phpBB's usage patterns.

### Consequences

#### Good
- Single-row fetch — no JOINs, no N+1 risk
- Schema-free — any key can be added without DDL
- Compact storage for sparse data (users with few fields filled)
- Clean JSON API serialization — already the right output format
- Simple migration: read `pf_*` values, serialize, write once

#### Bad
- Cannot efficiently index individual JSON keys for search-by-field queries
- No DB-level type enforcement per field — validation must be in PHP
- Field type information (bool, date, dropdown, etc.) must be managed separately
- `phpbb_profile_fields` definition table becomes a read-only reference for validation rules
- No foreign key constraints on field keys — orphaned keys possible after field deletion

---

## ADR-003: User Preferences — JSON Column Replacing Bitfield

### Status
Accepted

### Context
Legacy user preferences are split across two storage mechanisms: (1) `user_options INT(11)` bitfield storing 18 boolean preferences (default: 230271), and (2) 16 individual columns (`user_lang`, `user_timezone`, `user_topic_sortby_type`, etc.). The bitfield is only read in PHP (never queried per-flag in SQL). The research recommended keeping the bitfield with named getters (synthesis §5, option A), but the JSON approach was also evaluated.

### Decision Drivers
- **Unified storage**: One column for ALL preferences (booleans + strings + ints) instead of bitfield + 16 columns
- **Extensibility**: New preferences added without ALTER TABLE
- **API alignment**: JSON is the natural format for REST API preference payloads
- **Sparse defaults**: Only non-default values stored, reducing storage
- **Clean migration path**: One-time script decomposes bitfield + columns into JSON

### Considered Options
1. **Keep bitfield + named getters in PHP** — research recommended, zero migration
2. **Individual DB columns** — 18 ALTERs, clear schema, SQL-queryable
3. **JSON preferences column** — single column, schema-free (selected)
4. **Bitfield + JSON for new prefs** — hybrid, two storage mechanisms

### Decision Outcome
Chosen option: **3 — JSON preferences column**, because it unifies all 34+ preference values (18 booleans from bitfield + 16 individual columns) into a single extensible column. The `UserPreferences` DTO provides typed access with defaults. The trade-off vs keeping the bitfield is a one-time migration cost, accepted because the JSON approach eliminates the need to maintain bit-position mappings and provides a clean extension path for future preferences.

### Consequences

#### Good
- Single source of truth — all preferences in one column
- Typed DTO enforces property names and types in PHP
- Extensible — new preferences just add a key, no DDL
- REST API pass-through — JSON in DB maps directly to JSON in response
- Sparse storage — NULL column means "all defaults" (most users)

#### Bad
- One-time migration required (bitfield decomposition + column merge)
- No per-preference SQL queries (e.g., "find all users with viewImages=false")
- All defaults must be defined in PHP, not DB DEFAULT clauses
- JSON validation only in PHP — malformed JSON possible without CHECK constraint
- Legacy code reading `user_options` directly will break — must use new API

---

## ADR-004: Shadow Ban — Full User-Level, Separate Table, Response Decorator

### Status
Accepted

### Context
Shadow banning is entirely new — no legacy code, no existing DB table, no precedent in phpBB. The requirement is to silently restrict disruptive users: their content is hidden from others but visible to themselves, and they receive no error or indication. The design must integrate with Threads (content filtering) and Notifications (suppression). Options range from binary user-level to per-forum scoped to gradated levels.

### Decision Drivers
- **Simplicity for v1**: Ship the simplest working implementation quickly
- **Schema forward-compatibility**: Design schema to support per-forum scoping in v2
- **Separation from permanent bans**: Shadow bans have fundamentally different behavior (no block, no error)
- **Decorator integration**: Response decorator in Threads is the natural filtering point
- **Literature precedent**: Most forums started with full shadow bans, added scoping later

### Considered Options
1. **Full user-level shadow ban** — binary on/off, simplest (selected)
2. **Scoped per-forum** — `scope` + `forum_id` filtering per area
3. **Auto-queue for moderation** — posts held for review, not truly invisible
4. **Gradated levels (L1-L3)** — reduced reach → forum scoped → full ghost

### Decision Outcome
Chosen option: **1 — Full user-level shadow ban**, because it provides the core moderation capability with minimal implementation complexity. The `phpbb_shadow_bans` table schema includes `scope ENUM('full','forum')` and `forum_id` columns for v2 per-forum support with zero schema migration. The Threads `ShadowBanResponseDecorator` makes a single `isShadowBanned(userId)` call — no per-forum context needed in v1.

### Consequences

#### Good
- Simplest implementation — single boolean check per author
- Schema supports v2 scoping without migration
- Clear separation from permanent ban system (different table, different behavior)
- Response decorator pattern is established in project (Hierarchy, Threads)
- Admin UI is simple: toggle on/off per user

#### Bad
- No per-forum granularity in v1 — moderators must use traditional bans for area-specific issues
- All-or-nothing — can't shadow-ban a user in one problem forum only
- Sophisticated users may discover ban by checking logged-out view
- PM suppression is binary — user's PMs either all visible or none (v1)

---

## ADR-005: Delete Modes — Three Modes (Retain/Remove/Soft) with Event Cascade

### Status
Accepted

### Context
Legacy `user_delete()` (functions_user.php:404-735) directly DELETEs from 18+ tables with two modes: `retain` (reassign posts to anonymous user_id=1) and `remove` (hard-delete all content). In the new architecture, each service owns its tables — User service cannot directly DELETE from `phpbb_topics` or `phpbb_privmsgs`. The delete must be event-driven, with each service handling its own cleanup. Additionally, GDPR requirements suggest a "soft delete" mode that anonymizes PII without deleting content or the user record.

### Decision Drivers
- **Legacy compatibility**: Admins actively use both `retain` and `remove` modes
- **GDPR compliance**: Need a PII-anonymization path that preserves content attribution
- **Service autonomy**: Each service owns its tables; User can't cascade directly
- **Event-driven architecture**: Consistent with project convention
- **Reversibility**: Soft delete provides an undo window

### Considered Options
1. **Soft delete only** — flag + anonymize, no content removal
2. **Hard delete with events** — immediate, irreversible cascade
3. **Two-phase (soft → hard after retention)** — reversible with delayed purge
4. **Three modes: retain + remove + soft** — full legacy flexibility + GDPR (selected)

### Decision Outcome
Chosen option: **4 — Three modes**, because it preserves all legacy admin workflows (retain, remove) and adds GDPR-compatible soft delete. `UserDeletedEvent(userId, DeleteMode)` carries the mode; each downstream service implements mode-specific handlers. Auth clears cache (all modes). Threads reassigns posts (retain) or deletes them (remove) or leaves them with anonymized author (soft).

### Consequences

#### Good
- Full legacy compatibility — covers all existing admin workflows
- GDPR path via soft mode — anonymize PII, keep content with "DeletedUser_42" attribution
- Admin flexibility — choose the right mode per situation
- Event-driven — consistent with architecture conventions
- Each service decides how to handle each mode independently

#### Bad
- Three code paths per downstream service subscriber — testing matrix 3×
- Admin must understand the difference between three modes — needs clear UI explanation
- `retain` mode requires anonymous user (user_id=1) to exist in seeded data
- More complex than a single delete path
- Potential for subscriber failures leaving partial cleanup (mitigated by synchronous dispatch)

---

## ADR-006: Display DTO — Lightweight UserDisplayDTO for Cross-Service Batch Access

### Status
Accepted

### Context
Notifications and Messaging both need batch user resolution for 10-50 users per request (notification responder lists, PM participant previews). They need only `id`, `username`, `colour`, `avatarUrl`. The full `User` entity has 20+ fields including `passwordHash`, `email`, `ip`. Loading full entities wastes memory, risks leaking sensitive data, and runs wider SELECT queries than necessary.

### Decision Drivers
- **Type safety**: Consumers should know exactly what fields are available at compile time
- **Security**: No sensitive data (password, email, IP) should be in batch display objects
- **Performance**: Optimized `SELECT user_id, username, user_colour, user_avatar` query
- **Two confirmed consumers**: Notifications and Messaging both need this exact pattern
- **Consistency**: Small readonly DTO is a standard pattern in the project

### Considered Options
1. **Full User entity everywhere** — load all fields, ignore unneeded ones
2. **UserDisplayDTO** — dedicated lightweight readonly class (selected)
3. **Interface on User entity** — `UserDisplayInterface` restricts visible methods
4. **Field-selection on repository** — `findByIds($ids, $fields)` returns partial entities

### Decision Outcome
Chosen option: **2 — UserDisplayDTO**, because it provides a type-safe, minimal, security-clean contract for batch display consumers. The `findDisplayByIds(int[])` method runs an optimized SELECT with only 4-5 columns. The DTO is small (4 readonly properties) and stable.

### Consequences

#### Good
- Type-safe — consumers know exactly what's available
- No sensitive data in memory — defense in depth
- Optimized query — only needed columns transferred from DB
- Clear API contract — display consumers can't accidentally depend on non-display fields
- Stable interface — display fields rarely change

#### Bad
- One more class to maintain (`UserDisplayDTO`)
- One more repository method (`findDisplayByIds`)
- If consumers later need `posts` or `registeredAt`, the DTO must grow
- Slight duplication with `User` entity field definitions

---

## ADR-007: Extension Model — Decorators on CRUD + Events for Lifecycle

### Status
Accepted

### Context
The project has an established extensibility convention: Hierarchy and Threads use `RequestDecoratorInterface` + `ResponseDecoratorInterface` + `DecoratorPipeline` for data transformation, and Symfony EventDispatcher for lifecycle side effects. The question is which User service operations should be decoratable and which should use events only. Research (synthesis §5.4) maps specific operations to decorator vs event suitability.

### Decision Drivers
- **Project convention**: Hierarchy and Threads both use the decorator pipeline pattern
- **Plugin needs**: Registration (CAPTCHA, terms), login (2FA — via Auth), profile (badges, reputation), search (custom filters) — all need data transformation
- **Admin operations**: Bans, group management, and deletion need side effects only, not data transformation
- **Bounded surface**: Limiting decorators to ~4 operations keeps complexity manageable
- **Performance**: Empty decorator pipelines on admin operations would add overhead without value

### Considered Options
1. **Decorators on ALL methods** — maximum extensibility, massive surface
2. **Events only, no decorators** — simplest, but blocks data-transforming plugins
3. **Decorators on user-facing CRUD + Events for lifecycle** — balanced (selected)
4. **Tagged registries only** — good for type sets, but misses request/response transformation

### Decision Outcome
Chosen option: **3 — Decorators on CRUD + Events for lifecycle**, because it matches the established project convention while limiting the decorator surface to operations where plugins most commonly need data transformation. Registration, profile view, and search are decorated. Bans, group changes, deletion, and password operations use events only. The rule: "if a plugin needs to transform data → decorator; if side effect → event."

### Consequences

#### Good
- Matches Hierarchy/Threads convention — consistent project pattern
- Limited surface (~3-4 decorated operations) — manageable complexity
- Events cover all side-effect needs (cache clear, counter updates, cascade)
- Clear rule for developers: transform = decorator, side effect = event
- DecoratorPipeline infrastructure is already established (copy from Hierarchy)

#### Bad
- Must decide which methods get decorators — judgment call, documented in spec
- Two mechanisms to explain to plugin developers (decorators + events)
- If a future plugin needs to decorate `ban()`, it can't (events only) — would need to add decorator
- `changePassword()` is borderline — password policy plugins might want request decoration (defer to Auth since Auth owns login flow)

---

## ADR-008: Group Cascade — Default Group Property Propagation Rules

### Status
Accepted

### Context
When a group becomes (or stops being) a user's default group, certain group properties cascade to the user record: colour, rank, and avatar. Legacy code (functions_user.php:3437-3560) implements specific rules for when each property cascades. These rules also apply when a user is removed from their default group and a new default must be assigned. The reassignment priority is hard-coded: ADMINISTRATORS → GLOBAL_MODERATORS → NEWLY_REGISTERED → REGISTERED_COPPA → REGISTERED → BOTS → GUESTS.

### Decision Drivers
- **Legacy behavior preservation**: 30+ existing installations depend on these cascade rules
- **Data consistency**: `user_colour` must match `group_colour` of default group
- **Cross-table impact**: Colour cascades to denormalized fields in forums/topics tables
- **Performance**: Colour cascade touches potentially many rows — consider async for large groups
- **Founder immunity**: Founders are never removed from ADMINISTRATORS by the cascade system

### Considered Options
1. **Full cascade (always propagate all properties)** — simpler but overwrites user customizations
2. **Conditional cascade (current legacy behavior)** — colour always, rank if 0, avatar if empty (selected)
3. **No cascade** — users manage their own appearance independently of groups
4. **Config-driven cascade** — admin chooses which properties cascade

### Decision Outcome
Chosen option: **2 — Conditional cascade matching legacy behavior**, because it preserves the existing administrator expectations and avoids surprising data changes. The rules are:

| Property | Cascade Condition | Rationale |
|----------|------------------|-----------|
| `user_colour` | **Always** from default group | Colour is the primary visual group identifier |
| `user_rank` | Only if user's current rank = 0 | Non-zero rank = special rank assigned by admin, don't override |
| `user_avatar` | Only if user has no avatar | User-uploaded avatar takes priority over group default |

**Reassignment priority** (when removed from default group):
`ADMINISTRATORS(5) → GLOBAL_MODERATORS(4) → NEWLY_REGISTERED(7) → REGISTERED_COPPA(3) → REGISTERED(2) → BOTS(6) → GUESTS(1)`

### Consequences

#### Good
- Preserves expected behavior for all existing phpBB administrators
- Colour consistency — username colour always reflects group membership
- Respects user customizations — uploaded avatars and special ranks are preserved
- Hard-coded priority is deterministic — no configuration ambiguity
- Well-tested — legacy behavior has been stable for 15+ years

#### Bad
- Colour cascade touches `forum_last_poster_colour`, `topic_first_poster_colour`, `topic_last_poster_colour` — expensive for large groups
- Hard-coded group priority cannot be customized by administrators
- Adding a new special group type requires code change in priority list
- Complex logic in `setDefaultGroup()` — must be thoroughly integration-tested
- Colour propagation to forum/topic tables may need to be async for groups with 1000+ members

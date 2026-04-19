# Solution Exploration: Users Service (`phpbb\user`)

**Date**: 2026-04-19  
**Research Confidence**: HIGH (87%)  
**Decision Areas**: 7  
**Alternatives Generated**: 26  

---

## Problem Reframing

### Research Question

How should the `phpbb\user` service be designed to provide user management, preferences, group membership, banning (shadow+permanent), session management, and authentication — as a standalone PSR-4 service with zero legacy dependencies, extensible via events + decorators, exposed through REST API?

### How Might We Questions

1. **HMW store custom profile fields** so that plugins can add new fields without DDL changes while preserving the existing 10 legacy fields?
2. **HMW handle dual auth paths** (web sessions + API tokens) so that both consumers share user identity without duplicating login logic?
3. **HMW implement shadow banning** so that trolls are silenced without awareness, while keeping the system simple enough for v1?
4. **HMW expose user preferences** so that the API returns clean named booleans while the DB retains the `user_options` bitfield for migration safety?
5. **HMW handle user deletion** so that content attribution is preserved (retain mode) or fully purged (remove mode) across 6+ independent services?
6. **HMW provide lightweight user data** to batch consumers (Notifications, Messaging) without loading password hashes and full profiles?
7. **HMW expose extension points** so that plugins can modify registration, login, and profile lookups without requiring changes to core service code?

---

## Decision Area 1: Profile Field Data Model

### Context

Legacy phpBB uses dynamic `pf_*` columns on `phpbb_profile_fields_data` — one column per custom field, requiring ALTER TABLE for each new field. The `phpbb_profile_fields` definition table has 24 columns describing 7 field types. 10 custom fields exist in the current schema. This is an ORM-unfriendly anti-pattern that doesn't scale.

---

### Alternative A: Keep Dynamic Columns (Legacy Pattern)

Preserve the `pf_*` column-per-field approach. Each custom profile field maps to a dedicated column on the `phpbb_profile_fields_data` table. New fields require ALTER TABLE DDL.

**Pros**:
- Zero migration effort — existing data untouched
- Single-row fetch — one SELECT gets all field values for a user
- Direct column indexing possible for searchable fields
- Proven to work for phpBB's typical field count (5-20 fields)

**Cons**:
- Requires DDL (ALTER TABLE) for every new field — plugins can't add fields at runtime
- Unbounded column growth — table becomes unwieldy with many plugins
- ORM-unfriendly — cannot map to typed entity properties without code generation
- Column names (`pf_phpbb_location`, `pf_phpbb_interests`) are auto-generated, not semantic
- Breaks the "no legacy anti-patterns" project principle

---

### Alternative B: EAV Normalized Tables

New `phpbb_profile_field_values` table with `(field_id, user_id, value)` rows. One row per user per field. Field definitions remain in `phpbb_profile_fields` (read-only for User service).

**Pros**:
- No DDL for new fields — plugins register field definitions, values automatically work
- Clean relational model — standard ORM mapping, `ProfileFieldValue` entity
- Easy to query per-field (`WHERE field_id = ?`) or per-user (`WHERE user_id = ?`)
- Migration script converts existing `pf_*` data to rows (one-time, reversible)
- Aligns with PSR-4 entity model — typed `ProfileFieldValue` with `fromRow()`

**Cons**:
- Requires JOIN or second query to fetch all fields for a user (N+1 risk on batch)
- All values stored as string (VARCHAR) — type coercion needed for int/date/bool fields
- No direct column indexes for "find users where location = X" searches
- Migration introduces risk — must validate row count = user count × field count
- Slightly more storage overhead per row (field_id + user_id repeated)

---

### Alternative C: JSON Column Per-User

Single `profile_fields_json JSON` column on `phpbb_users` (or a new `phpbb_user_profile_data`). Stores all custom field values as a JSON object: `{"location": "Warsaw", "interests": "PHP"}`.

**Pros**:
- Single-row fetch — no JOINs, no N+1
- Schema-free — plugins add keys without DDL
- Native JSON support in MySQL 8+ / PostgreSQL — can index with virtual columns
- Compact storage for sparse fields (users with few fields filled)
- Easy to serialize/deserialize in PHP 8.2+

**Cons**:
- JSON querying is slower than indexed columns for user search by field value
- No foreign key constraints — orphaned field keys possible
- Field type enforcement must be entirely in PHP (no DB-level VARCHAR/INT check)
- Migration from `pf_*` columns is straightforward but introduces a new storage paradigm
- JSON columns not well-supported by all MySQL 5.x deployments (phpBB still targets some)

---

### Alternative D: Hybrid — Legacy Columns for Built-in + EAV for Plugin-Defined

Keep the existing 10 `pf_*` columns for backward compatibility with built-in fields. New plugin-defined fields use the EAV `phpbb_profile_field_values` table. `ProfileService` merges both sources transparently.

**Pros**:
- Zero migration for existing data — built-in fields unchanged
- Plugins get clean EAV model — no DDL needed for extensions
- Gradual migration path — built-in fields can be moved to EAV later
- Search on built-in fields retains direct column indexing
- Least disruptive to existing deployments

**Cons**:
- Two code paths for reading/writing profile fields — increased complexity
- `ProfileService` must merge two data sources, adding cognitive load
- Built-in fields remain ORM-unfriendly (dynamic column names)
- Future maintenance burden — two patterns to support indefinitely
- Testing surface doubles (must test both paths × all operations)

---

### Recommendation: Alternative B (EAV Normalized)

**Rationale**: The normalized EAV model eliminates the legacy DDL anti-pattern, aligns with the PSR-4 entity model, and keeps plugin extensibility clean. The N+1 risk is mitigated by a single `WHERE user_id IN (...)` batch query — acceptable for profile pages. The research confirms (synthesis §5.3) that legacy dynamic columns are a phpBB-specific anti-pattern that doesn't scale.

**Key trade-off accepted**: Slight query overhead vs. clean extensibility model.

**Assumption**: MySQL 5.7+ is the minimum target (confirmed by project constraints: PHP 8.2+ implies modern MySQL).

**Confidence**: MEDIUM-HIGH (80%) — profile field migration is the lowest-confidence area in the research (70%), but the EAV pattern is well-established in CMS/forum systems.

---

## Decision Area 2: Token / Session Strategy

### Context

Legacy phpBB uses cookie-based sessions (`phpbb_sessions`, CHAR(32) MD5 session IDs). The REST API requires Bearer token auth. The research unanimously confirms dual auth is mandatory — web sessions for Phase 1 backward compat, API tokens for modern clients. The question is ownership and architectural split.

---

### Alternative A: Unified — User Service Owns Both Sessions AND API Tokens

`AuthenticationService` handles login → creates BOTH a session (web) and an API token (programmatic). `SessionService` manages session lifecycle. `ApiTokenService` manages token lifecycle. Both live in `phpbb\user\Service\`.

**Pros**:
- Single authority for "who is logged in" — no ambiguity
- Login logic shared — password verification in one place
- Token creation on login is atomic — no separate API call needed
- Cross-cutting concerns (ban check, founder immunity) applied once
- Aligns with research recommendation (synthesis §1.3: "User service owns `ApiTokenService`")

**Cons**:
- User service becomes large — 12+ services is at the upper limit of cohesion
- API-only clients must go through the same `AuthenticationService::login()` path
- Session GC and token GC are different lifecycle concerns mixed in one service
- If token strategy changes (e.g., JWT), User service must be modified

---

### Alternative B: Split — User Owns Sessions; Separate TokenService at API Layer

`phpbb\user` owns `SessionService` only. Token management lives in `phpbb\api\TokenService`. The API layer creates/validates tokens independently, calling `UserRepository::findById()` to hydrate the user after validation.

**Pros**:
- Clear separation — User service stays focused on identity + sessions
- API layer owns its own auth mechanism — can evolve independently (JWT, OAuth2)
- User service is smaller and more cohesive
- Token strategy can change without touching User service

**Cons**:
- **Contradicts research recommendation** — synthesis §1.3 explicitly says "User service owns `ApiTokenService`"
- Token creation requires calling User service for password verification → cross-service call for login
- Ban check must be duplicated or shared via interface
- Two places to look for "auth" logic — confusing for new developers
- `phpbb_api_tokens` table ownership ambiguous — API layer doesn't own tables by convention

---

### Alternative C: Token-Only — Replace Sessions with Tokens

Eliminate legacy session management entirely. All auth uses Bearer tokens. Web clients get a token on login and store it in `httpOnly` cookie or `localStorage`.

**Pros**:
- Single auth path — simplest architecture
- No session GC, no session table maintenance
- Stateless server — horizontal scaling trivial
- Modern SPA pattern — aligns with React/Vue frontends

**Cons**:
- **Breaks Phase 1 backward compat** — existing web views depend on cookie sessions
- No CSRF protection built-in (sessions + SameSite cookies provide this)
- "Who is online" feature loses session-based tracking
- Token revocation requires DB lookup on every request (or Redis cache)
- Contradicts research: "Dual auth is non-negotiable" (synthesis §6)

---

### Alternative D: Dual Coexistence — SessionService (Web) + ApiTokenService (API)

Two distinct services within `phpbb\user`, each with its own lifecycle. `AuthenticationService` is the entry point for login — it creates a session (web requests) or returns a token (API requests) based on context. Shared: password verification, ban check, user lookup.

**Pros**:
- Clean internal separation — `SessionService` and `ApiTokenService` have no shared state
- Login path determines which auth artifact to create (session vs token)
- Each service has its own GC strategy (probabilistic vs cron)
- Matches exactly what REST API HLD + synthesis recommend
- "Who is online" preserved via sessions; API clients use tokens

**Cons**:
- Two parallel auth paths increase testing surface
- `AuthenticationService` must branch on request context → slight complexity
- Must coordinate: token-revoke should also kill active sessions (optional)
- New developers must understand which path applies when

---

### Recommendation: Alternative D (Dual Coexistence)

**Rationale**: Every research source converges on this approach. Sessions serve web backward compat; tokens serve the REST API. The `AuthenticationService` acts as a facade — login verifies credentials once, then delegates to `SessionService` or `ApiTokenService` based on request context. This is confirmed by synthesis §5.2 and research report §6.2.

**Key trade-off accepted**: Two parallel auth paths vs. backward compatibility + modern API support.

**Assumption**: Web-to-SPA migration will eventually retire `SessionService`, but that's a Phase 3+ concern.

**Confidence**: HIGH (90%) — all sources agree.

---

## Decision Area 3: Shadow Ban Scope

### Context

Shadow banning is entirely new — no legacy code, no existing DB table. The research recommends a separate `phpbb_shadow_bans` table (synthesis §5.1). The question is how granular the v1 implementation should be. Reddit has per-subreddit shadow bans; most forums use global.

---

### Alternative A: Full Shadow Ban Only (User-Level)

A shadow ban applies to the entire user — all their content is hidden from others across all forums, PMs, and search. Binary: shadow-banned or not.

**Pros**:
- Simplest implementation — single `isShadowBanned(userId): bool` check
- Easiest to reason about — no per-forum conditionals in decorators
- `ShadowBanResponseDecorator` on Threads is straightforward: filter by user_id
- Admin UI is simple: toggle on/off
- V1 scope is minimal — can ship quickly

**Cons**:
- No granularity — can't shadow-ban a user in one problem forum while leaving them visible elsewhere
- Overly aggressive for users who misbehave only in specific areas
- PM suppression may be unexpected (user can't shadow-PM individual conversations)
- Cannot escalate gradually — it's all or nothing

---

### Alternative B: Scoped Shadow Ban (Configurable Per-Area)

Shadow ban can be scoped: `full` (all content), `forum` (per-forum), `pm` (messaging only), `search` (invisible in search results). Stored with `scope` + `scope_id` columns.

**Pros**:
- Precise moderation — ban a troll only where they misbehave
- Allows graduated response before full shadow ban
- Per-forum scoping matches phpBB's per-forum permission model
- Schema already supports it (research report §6.1: `scope ENUM + forum_id`)

**Cons**:
- Significantly more complex — decorator must check scope + context for every content piece
- Threads decorator needs forum context to filter, adding a parameter dependency
- PM scoping is conceptually unclear — shadow-ban in PM means what exactly?
- Admin UI becomes complex — multi-scope management interface
- More edge cases: what if user is scoped-banned in 5 forums? Aggregation logic.

---

### Alternative C: Partial Shadow Ban (Auto-Queue for Moderation)

Shadow-banned user's content is created but automatically placed in moderation queue. User sees "posted" status. Moderators review and approve/reject. Not truly invisible — just held.

**Pros**:
- Less deceptive — content still exists, just delayed
- Moderators maintain control — can approve legitimate posts from shadow-banned user
- No response decorator needed — uses existing moderation queue infrastructure
- Gentler approach — user may eventually notice delay but isn't fully silenced

**Cons**:
- Increases moderation workload — every shadow-banned post needs review
- User may notice delay and realize something is wrong (defeats "shadow" purpose)
- Requires moderation queue infrastructure (not yet built in new architecture)
- Not a true shadow ban — misleading terminology
- Doesn't suppress notifications/search presence

---

### Alternative D: Gradated Levels (L1→L3)

Three levels of shadow banning with increasing severity:
- **L1 — Reduced Reach**: Posts appear but are deprioritized (sorted lower, not in "latest" feeds)
- **L2 — Forum-Scoped Ghost**: Posts invisible to others in specific forums
- **L3 — Full Ghost**: All content invisible, user unaware

**Pros**:
- Maximum moderation flexibility — escalation path from mild to severe
- L1 is non-destructive — useful for borderline cases
- L2 bridges the gap between full and no shadow ban
- Allows experimentation — moderators learn which level is effective

**Cons**:
- Highest implementation complexity — three different filtering mechanisms
- L1 "deprioritization" requires integration with content sorting (Threads service owns sort order)
- Difficult to explain to moderators — three definitions of "shadow ban"
- Over-engineering for v1 — no evidence this granularity is needed
- Testing matrix explodes — 3 levels × multiple content types × viewer contexts

---

### Recommendation: Alternative A (Full Shadow Ban) for v1

**Rationale**: The research confirms that no legacy precedent exists for shadow banning — this is entirely new functionality. Starting with the simplest model (full user-level ban) allows shipping quickly while validating the pattern. The schema already includes `scope` + `forum_id` columns (research report §6.1) — upgrading to Alternative B later requires zero schema changes, only service logic. Literature confirms most forum platforms started with full shadow bans and added scoping later.

**Key trade-off accepted**: No per-forum granularity in v1 — moderators must use traditional bans for forum-specific issues.

**Assumption**: Moderators primarily need shadow banning for persistent trolls, not nuanced per-area control.

**Confidence**: HIGH (85%) — clear literature precedent, schema designed for forward compatibility.

---

## Decision Area 4: User Options Decomposition

### Context

Legacy `user_options` is an `INT(11) UNSIGNED` bitfield storing 18 boolean preferences (default value: 230271). Bits include `viewimg`, `viewflash`, `viewsmilies`, `viewsigs`, `viewavatars`, `viewcensors`, `attachsig`, `bbcode`, `smilies`, etc. (documented in `user.php:52`). The bitfield is only read/written in PHP — never queried per-flag in SQL. Threads' `ContentContext` needs viewer preferences (`viewSmilies`, `viewImages`, `viewCensored`).

---

### Alternative A: Keep Bitfield in DB, Decompose via Named Getters (Abstraction Layer)

DB column remains `user_options INT(11)`. `UserPreferences` entity decomposes in `fromRow()` using bit shift operations. Public API exposes named boolean getters: `->viewImages`, `->viewSmilies`, etc.

**Pros**:
- Zero migration — DB column unchanged, no ALTER TABLE
- Backward compatibility — legacy code can coexist during migration period
- PHP 8.2 readonly properties expose clean names: `public readonly bool $viewImages`
- Bit manipulation is well-documented in legacy code (`$keyoptions` array)
- DB storage efficient — 18 bools in 4 bytes

**Cons**:
- Bit manipulation obscures intent — `$options & (1 << 2)` is opaque
- Cannot query per-preference in SQL (e.g., "find all users with viewImages=false")
- Adding new preferences requires choosing bit positions — fragile (bit 18+ collision risk)
- Code must maintain the `$keyoptions` mapping — a legacy artifact in new code
- Serializing to JSON API requires explicit mapping of each bit

---

### Alternative B: Decompose to Individual DB Columns

ALTER TABLE to add 18 individual TINYINT(1) columns: `view_images`, `view_smilies`, `view_signatures`, etc. Drop `user_options` column after migration.

**Pros**:
- SQL-queryable per-preference — `WHERE view_images = 0`
- Schema is self-documenting — column names describe the preference
- No bit manipulation needed — direct column mapping
- Adding new preferences is trivial — `ALTER TABLE ADD COLUMN`
- Clean ORM mapping — each column → entity property

**Cons**:
- Migration required — 18 ALTER TABLE operations + data conversion
- Increased row width — 18 bytes vs 4 bytes (minor at phpBB scale)
- Column explosion — `phpbb_users` already has 68 columns, adding 18 more
- Backward-incompatible — legacy code reading `user_options` breaks
- phpBB `users` table is already the widest — further widening is undesirable

---

### Alternative C: Separate JSON Preferences Column

Add a `preferences_json JSON` column to `phpbb_users` (or a separate `phpbb_user_preferences` table). Store preferences as `{"viewImages": true, "viewSmilies": false, ...}`. Entity parses on load.

**Pros**:
- Schema-free for new preferences — no ALTER TABLE for new keys
- Compact storage — only non-default values stored (sparse)
- Clean JSON API serialization — already the right format
- Single column — no table widening
- MySQL 8+ JSON functions available for queries if needed

**Cons**:
- Cannot efficiently index/query per-preference in SQL
- JSON parsing on every user load — slight overhead
- No DB-level default value per preference — must handle in PHP
- Migration from bitfield needed (one-time script)
- JSON validation only in PHP — DB allows malformed JSON (without CHECK constraint)

---

### Alternative D: Keep Bitfield + JSON Column for New Preferences

Retain `user_options` bitfield for the existing 18 preferences. Add a new `user_preferences_ext JSON` column for any future preferences. `UserPreferences` entity merges both sources.

**Pros**:
- Zero migration for existing preferences — bitfield untouched
- New preferences use clean JSON — no bit position management
- Gradual transition — old prefs in bitfield, new in JSON
- Backward compatible with legacy code reading `user_options`

**Cons**:
- Two storage mechanisms — increased complexity
- Entity must merge bitfield + JSON — two parse paths
- New developers must understand which storage holds which preference
- Cannot easily move a preference from bitfield to JSON (or vice versa)
- Perpetuates the bitfield pattern for existing preferences

---

### Recommendation: Alternative A (Bitfield in DB, Named Getters in Entity)

**Rationale**: The research confirms the bitfield is only read in PHP, never queried per-flag in SQL (synthesis §5, research report §6). The `UserPreferences` entity's `fromRow()` performs the decomposition once, exposing clean `readonly bool` properties. This is the lowest-risk option with zero migration. The bitfield default value (230271) is well-documented and all 18 bit positions are known.

**Key trade-off accepted**: Cannot query per-preference in SQL — acceptable since no current or planned feature needs this.

**Assumption**: If per-preference SQL queries become needed (e.g., "email all users with viewImages=false"), we can add individual columns later without breaking the entity API.

**Confidence**: HIGH (85%) — all sources confirm bitfield is PHP-consumed only.

---

## Decision Area 5: Delete Cascade Strategy

### Context

Legacy `user_delete()` (functions_user.php:404-735) directly DELETEs from 18+ tables with two modes: `retain` (reassign posts to anonymous user_id=1) and `remove` (hard-delete all content). In the new architecture, each service owns its tables — User service can't directly DELETE from `phpbb_topics` or `phpbb_privmsgs`.

---

### Alternative A: Soft Delete Only (Flag + Anonymize)

Set `user_type = Inactive` (or a new `Deleted` type) and anonymize PII fields (email, IP, username → "DeletedUser_42"). Content remains attributed to the anonymized user. No cross-service cascade needed.

**Pros**:
- Simplest — no cross-service coordination
- Content attribution preserved — posts remain with anonymized author
- Reversible (within anonymization limits) — admin can reactivate
- GDPR-compatible — PII removed, content retained
- No orphan data risk — nothing is deleted from any table

**Cons**:
- No "remove all content" option — can't satisfy admins who want full purge
- Partial legacy compatibility — legacy had explicit `retain` AND `remove` modes
- Ghost data — anonymous users accumulate in the users table forever
- Group memberships, bans, sessions remain (unless explicitly cleaned)
- Admin must still manually remove problematic content post-anonymization

---

### Alternative B: Hard Delete with Event-Driven Cascade

User service deletes user row and dispatches `UserDeletedEvent(userId, deleteMode: 'remove')`. Each downstream service subscribes and handles its own table cleanup. No soft-delete intermediate state.

**Pros**:
- Complete cleanup — no orphan data across services
- Decoupled — each service owns its own cleanup logic
- Matches the architecture's event-driven convention
- Admin gets immediate result — user is gone
- No ghost data in users table

**Cons**:
- **Irreversible** — no undo once event is processed
- Cascade failure risk — if one subscriber fails, partial cleanup (e.g., posts deleted but PMs remain)
- Event ordering matters — Auth must clear cache before Threads tries to decrement counters
- No retention period — data gone instantly (may conflict with legal holds)
- Complexity in each subscriber — Threads must handle topic/post reassignment or deletion

---

### Alternative C: Two-Phase — Soft Delete → Hard Purge After Retention

Phase 1: Soft delete (anonymize PII, deactivate, dispatch `UserSoftDeletedEvent`). Phase 2: After configurable retention period (30-90 days), cron job dispatches `UserPurgeEvent` for hard deletion across all services.

**Pros**:
- Reversible during retention period — admin can undo within 30 days
- Legal hold compatible — retention period satisfies data preservation requirements
- Two events give services time to prepare (e.g., export data before purge)
- Gradual cleanup — no thundering herd of cross-service deletes
- Best of both worlds — soft delete safety + eventual hard cleanup

**Cons**:
- Most complex — two events, two handlers per service, cron infrastructure
- Retention period configuration adds admin complexity
- Ghost users exist for retention period — must be filtered from all queries
- Cron dependency — if cron fails, purge never happens
- Over-engineering for forums where admins typically want immediate action

---

### Alternative D: Three Modes — Retain + Remove + Soft (Legacy Flexibility)

Preserve all three legacy semantics:
- **Retain**: Reassign content to anonymous (user_id=1), delete user row
- **Remove**: Hard-delete all content, delete user row
- **Soft**: Anonymize + deactivate, keep user row

Admin chooses mode at delete time. `UserDeletedEvent` carries the `deleteMode` enum.

**Pros**:
- Full legacy compatibility — covers all existing admin workflows
- Admin has maximum flexibility per situation
- `retain` mode preserves forum history
- `remove` mode satisfies "right to be forgotten" requests
- `soft` mode allows reversible deactivation

**Cons**:
- Three code paths per downstream service — each subscriber handles retain, remove, and soft
- Testing matrix is 3× larger (3 modes × N services)
- Admin must understand the difference — UI needs clear explanation
- `retain` mode requires anonymous user (user_id=1) to exist — constraint on seeding
- Most complex option — but matches proven legacy behavior

---

### Recommendation: Alternative D (Three Modes — Legacy Flexibility)

**Rationale**: Legacy phpBB explicitly supports `retain` and `remove` modes (functions_user.php:404-735), and administrators actively use both depending on context. Adding `soft` mode (anonymize + deactivate) provides the GDPR-compatible path without breaking existing admin expectations. The `DeleteMode` enum (`Retain`, `Remove`, `Soft`) on `UserDeletedEvent` lets each service decide how to handle each mode.

**Key trade-off accepted**: Three code paths per service subscriber — mitigated by clear documentation and mode-specific handler methods.

**Assumption**: Downstream services will implement all three handlers. If a service doesn't need mode-specific behavior (e.g., Auth just clears cache regardless), it ignores the mode.

**Confidence**: MEDIUM-HIGH (80%) — legacy behavior is well-documented; the soft-delete addition extends proven patterns.

---

## Decision Area 6: Display DTO Design

### Context

Notifications and Messaging both need batch user resolution for 10-50 users at a time. They need: `id`, `username`, `colour`, `avatarUrl`. The full `User` entity has 15+ fields including `passwordHash`. Loading full entities wastes memory and risks leaking sensitive data.

---

### Alternative A: Full User Entity Everywhere

`findByIds(int[]): User[]` returns complete `User` entities. Consumers access only the fields they need. `passwordHash` is set to empty string in batch queries.

**Pros**:
- Simplest — no extra class, no extra repository method
- Consumers already understand `User` — no new type to learn
- Flexible — consumers can access any field without requesting a different type
- Single consistent return type across all queries

**Cons**:
- Memory waste — loading 15+ fields when only 4 needed
- Security risk — `passwordHash` in memory even if empty (defense-in-depth violation)
- Performance — larger SELECT, more data transferred from DB
- Consumers may accidentally access fields not populated in batch mode (empty `lastmark`, etc.)
- No compile-time guarantee of which fields are available

---

### Alternative B: Lightweight UserDisplayDTO

Dedicated `UserDisplayDTO` readonly class with only display fields: `id`, `username`, `colour`, `avatarUrl`. New repository method: `findDisplayByIds(int[]): UserDisplayDTO[]`.

**Pros**:
- Minimal memory — 4 fields only
- Type-safe — consumers know exactly what's available
- Security — no sensitive data (password, email) in DTO
- Optimized SELECT — only 4-5 columns fetched from DB
- Clear API contract — display consumers can't accidentally depend on non-display fields

**Cons**:
- Extra class to maintain — `UserDisplayDTO` must stay in sync with `User`
- Extra repository method — `findDisplayByIds()` is nearly-duplicate query
- If consumers later need `posts` or `registeredAt`, the DTO must grow
- Proliferation risk — if each consumer's needs differ, we'd need multiple DTOs

---

### Alternative C: UserDisplayInterface on User Entity

`User` entity implements `UserDisplayInterface` (defines `getId()`, `getUsername()`, `getColour()`, `getAvatarUrl()`). Consumers type-hint against the interface. Repository returns full `User` entities but consumers see only interface methods.

**Pros**:
- No extra class — interface on existing entity
- Consumers are restricted at type level — can only call interface methods
- Full data available if consumer casts (flexibility when needed)
- Single repository method — `findByIds()` returns `User[]`
- Interfaces are the project convention for cross-service contracts

**Cons**:
- Full `User` still loaded in memory — no performance gain
- Consumers CAN cast to `User` and bypass the interface — no hard boundary
- Interface on a concrete entity mixes concerns (entity + contract)
- If User entity gets large, the unnecessary data still travels over the wire
- False safety — the security benefit is superficial

---

### Alternative D: Field-Selection on Repository Queries

`UserRepository::findByIds(int[] $ids, array $fields = ['*']): User[]` accepts a `$fields` parameter specifying which columns to SELECT. Returns partial `User` entities with only requested fields populated (others null).

**Pros**:
- No extra class or interface — reuse `User` entity
- Flexible — each consumer specifies exactly what it needs
- Optimized SELECT — only requested columns transferred
- Single method handles all use cases

**Cons**:
- Nullable properties on User entity — `$email` could be null even though it's never null in DB
- No type safety — caller can access a field that wasn't loaded → silent null
- Repository method signature is complex — magic `$fields` array
- Breaks the immutable entity contract — partially-constructed entities
- Testing is harder — must test every field combination

---

### Recommendation: Alternative B (Lightweight UserDisplayDTO)

**Rationale**: The research identifies batch display data as a recurring need across Notifications, Messaging, and Threads (synthesis §2.3). A dedicated `UserDisplayDTO` with exactly the fields consumers need provides type safety, minimal memory, and no security leakage. The repository method `findDisplayByIds(int[])` runs an optimized `SELECT id, username, user_colour, ... FROM phpbb_users WHERE user_id IN (...)` — no JOINs, no extra data. The extra class is small (4-5 readonly properties) and stable.

**Key trade-off accepted**: One more class + one more repository method vs. clean contract and optimized queries.

**Assumption**: Display fields are stable (id, username, colour, avatarUrl) — if consumers need more, the DTO grows but remains small.

**Confidence**: HIGH (85%) — two cross-service consumers need this, pattern is standard.

---

## Decision Area 7: Extension Model

### Context

The project has two established extensibility patterns: (1) Request/Response Decorators + DecoratorPipeline (Hierarchy, Threads), and (2) Tagged DI registries / service_collection (Notifications). The question is which pattern to apply to User service operations and at what granularity.

---

### Alternative A: Decorators on ALL Service Methods

Every public method in every service class goes through `DecoratorPipeline`. Request decorators pre-process input; response decorators post-process output. Full coverage — no service method escapes decoration.

**Pros**:
- Maximum extensibility — plugins can hook into any operation
- Consistent pattern — every method works the same way
- No need to decide "which methods are decoratable"
- Future-proof — any currently-unknown extension point is covered

**Cons**:
- Massive overhead — 12+ services × many methods = huge decorator surface
- Performance impact — pipeline invocation on every call (even if no decorators registered)
- Over-engineering — most admin operations (ban, group change) don't need decoration
- Cognitive load — developers must understand every decorator touchpoint
- Testing explosion — must verify decorator pipeline for every method

---

### Alternative B: Events Only, No Decorators

All extensibility via `EventDispatcher::dispatch()`. Events before and after each operation. Plugins subscribe to events for side effects and cannot modify inputs/outputs.

**Pros**:
- Simplest — standard Symfony EventDispatcher, no custom infrastructure
- Loose coupling — plugins only subscribe, never modify core flow
- No performance overhead from empty decorator pipelines
- Well-understood pattern — Symfony events are widely documented
- Sufficient for side effects (logging, caching, notifications)

**Cons**:
- Cannot modify request/response — no CAPTCHA on registration, no 2FA on login
- Cannot enrich profile data — no badges, reputation, custom fields injection
- Breaks the established project convention (Hierarchy/Threads use decorators)
- Plugins that need data transformation are impossible — only side effects
- Research explicitly identifies decorator needs: CAPTCHA, 2FA, profile enrichment (synthesis §5.4)

---

### Alternative C: Decorators on User-Facing CRUD + Events for Lifecycle

Decorators (Request + Response, through `DecoratorPipeline`) on user-facing read/write operations:
- `RegistrationService::register()` — CAPTCHA, terms, referral
- `AuthenticationService::login()` — 2FA, OAuth step
- `ProfileService::getProfile()` — badges, reputation, custom fields
- `UserSearchService::search()` — custom filters, extra data

Events only for admin/mod operations: bans, group changes, password changes, session lifecycle.

**Pros**:
- Matches synthesis conclusion (§5.4) — decorators where plugins commonly extend
- Limited surface — only ~4-6 methods have decorator pipelines
- Events cover all side-effect needs (ACL cache clear, counter updates)
- Follows the Hierarchy/Threads convention for the decorated subset
- Clear rule: "if a plugin needs to transform data → decorator; if side effect → event"

**Cons**:
- Must decide which methods deserve decorators — judgment call
- If a future plugin needs to decorate `ban()`, it can't (events only)
- Two mechanisms to explain — "some methods have decorators, others have events"
- `changePassword()` is borderline — password policy plugin needs request decorator?

---

### Alternative D: Tagged Registries for Type Sets + Events

Use Symfony tagged DI `service_collection` for polymorphic extension sets (profile field types, validation rules, auth providers). Events for lifecycle. No request/response decorators.

**Pros**:
- Clean for type registries — profile field types (bool, dropdown, text, url, date, int, custom)
- Auth provider pattern from legacy (`db`, `ldap`, `apache`) maps to tagged DI
- Validation rules as tagged services — plugins add custom validators
- Follows Notifications pattern for type sets

**Cons**:
- Cannot modify registration/login flow — no CAPTCHA/2FA decorator
- Cannot enrich profile response — no badge/reputation injection
- Tagged registries solve a different problem than request/response transformation
- Misses the most common plugin extension points (registration, login, profile view)
- Not sufficient on its own — would need to be combined with another pattern

---

### Recommendation: Alternative C (Decorators on CRUD + Events for Lifecycle)

**Rationale**: The synthesis (§5.4) explicitly maps which operations need decorators vs. events. The decorated set is small (~4-6 methods) and covers the highest-value plugin extension points: registration (CAPTCHA, terms), login (2FA), profile view (enrichment), and search (custom filters). Admin operations (bans, groups) need only events for side effects. This matches the Hierarchy/Threads convention and stays within the established project patterns.

**Addendum**: Profile field types should use tagged DI registries (Alt D's strength) — this doesn't conflict with alt C. The User service uses BOTH: decorators for CRUD operations AND tagged registries for profile field types.

**Key trade-off accepted**: Decorator boundary requires a one-time judgment on which methods qualify — documented in the spec.

**Assumption**: Plugins primarily extend registration, login, and profile views — not admin operations.

**Confidence**: MEDIUM-HIGH (80%) — pattern established in 2 services; applying to User service is straightforward but untested.

---

## Trade-Off Analysis

### 5-Perspective Comparison Matrix

| Decision Area | Recommended | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|---|
| **1. Profile Fields** | B: EAV Normalized | HIGH — standard relational pattern | MEDIUM — migration needed but transparent | MEDIUM — JOINs needed | MEDIUM — migration risk | HIGH — unlimited fields |
| **2. Token/Session** | D: Dual Coexistence | HIGH — both patterns well-known | HIGH — web + API both work | MEDIUM — two paths | LOW — proven patterns | HIGH — independent scaling |
| **3. Shadow Ban** | A: Full Only (v1) | HIGH — simplest possible | MEDIUM — no per-forum control | HIGH — binary check | LOW — minimal new logic | MEDIUM — upgrade to B later |
| **4. User Options** | A: Bitfield + Getters | HIGH — zero DB change | HIGH — clean API | HIGH — abstraction only | LOW — no migration | MEDIUM — bit position limits |
| **5. Delete Cascade** | D: Three Modes | MEDIUM — complex handlers | HIGH — admin flexibility | LOW — three code paths | MEDIUM — cascade failures | MEDIUM — per-service handlers |
| **6. Display DTO** | B: UserDisplayDTO | HIGH — simple class | HIGH — fast batch ops | HIGH — small DTO | LOW — minimal code | HIGH — optimized queries |
| **7. Extension Model** | C: Decorators + Events | HIGH — established pattern | HIGH — plugin flexibility | MEDIUM — two mechanisms | LOW — proven in 2 services | HIGH — decorator chain |

### Perspective Legend

- **Technical Feasibility**: Can we build this with current stack (PHP 8.2, PDO, Symfony DI)?
- **User Impact**: How well does this serve end users, admins, and plugin developers?
- **Simplicity**: How easy is it to understand, implement, and maintain?
- **Risk**: What can go wrong? How reversible are mistakes?
- **Scalability**: How well does this handle growth in users, data, and extensions?

---

## Cross-Cutting Analysis

### Pattern 1: Minimize v1 Scope, Design Schema for v2

Three decision areas (Shadow Ban, Profile Fields, User Options) share a common theme: **implement the simpler option in v1 but ensure the DB schema supports the richer option for v2**. The shadow ban table already has `scope` + `forum_id` columns. The EAV profile field table supports unlimited fields. The bitfield abstraction layer can be replaced with column migration later without changing the entity API.

### Pattern 2: Event-Driven Cross-Service Communication

Two decision areas (Delete Cascade, Extension Model) converge on events as the primary cross-service mechanism. `UserDeletedEvent(userId, deleteMode)` drives cascade cleanup; lifecycle events drive Auth cache clearing, Threads counter updates, and Notifications triggers. Decorators are reserved for within-service data transformation.

### Pattern 3: Type-Safe Boundaries Over Convenience

Two decision areas (Display DTO, User Options) prioritize type-safe contracts over fewer classes. `UserDisplayDTO` is an extra class but guarantees consumers can't access `passwordHash`. Named boolean getters on `UserPreferences` mean consumers never manipulate bits. The theme: pay the cost of an extra type to get compile-time safety.

### Inter-Decision Dependencies

| Decision | Depends On | Impact |
|---|---|---|
| Profile Fields (EAV) | Extension Model (Tagged Registry) | Profile field types use tagged DI services — EAV rows have a `field_type` FK that resolves to a tagged handler |
| Delete Cascade (Three Modes) | Events (from Extension Model) | `UserDeletedEvent` must carry `deleteMode` — services subscribe via EventDispatcher |
| Shadow Ban (Full) | Display DTO | `ShadowBanResponseDecorator` in Threads needs to call `ShadowBanService::isShadowBanned()` keyed by `userId` from the post's author |
| Token/Session (Dual) | Extension Model (Decorators on login) | 2FA decorator on `AuthenticationService::login()` must work for BOTH session and token creation paths |

---

## User Preferences (from Research Context)

- PHP 8.2+, PDO, Symfony DI — all recommendations are compatible
- REST API first — all recommendations expose clean JSON APIs
- Events + decorators — the extension model (Alt C) matches this exactly
- Critical Blocker #1 — this service unblocks Auth, which depends on User entity + GroupRepository

---

## Recommended Approach Summary

| # | Decision Area | Recommendation | Confidence |
|---|---|---|---|
| 1 | Profile Field Data Model | **B: EAV Normalized** — clean relational model, plugin-friendly | 80% |
| 2 | Token / Session Strategy | **D: Dual Coexistence** — sessions for web, tokens for API | 90% |
| 3 | Shadow Ban Scope | **A: Full Only** — simplest v1, schema supports scoping later | 85% |
| 4 | User Options Decomposition | **A: Bitfield + Named Getters** — zero migration, clean API | 85% |
| 5 | Delete Cascade Strategy | **D: Three Modes** — retain + remove + soft, legacy-compatible | 80% |
| 6 | Display DTO Design | **B: UserDisplayDTO** — type-safe, optimized batch queries | 85% |
| 7 | Extension Model | **C: Decorators on CRUD + Events** — proven project pattern | 80% |

---

## Why Not Others

| Decision | Rejected | Why Not |
|---|---|---|
| 1 | A (Dynamic Columns) | Legacy anti-pattern, requires DDL for new fields, ORM-unfriendly |
| 1 | C (JSON Column) | Weaker query support, no FK constraints, MySQL 5.x risk |
| 1 | D (Hybrid) | Two code paths double maintenance and testing without clear benefit |
| 2 | A (Unified) | Technically viable but makes User service too large; Alt D achieves the same with cleaner separation |
| 2 | B (Split to API layer) | Contradicts research recommendation; token table ownership ambiguous |
| 2 | C (Token-only) | Breaks Phase 1 backward compat; "who is online" feature lost; research says "non-negotiable" |
| 3 | B (Scoped) | Over-engineering for v1; decorator complexity per-forum; schema already supports upgrade |
| 3 | C (Partial/Queue) | Not a true shadow ban; increases moderation workload; requires queue infrastructure |
| 3 | D (Gradated) | Three filtering mechanisms for unproven need; testing matrix explosion |
| 4 | B (Individual Columns) | 18 ALTER TABLEs on already-widest table; backward-incompatible; unnecessary given PHP-only reads |
| 4 | C (JSON Column) | Migration needed for marginal benefit; JSON querying weaker than column access |
| 4 | D (Bitfield + JSON) | Two storage mechanisms for preferences is unnecessary indirection |
| 5 | A (Soft Only) | No "remove all content" option; misses legacy `remove` mode admins depend on |
| 5 | B (Hard Delete Events) | Irreversible; cascade failure risk without retention safety net |
| 5 | C (Two-Phase) | Over-engineering — retention period adds complexity without clearly demonstrated need |
| 6 | A (Full Entity) | Memory waste, security risk (passwordHash in memory), no type-safe contract |
| 6 | C (Interface on Entity) | No performance gain; false safety — consumers can cast past the interface |
| 6 | D (Field Selection) | Nullable entity properties break immutable contract; magic `$fields` parameter |
| 7 | A (Decorators Everywhere) | Massive surface, performance overhead, over-engineering for admin operations |
| 7 | B (Events Only) | Cannot modify request/response; blocks CAPTCHA, 2FA, profile enrichment plugins |
| 7 | D (Tagged Only) | Solves type registries but misses request/response transformation needs |

---

## Deferred Ideas

1. **OAuth2/OIDC account linking** — three `phpbb_oauth_*` tables exist in legacy. Modern OAuth2 provider integration deferred to future research (research report §4.1).
2. **DNSBL checking modernization** — legacy checks Spamhaus + SpamCop. Make configurable + optional; implement as a registration decorator when the decorator pipeline (Decision 7) is in place. Not v1.
3. **COPPA compliance** — REGISTERED_COPPA group exists. Legal requirements unclear. Preserve group, defer compliance details to legal review.
4. **User merge** — admin merges two accounts (reassign all content). Complex cascade; defer to Phase 3 with admin operations.
5. **Per-subreddit (per-forum) shadow bans** — schema supports it via `scope` + `forum_id`. Implement in v2 after v1 shadow ban pattern is validated.
6. **WebAuthn/Passkey support** — modern passwordless auth. Good fit for the dual auth architecture but out of scope for v1.
7. **Rate limiting on registration/login** — currently handles via `login_attempts` counter. Consider a dedicated rate-limiting middleware in the API layer rather than baking it into User service.

---

*Generated from research synthesis (confidence 87%) and research report. All alternatives linked to evidence from legacy code analysis, DB schema inspection, cross-service contract mapping, and external literature review.*

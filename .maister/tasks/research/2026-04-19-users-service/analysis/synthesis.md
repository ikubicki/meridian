# Synthesis: Users Service (`phpbb\user`)

**Research Question**: How should the `phpbb\user` service be designed to provide user management, preferences, group membership, banning (shadow+permanent), session management, and authentication — as a standalone PSR-4 service with zero legacy dependencies, based on existing DB schemas, extensible via events + decorators, and exposed through REST API?

---

## Executive Summary

Five parallel research agents produced highly consistent findings across legacy code analysis, database schema inspection, existing spec evaluation, cross-service integration mapping, and patterns/literature review. The existing IMPLEMENTATION_SPEC.md (score 4.0/5) provides a strong foundation covering ~80% of the design surface. Seven additive gaps were identified — shadow banning, decorators, REST API, profile fields, token auth, missing events, and admin operations — none requiring fundamental restructuring. Cross-service contracts are well-defined: Auth needs User entity + GroupRepository, Threads needs event-driven counter updates, Notifications/Messaging need batch user display data. The shadow ban design converges on a separate table + response decorator pattern. Token-based auth coexists with legacy sessions via a dual-path approach.

---

## 1. Cross-Source Analysis

### 1.1 Cross-Reference Matrix

| Finding | Spec Gap | Legacy Code | DB Schema | Cross-Service | Literature | Confidence |
|---------|----------|-------------|-----------|---------------|------------|------------|
| **User entity shape** | 34 properties defined | Confirms all fields used in session.php/functions_user.php | 68 columns mapped; entity covers core + profile + prefs | Auth needs `id`, `type`; Threads needs `username`, `colour`; Hierarchy needs `lastmark` | — | **HIGH** — 4 sources agree on User entity structure |
| **UserType enum (0-3)** | Defined: Normal, Inactive, Ignore, Founder | Founder immune to bans (session.php:1142); Ignore = bots + anonymous (overloaded) | `user_type TINYINT(2)` with values 0-3 | Auth: Founder override in Layer 5; REST API: active check needs type | — | **HIGH** — All sources consistent. Note: Ignore (2) overloaded for bots+anonymous |
| **Ban exclude pattern** | `ban_exclude` in Ban entity | `ban_exclude=1` overrides ALL bans immediately (session.php:1142-1267) | `ban_exclude TINYINT(1)` indexed with ban_user, ban_email, ban_ip | Not consumed by downstream services | — | **HIGH** — Unique phpBB pattern, well-documented in legacy code |
| **Shadow banning** | ❌ Not in spec | ❌ Not in legacy code | ❌ No table/column exists | Threads needs `isShadowBanned()` for content filtering | Separate table recommended; response decorator pattern | **HIGH** — All sources agree it's absent and needed. Literature gives clear implementation path |
| **Session ID = MD5** | In spec (session entity) | `md5(unique_id())` in session.php:790 | `session_id CHAR(32)` PK | REST API uses tokens (not sessions) for API auth | Token-based auth recommended for API | **HIGH** — Legacy pattern documented; new token system replaces for API |
| **Auto-login key = md5(plaintext)** | In spec | DB stores `md5(key)`, cookie stores plaintext (session.php:795-797) | `key_id CHAR(32)` in sessions_keys | — | Modern approach: SHA-256 of random bytes | **HIGH** — Legacy pattern clear; modernization path defined |
| **Group cascade (colour/rank/avatar)** | Group entity + set_default_group logic | Detailed in functions_user.php:3437-3560: colour always, rank IF 0, avatar IF empty | `user_colour`, `user_rank` on users; `group_colour`, `group_rank` on groups | Auth: clear ACL cache on group change; Threads: poster colour denormalized | — | **HIGH** — 3 sources detail same cascade logic |
| **Profile fields (custom/dynamic)** | ❌ Not in spec | manager.php: EAV hybrid with `pf_*` dynamic columns, 7 field types | 24-column field definition table + dynamic data table | — | Tagged registry for field types recommended | **HIGH** — Gap confirmed by both spec analysis and DB schema |
| **Token auth for API** | ❌ Not in spec | ❌ No legacy equivalent | No `phpbb_api_tokens` table yet (needs creation) | REST API defines `phpbb_api_tokens` schema; Auth subscriber validates tokens | SHA-256 hash storage, 32-byte random tokens | **HIGH** — REST API HLD provides complete spec; literature confirms pattern |
| **Decorator pipeline** | ❌ Not in spec | ❌ Not in legacy code | — | Hierarchy + Threads both use same DecoratorPipeline pattern | Request/Response decorators recommended | **HIGH** — Two existing services establish project convention |
| **user_options bitfield** | Referenced but not decomposed | 18 named bits in user.php:52 (default 230271) | `user_options INT(11) UNSIGNED` | Threads' ContentContext needs viewer preferences (viewSmilies, viewImages, viewCensored) | — | **HIGH** — Bitfield semantics fully documented; decomposition needed for new entity |
| **user_permissions column** | Excluded from User entity (correct) | Compiled ACL blob (binary-encoded) | `MEDIUMTEXT` column on phpbb_users | Auth owns this column via direct PDO (ADR-003) | — | **HIGH** — All sources agree: Auth-owned, not User entity |
| **Batch user lookup** | Not in spec | No batch API in legacy | — | Notifications + Messaging both need `findByIds(int[])` for display data | Lightweight DTO recommended | **MEDIUM-HIGH** — 2 consumers need it; no existing interface |
| **user_new leave mechanism** | Not detailed | When `user_posts >= new_member_post_limit`, leave NEWLY_REGISTERED group | `user_new TINYINT(1)` flag | Threads dispatches PostCreatedEvent → counter increment → group check | — | **MEDIUM** — Subtle legacy rule; crosses User + Threads boundary |
| **Event catalog completeness** | 8 events defined | 30+ state changes identified | — | Auth needs UserGroupChangedEvent; all services need UserDeletedEvent | 10+ additional events recommended | **HIGH** — Gap size quantified by both spec analysis and cross-service mapping |

### 1.2 Validated Findings (Multiple Sources Confirm)

1. **User entity is well-scoped**: The spec's 34-property User entity covers core identity, profile, and preferences. DB schema analysis confirms all properties map to real columns. Cross-service contracts confirm downstream consumers need `id`, `type`, `username`, `colour`, `avatarUrl`, `lastmark`, `posts`, `lastPostTime`.

2. **Repository-interface-first is correct**: Spec defines 6 repository interfaces. Auth and Messaging both explicitly call `UserRepositoryInterface::findById()` and `GroupRepositoryInterface::getMembershipsForUser()`. Literature confirms interface-first is best practice for testability.

3. **Founder immunity is absolute**: Legacy code (session.php) skips ban checks for USER_FOUNDER. Spec gap analysis confirms `User::isFounder()`. Auth HLD uses `UserType::Founder` for Layer 5 override. DB schema: `user_type=3`.

4. **Group default cascade logic is complex and must be preserved**: Legacy code documents exact priority order (ADMINISTRATORS > GLOBAL_MODERATORS > NEWLY_REGISTERED > REGISTERED_COPPA > REGISTERED > BOTS > GUESTS). DB schema confirms `group_id` on users table as denormalized FK.

5. **Dual auth path (sessions + tokens) is the correct architecture**: Legacy sessions serve web; new tokens serve API. REST API HLD explicitly designs for this. Literature confirms session/token duality is standard for forum-to-API migrations.

### 1.3 Contradictions Resolved

| Contradiction | Source A | Source B | Resolution |
|--------------|----------|----------|------------|
| **UserType value 2 meaning** | Spec: `Ignore(2)` | DB: covers both anonymous (user_id=1) AND bots | **Keep value 2 as `Ignore`**. Distinguish bots from anonymous via group membership (BOTS group_id=6). Document that `Ignore` is an umbrella type. Alternatively, rename to `System` to clarify. |
| **JWT vs DB tokens** | Auth HLD (original draft) mentioned JWT | REST API HLD ADR-002: DB opaque tokens | **DB opaque tokens win** — REST API ADR is definitive. Auth HLD was updated to align. Confirmed by services-architecture.md critical items. |
| **Extension model: decorators vs tagged DI** | Hierarchy/Threads: request/response decorators | Notifications: tagged service_collection | **Not a contradiction** — they serve different purposes. Decorators for data transformation pipeline; tagged registries for polymorphic extension sets. User service uses BOTH: decorators for CRUD operations, tagged registry for profile field types. |
| **`user_allow_pm` field** | DB: column exists, legacy code checks it | Messaging HLD: not referenced, blocking is conversation-level | **Deprecate `user_allow_pm`** at user-preference level. Expose as a read-only preference for display but don't enforce in Messaging service. New blocking is per-conversation via rules. |
| **Token management ownership** | REST API HLD defines `phpbb_api_tokens` | Cross-service analysis asks: does User own tokens? | **User service owns `ApiTokenService`**. Tokens are user-scoped (creation, revocation, listing). REST API subscriber only VALIDATES tokens by calling User service. |

### 1.4 Confidence Assessment

| Area | Confidence | Supporting Evidence | Notes |
|------|------------|-------------------|-------|
| User entity model | **HIGH** (95%) | Spec + DB + 5 cross-service consumers | Minor refinements needed (add `lastmark`, `posts`) |
| Group management | **HIGH** (90%) | Spec + Legacy code + DB + Auth HLD | Cascade logic complex but well-documented |
| Ban system (permanent) | **HIGH** (95%) | Spec + Legacy code + DB | Exclude pattern unique to phpBB, well understood |
| Shadow ban design | **HIGH** (85%) | Literature + DB gap + Cross-service needs | No legacy precedent, but clear pattern from literature |
| Session management | **HIGH** (90%) | Legacy code + DB + Spec | Well-documented; modernization path clear |
| Token auth | **HIGH** (85%) | REST API HLD + Literature | Schema defined; integration with User service needs implementation |
| Decorator pipeline | **MEDIUM-HIGH** (80%) | Hierarchy/Threads convention + Literature | Convention exists; adapting to User operations is straightforward but untested |
| Profile fields | **MEDIUM** (70%) | Legacy code + DB schema | Complex dynamic-column pattern; migration to normalized model needs design |
| Admin operations | **MEDIUM** (65%) | Legacy code | `user_delete()` cleanup is massive; retain/remove modes need careful porting |
| REST API endpoints | **MEDIUM-HIGH** (80%) | Spec gaps + Literature + REST API HLD conventions | Endpoint catalog complete; request/response DTOs need detailed design |

---

## 2. Patterns and Themes

### 2.1 Architectural Patterns

| Pattern | Prevalence | Quality | Sources |
|---------|-----------|---------|---------|
| **Repository → Service → Controller** | Project-wide convention | Established, documented | services-architecture.md, all HLDs |
| **Event-driven side effects** | All new services use it | Established | Threads, Hierarchy, Notifications, Messaging |
| **Request/Response Decorator pipeline** | 2 services (Hierarchy, Threads) | Emerging convention | Gap analysis, Literature review |
| **Tagged DI registry** | 1 service (Notifications) | Legacy pattern | Cross-cutting assessment identified contradiction |
| **Dual auth (session + token)** | Architectural decision | Confirmed by REST API HLD | Literature, services-architecture.md |
| **Denormalized counters** | Extensive in phpBB | Legacy-driven, accepted trade-off | DB schema (user_posts, user_warnings, user_colour, poster_colour) |
| **Bitfield preferences** | 1 field (user_options) | Legacy — decompose in new design | DB schema, Legacy code |

### 2.2 Design Patterns

| Pattern | Examples | Assessment |
|---------|----------|------------|
| **Immutable readonly entities** | All entities in spec | Modern PHP 8.2, well-typed |
| **`fromRow()` factory pattern** | User, Session, Group entities | Clean DB→entity mapping |
| **Interface-first repositories** | 6 repository interfaces in spec | Testable, swappable |
| **DTO for inputs, Entity for outputs** | CreateUserDTO → User entity | Clear separation of mutable input vs immutable output |
| **Enum for type safety** | UserType, BanType, GroupType | PHP 8.1+ best practice |
| **Exception hierarchy with HTTP codes** | 13 specific exceptions | Excellent API error mapping |

### 2.3 Recurring Requirements

1. **Batch user resolution**: Notifications, Messaging, and Threads ALL need to resolve `user_id → {username, avatar_url, colour}` for lists of users. A dedicated `UserDisplayDTO` and `findByIds()` method is needed.

2. **Event-driven counter maintenance**: User's `user_posts`, `user_lastpost_time` updated via Threads events. `user_warnings` updated via warnings system. Pattern: always update via events, never let consumers write to User table directly.

3. **Group membership as cross-cutting data**: Auth needs groups for ACL resolution. Messaging needs groups for PM rules. Groups are the most shared data after user identity.

4. **Privacy-tiered data access**: Every downstream consumer needs different subsets of user data depending on context (public view, authenticated view, self view, admin view).

---

## 3. Relationships and Dependencies

### 3.1 Dependency Map

```
phpbb\user
├── CONSUMED BY (hard imports):
│   ├── phpbb\auth
│   │   ├── Entity\User (id, type)
│   │   ├── Entity\Group (skipAuth)
│   │   ├── Entity\GroupMembership (isPending, isLeader)
│   │   ├── Contract\UserRepositoryInterface (findById)
│   │   └── Contract\GroupRepositoryInterface (getMembershipsForUser, findById)
│   ├── phpbb\api (REST framework)
│   │   └── Contract\UserRepositoryInterface (findById for token→user hydration)
│   └── phpbb\messaging
│       └── Contract\GroupRepositoryInterface (getMembershipsForUser for rule checks)
│
├── EVENT CONSUMERS (loose coupling):
│   ├── phpbb\auth → listens to UserGroupChangedEvent (clear ACL cache)
│   ├── phpbb\threads → dispatches events User consumes (PostCreated, PostDeleted, etc.)
│   └── phpbb\notifications → consumes UserCreatedEvent for welcome notification
│
├── BATCH DATA CONSUMERS:
│   ├── phpbb\notifications → findByIds() for responder display
│   ├── phpbb\messaging → findByIds() for participant display
│   └── phpbb\threads → User entity for denormalized poster data at write time
│
└── LIGHTWEIGHT CONSUMERS:
    └── phpbb\hierarchy → user_lastmark (single int column via User entity)
```

### 3.2 Data Flow Analysis

```
Registration:
  Client → POST /api/v1/auth/signup → RegistrationService::register()
    → INSERT phpbb_users → INSERT phpbb_user_group 
    → dispatch UserCreatedEvent → Notifications (welcome email)

Login:
  Client → POST /api/v1/auth/login → AuthenticationService::login()
    → verify password → create Session row → create ApiToken 
    → return raw_token + user data to client

Post Creation (cross-service):
  Client → Threads::createPost() → dispatch PostCreatedEvent
    → User EventSubscriber::onPostCreated()
    → UPDATE phpbb_users SET user_posts = user_posts + 1, user_lastpost_time = :now

Ban Check:
  API Request → token_auth_subscriber → ApiTokenService::validateToken()
    → UserRepository::findById() → BanService::assertNotBanned()
    → proceed or throw BannedException

Shadow Ban Content Filtering:
  Client → GET /api/v1/topics/{id}/posts → Threads::getPosts()
    → ShadowBanResponseDecorator::decorate(PostListResponse)
    → filter out shadow-banned users' posts (unless viewer is the banned user)
    → return filtered response
```

---

## 4. Gaps and Uncertainties

### 4.1 Information Gaps

| Gap | Impact | Why Unknown | Suggested Resolution |
|----|--------|-------------|---------------------|
| **Profile field migration strategy** | MEDIUM | Dynamic `pf_*` columns vs normalized rows — neither approach tested | Design spike: benchmark EAV rows vs dynamic columns for 10k users × 10 fields |
| **Admin operation scope** | MEDIUM | `user_delete()` touches 18+ tables; cross-service cleanup not designed | Enumerate cleanup per service; document which service handles which cleanup via events |
| **OAuth account linking** | LOW | Three OAuth tables exist; no research on modern OAuth2/OIDC patterns | Defer to future research; keep `phpbb_oauth_*` tables for reference |
| **DNSBL checking in new service** | LOW | Legacy checks Spamhaus + SpamCop; modern relevance unclear | Make it configurable + optional; implement as a registration decorator |
| **COPPA compliance** | LOW | REGISTERED_COPPA group exists; legal requirements unknown | Preserve group; defer compliance details to legal review |

### 4.2 Unverified Claims

| Claim | Source | Risk |
|-------|--------|------|
| "Response decorator for shadow ban is performant at scale" | Literature | MEDIUM — large topic views (500+ posts) may need query-level filtering instead |
| "Offset-based pagination is sufficient" | Literature | LOW — for most forum queries, but may be slow past page 1000+ with large user tables |
| "Bitfield decomposition has no migration risk" | Schema analysis | LOW — `user_options` is only read in PHP; decomposing to individual columns is safe |

### 4.3 Unresolved Inconsistencies

1. **`user_type=2` overload**: Value 2 means `Ignore` but covers both anonymous (user_id=1) and bots. The spec names it `Ignore` and the DB proves it's overloaded. Resolution: keep the value, add `isBotUser()` method on User entity that checks group membership.

2. **Who owns `phpbb_user_notifications`?**: DB schema shows it's per-user notification preferences. Notifications HLD says it owns this table. But the data is user-specific. Resolution: **Notifications service owns it** — it's notification configuration, not user configuration. User service doesn't touch it.

3. **`user_new` group transition trigger**: Legacy code checks `user_posts >= new_member_post_limit` during session update. In the new system, this should happen in the User event subscriber handling `PostCreatedEvent`. But the config value (`new_member_post_limit`) lives in the global config, not User service. Resolution: User event subscriber reads this config value; the group transition is internal to User service.

---

## 5. Key Tensions and Trade-Offs

### 5.1 Shadow Ban Storage: Separate Table vs Banlist Extension vs User Column

| Option | Pros | Cons | Cross-Source Support |
|--------|------|------|---------------------|
| **Separate `phpbb_shadow_bans` table** | Clean separation, audit trail, scoping potential, no existing schema changes | Additional table + query | Literature (recommended), DB schema (no existing support), Spec gap analysis |
| **Extension of `phpbb_banlist`** | Reuses existing ban infrastructure, supports IP/email shadow bans, has exclude mechanism | Complicates ban check logic, mixes fundamentally different concepts | Legacy code (ban check is already complex) |
| **Flag on `phpbb_users`** | Fastest lookup (already loading user row), simplest | No audit trail, no scoping, no exclude mechanism, tight coupling | DB schema (simplest change) |

**Synthesis verdict**: **Separate table** — strongest support from literature + clearest separation. The ban check algorithm (session.php:1142-1267) is already complex; adding shadow ban logic to it would increase fragility. Two sources (literature, spec gap) recommend separate; one (DB schema) is neutral.

### 5.2 Session vs Token Auth Architecture

| Aspect | Sessions Only | Tokens Only | Dual Path (Recommended) |
|--------|--------------|-------------|------------------------|
| Web compatibility | ✅ Cookie-based, existing | ❌ No CSRF protection built-in | ✅ Sessions for web |
| API compatibility | ❌ Cookie in API is awkward | ✅ Bearer header, no CSRF | ✅ Tokens for API |
| Migration risk | ✅ Zero migration | ⚠️ All existing sessions break | ✅ Gradual migration |
| Complexity | ✅ Single path | ✅ Single path | ⚠️ Two parallel auth paths |
| Cross-source support | Legacy code, Spec | REST API HLD, Literature | services-architecture.md, REST API HLD |

**Synthesis verdict**: **Dual path** — every source points this way. Sessions for Phase 1 web compat; tokens for API. User service's `AuthenticationService` creates sessions (web) AND `ApiTokenService` creates tokens (API).

### 5.3 Profile Field Data Model: Dynamic Columns vs Normalized Rows

| Option | Pros | Cons | Source |
|--------|------|------|--------|
| **Keep dynamic `pf_*` columns** | Direct backward compatibility, single-row fetch, no JOIN | Schema changes for new fields, ORM unfriendly, migration complexity | Legacy code, DB schema |
| **Normalize to rows (field_id, user_id, value)** | Clean relational model, no DDL for new fields, standard ORM mapping | Break from legacy, need JOIN to fetch all fields, potential N+1 | Spec gap analysis, Literature |
| **Hybrid: read legacy, write normalized** | Backward compat + forward clean path | Two read paths, data sync complexity | Spec gap analysis suggestion |

**Synthesis verdict**: **Normalize to rows** for the new service. The legacy `pf_*` column approach is a phpBB-specific anti-pattern that doesn't scale and isn't ORM-friendly. Migration script converts existing `pf_*` data to rows. Field definitions remain in `phpbb_profile_fields` (read-only for users).

### 5.4 Decorator Granularity: Which Operations Are Decoratable?

| Operation | Decorate? | Rationale |
|-----------|-----------|-----------|
| `register()` | ✅ Request + Response | Plugins add CAPTCHA, terms, referral codes to registration |
| `login()` | ✅ Request + Response | 2FA plugin decorates login with extra step |
| `getUserProfile()` | ✅ Response only | Plugins enrich profile with badges, reputation, etc. |
| `listUsers()` / `search()` | ✅ Request + Response | Custom filters, extra display data |
| `changePassword()` | ⚠️ Request only (validation) | Password policy plugins |
| `ban()` / `shadowBan()` | ❌ Events sufficient | Side effects only (log, notify), no data transformation needed |
| `addToGroup()` / `removeFromGroup()` | ❌ Events sufficient | ACL cache clear is a side effect |
| `updatePreferences()` | ⚠️ Request only | Preference validation plugins |

**Synthesis verdict**: Decorate **user-facing read/write operations** (register, login, profile, search). Use **events for admin/mod operations** (bans, group management). This limits decorator complexity while maintaining extensibility where plugins most commonly need it.

### 5.5 User Entity: Lightweight vs Complete

| Option | Properties | Users | Concern |
|--------|-----------|-------|---------|
| **Lightweight (Auth-optimized)** | id, type, username, colour, defaultGroupId | Auth, Hierarchy | Fast, minimal data |
| **Complete (full row)** | All 34+ properties | Profile, Preferences, Admin | Expensive to load for ACL checks |
| **Multi-entity decomposition** | User (core) + UserProfile + UserPreferences + UserActivity | All consumers | Multiple queries or lazy loading |

**Synthesis verdict**: **User entity = core identity (~12 fields)** + separate `UserProfile`, `UserPreferences`, `UserActivity` entities. The spec already does this (7 entities). Refine the core `User` to include the fields ALL consumers need: `id`, `type`, `username`, `usernameClean`, `email`, `colour`, `defaultGroupId`, `avatarUrl`, `registeredAt`, `lastmark`, `posts`, `lastPostTime`. This satisfies Auth (id, type), Threads (username, colour, posts), Hierarchy (lastmark), and display consumers (avatarUrl) with a SINGLE query.

---

## 6. Synthesis Conclusions

### Primary Conclusions

1. **The existing IMPLEMENTATION_SPEC.md is 80% complete** — seven additive gaps, zero fundamental redesign needed. Score: 4.0/5.

2. **Shadow banning is the largest new design surface** — no legacy precedent exists. A separate `phpbb_shadow_bans` table with response decorators on downstream services (Threads, Notifications) is the recommended approach.

3. **Cross-service contracts are clearly defined** — Auth needs User entity + GroupRepository; Threads needs event-driven counters; Notifications/Messaging need batch display data. All contracts have concrete interface signatures.

4. **Dual auth (sessions + tokens) is non-negotiable** — web sessions for Phase 1 backward compat, API tokens for modern clients. Both services live within `phpbb\user`.

5. **The decorator pipeline convention is established** — adopting the Hierarchy/Threads pattern (RequestDecorator + ResponseDecorator + DecoratorPipeline) is the only sensible choice. Tagged registry for profile field types (paralleling Notifications' type registry).

6. **10+ missing events need to be added** — the spec's 8 events are insufficient. Group membership changes, user deletion, activation/deactivation, username changes, and session lifecycle events are all needed by downstream consumers.

### Secondary Conclusions

7. **Profile fields should be normalized** from legacy dynamic columns to standard relational rows, with a migration path for existing data.

8. **`user_options` bitfield should be decomposed** into individual boolean properties on `UserPreferences` entity.

9. **`user_permissions` and `user_perm_from` are definitively Auth-owned** — they should NOT appear on the User entity. All sources confirm this boundary.

10. **Admin operations (delete, deactivate, merge)** are needed but lower priority — the cleanup scope (18+ table cascades) makes them the riskiest implementation area.

### Recommendations

1. **Update IMPLEMENTATION_SPEC.md** with all 7 identified gaps as additive sections.
2. **Design shadow banning** as a separate subsystem within User service (ShadowBanService + ShadowBan entity + dedicated table).
3. **Prioritize cross-service contracts** — Auth and Threads integration is Phase 1 critical.
4. **Add event catalog** to spec: expand from 8 to 18+ events.
5. **Design profile field migration** from dynamic columns to normalized rows.
6. **Create REST API controller specs** following existing project patterns.
7. **Implement dual auth** with clear boundary: `SessionService` for web, `ApiTokenService` for API.

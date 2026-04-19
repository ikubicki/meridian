# Reality Assessment: phpBB Rebuild — Research & Planning Phase

**Date**: 2026-04-19
**Scope**: All tasks in `.maister/tasks/` (14 research + 4 development)
**Assessor**: Reality assessor agent
**Previous assessment**: `cross-cutting-assessment.md` (2026-04-19)

---

## Status: ⚠️ Issues Found — NOT Ready to Start Full Implementation

**Bottom line**: Of the 3 original CRITICAL gaps, **1 is fully resolved** (User Service research), **1 is mostly resolved** (extension model), and **1 is still open** (JWT vs DB token). Of 5 HIGH gaps, **0 are fully resolved**. Additionally, there's a newly identified critical gap — the authentication/session service lives in a no-man's-land between Auth and User. The research phase is ~75% complete for domain services but the **integration layer** (how services connect at runtime) remains largely unaddressed.

**Can individual services start implementation?** Yes — Database Service, Cache Service, and User Service can begin immediately. But full-stack integration requires resolving the items below.

---

## 1. Original Critical Gap Status

### GAP-1: User Service Missing → ✅ RESOLVED

| Claim | Reality | Evidence |
|-------|---------|----------|
| User Service not researched | Full research completed | `research/2026-04-19-users-service/outputs/` — HLD (300+ lines), decision log (8 ADRs), research report, solution exploration |
| Auth blocked by missing User entity | User entity fully designed | HLD defines `User`, `UserProfile`, `UserPreferences`, `Group`, `GroupMembership`, `Ban`, `ShadowBan` entities |
| No Auth↔User boundary defined | Explicit boundary via ADR-001 | User = data management (CRUD, profile, groups, bans); Auth = AuthN + AuthZ. Clear direction: Auth → User (never reverse) |
| No development plan | Spec + implementation plan exist | `development/2026-04-18-user-service/spec.md` (detailed, ~65 files planned), `implementation-plan.md` (10 task groups) |

**Assessment**: Fully resolved at the design level. Implementation at 0/65 files — not started yet. The research quality is high: complete entity model, all method signatures defined, JSON profile fields (ADR-002), shadow banning (ADR-004), three delete modes (ADR-005).

**Note**: The development spec (2026-04-18) predates the research (2026-04-19). The spec includes `AuthenticationService` and `SessionService` inside User, but the research ADR-001 explicitly moves these to Auth. The spec needs updating to reflect the final research decision.

---

### GAP-2: Extension Model Contradiction → ⚠️ MOSTLY RESOLVED

| Claim | Reality | Evidence |
|-------|---------|----------|
| Events+decorators vs tagged DI conflict | Plugin system defines shared decorator interfaces | `research/2026-04-19-plugin-system/outputs/high-level-design.md` — DA-3: shared `RequestDecoratorInterface`/`ResponseDecoratorInterface` in `phpbb\plugin\decorator\` |
| Notifications uses tagged DI | Still uses tagged DI for type/method registration | `notifications/decision-log.md` ADR-007: "Tagged DI services with interface contracts" — `notification.type`, `notification.method` tags |
| No consistent extension model | Plugin system implicitly allows both patterns | Plugin system DD-8 accepts mutable events as equivalent to decorators for Search |

**The implicit resolution**: Two patterns coexist with different purposes:
- **Decorators + Events**: For lifecycle extension (modifying request/response flow, reacting to state changes). Used by Threads, Hierarchy, Users, Search, Messaging.
- **Tagged DI**: For type-registry extension (registering new "things" — notification types, delivery methods). Used by Notifications only.

**What's missing**: This distinction is NOT documented as an explicit ADR. No cross-cutting architecture decision says "use tagged DI for type registries, decorators+events for lifecycle." It's inferred but could easily be misunderstood by implementers.

**Remaining conflict**: Hierarchy uses `RegisterForumTypesEvent` (an event) for type registration, while Notifications uses tagged DI for the same pattern. These should use the same mechanism. The plugin system HLD doesn't address this specific discrepancy.

**Severity**: Downgraded from CRITICAL to HIGH. Functional — the patterns work — but inconsistent and undocumented.

---

### GAP-3: JWT vs DB Token Confusion → ❌ STILL OPEN

| Claim | Reality | Evidence |
|-------|---------|----------|
| Auth says JWT | Still says JWT throughout | Auth HLD: "HTTPS + Bearer JWT" (line 28), "Extract Bearer JWT" (line 995), "Validate JWT signature" (line 996), "Resolve User from JWT sub claim" (line 997), 20+ JWT references |
| REST API says DB tokens | REST API ADR-002 defines `phpbb_api_tokens` table | SHA-256 hashed opaque tokens, DB lookup per request |
| Someone resolved it | No resolution exists | Auth HLD line 1401: "Token generation/refresh strategy — Token service will be designed independently" |

**Evidence of contradiction**:
- Auth HLD references `firebase/php-jwt` (already in vendor) and JWT-specific concepts (claims, sub, signature validation)
- REST API defines a concrete `phpbb_api_tokens` DB table with no JWT
- Notifications HLD references "auth_subscriber JWT → _api_user (priority 8)"
- User service research defers all token/session ownership to Auth

**Impact**: This directly affects how every API request is authenticated. It's the first thing hit on every HTTP call. Cannot be left ambiguous.

**Recommended resolution** (from cross-cutting assessment, still valid): Adopt DB tokens (REST API's design). They're simpler, immediately revocable, don't require key management, and the REST API design is far more detailed. Update Auth HLD to say "Bearer token" not "Bearer JWT."

---

## 2. Original High Gap Status

### GAP-4: Migration Strategy Undefined → ❌ STILL OPEN

No migration strategy document exists anywhere in the task tree. Services still make contradictory schema assumptions:

| Service | Schema Strategy | Tables |
|---------|----------------|--------|
| Auth | Reuse legacy tables exactly | `phpbb_acl_*` (existing) |
| Hierarchy | Reuse legacy tables exactly | `phpbb_forums` (existing) |
| Threads | Reuse legacy tables exactly | `phpbb_topics`, `phpbb_posts` (existing) |
| Users | Reuse legacy + add JSON columns | `phpbb_users` + `profile_fields JSON`, `preferences JSON` |
| Messaging | Entirely new schema | 7 new `messaging_*` tables, legacy `phpbb_privmsgs` abandoned |
| Storage | New table | `phpbb_stored_files` replaces `phpbb_attachments`, UUID v7 IDs |
| Search | Reuse legacy | `phpbb_search_*` (existing 3-table word index) |
| Notifications | Full rewrite, new repository | Reuses `phpbb_notifications` but with new PDO repository |

**Unanswered questions**:
- How does PM data migrate from `phpbb_privmsgs*` → `messaging_conversations`?
- How do attachments migrate from `phpbb_attachments` → `phpbb_stored_files` with UUID v7 IDs?
- Can old and new systems coexist during transition?
- Is there a data migration tool/script design?
- Big bang or incremental?

**Pragmatic view**: This is NOT a blocker for starting implementation of individual services. It IS a blocker for going to production. Can be planned in parallel.

---

### GAP-5: Hierarchy Counter Update API Not Defined → ❌ STILL OPEN

| Claim | Reality | Evidence |
|-------|---------|----------|
| Threads assumes `updateForumStats()` | Method doesn't exist in Hierarchy | Searched `HierarchyServiceInterface` — no `updateForumStats`, `updateForumLastPost`, or `recalculateForumLastPost` methods |
| Hierarchy exposes counter management | Only `resetStats()` exists | `ForumRepository` has `resetStats($id)` that zeroes all 6 counters — no incremental update |

**Threads HLD explicitly calls**:
```php
$this->hierarchyService->updateForumStats($forumId, new ForumStatsDelta(...));
$this->hierarchyService->updateForumLastPost($forumId, new ForumLastPostInfo(...));
$this->hierarchyService->recalculateForumLastPost($forumId);
```

**Hierarchy HLD does NOT define** any of these methods. `HierarchyServiceInterface` has: `createForum`, `updateForum`, `deleteForum`, `moveForum`, `reorderForum`, `getForum`, `getTree`, `getPath`, `getSubtree`, `getChildIds`, `markForumRead`, `markAllRead`, `isForumUnread`, `getUnreadStatus`, subscription methods.

**Impact**: Blocks Threads ↔ Hierarchy integration. Must be resolved before either service's counter logic can be implemented.

**Severity**: HIGH. Simple fix — add 3 methods to `HierarchyServiceInterface` + DTOs for `ForumStatsDelta` and `ForumLastPostInfo`. But it requires a deliberate decision.

---

### GAP-6: Auth Subscriber Priority Conflicts → ⚠️ PARTIALLY RESOLVED

| Service | Subscriber | Priority | Role |
|---------|-----------|----------|------|
| REST API | `token_auth_subscriber` | 16 | Authentication (token → user) |
| Auth | `AuthorizationSubscriber` | 8 | Authorization (ACL check) |
| Notifications | `auth_subscriber` | 8 | "JWT → _api_user" — CONFLICT |

Auth (16 for AuthN, 8 for AuthZ) is correct. Notifications claiming priority 8 for authentication collides with Auth's authorization at the same priority.

**Pragmatic view**: Easy to fix during Notifications implementation. Not a blocker.

---

### GAP-7: post_text Format Migration → ❌ STILL OPEN

No research document addresses the s9e XML → raw text migration. Threads ADR-001 says "raw text only" but the `phpbb_posts.post_text` column currently contains s9e XML with `bbcode_uid`, `bbcode_bitfield`, etc.

**Options** (none documented):
1. One-time migration converting all s9e XML → raw text (destructive, risky, 1M+ posts)
2. ContentPipeline dual-mode (detect format, handle both)
3. Keep s9e XML storage, change rendering pipeline only

**Impact**: Affects every post display. Must be decided before Threads implementation.

---

### GAP-8: Cross-Cutting ADR for Shared Patterns → ❌ STILL OPEN

No cross-cutting architecture document exists. Missing decisions:
- Shared exception base class + HTTP error mapping
- Counter management pattern (Threads "hybrid tiered" vs Messaging "tiered hot+cold" — same concept, different names)
- Transaction coordination across services (Threads calls Hierarchy in-transaction — how?)
- Error response format (JSON:API? Custom?)
- Logging convention
- Health check / monitoring

The plugin system partially addresses decorator interfaces but doesn't cover these patterns.

---

## 3. Original Medium Gap Status

| # | Gap | Status | Evidence |
|---|-----|--------|----------|
| 9 | Search Service missing | ✅ **RESOLVED** | Full research: `2026-04-19-search-service/outputs/` — HLD with orchestrator + ISP backends, 4 interfaces, AST query parser, permission-group caching, 3 backends |
| 10 | Content formatting plugins | ❌ **STILL OPEN** | No research. ContentPipeline designed in Threads, but BBCode/Markdown/Smilies plugins don't exist |
| 11 | Shared DB wrapper | ✅ **RESOLVED** | Database service: `2026-04-19-database-service/outputs/` — 4-layer architecture, `ConnectionInterface` (5 methods, lazy PDO, table prefix, driver detection), `TransactionManager`, schema management |
| 12 | Frontend strategy | ❌ **STILL OPEN** | Only Notifications has React component (`<NotificationBell>`). No overall SPA/MPA/React Islands decision |
| 13 | Configuration service | ❌ **STILL OPEN** | No research. Services reference config values but no unified mechanism |
| 14 | Moderation/Admin services | ❌ **STILL OPEN** | No research |

---

## 4. NEW Gaps Discovered

### NEW-1: ❌ CRITICAL — Authentication/Session Service Ownership Gap

**The problem**: Who designs and implements the authentication (login/logout/session/token) service?

- **User service research** (ADR-001): "Auth owns all authentication — sessions, tokens, login/logout"
- **Auth service HLD**: Only designs authorization (ACL). Explicitly says: "Authentication stays in `phpbb\user`, no `login()` / `logout()` in this service" (ADR-001) and "Token generation/refresh strategy — Token service will be designed independently"
- **User service dev spec** (pre-research): Includes `AuthenticationService`, `SessionService` in `phpbb\user`
- **User service research** (post-spec): Removes these, defers to Auth

**Result**: There's a circular deferral:
- User research says → Auth owns it
- Auth research says → it stays in `phpbb\user` (referring to the pre-research spec)
- Auth also says → token service "will be designed independently"

**Nobody has designed**:
- Session management (create, validate, destroy, remember-me)
- Token service (API token CRUD, token validation, token hashing)
- Login flow orchestration (credential check → session/token creation)
- Logout flow (session/token invalidation)

**Impact**: CRITICAL. Every authenticated request depends on this. The User service entity + password verification exist. The Auth ACL exists. But the bridging authentication layer — the thing that converts credentials into sessions/tokens — is undesigned.

---

### NEW-2: ⚠️ HIGH — Plugin ↔ Database Service Integration Undefined

The Plugin system's `SchemaEngine` (YAML→DDL for plugin tables) uses the exact same components as Database service Layer 2 (SchemaCompiler, SchemaDiffer, DdlGenerator). They were researched independently. Questions:
- Does Plugin import Database service's schema components?
- Or does Plugin have its own parallel implementation?
- The Database HLD says SchemaEngine is in `phpbb\database\schema\` — the Plugin HLD also references `phpbb\plugin\Schema\SchemaCompiler` etc.

**Impact**: Duplicate implementations would be wasteful. The integration path needs to be mapped.

---

### NEW-3: ⚠️ MEDIUM — No Testing Infrastructure Design

No service research addresses testing strategy. With 8+ domain services, all using PDO directly, there's no design for:
- Shared test database setup/teardown
- Entity factory classes for test data
- Mock event dispatcher for unit tests
- Integration test harness for cross-service calls
- API functional tests

**Pragmatic view**: Testing patterns typically emerge during implementation. But for a project this size, some shared conventions would prevent divergence.

---

## 5. Development Task Status

| Task | Status | Progress | Blocking? |
|------|--------|----------|-----------|
| **eliminate-phpbb-root-path** | ✅ Completed | Done | No |
| **phpbb-rest-api** | 🔄 In Progress | Phase 1 started, 0 phases completed | Partially — other services need the REST framework |
| **url-generation-refactor** | 🔄 In Progress | Early phase, minimal progress | No — independent refactoring |
| **user-service** | ⬜ Not Started | 0/65 files, spec+plan ready | Yes — blocks Auth, which blocks everything |

**Key observation**: The REST API is the only development task that other services depend on (it provides the HTTP framework + routing + auth middleware). It's in progress but incomplete. All other services need it to serve HTTP endpoints.

---

## 6. Research Completeness Summary

| Service | Research | HLD | ADRs | Aligned w/ Plugin System | Implementation Spec |
|---------|----------|-----|------|--------------------------|---------------------|
| Database | ✅ | ✅ | 5 ADRs | N/A (infrastructure) | ❌ No dev task |
| Cache | ✅ | ✅ | ADRs | ✅ | ❌ No dev task |
| Users | ✅ | ✅ | 8 ADRs | ✅ (decorators+events) | ✅ Dev task exists (not started) |
| Auth (ACL) | ✅ | ✅ | ADRs | ✅ | ❌ No dev task |
| Auth (AuthN) | ❌ **MISSING** | — | — | — | — |
| Hierarchy | ✅ | ✅ | ADRs | ✅ | ❌ No dev task |
| Threads | ✅ | ✅ | ADRs | ✅ | ❌ No dev task |
| Search | ✅ | ✅ | 7 ADRs | ✅ | ❌ No dev task |
| Messaging | ✅ | ✅ | ADRs | ✅ | ❌ No dev task |
| Notifications | ✅ | ✅ | ADRs | ⚠️ (tagged DI discrepancy) | ❌ No dev task |
| Storage | ✅ | ✅ | ADRs | ✅ | ❌ No dev task |
| Plugin System | ✅ | ✅ | ADRs | Self | ❌ No dev task |
| REST API | ✅ | ✅ | ADRs | N/A | 🔄 Dev task in progress |

---

## 7. Pragmatic Action Plan — What MUST Happen Before Coding Starts

### Tier 1: Blocks ALL Implementation (Must do first)

| # | Action | Effort | Resolves |
|---|--------|--------|----------|
| **A1** | **Design Authentication/Session/Token service** — Define who owns the login flow, session management, and API token lifecycle. This is the biggest gap. Write an HLD covering: credential verification → session/token creation, token type (JWT vs DB), session table management, remember-me, logout/revocation. | Medium (1 research task) | GAP-3 (JWT vs DB), NEW-1 (AuthN gap) |
| **A2** | **Write cross-cutting architecture ADR** — One document covering: token type decision, extension model (decorators vs tagged DI when), subscriber priorities, exception hierarchy, error response format, counter management pattern name, transaction coordination rules. | Small (1 document) | GAP-2 remainder, GAP-6, GAP-8 |

### Tier 2: Blocks Service Integration (Must do before cross-service work)

| # | Action | Effort | Resolves |
|---|--------|--------|----------|
| **B1** | **Add counter update methods to HierarchyServiceInterface** — `updateForumStats(int, ForumStatsDelta)`, `updateForumLastPost(int, ForumLastPostInfo)`, `recalculateForumLastPost(int)`. Define `ForumStatsDelta` and `ForumLastPostInfo` DTOs. | Tiny (interface change) | GAP-5 |
| **B2** | **Decide post_text format handling** — ADR: "ContentPipeline detects s9e XML and handles dual-format during transition; new posts stored as raw text; migration script converts on batch schedule." OR "Keep s9e XML, change rendering only." | Small (1 ADR) | GAP-7 |
| **B3** | **Map Plugin ↔ Database integration** — Clarify: does Plugin import Database service schema components? Or duplicate? One sentence in each HLD suffices. | Tiny | NEW-2 |
| **B4** | **Update User Service dev spec** — Remove `AuthenticationService` and `SessionService` from spec to match research ADR-001. | Tiny | Spec/research mismatch |

### Tier 3: Can Resolve During Implementation

| # | Action | Effort | Resolves |
|---|--------|--------|----------|
| **C1** | Define migration strategy (big bang vs incremental) | Medium | GAP-4 |
| **C2** | Research content formatting plugins (BBCode, Markdown, Smilies) | Medium | GAP-10 |
| **C3** | Define frontend strategy (SPA vs MPA vs React Islands) | Medium | GAP-12 |
| **C4** | Design configuration service | Small | GAP-13 |
| **C5** | Research moderation/admin services | Medium | GAP-14 |
| **C6** | Design shared test infrastructure | Small | NEW-3 |

---

## 8. What CAN Start Immediately

Despite the gaps, several services are self-contained enough to begin implementation:

| Service | Why It Can Start | Dependencies Satisfied? |
|---------|-----------------|------------------------|
| **Database Service** | Pure infrastructure, no domain dependencies | ✅ Fully self-contained |
| **Cache Service** | Pure infrastructure, no domain dependencies | ✅ Fully self-contained |
| **User Service** (data layer only) | Entities, DTOs, repositories, data services — no AuthN | ✅ With updated spec (remove AuthN) |
| **Hierarchy Service** | Reuses legacy tables, no upstream deps | ✅ After adding counter API (B1) |
| **Storage Service** | Independent, new tables, no upstream deps | ✅ Fully self-contained |

**Cannot start yet**:
- Auth (AuthZ) — needs User entity implemented
- Threads — needs Hierarchy counter API resolved (B1)
- Search — needs Threads events defined (implementation dependency)
- Messaging — needs Storage first
- Notifications — needs everything else (last in dependency chain)
- Plugin System — needs Database service schema components

---

## 9. Deployment Decision

### ⚠️ Issues Found — Conditional GO

**For starting implementation of individual services**: **GO** — Database, Cache, Users (data), Hierarchy, Storage can begin immediately.

**For full-stack integration**: **NO-GO** until A1 (AuthN service design) and A2 (cross-cutting ADR) are complete.

**For production deployment**: **NO-GO** until Tier 1 + Tier 2 items are resolved and migration strategy (C1) is defined.

**Honest assessment**: The research phase has been thorough and high-quality. 12 of 14 research tasks have complete, detailed outputs. The remaining gap is not in individual service design — it's in the **connective tissue**: how services authenticate requests, how they coordinate transactions, and how the extension model works consistently. Fixing this requires ~2 focused research sessions (A1 + A2), not a fundamental rethink.

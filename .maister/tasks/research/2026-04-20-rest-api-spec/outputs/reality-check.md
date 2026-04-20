# Reality Check: REST API Documentation Sufficiency
## Is the existing documentation sufficient for AI models to implement the REST API?

**Date**: 2026-04-20  
**Assessor**: reality-assessor agent  
**Scope**: `openapi.yaml` + all supporting HLD/research docs under `.maister/tasks/research/`  
**Question**: Would an AI agent be able to implement a working, production-grade phpBB API service using only the available docs, without asking clarifying questions?

---

## Overall Status

⚠️ **PARTIALLY SUFFICIENT**

The documentation corpus is **unusually thorough** for an architecture research phase — better than most enterprise projects ever document. The OpenAPI spec + service HLDs provide enough context to implement the **happy path of every service**. However, an AI agent implementing directly from this material would hit a predictable set of blockers in production edge cases, permission enforcement, and admin/moderation workflows. Approximately **75–80% of the API surface is implementable without clarification**; the remaining 20–25% requires either assumptions or follow-up.

---

## Docs Inventory Assessment

| Document | Coverage | Quality |
|----------|----------|---------|
| `openapi.yaml` | All 8 service domains, ~55 endpoints | ✅ Good schema definitions; gaps in permission annotation |
| Auth HLD (`2026-04-19-auth-unified-service/`) | JWT lifecycle, refresh, elevation, SSO | ✅ Excellent — most complete HLD in the corpus |
| Hierarchy HLD (`2026-04-18-hierarchy-service/`) | Nested set, 5 services, events | ✅ Strong — entity model, DB schema, algo clear |
| Threads HLD (`2026-04-18-threads-service/`) | Topics, posts, visibility, counters | ✅ Strong — event model and counter patterns clear |
| Users HLD (`2026-04-19-users-service/`) | Registration, profile, groups, bans | ✅ Good — JSON column decisions, delete modes clear |
| Messaging HLD (`2026-04-19-messaging-service/`) | Conversations, messages, rules | ✅ Good — DB schema, participant model clear |
| Notifications HLD (`2026-04-19-notifications-service/`) | Polling, registry, cache | ✅ Good — polling strategy and cache TTL clear |
| Search HLD (`2026-04-19-search-service/`) | AST parser, 3 backends, caching | ✅ Very detailed — ISP interfaces, permission hash |
| Storage HLD (`2026-04-19-storage-service/`) | Flysystem, quotas, orphans, serving | ✅ Strong — UUID v7, hybrid serving strategy clear |
| `services-architecture.md` | Implementation order, shared patterns | ✅ Good alignment reference |
| `cross-cutting-decisions-plan.md` | 13 resolved patterns | ✅ Resolves most previously divergent decisions |
| `standards/backend/STANDARDS.md` | PHP 8.3, DI, PDO, enums | ✅ Precise coding standards |
| `standards/backend/REST_API.md` | Response shapes, HTTP codes, JWT | ⚠️ **Conflicts with OpenAPI spec** (see Gap #1) |
| `standards/backend/COUNTER_PATTERN.md` | Tiered hot/cold/recalc | ✅ Clear pattern |
| `standards/backend/DOMAIN_EVENTS.md` | DomainEventCollection, naming | ✅ Clear pattern |

---

## Service-by-Service Assessment

### 1. Auth Service — login, SSO, elevate, refresh, logout

**Claim**: Complete.

**Reality**: ✅ **Implementable** — the Auth HLD is the most thorough document in the corpus.  
The JWT structure, key derivation, refresh token family rotation, and all 4 flows (login, refresh, elevation, logout) have full sequence diagrams with exact error tables.

**Gaps**:
- **Rate limiting thresholds not specified**: The HLD says "rate limit check (IP + user throttle)" but never states the thresholds (e.g., 5 attempts per 15 minutes). An AI agent must invent these values.
- **SSO PKCE state storage not specified**: `GET /auth/sso/{provider}/authorize` returns a `state` parameter for CSRF. Where is this state stored between the authorize call and the callback? (Redis? DB? Symfony session? Not documented.)
- **`phpbb_sso_connections` table schema missing**: The SSO callback links a provider account to a phpBB user, but no DB table schema exists for storing OAuth provider tokens/IDs.
- **`ElevateRequest` schema structural ambiguity**: In `openapi.yaml` the `scopes` field is declared at the parent level but `required: [scopes]` while `oneOf` contains `PasswordElevation` and `SsoElevation`. JSON Schema `oneOf` semantics here are ambiguous — a validator could reject requests that intend to work.
- **REST_API.md JWT payload mismatch**: The standards file defines `{ user_id, username, admin, iat, exp }` while Auth HLD defines `{ sub, aud, gen, pv, utype, flags, kid }`. These contradict each other; a controller implementing `$request->attributes->get('_api_token')` will find different fields depending on which doc it follows.

---

### 2. Forum / Hierarchy Service

**Claim**: Complete.

**Reality**: ✅ **Happy path implementable**; permission gates require inference.

**Gaps**:
- **Permission names per endpoint not formalized**: OpenAPI says `description: Requires admin permission` but does not specify which `a_*` flag is required (`a_forum`? `a_edit`?). An AI agent must guess the phpBB ACL permission name.
- **`GET /forums` ACL filtering not documented**: The Hierarchy HLD explicitly says "Hierarchy is **ACL-unaware**; the display/API layer applies permission filters." The OpenAPI spec does not document that `GET /forums` silently omits forums the user cannot `f_list`. An AI agent implementing this endpoint would either omit or include the filtering step based purely on a guess.
- **Forum password authentication flow missing**: The `Forum` schema has `hasPassword: boolean`. The OpenAPI spec defines no endpoint/flow for a user to submit a forum password. The HLD does not address this either.
- **`DELETE /forums/{forumId}` cascades undocumented**: The endpoint has a `moveContentTo` parameter. What happens if `moveContentTo` is omitted? Is there a hard delete? Are subforums recursively deleted? Not specified.
- **Forum prune settings not in OpenAPI**: The Hierarchy HLD `Forum` entity has `ForumPruneSettings` but `CreateForumRequest`/`UpdateForumRequest` schemas in OpenAPI do not expose prune configuration.

---

### 3. Topics & Posts Service

**Claim**: Complete.

**Reality**: ✅ **Core CRUD implementable**; moderation and approval flows require inference.

**Gaps**:
- **Post editing policy not documented**: The OpenAPI `PATCH /posts/{postId}` description says "Edit post" and returns 403 "Cannot edit this post" — but no rule specifies *who* can edit: the owner? only within a time window? moderators? what permission? The Threads HLD says `m_edit` for moderators but doesn't specify the rule for the post author.
- **Visibility state transition rules missing**: `Topic.visibility` and `Post.visibility` enum values `[0,1,2,3]` are described as `Unapproved, Approved, Deleted, Reapprove` but the rules for *who* can trigger each transition are not in the OpenAPI spec. The `VisibilityService` 4-state machine exists in the HLD but there is no corresponding API endpoint for moderators to approve/unapprove posts.
- **Missing post approval endpoint**: There is no `POST /posts/{postId}/approve` or `POST /topics/{topicId}/approve` in the OpenAPI spec. Moderation approval is a core phpBB workflow; without it no moderation queue is possible.
- **`f_noapprove` pass-through not specified in spec**: The Threads HLD says `f_noapprove` is "passed as parameter for initial visibility" but OpenAPI `CreateTopicRequest` and `CreatePostRequest` have no such field. An AI agent cannot know the forum-level configuration affecting whether new posts start as approved or unapproved.
- **Topic type permissions missing**: Who can create sticky (`type: 1`), announce (`type: 2`), global announce (`type: 3`)? Not documented. An AI agent would either permit everyone or no-one.
- **Draft management API missing**: The Threads HLD describes a `DraftService` but there are no draft endpoints in the OpenAPI spec (`GET/POST/DELETE /drafts`, etc.).
- **Soft-delete vs hard-delete policy**: `DELETE /topics/{topicId}` says "soft-delete" — but the Threads HLD mentions hard deletes too. No API endpoint for hard deletion or restoration.
- **`POST /posts/{postId}/report` missing**: Post reporting exists in the Threads HLD (referenced by `hasReports: boolean`) but no report submission endpoint exists in the spec.

---

### 4. Users & Groups Service

**Claim**: Complete.

**Reality**: ✅ **Registration and public profiles implementable**; admin and moderation operations are absent.

**Gaps**:
- **User ban endpoint missing**: There is no `POST /users/{userId}/ban`, `DELETE /users/{userId}/ban/`, or `GET /users/{userId}/bans` in the OpenAPI spec. The Users HLD has a complete `BanService` with `BanType` (User/IP/Email). An AI cannot implement a production-grade user service without ban management.
- **User deletion (3 modes) missing**: The HLD defines `DeleteMode` (retain/remove/soft) as a critical design decision, but `DELETE /users/{userId}` is not in the OpenAPI spec at all.
- **User type/activation management missing**: No endpoint to activate a user by key (email activation), change user type (regular→founder), or deactivate a user.
- **Shadow ban not in API**: The Users HLD dedicates significant space to shadow bans. No endpoint exists for shadow ban management.
- **Group CRUD incomplete**: `POST /groups` (create group) and `PATCH /groups/{groupId}` (update group) and `DELETE /groups/{groupId}` are absent. Only list and member management are present.
- **`GET /users` email filter permission gap**: The spec says `email` filter is "Admin only" in the description, but no 403 response is documented if a non-admin uses it. An AI agent would implement it without enforcement.
- **`/me/groups` missing**: No endpoint for the current user to list their own group memberships, set default group, or leave a group.
- **Username validation rules not enumerated**: The `RegisterRequest` schema validates `minLength: 3, maxLength: 255` but phpBB has complex username rules (forbidden characters, reserved usernames, similarity checks). The Users HLD does not enumerate these rules.

---

### 5. Messaging Service

**Claim**: Complete.

**Reality**: ✅ **Core send/receive/list implementable**; message editing and participant management are absent.

**Gaps**:
- **Message editing endpoint missing**: `PATCH /conversations/{conversationId}/messages/{messageId}` does not exist in the OpenAPI spec. The Messaging HLD designs a `MessageService.edit()` with a configurable time window — but there is no API surface for it.
- **Message deletion missing**: `DELETE /conversations/{conversationId}/messages/{messageId}` is absent. The Messaging HLD has per-participant delete capability.
- **Participant management missing**: No endpoint to add (`POST /conversations/{conversationId}/participants`) or remove (`DELETE /conversations/{conversationId}/participants/{userId}`) participants from a group conversation. The Messaging HLD has full `ParticipantService`.
- **Conversation title update missing**: `PATCH /conversations/{conversationId}` does not exist. The HLD has `title VARCHAR(255) DEFAULT NULL` and design decision for group conversation titles.
- **PM limits not in spec**: The Messaging HLD discusses rule evaluation and quotas but no spec documents the business rules (e.g., max recipients, message length, per-day quota). An AI cannot implement rule enforcement without these values.

---

### 6. Notifications Service

**Claim**: Complete.

**Reality**: ✅ **Polling and count endpoint implementable**; notification *types* are opaque.

**Gaps**:
- **Notification type catalog not defined**: `Notification.type` is `{ type: string }` with no enum and no documentation of valid values. The Notifications HLD says types are registered via `RegisterNotificationTypesEvent` (event-based discovery) — but never enumerates them. An AI agent cannot implement rendering (`display` object) or notification creation triggers without knowing what types exist (e.g., `post.reply`, `topic.subscribe`, `pm.received`).
- **`display` object structure not defined**: `Notification.display: { type: object, description: "Type-specific rendering data" }` — an `additionalProperties: true` blob. An AI agent implementing a frontend component needs to know the shape. The HLD does not define it.
- **Notification subscription management missing**: No endpoint to subscribe/unsubscribe from a topic or forum. The `phpbb_user_notifs` table (subscription prefs) has no corresponding API surface.
- **Missing endpoint for deleting notifications**: There is no `DELETE /notifications/{notificationId}` or bulk delete. The HLD's `NotificationService.deleteNotifications()` has no API exposure.

---

### 7. Search Service

**Claim**: Complete.

**Reality**: ✅ **Single-endpoint search implementable**; backend selection and permission behavior need inference.

**Gaps**:
- **Backend selection not in spec**: `GET /search` has no `backend` parameter. The Search HLD supports Native, MySQL FULLTEXT, and PostgreSQL GIN backends with different feature sets. An AI agent would implement with a hardcoded default backend.
- **Result type discriminator missing**: `SearchResult.data` is `oneOf [Topic, Post]` without a discriminator field. JSON Schema `oneOf` without a `discriminator` is ambiguous; deserializing on the client side requires guessing which type it is.
- **Permission-transparent behavior undocumented**: The Search HLD specifies that `SearchOrchestrator` calls `getGrantedForums($user, 'f_read')` for pre-filtering. The OpenAPI spec never documents that results are automatically ACL-filtered and that `forumId` parameter would silently return 0 results if the user has no access (rather than 403).
- **Minimum query length documented inconsistently**: `q` parameter has `minLength: 3` — correct. But the legacy phpBB also enforces a minimum word length per search backend. This is not documented.

---

### 8. Storage / Files Service

**Claim**: Complete.

**Reality**: ✅ **Upload/download flow implementable**; quotas, MIME types, and private file access require inference.

**Gaps**:
- **File size limits per `assetType` not specified**: The `POST /files` returns 413 but doesn't document what the limit is for `attachment` vs `avatar` vs `export`. An AI must guess.
- **Allowed MIME types not enumerated**: The `POST /files` returns 415 but doesn't list which MIME types are allowed for each `assetType`. An AI would implement a permissive uploadHandler.
- **Private file access authorization undocumented**: `GET /files/{fileId}/download` returns binary data but doesn't specify when the file is private vs public, or what `Authorization` header enforcement applies. The Storage HLD specifies X-Accel-Redirect but this implementation detail is absent from the spec.
- **Variant/thumbnail status missing**: `StoredFile.thumbnailUrl` can be null (thumbnail not yet generated). There is no endpoint or mechanism to poll when a thumbnail becomes available after async generation.
- **Orphan claim flow not in API**: The Storage HLD has `claim()` as a key operation (called after a post is created to adopt uploaded files). This is an internal operation, but the API needs a hook for the client to signal that a file is being used. No `POST /files/{fileId}/claim` endpoint exists.

---

## Cross-Cutting Gaps

These gaps affect all services simultaneously and would cause an AI agent to make inconsistent implementation choices:

### Gap #1: Response Envelope Convention Conflict (CRITICAL)

**Claim**: OpenAPI and `REST_API.md` both define response shape.

**Reality**: They conflict.

`standards/backend/REST_API.md` specifies:
```json
// Collection:
{ "forums": [...], "total": 3 }
// Single:
{ "topic": { "id": 1 } }
```

`openapi.yaml` uses universally:
```json
{ "data": [...], "meta": { "total": ..., "page": ..., "perPage": ..., "lastPage": ... } }
```

An AI implementing the standards doc would produce `{ "forums": [...] }`. An AI following the OpenAPI spec would produce `{ "data": [...] }`. These are incompatible. The OpenAPI spec is likely the intended canonical shape (it was written after REST_API.md), but the conflict is a trap.

### Gap #2: JWT Payload Conflict (CRITICAL)

**Claim**: Auth documentation defines JWT structure.

**Reality**: Two incompatible JWT payload structures coexist.

`standards/backend/REST_API.md` shows:
```json
{ "user_id": 2, "username": "admin", "admin": true, "iat": 1700000000, "exp": 1700003600 }
```

Auth HLD shows:
```json
{ "iss": "phpbb", "sub": 42, "aud": "phpbb-api", "gen": 3, "pv": 17, "utype": 0, "flags": "...", "kid": "access-v1" }
```

The REST_API.md version is a simplified legacy draft. The Auth HLD version is the authoritative design. Any controller code generated from REST_API.md would call `$token->user_id` instead of `$token->sub` and would miss `gen`, `pv`, `flags` entirely. An AI without awareness of which doc supersedes the other would implement this incorrectly.

### Gap #3: Per-Endpoint Permission Requirements Not Formalized

The OpenAPI spec occasionally says "Requires admin permission" in `description` text, but there is no formalized machine-readable per-endpoint permission requirement (e.g., an `x-permission: a_forum` vendor extension). For 80% of endpoints, no permission requirement is stated at all. An AI implementing controllers would have to:
1. Infer permission names from the phpBB ACL naming convention (`f_post`, `m_edit`, `a_forum`, etc.)
2. Guess which endpoints require what (e.g., who can create a group? who can list banned users?)

The Auth HLD defines the bitfield positions and names (`a_*`, `m_*`, `u_*`) but does not enumerate which API endpoint maps to which permission flag.

### Gap #4: Admin (ACP) and Moderation (MCP) Endpoints Entirely Missing

The OpenAPI spec has **no admin control panel endpoints** and **no moderation control panel endpoints**. The entire ACP surface (board configuration, user management from admin perspective, extension management, permission matrix management) is absent. This represents roughly 30–40% of a production phpBB surface.

Missing endpoint categories:
- `GET/POST /admin/users/{userId}/ban` — ban management
- `GET/POST /admin/users/{userId}/type` — change user type to founder/admin/bot
- `POST /admin/users/{userId}/delete` — delete user with mode selection
- `POST/DELETE /admin/forums/{forumId}/permissions/{groupId}` — ACL matrix management
- `GET/POST /admin/config` — board configuration
- `POST /admin/search/rebuild` — search index administration (`IndexAdminInterface`)
- `POST /topics/{topicId}/posts/{postId}/approve` — moderation approval
- `POST /topics/{topicId}/split` — topic split
- `POST /topics/{topicId}/merge` — topic merge
- `POST /topics/{topicId}/posts/{postId}/report` — post reporting

### Gap #5: `phpbb\common` Package Interfaces Not Yet Written

The `cross-cutting-decisions-plan.md` Decision #4 specifies a `phpbb\common` package with `NotFoundException`, `AccessDeniedException`, `ValidationException`, etc. These are referenced by all HLDs for exception-to-HTTP mapping. This package exists only as a spec in the decisions plan — no source code and no concrete interface implementations exist. An AI implementing any service would need to create these first, but the exact method signatures are not specified.

### Gap #6: No CORS or Rate-Limit Response Headers Documented

None of the OpenAPI endpoints document response headers for:
- CORS (`Access-Control-Allow-Origin`)
- Rate limiting (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`)
- Caching (`Cache-Control`, `ETag`, `Last-Modified`) — except partially on `GET /notifications/count`

The Auth HLD mentions rate limiting being enforced but gives no threshold values.

---

## Reality vs Claims Summary

| Dimension | Claimed State | Actual State | Gap |
|-----------|--------------|--------------|-----|
| OpenAPI spec coverage | All services covered | ✅ Correct | — |
| Auth service | Complete | ✅ 90% implementable | SSO state storage, rate limit thresholds |
| Forum hierarchy | Complete | ✅ 85% implementable | Permission names, forum password flow |
| Topics & Posts | Complete | ⚠️ 70% implementable | Approval workflow, moderation endpoints, drafts |
| Users & Groups | Complete | ⚠️ 65% implementable | Ban management, deletion, admin operations |
| Messaging | Complete | ⚠️ 75% implementable | Edit/delete messages, participant management |
| Notifications | Complete | ⚠️ 70% implementable | Type catalog, subscription management |
| Search | Complete | ✅ 80% implementable | Backend selection, discriminator |
| Storage | Complete | ✅ 80% implementable | MIME types, file size limits, orphan API |
| ACP/MCP surface | Not documented | ❌ 0% implementable | Entire surface missing |
| Cross-cutting consistency | Resolved (D-plan) | ⚠️ 2 critical conflicts | JWT payload, response envelope |

---

## Gap Severity Classification

### Critical (blocks production-grade implementation)

| # | Gap | Impact |
|---|-----|--------|
| C1 | JWT payload conflict between `REST_API.md` and Auth HLD | Every controller would extract wrong claims → auth breaks |
| C2 | Response envelope conflict between `REST_API.md` and OpenAPI | Frontend incompatibility; inconsistent APIs |
| C3 | No post/topic approval/unapproval endpoints | Moderation queue unusable; unmoderated forums broke |
| C4 | No user ban management endpoints | Core safety feature missing |
| C5 | Permission requirements not formal per endpoint | AI would implement with no ACL enforcement on 80% of endpoints |

### High (production-relevant missing functionality)

| # | Gap | Impact |
|---|-----|--------|
| H1 | SSO state (PKCE) storage mechanism not specified | SSO login vulnerable to state injection or incorrect |
| H2 | Forum password flow not specified | Password-protected forums inaccessible |
| H3 | Draft management endpoints missing | DraftService designed but zero API exposure |
| H4 | Message edit/delete endpoints missing | Messaging UX broken; edit window feature unusable |
| H5 | Notification type catalog not defined | Bell badge renders nothing; type-checking impossible |
| H6 | Admin/ACP endpoints entirely absent | No admin panel possible |
| H7 | Topic type permission rules missing | Any user could create global announcements |
| H8 | `phpbb\common` exception interfaces not written | No shared error handling; each service invents its own |

### Medium (quality or edge-case gaps)

| # | Gap | Impact |
|---|-----|--------|
| M1 | File size / MIME type limits not documented | Upload validation either too strict or too permissive |
| M2 | Post editing policy (who/when) not specified | Inconsistent UX; AI would hardcode an arbitrary rule |
| M3 | Search result `oneOf` without discriminator | Client deserialization ambiguous |
| M4 | `display` object in Notification unspecified | Frontend rendering component cannot be implemented |
| M5 | Group CRUD (create/update/delete) missing | Group management only partially possible |
| M6 | `DELETE /users/{userId}` missing | No user deletion from API |
| M7 | Participant management (add/remove) missing | Group conversations limited to initial participants |
| M8 | Forum ACL filter behavior undocumented | `GET /forums` behavior depends on whether AI adds filter |
| M9 | Rate limiting thresholds never specified | AI would hardcode guessed values |
| M10 | Storage orphan claim endpoint missing | Premature orphan cleanup could delete in-use files |

### Low (minor polish items)

| # | Gap | Impact |
|---|-----|--------|
| L1 | No rate-limit response headers defined | SPA cannot implement backoff |
| L2 | No CORS documentation | Server-to-server calls may fail without CORS config |
| L3 | Username validation rules not enumerated | Registration accepts invalid usernames |
| L4 | Forum prune settings not in OpenAPI | Admin cannot configure auto-pruning via API |
| L5 | `POST /files/{fileId}/claim` not documented | Orphan detection requires manual implementation |

---

## Pragmatic Action Plan

For each critical/high gap, here is the minimum addition needed to unblock AI implementation:

### Priority 1: Fix Documentation Conflicts (C1, C2)

**C1 — JWT payload**: Add a single note to `REST_API.md` deprecating its JWT example and pointing to `2026-04-19-auth-unified-service/outputs/high-level-design.md` as canonical. Estimated effort: 5 minutes.

**C2 — Response envelope**: Update `REST_API.md` to adopt the `{ "data": [...], "meta": {...} }` convention documented in `openapi.yaml`. Or add a note in OpenAPI info section stating it supersedes REST_API.md conventions. Estimated effort: 30 minutes.

### Priority 2: Add Missing Core Endpoints to OpenAPI (C3, C4, H2–H7)

The following endpoint groups need OpenAPI specification entries added:

1. **Post/Topic moderation**: `POST /posts/{postId}/approve`, `POST /posts/{postId}/restore`, `POST /topics/{topicId}/approve`
2. **User bans**: `POST /admin/users/{userId}/bans`, `DELETE /admin/users/{userId}/bans/{banId}`, `GET /users/{userId}/bans`
3. **Message edit/delete**: `PATCH /conversations/{conversationId}/messages/{messageId}`, `DELETE /conversations/{conversationId}/messages/{messageId}`
4. **Participant management**: `POST /conversations/{conversationId}/participants`, `DELETE /conversations/{conversationId}/participants/{userId}`
5. **Draft management**: `GET/POST /drafts`, `GET/PATCH/DELETE /drafts/{draftId}`
6. **Forum forum-password auth**: `POST /forums/{forumId}/authenticate`
7. **Topic type permission documentation**: Add `x-required-permission` annotations to endpoints

Estimated effort per group: 1–3 hours each.

### Priority 3: Document Permission Requirements Per Endpoint (C5)

Add a `x-permission` vendor extension to each protected endpoint in `openapi.yaml`. Example:
```yaml
post:
  x-permission: f_post
  description: Create new topic. Requires f_post permission in the forum.
```

The Auth HLD already enumerates all permission names and bitfield positions. This is a mapping exercise.

Estimated effort: 4–6 hours for the full spec.

### Priority 4: Define Notification Type Catalog (H5)

Add a new section to the Notifications HLD (or OpenAPI spec) enumerating all built-in notification type strings and their `display` object shape. Example:
```yaml
# In openapi.yaml components/schemas/NotificationDisplay
oneOf:
  - title: PostReply
    properties:
      topicTitle: { type: string }
      forumName: { type: string }
  - title: PrivateMessage
    properties:
      senderName: { type: string }
```

Estimated effort: 2–3 hours.

### Priority 5: Specify SSO State Storage (H1)

Add a "SSO PKCE State Management" section to the Auth HLD specifying:
- Storage mechanism (Symfony Cache with 10-minute TTL recommended)
- State key format
- Replay protection

Estimated effort: 1–2 hours.

---

## Deployment Decision

**NO-GO** for direct AI implementation without addressing Critical gaps.

**GO conditions**:
- C1 + C2 resolved (documentation conflicts fixed — 1 hour total)
- C5 resolved or AI agent is instructed to infer permissions from phpBB ACL naming convention
- C3 + C4 explicitly scoped out ("phase 2: moderation + admin") and documented as known omissions

**Realistic assessment**: With C1/C2 fixed and C3/C4/C5 explicitly scoped as Phase 2, an AI agent could implement a **working Phase 1 API** covering: auth (login/SSO/refresh), forum browsing, topic/post CRUD (happy path), user profiles, messaging (send/receive), notifications (polling), search, and file uploads. That is the core forum-reading and forum-participating surface.

A **production-grade full-feature phpBB API** (including moderation, admin panel, ban management, draft system) requires the High-priority gaps to also be addressed.

---

## Functional Completeness Estimate

| Feature Area | Completeness for AI Implementation |
|-------------|-----------------------------------|
| Auth service | 85% |
| Forum listing/browsing | 80% |
| Topic/post reading | 90% |
| Topic/post creation (happy path) | 85% |
| Topic/post moderation | 15% |
| User registration & profiles | 80% |
| User administration (ban, delete) | 5% |
| Messaging (send/read) | 75% |
| Notifications (poll/list) | 75% |
| Search | 80% |
| File storage (upload/download) | 75% |
| Admin panel (ACP) | 0% |
| **Overall** | **~65%** |

The corpus is well above average for AI-assisted implementation. The missing 35% is concentrated in moderation, administration, and advanced interactive features — all explicitly out of scope for the research phase but now needed for production-grade work.

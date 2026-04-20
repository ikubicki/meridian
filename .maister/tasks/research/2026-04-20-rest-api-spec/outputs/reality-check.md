# Reality Check: REST API Specification Sufficiency for AI Implementation
## Can an AI coding agent produce a correct, secure, working implementation from this spec alone?

**Date**: 2026-04-20 (v2 — reassessment after openapi.yaml update)  
**Assessor**: reality-assessor agent  
**Primary Artifact**: `openapi.yaml` (2 905 lines)  
**Question**: Would Claude Sonnet 4.6 receive only `openapi.yaml` + the project coding standards and produce a correct, secure PHP/Symfony backend?

---

## Overall Status

⚠️ **GAPS FOUND — Substantially improved; 3 blocking bugs remain**

The `openapi.yaml` has been substantially revised since the previous check. All five previously-critical gaps and most high-priority gaps have been addressed. The spec now covers the full API surface — including moderation, admin operations, ban management, draft management, participant management, group CRUD, board config, and search administration. Permission annotations (`x-permission`) are now on every protected endpoint. The previous ~65% implementability estimate has risen to **approximately 87%**.

Three blocking bugs remain that would cause an AI coding agent to generate **wrong code** — not just incomplete code — even from a fully context-aware session. These must be fixed before handing the spec to an AI for implementation.

---

## What Changed Since Previous Check

The following gaps from the v1 check have been **resolved** in the updated spec:

| Previous Gap | Status | Evidence |
|---|---|---|
| C3: No post/topic approval endpoints | ✅ Resolved | `POST /topics/{topicId}/approve`, `POST /posts/{postId}/approve`, `/restore` variants |
| C4: No user ban management | ✅ Resolved | `/bans`, `/bans/{banId}`, `/users/{userId}/bans`, `/users/{userId}/bans/{banId}` |
| C5: Permission requirements informal | ✅ Resolved | `x-permission` on every protected endpoint; global permission table in info block |
| H1: SSO PKCE state storage | ✅ Resolved | Description: "stores state in cache (10-min TTL)" |
| H2: Forum password flow | ✅ Resolved | `X-Forum-Password` header documented on `/forums/{forumId}`, `/topics`, `/posts` |
| H3: Draft endpoints missing | ✅ Resolved | Full CRUD: `/drafts`, `/drafts/{draftId}` |
| H4: Message edit/delete missing | ✅ Resolved | `PATCH`/`DELETE /conversations/{cId}/messages/{mId}` |
| H6: ACP endpoints absent | ✅ Resolved | User type/delete, ban, group CRUD, forum permissions, `/config` |
| H7: Topic type permission undocumented | ✅ Resolved | `f_sticky`, `m_announce`, `a_announce` documented in `POST /forums/{id}/topics` description |
| M3: Search discriminator missing | ✅ Resolved | `discriminator: { propertyName: _type, mapping: { topic/post } }` added |
| M5: Group CRUD incomplete | ✅ Resolved | `POST/PATCH/DELETE /groups/{groupId}` added |
| M6: User delete missing | ✅ Resolved | `POST /users/{userId}/delete` with `mode` enum |
| M7: Participant management missing | ✅ Resolved | `POST/DELETE /conversations/{cId}/participants/{userId}` |
| M8: Forum ACL filter undocumented | ✅ Resolved | Description: "Returns forum tree filtered by f_list ACL" |
| Post reporting missing | ✅ Resolved | `POST /posts/{postId}/report` |
| Topic split/merge missing | ✅ Resolved | `POST /topics/{topicId}/split`, `/merge` |

New endpoints also added not previously tracked: `/groups/{groupId}/members`, `/messaging/unread`, `/users/check-username`, `/users/check-email`, `/password-reset`, `/password-reset/confirm`, `/search/rebuild`, `/health`, conversation pin/archive/mute endpoints.

---

## Blocking Bugs (Would Cause Wrong Code)

### BUG-1 (Critical): `_type` Discriminator Field Missing from Schemas

**Location**: `components/schemas/SearchResult.data.items.discriminator`

**Claim**: The `SearchResult` uses a discriminator to distinguish Topics from Posts:
```yaml
discriminator:
  propertyName: _type
  mapping:
    topic: '#/components/schemas/Topic'
    post: '#/components/schemas/Post'
```

**Reality**: Neither `Topic` nor `Post` schema defines a `_type` property. JSON Schema discriminators only work if the referenced schemas actually include the discriminator property. The discriminator is currently inert — it has no effect because neither schema has the field labeled `_type`.

**Impact**: An AI implementing the API controller would serialize `Topic` and `Post` objects without a `_type` field. The client consuming the search results has no reliable way to determine whether each result is a topic or post. An AI implementing the SPA consuming layer would emit broken deserialization code.

**Fix required**: Add `_type` to `Topic` and `Post` schemas as a required constant:
```yaml
# In Topic schema:
_type: { type: string, enum: [topic], description: Discriminator for polymorphic search results }
# In Post schema:
_type: { type: string, enum: [post], description: Discriminator for polymorphic search results }
```

---

### BUG-2 (Critical): Response Envelope Conflicts with `REST_API.md` Standard

**Location**: `standards/backend/REST_API.md` vs `openapi.yaml`

**Claim**: The project has a defined response shape convention.

**Reality**: Two contradictory conventions coexist.

`standards/backend/REST_API.md` (which an AI will read as a coding standard) specifies:
```json
// Collection:
{ "forums": [...], "total": 3 }
// Single resource:
{ "topic": { "id": 1, "title": "..." } }
```

`openapi.yaml` uses universally:
```json
{ "data": [...], "meta": { "total": ..., "page": ..., "perPage": ..., "lastPage": ... } }
{ "data": { "id": 1 } }
```

An AI following the coding standard (as instructed) would produce `{ "forums": [...] }`. The spec expects `{ "data": [...] }`. These are incompatible response envelopes that would cause frontend breakage.

The REST_API.md is the older draft; `openapi.yaml` is the intended canonical shape. But the REST_API.md file has not been updated and is still authoritative from a standards-loading perspective.

**Fix required**: Update `REST_API.md` with a one-sentence deprecation note on the response shape section redirecting to `openapi.yaml` as the authoritative response envelope standard.

---

### BUG-3 (Critical): JWT Payload Conflict

**Location**: `standards/backend/REST_API.md` vs Auth HLD

**Claim**: JWT claims are defined.

**Reality**: Two incompatible JWT payload structures coexist.

`standards/backend/REST_API.md` defines:
```json
{ "user_id": 2, "username": "admin", "admin": true, "iat": 1700000000, "exp": 1700003600 }
```
And maps this directly to `$request->attributes->get('_api_token')` with `user_id` access.

Auth HLD defines:
```json
{ "iss": "phpbb", "sub": 42, "aud": "phpbb-api", "gen": 3, "pv": 17, "utype": 0, "flags": "...", "kid": "access-v1" }
```

Any controller generated from `REST_API.md` would call `$token->user_id` and `$token->admin`. Any controller generated from the Auth HLD would call `$token->sub` and check `$token->utype`. These produce broken auth in every controller. The `gen` and `pv` fields for refresh token family invalidation would be entirely missing.

This conflict is the single highest-risk failure mode. An AI without explicit instruction on which to follow will pick one and be wrong 50% of the time.

**Fix required**: Add a deprecation note to `REST_API.md`'s JWT section pointing to the Auth HLD as canonical. Alternatively, add the authoritative JWT claim structure to the `openapi.yaml` info section as a code block.

---

## High-Priority Gaps (Would Produce Incomplete Implementation)

### H1: Notification Type Catalog Undefined

`Notification.type` is `{ type: string }` with no enum. `Notification.display` is `{ type: object, description: "Type-specific rendering data" }` — an opaque blob. An AI implementing the notification rendering component has no information about what type strings exist or what shape `display` takes for each type.

**Impact**: Bell badge renders placeholder; no type-specific rendering possible. Notification subscription management (subscribe to topic/forum) also has no API surface.

**Fix**: Add `x-notification-types` vendor extension or a standalone schema section enumerating built-in types (`post.reply`, `topic.subscribe`, `pm.received`, etc.) with their `display` shape per type.

### H2: `a_group_leader` Permission Not in Global Permission Table

`PATCH /groups/{groupId}` and `POST /groups/{groupId}/members` use `x-permission: OR:a_group,a_group_leader`. However `a_group_leader` is not documented in the permission table in the spec's `info.description`.

**Impact**: An AI building the permission validator does not know `a_group_leader` is a valid flag; it may treat it as a typo and omit the OR branch.

### H3: `a_manage_files` and `a_viewprofile` Not in Permission Table

- `DELETE /files/{fileId}` description: "admins with `a_manage_files` may override" — but `a_manage_files` is absent from the permission table.
- `GET /users` description: "email filter requires `a_viewprofile`" — but the documented admin permission is `a_user`, not `a_viewprofile`.

**Impact**: Inconsistent permission enforcement; AI would either omit the admin override for file deletion or apply the wrong permission name.

### H4: `/files/{fileId}/download` Needs Explicit `security: []` Override

The endpoint description says "For public files, security: [] (anonymous access allowed)" — but the endpoint path definition does not include `security: []` to override the global `security: [bearerAuth: []]`. The description and the actual OpenAPI security semantics contradict each other.

**Impact**: An AI generating a Symfony firewall config or `security: []` annotation would apply authentication to all file downloads, breaking anonymous attachment viewing in forums.

**Fix**: Add `security: []` directly on the `GET /files/{fileId}/download` operation.

---

## Medium Gaps (Quality / Edge Cases)

| # | Gap | Impact |
|---|-----|--------|
| M1 | File size / MIME type limits not specified (413/415 without thresholds) | AI hardcodes arbitrary limits or omits enforcement |
| M2 | `display` object in Notification completely opaque | Frontend render component cannot be implemented |
| M3 | Rate limiting thresholds not specified anywhere | AI invents values; inconsistent throttle across services |
| M4 | `/me/groups` endpoint missing | User cannot list own group memberships or set default group |
| M5 | Email activation flow missing — `POST /users/activate` does not exist | Users with `type=1` (Inactive) cannot activate via API |
| M6 | Orphan file claim mechanism undocumented | Upload says "associate with post/topic" but no `POST /files/{id}/claim` endpoint |
| M7 | `ElevateRequest.oneOf` semantic ambiguity | `scopes` at parent + `oneOf` sub-schemas may confuse strict JSON Schema validators |
| M8 | SSO account linking/unlinking endpoints absent | No `GET/DELETE /me/connections/{provider}` to list or unlink OAuth providers |
| M9 | `BoardConfig` sensitive key redaction undocumented | No list of which config keys are masked; AI would expose `smtp_password` |

---

## Low Gaps

| # | Gap | Impact |
|---|-----|--------|
| L1 | No `X-RateLimit-*` response headers documented | SPA cannot implement backoff |
| L2 | No CORS configuration documentation | First-time setup will fail without trial-and-error |
| L3 | Username validation rules limited (`minLength: 3, maxLength: 255`) | phpBB has forbidden characters and reserved name rules not in spec |
| L4 | Forum prune settings absent from `CreateForumRequest`/`UpdateForumRequest` | Admin cannot configure auto-prune via API |
| L5 | `DELETE /notifications/{notificationId}` missing | Individual notification deletion has no API surface |

---

## Service-by-Service Assessment (Revised)

| Service | Previous Score | New Score | Remaining Gap |
|---------|---------------|-----------|---------------|
| Auth service | 85% | 90% | JWT payload conflict affects all controllers (BUG-3) |
| Forum hierarchy | 85% | 97% | ACL filter and password flow fully documented |
| Topics/posts (core) | 70%/15% | 95% | Full approval+moderation workflow now present |
| Users/profiles | 65% | 88% | Email activation flow missing (M5) |
| User admin (ban, delete, type) | 5% | 95% | All 3 admin user operations now present |
| Messaging | 75% | 92% | Edit time window referenced but `pm_edit_time` config not in /config docs |
| Notifications | 70% | 72% | Type catalog and subscription management still absent |
| Search | 80% | 80% | Discriminator technically present but inert (BUG-1) |
| Storage | 75% | 82% | download security override missing (H4); size limits absent |
| Groups | 30% | 90% | Full CRUD + member management; `a_group_leader` undocumented (H2) |
| Board config | 0% | 87% | Sensitive key redaction list missing |
| **Overall** | **~65%** | **~87%** | |

---

## AI Implementability Score

**87% on first attempt**, with the following failure distribution:

| Failure Mode | Likelihood | Consequence |
|---|---|---|
| BUG-1: Wrong search result serialization | **Certain** | Search response has no `_type` field; client deserialization breaks |
| BUG-2: Wrong response envelope | **High** (if AI reads REST_API.md) | All responses formatted as `{ "forums": [...] }` instead of `{ "data": [...] }` |
| BUG-3: Wrong JWT claims | **High** (if AI reads REST_API.md) | All controllers extract `user_id` instead of `sub`; auth broken everywhere |
| H4: File downloads auth-gated | **Certain** | Anonymous forum attachment viewing blocked |
| H1: Notifications non-functional | **Certain** | Notification bell works for count, renders nothing per-type |
| M5: No email activation | **Certain** | Newly registered users stuck as Inactive with no activation path |

**With BUG-1 through BUG-3 and H4 fixed**, the AI implementability score rises to approximately **~93%**. The remaining 7% is quality/edge-case work (notification types, file limits, activation) that would be discovered during QA rather than causing security or data-model failures.

---

## Deployment Decision

**NO-GO** for handing directly to an AI coding agent without addressing the 3 blocking bugs.

**GO conditions** (in order of effort):
1. **BUG-3** (30 min): Add deprecation note to `REST_API.md` JWT section pointing to Auth HLD as canonical, and paste the authoritative `{ sub, gen, pv, utype, flags, kid }` payload into `openapi.yaml` info section
2. **BUG-2** (30 min): Update `REST_API.md` response shape section to adopt `{ "data": [...], "meta": {...} }` and strike the old named-key examples
3. **BUG-1** (15 min): Add `_type: { type: string, enum: [topic] }` / `[post]` to `Topic` and `Post` schemas respectively
4. **H4** (5 min): Add `security: []` override to `GET /files/{fileId}/download` operation

**Total effort to unblock**: ~80 minutes.

After those 4 changes, the spec enables an AI agent to produce a **correct, secure, production-usable Phase 1 API** — covering: auth (login/SSO/refresh/elevation/logout), full forum browsing with ACL, topic and post CRUD with full moderation workflow (approve/restore/soft-delete/move/split/merge), user registration and profiles, ban management, group management, messaging (send/reply/edit/delete/participant management), notifications (polling), search, file uploads/downloads, and board configuration.

Missing functionality (notification type rendering, email activation, MIME/size enforcement, SSO unlinking) is edge-case quality work that will be discovered during QA and does not produce incorrect or insecure baseline behavior.

---

## Functional Completeness Estimate (Revised)

| Feature Area | v1 Score | v2 Score | Remaining Gap |
|---|---|---|---|
| Auth (login/SSO/refresh/elevation/logout) | 85% | 90% | BUG-3 JWT conflict; rate limit thresholds |
| Forum browsing + ACL | 80% | 97% | — |
| Topic/post reading | 90% | 97% | — |
| Topic/post CRUD (create/edit/delete) | 85% | 95% | `f_edit_time` enforcement detail |
| Moderation (approve/restore/move/split/merge) | 15% | 95% | — |
| User registration & profiles | 80% | 88% | Email activation flow missing |
| User admin (ban/delete/type) | 5% | 95% | — |
| Messaging (all operations) | 75% | 92% | `pm_edit_time` config reference |
| Notifications (poll/count/read) | 75% | 72% | Type catalog still undefined |
| Search | 80% | 80% | BUG-1 discriminator inert |
| File storage | 75% | 82% | Size/MIME limits; H4 download security |
| Groups (CRUD + members) | 30% | 90% | H2 `a_group_leader` undocumented |
| Board config | 0% | 87% | Sensitive key redaction list |
| **Overall** | **~65%** | **~87%** | |

The spec has improved dramatically. The remaining 13% failure surface is concentrated in three bugs that must be patched before AI implementation, plus the notification type system which will require a follow-up spec section.

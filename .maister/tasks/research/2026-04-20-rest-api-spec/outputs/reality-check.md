# Reality Check: REST API Specification Sufficiency for AI Implementation
## Can an AI coding agent produce a correct, secure, working implementation from this spec alone?

**Date**: 2026-04-20 (v3 — re-check after claimed fixes from v2)  
**Assessor**: reality-assessor agent  
**Primary Artifact**: `openapi.yaml` (2 915 lines)  
**Question**: Would Claude Sonnet 4.6 receive only `openapi.yaml` + the project coding standards and produce a correct, secure PHP/Symfony backend?

---

## Overall Status

⚠️ **GAPS FOUND — BUG-1 and SEC-1 genuinely fixed; BUG-2 only partially addressed; BUG-3 not fixed**

Two of the four v2 issues were genuinely resolved. `_type` discriminator fields are now in both `Topic` and `Post` schemas; `GET /files/{fileId}/download` now carries `x-permission: authenticated` with a description that requires auth for all downloads. The other two issues show mixed results: BUG-2 (response envelope conflict) added a "spec wins" disclaimer but left all conflicting code examples untouched; BUG-3 (JWT payload conflict) is claimed resolved but the old `{ "user_id", "admin" }` payload remains in `REST_API.md` with no deprecation note, and the openapi.yaml still contains zero documentation of JWT claim structure.

---

## v3 Fix Verification (Claimed Fixes from v2)

### BUG-1: `_type` Discriminator — ✅ CONFIRMED FIXED

**Evidence**:
- `Topic` schema (line 337): `_type: { type: string, const: topic, description: Discriminator field for SearchResult deserialization }`
- `Post` schema (line 370): `_type: { type: string, const: post, description: Discriminator field for SearchResult deserialization }`
- `SearchResult.data.items.discriminator` at line 662: `propertyName: _type`

The discriminator is now live — both referenced schemas include the property, so OpenAPI validators and code generators will correctly use `_type` to branch between `Topic` and `Post` in search results.

---

### BUG-2: Response Envelope Conflict — ⚠️ PARTIALLY FIXED

**What was done**: A disclaimer block was added at the top of `REST_API.md` (lines 5–10):
> "The OpenAPI specification … is the **single source of truth** for all endpoint contracts … In case of any conflict between this document and the OpenAPI spec, **the spec wins**."

**What remains wrong**: The remainder of `REST_API.md` is unchanged. An AI agent reading the document will see:
- "Response Shape Conventions" section (line 45) still uses `{ "forums": [...], "total": 3 }` and `{ "topic": { ... } }`
- PHP code examples in the "Controller Conventions" section still return `['forums' => $forums, 'total' => count($forums)]` and `['forum' => $forum]`
- The disclaimer requires a developer to mentally override every code example they encounter; an AI coding agent making pattern-completion decisions from examples will implement the wrong format

**Impact**: The disclaimer reduces (but does not eliminate) the risk that an AI produces the wrong response envelope. An AI in a fresh context with no prior instruction to prefer the spec over examples will likely pattern-match on the code, producing `{ "forums": [...] }` rather than `{ "data": [...], "meta": {...} }`. Probability of wrong envelope in generated controllers: **~50%** (was ~80% without disclaimer).

**Fix still required**: Replace the conflicting examples in "Response Shape Conventions" and "Controller Conventions" sections with `{ "data": [...], "meta": {...} }` format. The disclaimer alone is not sufficient.

---

### BUG-3: JWT Payload Conflict — ❌ NOT FIXED

**What was done**: Nothing. The user's task description says "BUG-3 resolved: openapi.yaml is the authoritative source for JWT payload shape." This claim is incorrect.

**Evidence of what is actually in the files**:

`REST_API.md` lines 95–105 still contain:
```json
{
    "user_id": 2,
    "username": "admin",
    "admin": true,
    "iat": 1700000000,
    "exp": 1700003600
}
```
No deprecation note. No pointer to the Auth HLD. Not marked as outdated.

`openapi.yaml` contains **zero documentation** of JWT payload structure. `TokenPair.accessToken` is declared as `{ type: string }` with no description of what claims are inside. The correct claims (`sub`, `gen`, `pv`, `utype`, `flags`, `kid`) appear nowhere in the spec.

**Impact**: Unchanged from v2 — this is the single highest-risk failure mode. Any AI agent that:
1. reads `REST_API.md` (which copilot-instructions.md directs it to do), AND
2. implements the `AuthSubscriber` or any controller that reads token claims

…will produce: `$token->user_id`, `$token->admin` — both of which are wrong. The correct implementation is `$token->sub` (for user ID) and checking `$token->utype` or the `flags` bitfield (for permission level). The `gen`/`pv` staleness-check logic will be entirely absent. **Auth is broken in every controller generated from this context.**

**Fix still required** (two steps):
1. Add a deprecation note immediately before the JWT payload code block in `REST_API.md`:
   > "⚠️ Deprecated payload shape — see Auth HLD or `openapi.yaml` info section for the canonical claims (`sub`, `gen`, `pv`, `utype`, `flags`, `kid`)."
2. Add a JWT Claims table to `openapi.yaml`'s `info.description` section:
   ```
   ## JWT Access Token Claims
   | Claim | Type | Description |
   |---|---|---|
   | iss | string | "phpbb" |
   | sub | integer | User ID (use this, not user_id) |
   | aud | string | "phpbb-api" or "phpbb-admin" |
   | iat | integer | Issued-at Unix timestamp |
   | exp | integer | Expiry (iat + 900 for access, iat + 300 for elevated) |
   | jti | string | UUID v4 — unique per token; check deny-list |
   | gen | integer | Token generation — must match user.token_generation |
   | pv | integer | Permission version — must match user.perm_version |
   | utype | integer | User type (0=Normal, 3=Founder) |
   | flags | string | Base64-encoded 92-bit permission bitfield |
   | kid | string | Key ID for rotation ("access-v1", "elevated-v1") |
   | scope | string[] | [Elevated only] ["acp"] or ["mcp"] |
   | elv_jti | string | [Elevated only] Parent access token JTI |
   ```

---

### SEC-1 / H4: File Download Security — ✅ CONFIRMED FIXED (design changed)

**Evidence**: `GET /files/{fileId}/download` now has `x-permission: authenticated` (line 2833) and description: "All file downloads require a valid JWT (authenticated user)."

**Design decision documented**: The decision was to require authentication for ALL downloads, including public-visibility files. This differs from classic phpBB (guests can view public attachments), but the spec now clearly states the behavior. `security: []` is correctly absent — the global `bearerAuth` requirement applies.

**Note**: This removes guest access to forum attachments. If this is intentional (React SPA requiring login to view attachments), it is now correctly specified. If guests should be able to download public files, a `security: []` override is still needed.

---

## Remaining Gaps (Carried from v2, Still Present)

### H1 (High): Notification Type Catalog Undefined

`Notification.type` is `{ type: string }` — no enum. `Notification.display` is `{ type: object, description: "Type-specific rendering data" }` — completely opaque. An AI implementing the notification bell and per-type rendering has no specification. Notification subscription/unsubscription endpoints are also absent.

**Status**: Unchanged. All notification rendering work will stall.

---

### H2 (High): `a_group_leader` Used But Undocumented

`PATCH /groups/{groupId}`, `POST /groups/{groupId}/members`, and `DELETE /groups/{groupId}/members/{userId}` carry `x-permission: OR:a_group,a_group_leader`. However `a_group_leader` does not appear in the global permission table in `info.description`. An AI building the permission validator may treat it as a typo or omit the OR branch entirely.

**Status**: Unchanged.

---

### H3 (Medium): `a_viewprofile` Misused in `GET /users`

`GET /users` (user list endpoint) description says "email filter requires `a_viewprofile`". But `a_viewprofile` is listed in the spec as a **user preference** permission (`u_viewprofile`), not an admin permission. The correct admin permission for managing user data is `a_user`. This inconsistency will cause an AI to apply the wrong permission check on the email filter.

**Status**: Unchanged.

---

### M4 (Medium): `/me/groups` Endpoint Missing

Users cannot list their own group memberships or set their default group via the API. The `User.defaultGroupId` field is present in the schema but there is no endpoint to change it. The `GroupService.setDefaultGroup()` method (in Auth HLD) has no REST surface.

**Status**: Unchanged.

---

### M5 (Medium): Email Activation Flow Missing

`POST /users` (register) returns a `User` object with `type` potentially set to `1` (Inactive) when the board requires email confirmation. There is no `POST /users/activate` endpoint that accepts an activation key from the email link. New users on boards with email verification enabled have no way to activate their account through the API.

**Status**: Unchanged. Note: `POST /users/{userId}/type` (admin endpoint, `x-permission: a_user`) can manually activate a user, but the self-service activation flow is absent.

---

### M6 (Medium): Orphan File Claim Mechanism Undocumented

`POST /files` (upload) response description says "client associates it with a post/topic to prevent orphan cleanup." `StoredFile.isOrphan` and `StoredFile.claimedAt` fields are present. But there is no documented mechanism (endpoint or request field) for performing the claim. The AI-generated upload flow will leave files perpetually orphaned.

**Status**: Unchanged.

---

### M7 (Low): `ElevateRequest.oneOf` Semantic Ambiguity

`ElevateRequest` declares `scopes` at the root then uses `oneOf` for either password or SSO sub-schemas. Some strict JSON Schema validators interpret this as requiring the root `scopes` property to be absent from the `oneOf` sub-schemas. May cause runtime validation errors depending on the validator library.

**Status**: Unchanged.

---

### M8 (Low): SSO Account Linking/Unlinking Absent

When a user authenticates via SSO, they create an account linked to a provider. There are no `GET /me/connections` or `DELETE /me/connections/{provider}` endpoints to list or revoke these links. An SSO user who wants to unlink a provider or link a new one has no API path.

**Status**: Unchanged.

---

### M9 (Low): BoardConfig Sensitive Key List Absent

`GET /config` description says "Sensitive keys (e.g. smtp_password) are redacted." No list of which keys are redacted. An AI implementing the config controller will either redact nothing (exposing credentials) or guess which keys to hide. The actual phpBB config table contains ~200+ keys; the ones holding credentials must be enumerated explicitly.

**Status**: Unchanged.

---

## New Issues Found (v3)

### NEW-1 (High): JWT Claims Absent from openapi.yaml

The spec makes no mention of what claims are inside an `accessToken`. `TokenPair.accessToken` is `{ type: string }` — no description. An AI building `AuthSubscriber.php` or any controller that reads permissions from the JWT token has **no spec-level guidance** on claim names. This makes BUG-3 worse than v2 recognised: even if `REST_API.md` is resolved, the spec itself needs to document claims. Without this, every AI-generated auth middleware is guesswork.

**Fix**: Add the JWT Claims table to `openapi.yaml` `info.description` (see BUG-3 fix step 2 above).

---

### NEW-2 (Medium): Registration Response Lacks Activation State Signal

`POST /users` (register) always returns HTTP 201 with `data: { User }`. When the board is configured to require email confirmation, the returned user will have `type: 1` (Inactive). The SPA has no reliable way to know it should show "check your email" vs "you're logged in" — it must infer from `type`, but this inference is not documented.

**Fix**: Add `requiresEmailActivation: boolean` to the `POST /users` 201 response, or use HTTP 202 (Accepted) for pending-activation registrations.

---

### NEW-3 (Low): Guest Attachment Access Change Not Documented as Intentional

Classic phpBB allows unauthenticated users to download attachments in public forums. The new spec requires JWT for all file downloads (`x-permission: authenticated`). This is a breaking behavior change that should be explicitly noted as intentional in the spec — otherwise teams migrating from phpBB 3.x may assume it is an oversight.

---

## Service-by-Service Assessment (v3 Update)

| Service | v1 Score | v2 Score | v3 Score | Change from v2 |
|---------|----------|----------|----------|----------------|
| Auth service | 85% | 90% | 90% | No change; BUG-3 not fixed |
| Forum hierarchy | 85% | 97% | 97% | No change |
| Topics/posts (core) | 70%/15% | 95% | 95% | No change |
| Users/profiles | 65% | 88% | 88% | No change; activation still missing |
| User admin (ban, delete, type) | 5% | 95% | 95% | No change |
| Messaging | 75% | 92% | 92% | No change |
| Notifications | 70% | 72% | 72% | No change |
| Search | 80% | 80% | 95% | **BUG-1 fixed** — `_type` now correct |
| Storage | 75% | 82% | 88% | **SEC-1 fixed** — auth model clarified |
| Groups | 30% | 90% | 90% | No change; `a_group_leader` still undocumented |
| Board config | 0% | 87% | 87% | No change |
| **Overall** | **~65%** | **~87%** | **~89%** | +2% from two genuine fixes |

---

## AI Implementability Score

**v3: ~89% on first attempt** (up from ~87% in v2, up from ~65% in v1)

| Failure Mode | v2 Status | v3 Status | Consequence |
|---|---|---|---|
| BUG-1: Wrong search result serialization | Blocking | **FIXED** | `_type` now in Topic/Post schemas |
| BUG-2: Wrong response envelope | Blocking | **Partially mitigated** | Disclaimer present; code examples still wrong |
| BUG-3: Wrong JWT claims | Blocking | **Still blocking** | `user_id`/`admin` still in REST_API.md; no JWT docs in spec |
| H4/SEC-1: File downloads ambiguous | High | **FIXED** | Auth requirement unambiguous |
| H1: Notifications type catalog absent | High | Still absent | Bell renders; per-type UI cannot be implemented |
| M5: No email activation | Medium | Still absent | Inactive users cannot self-activate |
| NEW-1: JWT claims absent from spec | — | **New blocker** | Auth middleware implementable only via Auth HLD |

**With BUG-3 + NEW-1 fixed** (JWT claims documented in spec + REST_API.md deprecated): score rises to **~93%**.

The remaining 7% is quality/edge-case surface: notification type rendering, email activation, MIME/size limits, orphan file claim, `a_group_leader` in permission table. These are discoverable during QA and do not produce incorrect security behavior.

---

## Deployment Decision

**CONDITIONAL GO. The `openapi.yaml` alone is markedly better. The combined context of `openapi.yaml` + current `REST_API.md` is still a NO-GO.**

The critical path to a safe AI implementation handoff:

| Fix | File | Effort | Blocks |
|-----|------|--------|--------|
| 1. Add JWT Claims table | `openapi.yaml` info.description | 20 min | NEW-1, BUG-3 partial |
| 2. Deprecate JWT payload in `REST_API.md` | `REST_API.md` L95-L103 | 5 min | BUG-3 |
| 3. Replace conflicting response envelope examples | `REST_API.md` L45-L180 | 30 min | BUG-2 |
| **Total** | | **~55 min** | |

After these 3 changes, the combined spec context is sufficient for an AI agent to produce a correct, secure Phase 1 implementation. The remaining gaps (notification types, email activation, orphan claim) will be discovered during QA and require spec additions, not doc conflict resolution.

---

## Functional Completeness Estimate (v3)

| Feature Area | v1 | v2 | v3 | Remaining Gap |
|---|---|---|---|---|
| Auth (login/SSO/refresh/elevation/logout) | 85% | 90% | 90% | BUG-3 JWT conflict; rate limit thresholds |
| Forum browsing + ACL | 80% | 97% | 97% | — |
| Topic/post reading | 90% | 97% | 97% | — |
| Topic/post CRUD (create/edit/delete) | 85% | 95% | 95% | `f_edit_time` enforcement detail |
| Moderation (approve/restore/move/split/merge) | 15% | 95% | 95% | — |
| User registration & profiles | 80% | 88% | 88% | Email activation flow (M5) |
| User admin (ban/delete/type) | 5% | 95% | 95% | — |
| Messaging (all operations) | 75% | 92% | 92% | `pm_edit_time` config reference |
| Notifications (poll/count/read) | 75% | 72% | 72% | Type catalog (H1) |
| Search | 80% | 80% | 95% | — (BUG-1 fixed) |
| File storage | 75% | 82% | 88% | Size/MIME limits; orphan claim (M6) |
| Groups (CRUD + members) | 30% | 90% | 90% | `a_group_leader` undocumented (H2) |
| Board config | 0% | 87% | 87% | Sensitive key list (M9) |
| **Overall** | **~65%** | **~87%** | **~89%** | |

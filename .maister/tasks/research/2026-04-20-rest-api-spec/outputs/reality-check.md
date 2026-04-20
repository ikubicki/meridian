# Reality Check: REST API Specification Sufficiency for AI Implementation
## Can an AI coding agent produce a correct, secure, working implementation from this spec alone?

**Date**: 2026-04-20 (v4 — re-check after claimed fixes from v3)
**Assessor**: reality-assessor agent
**Primary Artifact**: `openapi.yaml` (~2 950 lines)
**Question**: Would Claude Sonnet 4.6 receive only `openapi.yaml` + project coding standards and produce a correct, secure PHP/Symfony backend?

---

## Overall Status

⚠️ **GAPS FOUND — All four v3 claimed fixes are genuine; three new medium/high gaps identified**

All fixes from the v3→v4 cycle are confirmed real: JWT claims table is now in `openapi.yaml` and `REST_API.md` is fully corrected; BUG-2 (response envelope) and BUG-3 (JWT claims) are both fully resolved. The spec is substantially more AI-implementable than v3. However, a fresh full-pass found three new gaps: the `jti` token identifier is absent from the JWT claims documentation (breaking logout deny-listing), soft-delete visibility filtering rules are entirely absent from collection endpoints (data exposure risk), and `GET /files/{fileId}` has no access-control annotation (IDOR risk for private file metadata).

**Revised AI implementability score: ~90%** — borderline ⚠️.

---

## v4 Fix Verification Table

| Fix ID | Description | Evidence | Status |
|--------|-------------|----------|--------|
| **BUG-3a** | JWT Claims table added to `openapi.yaml` `info.description` | Lines 78–127: complete `## JWT Claims Reference` table with `sub`, `username`, `utype`, `gen`, `pv`, `flags`, `kid`, `iat`, `exp`, example payload, and middleware usage note | ✅ CONFIRMED |
| **BUG-3b** | `REST_API.md` JWT payload updated to `sub`-based claims | "JWT payload structure" section shows `sub`, `username`, `utype`, `gen`, `pv`, `flags`, `kid`; old `user_id`/`admin` payload is gone; disclaimer points to spec as authoritative | ✅ CONFIRMED |
| **BUG-2a** | `REST_API.md` "Response Shape Conventions" uses `{ "data": [...], "meta": {...} }` | Section now shows three canonical shapes (paginated collection, single resource, created resource) all using `data` key | ✅ CONFIRMED |
| **BUG-2b** | `REST_API.md` controller example uses `data`/`meta` keys | `ForumsController::index()` returns `['data' => $forums, 'meta' => [...]]`; `show()` returns `['data' => $forum]` | ✅ CONFIRMED |

### BUG-1 + SEC-1 (Previously Fixed — Still Confirmed)

| Fix | Evidence | Status |
|-----|----------|--------|
| BUG-1: `_type: { const: topic }` in `Topic` schema | `openapi.yaml` line ~337: `_type: { type: string, const: topic, description: Discriminator field … }` | ✅ |
| BUG-1: `_type: { const: post }` in `Post` schema | `openapi.yaml` line ~402: `_type: { type: string, const: post, … }` | ✅ |
| SEC-1: `x-permission: authenticated` on `GET /files/{fileId}/download` | `openapi.yaml` line 2872: `x-permission: authenticated`; description: "All file downloads require a valid JWT" | ✅ |

---

## Remaining Gaps (Carried from v3, Still Present)

### H1 (High): Notification Type Catalog Undefined

`Notification.type` = `{ type: string }` with no enum. `Notification.display` = opaque object. Per-notification rendering logic cannot be derived from the spec. Subscription management endpoints absent.

**Impact**: Notification bell + unread count works; per-type rendering stalls entirely.

---

### H2 (High): `a_group_leader` Used But Undocumented

`PATCH /groups/{groupId}`, `POST /groups/{groupId}/members`, and `DELETE /groups/{groupId}/members/{userId}` carry `x-permission: OR:a_group,a_group_leader`. `a_group_leader` does not appear in the global permission table in `info.description`. An AI building the permission guard layer may omit the `OR` branch.

---

### H3 (Medium): `a_viewprofile` Misused for Email Filter

`GET /users` description says "email filter requires `a_viewprofile` permission". `a_viewprofile` (`u_viewprofile`) is a user-facing preference permission, not an admin data-access permission. The correct permission is `a_user`. AI-generated guard logic will apply the wrong check.

---

### M4 (Medium): `/me/groups` Endpoint Missing

No endpoint to list own group memberships or change default group. `User.defaultGroupId` exists in schema but has no write path.

---

### M5 (Medium): Email Activation Flow Missing

`POST /users` returns 201 `User` whether or not the board requires email confirmation. A user with `type: 1` (Inactive) has no API path to self-activate. SPA cannot show "check your email" vs "logged in" from the response alone.

---

### M6 (Medium): Orphan File Claim Mechanism Undocumented

`POST /files` 201 response reads "associate it with a post/topic to prevent orphan cleanup." No endpoint or protocol document explains how to perform the claim. Uploaded files will remain orphans.

---

### M7 (Low): ElevateRequest.oneOf Semantic Ambiguity

`ElevateRequest` declares `scopes` at root then uses `oneOf`. Some validators reject root properties absent from the selected `oneOf` branch. May cause runtime validation errors.

---

### M8 (Low): SSO Account Linking/Unlinking Absent

No `GET /me/connections` or `DELETE /me/connections/{provider}` endpoints.

---

### M9 (Low): BoardConfig Sensitive Key Redaction List Absent

`GET /config` description says "Sensitive keys (e.g. smtp_password) are redacted." No list of which keys. AI-generated config controller will either redact nothing or guess.

---

### v3-NEW-2 (Low): Registration Response Lacks Activation State Signal

`POST /users` 201 is always `{ data: User }`. SPA must infer activation state from `type: 1`. Not documented as the intended signal.

---

## New Issues Found (v4 Fresh Assessment)

### NEW-A (High): `jti` Claim Absent from JWT Documentation — Logout Deny-List Broken

The JWT Claims Reference added in v4 documents nine claims (`sub`, `username`, `utype`, `gen`, `pv`, `flags`, `kid`, `iat`, `exp`). **`jti` (JWT ID — the unique per-token UUID) is missing.**

`jti` is required for the deny-list implementation that backs `POST /auth/logout`. When a user logs out, the still-valid access token (up to 15-minute TTL) must be blocklisted by adding its `jti` to a deny-list store. `AuthSubscriber.php` must check `isJtiDenied($jti)` on every request. Without `jti` in the spec:

- An AI implementing `AuthSubscriber.php` will not add the deny-list check.
- An access token stolen immediately before logout remains valid for up to 15 minutes.
- `POST /auth/logout` only revokes the refresh token family — the access token continues to work.

The Auth HLD defines `denyJti(jti, remainingTtl)` and `isJtiDenied(jti)` on `TokenServiceInterface`. The spec's omission of `jti` silently drops this security mechanism.

**Fix**: Add `jti` to the JWT claims table in `openapi.yaml` info.description:
```
| `jti` | string | Unique token ID (UUID v4) — checked against deny-list on every request |
```
Add to `POST /auth/logout` description: "Also deny-lists the current access token's `jti` with TTL = `exp − now()`."

---

### NEW-B (High): Soft-Delete Visibility Filtering Rules Absent from Collection Endpoints

`Topic.visibility` and `Post.visibility` enums are documented (0=Unapproved, 1=Approved, 2=Deleted, 3=Reapprove). But neither `GET /forums/{forumId}/topics` nor `GET /topics/{topicId}/posts` contains any description of which `visibility` values are returned to which caller.

**Impact**: An AI implementing the repository layer returns all rows. This exposes:
1. **Soft-deleted posts** (`visibility=2`) to regular users.
2. **Unapproved posts** (`visibility=0`) to non-moderators — moderation queue bypass.

The only mention of approval is in `POST /forums/{forumId}/topics` description (creation context). Collection retrieval filtering is completely undocumented.

**Fix**: Add to `GET /forums/{forumId}/topics` description:
> "Returns only topics where `visibility=1` (Approved) unless caller has `m_approve` (also sees `visibility=0,3`) or `m_delete` (also sees `visibility=2`)."

Same for `GET /topics/{topicId}/posts`.

---

### NEW-C (Medium): `GET /files/{fileId}` Missing x-permission — Private File Metadata IDOR

`GET /files/{fileId}` (metadata endpoint) has no `x-permission` annotation, no auth description, and only a `404` error response. `StoredFile.visibility` has values `public` and `private`. A private file's metadata (filename, original name, checksum, uploader ID, size) is accessible to any authenticated user who knows or guesses the UUID.

**Fix**: Add `x-permission: authenticated` and description: "Private files (`visibility=private`) return 403 unless caller is the uploader or has `a_manage_files`." Add `403` response code.

---

### NEW-D (Low): `a_manage_files` Referenced in 403 Response but Not in Permission Table

`DELETE /files/{fileId}` 403 response says "admins with `a_manage_files` may override." `a_manage_files` is not in the global permission table in `info.description`. Same category as H2 (`a_group_leader`).

---

### NEW-E (Low): `flags` Claim Definition Differs from Auth HLD

`openapi.yaml` defines `flags: string[]` = "Elevated scope claims granted at POST /auth/elevate."  
Auth HLD (session memory) defines `flags: string` = "Base64-encoded 92-bit permission bitfield" plus a separate `scope: string[]` for elevation scopes.

The spec's version is internally consistent and the middleware guide (`in_array('acp', $token['flags'] ?? [])`) is correct per the spec. However, this represents an architectural change from the HLD: the permission bitfield is absent, so `f_post`/`m_edit`/`a_forum` checks require DB lookups via `AuthorizationServiceInterface::isGranted()`. This mechanism is not documented in the spec, leaving permission-check implementation to inference.

**Impact**: Low — spec is consistent. Bitfield-based permission inlining (HLD design) is silently replaced by service-layer DB lookups. An AI will implement DB-based checks correctly if given `$authorizationService`.

---

### NEW-F (Low): `POST /conversations` Response Envelope Inconsistency

`POST /conversations` response:
```yaml
data: { $ref: '.../Message' }
conversation: { $ref: '.../Conversation' }
```
`conversation` is a sibling of `data` at the top level, breaking the `{ "data": { … } }` single-root envelope pattern. Minor, but requires a special client-side handling path.

---

## Service-by-Service Assessment (v4 Update)

| Service | v1 | v2 | v3 | v4 | Change | Reason |
|---------|----|----|----|----|--------|--------|
| Auth service | 85% | 90% | 90% | 93% | **+3%** | JWT claims correct; minus jti (NEW-A) |
| Forum hierarchy | 85% | 97% | 97% | 97% | — | — |
| Topics/posts (core) | 70% | 95% | 95% | 90% | **−5%** | NEW-B: visibility filtering absent |
| Users/profiles | 65% | 88% | 88% | 88% | — | — |
| User admin | 5% | 95% | 95% | 95% | — | — |
| Messaging | 75% | 92% | 92% | 91% | −1% | NEW-F envelope inconsistency |
| Notifications | 70% | 72% | 72% | 72% | — | — |
| Search | 80% | 80% | 95% | 95% | — | — |
| Storage | 75% | 82% | 88% | 84% | **−4%** | NEW-C IDOR; NEW-D `a_manage_files` undoc |
| Groups | 30% | 90% | 90% | 90% | — | — |
| Board config | 0% | 87% | 87% | 87% | — | — |
| **Overall** | **~65%** | **~87%** | **~89%** | **~90%** | **+1%** | Auth gain offset by visibility + storage gaps |

---

## AI Implementability Score

**v4: ~90% on first attempt** (up from ~89% in v3, up from ~65% in v1)

| Issue | v3 Status | v4 Status | Impact |
|---|---|---|---|
| BUG-1: Wrong `_type` discriminator | FIXED ✅ | FIXED ✅ | — |
| BUG-2: Wrong response envelope | Partial | **Fully FIXED ✅** | — |
| BUG-3: Wrong JWT claims | Blocking | **Fully FIXED ✅** | — |
| SEC-1: File download auth ambiguous | FIXED ✅ | FIXED ✅ | — |
| H1: Notifications type catalog absent | High | High | Bell works; per-type UI stalls |
| **NEW-A: `jti` absent from JWT docs** | — | **New High** | Logout doesn't invalidate access tokens |
| **NEW-B: Visibility filtering undoc** | — | **New High** | Deleted/unapproved content visible to all users |
| **NEW-C: File metadata IDOR** | — | **New Medium** | Private file metadata leakable |
| M5: Email activation absent | Medium | Medium | Self-activation impossible |

**To reach ≥93% score**, two targeted fixes needed (≈35 minutes):
1. Add `jti` to JWT claims table + logout description (NEW-A).
2. Add visibility filtering rules to both listing endpoints (NEW-B).

---

## Gap Priority List

| ID | Severity | Fix Effort | Blocks |
|----|----------|------------|--------|
| NEW-A | **Critical** | 15 min | Token deny-list; post-logout token reuse |
| NEW-B | **High** | 20 min | Deleted/unapproved content exposure |
| H2 | High | 10 min | `a_group_leader` permission guard |
| H1 | High | 60+ min | Notification type rendering |
| NEW-C | Medium | 10 min | Private file metadata IDOR |
| H3 | Medium | 5 min | Wrong permission on email filter |
| M5 | Medium | 30 min | Email activation self-service |
| M6 | Medium | 20 min | Orphan file claim API |
| NEW-D | Low | 5 min | `a_manage_files` undocumented |
| NEW-E | Low | 10 min | Bitfield vs DB permission check undocumented |
| NEW-F | Low | 5 min | `POST /conversations` envelope inconsistency |
| M4,M8,M9 | Low | — | Edge cases; QA-discoverable |

---

## Deployment Decision

**⚠️ CONDITIONAL GO — two mandatory pre-handoff fixes required.**

The v4 spec is a major improvement over v3. With BUG-2 and BUG-3 fully resolved, an AI agent will now produce correct JWT claim parsing and correct response envelope code. However:

- **NEW-A** (`jti` missing) is a silent security defect — generated `AuthSubscriber.php` will lack the deny-list check. Access tokens remain reusable after logout until their 15-minute TTL expires.
- **NEW-B** (visibility filtering) is a silent data exposure defect — generated repository code will return soft-deleted and unapproved content to regular users.

Both are quick to add (35 minutes total).

| Fix | File | Change | Effort |
|-----|------|--------|--------|
| Add `jti` to JWT claims table | `openapi.yaml` info.description | Add row to table + note to `POST /auth/logout` | 15 min |
| Add visibility filtering rule | `openapi.yaml` `GET /forums/{forumId}/topics` | "Returns only visibility=1 unless caller has m_approve/m_delete" | 10 min |
| Add visibility filtering rule | `openapi.yaml` `GET /topics/{topicId}/posts` | Same rule | 10 min |
| **Total** | | | **~35 min** |

After these three changes the spec is sufficient for a correct, secure Phase-1 AI implementation at ≥93%. The remaining gaps (H1 notifications, M5 activation, M6 orphan claim) are individually discoverable during QA.

---

## Functional Completeness Estimate (v4)

| Feature Area | v1 | v2 | v3 | v4 | Remaining Gap |
|---|---|---|---|---|---|
| Auth (login/SSO/refresh/elevation/logout) | 85% | 90% | 90% | 93% | `jti` deny-list (NEW-A) |
| Forum browsing + ACL | 80% | 97% | 97% | 97% | — |
| Topic/post reading | 90% | 97% | 97% | 90% | Visibility filtering (NEW-B) |
| Topic/post CRUD | 85% | 95% | 95% | 95% | `f_edit_time` enforcement detail |
| Moderation (approve/restore/move/split/merge) | 15% | 95% | 95% | 95% | — |
| User registration & profiles | 80% | 88% | 88% | 88% | Email activation (M5) |
| User admin (ban/delete/type) | 5% | 95% | 95% | 95% | — |
| Messaging | 75% | 92% | 92% | 91% | Envelope on first-send (NEW-F) |
| Notifications | 75% | 72% | 72% | 72% | Type catalog (H1) |
| Search | 80% | 80% | 95% | 95% | — |
| File storage | 75% | 82% | 88% | 84% | Metadata IDOR (NEW-C) |
| Groups | 30% | 90% | 90% | 90% | `a_group_leader` undoc (H2) |
| Board config | 0% | 87% | 87% | 87% | Sensitive key list (M9) |
| **Overall** | **~65%** | **~87%** | **~89%** | **~90%** | |


# Storage Service — Cross-Source Synthesis

## Research Question

How should the `phpbb\storage` service be designed as a shared infrastructure layer that abstracts file storage for multiple consumers (AttachmentPlugin, AvatarPlugin, future plugins), provides multi-level quota enforcement, generalizes the orphan lifecycle, supports variants/thumbnails, and maintains backward compatibility with legacy file paths?

---

## Executive Summary

The legacy phpBB codebase manages file storage through two completely independent subsystems — attachments (`files/` directory, `phpbb_attachments` table, `attachment\manager` facade) and avatars (`images/avatars/upload/`, user table columns, driver-based architecture). These share no code, no abstractions, and no common file model. Despite this, cross-referencing reveals **strong structural parallels**: both produce a physical file with a randomized name, track metadata (size, MIME, dimensions), enforce upload validation with identical security pipelines, and handle cleanup on entity deletion. The new `phpbb\storage` service can unify these patterns behind a single **StoredFile** domain model with adapter-based filesystem access, generic orphan lifecycle, multi-level quotas (extending the legacy global-only model), and a variant system for thumbnails/resized images.

Key architectural decisions center on: (1) whether to use a single `stored_files` table or keep attachment/avatar metadata separate, (2) how to implement quota checks atomically under concurrent uploads, (3) how to serve files — proxy vs direct URL — in a way that supports both avatar (public, cacheable) and attachment (auth-gated) access patterns, and (4) how to migrate legacy files without physically relocating them.

---

## Cross-Source Analysis

### Validated Findings (Multiple Sources Confirm)

| Finding | Sources | Confidence |
|---------|---------|------------|
| All uploads go through `phpbb\files\upload` → `filespec` pipeline | attachment-lifecycle, file-infrastructure, avatar-system | **High** |
| Physical filenames are always randomized (no user-controlled paths) | attachment-lifecycle (unique mode), avatar-system (avatar salt + user_id) | **High** |
| Security validation is identical: extension whitelist, content sniffing (first 256 bytes), MIME re-detection after move | file-infrastructure, quota-enforcement | **High** |
| Orphan pattern exists for attachments but NOT for avatars | attachment-lifecycle, avatar-system | **High** |
| Legacy has only global quota (`attachment_quota`), no per-user or per-forum | quota-enforcement, storage-schema | **High** |
| Avatar serving is stateless/public; attachment serving requires session + ACL | download-serving, avatar-system | **High** |
| Thumbnails are stored as `thumb_` prefix in same directory, no separate table | attachment-lifecycle, file-infrastructure | **High** |
| `upload_dir_size` counter is eventually consistent (incremented on adoption, not upload) | quota-enforcement, attachment-lifecycle | **High** |
| Admin/mod bypass all file size and dimension limits | quota-enforcement | **High** |
| File reference counting exists — physical files only deleted when last DB reference removed | attachment-lifecycle (delete.php reference counting) | **High** |

### Contradictions Resolved

| Contradiction | Resolution |
|--------------|------------|
| Attachment filenames have no extension on disk (`{user_id}_{md5}`), but avatar filenames do (`{salt}_{user_id}.{ext}`) | Different `clean_filename()` modes: attachments use `'unique'` (no ext), avatars use `'avatar'` (with ext). The new service should standardize on **with extension** for easier debugging and X-Accel-Redirect. |
| Attachments use proxy serving (auth-gated), but avatars also use proxy serving despite being public | Legacy decision — avatar proxy avoids exposing `avatar_salt` and file structure. New service should support both modes: public direct URL for avatars, proxy for attachments. |
| `img_min_thumb_filesize` skips small images, but there's no equivalent for avatar thumbnails | Avatars don't have thumbnails at all. The new variant system should support configurable skip thresholds per variant type. |
| Cron cleans plupload temp files but NOT orphan attachment DB rows | Confirmed gap. No orphan cleanup for `phpbb_attachments` rows. The new service MUST implement orphan TTL cleanup. |

### Confidence Assessment

| Area | Confidence | Reasoning |
|------|-----------|-----------|
| Upload pipeline (validation, move, naming) | **High** | Direct code review of `upload.php`, `filespec.php`, driver classes |
| Quota enforcement (current) | **High** | Explicit code in `check_attach_quota()`, config values confirmed |
| Orphan lifecycle (attachments) | **High** | Full trace from INSERT through adoption to cleanup gap |
| Avatar lifecycle | **High** | Complete driver analysis, DB schema, serving flow |
| Download/serving security | **High** | Full `file.php` and `functions_download.php` analysis |
| Per-forum extension restrictions | **Medium** | `allowed_forums` field found in schema but only one code path using it |
| Reference counting for shared files | **Medium** | Found in `delete.php` but unclear when files are actually shared (topic copying?) |

---

## Patterns and Themes

### Pattern 1: Upload → Validate → Randomize → Store → Track

**Evidence**: Both attachment and avatar uploads follow this exact sequence.
**Prevalence**: Universal — every file write path.
**Quality**: Well-established in legacy code, consistently applied.

Attachment: `form → filespec → common_checks → clean_filename('unique') → move_file → DB INSERT`
Avatar: `form → filespec → common_checks → clean_filename('avatar') → move_file → update user row`

**Implication for storage service**: This becomes the core `StorageService::store()` method with pluggable naming strategies.

### Pattern 2: Orphan-Then-Claim (Deferred Association)

**Evidence**: Attachments are created with `is_orphan=1`, claimed in `submit_post()` when `is_orphan→0`. Upload happens during form composition, association happens on form submit.
**Prevalence**: Only attachments — avatars are immediately associated with the user.
**Quality**: Functional but has a gap — no TTL cleanup for abandoned orphans.

**Implication**: The storage service should provide a generic `store() → claim()` lifecycle where:
- `store()` creates an unassociated file record
- `claim(fileId, ownerId, ownerType)` associates it
- A cleanup job reaps unclaimed files after configurable TTL

### Pattern 3: Proxy-Based Serving with Auth Gating

**Evidence**: Both avatars and attachments serve through `web/download/file.php`, but with different security levels:
- Avatars: lightweight bootstrap, no session, public cache headers
- Attachments: full session, ACL check, private cache, per-forum auth

**Prevalence**: Universal for all file serving.
**Quality**: Mature, includes X-Accel-Redirect/X-Sendfile support, range requests for attachments.

**Implication**: The storage service needs two serving strategies:
1. **Authenticated proxy**: Full auth check, private cache (attachments)
2. **Public proxy/direct**: No auth, aggressive caching (avatars, gallery images)

### Pattern 4: Flat Directory Storage

**Evidence**: All attachments in `files/`, all uploaded avatars in `images/avatars/upload/`. No date-based subdirectories, no user-based sharding.
**Prevalence**: Universal in legacy.
**Quality**: Simple but will hit filesystem limits at scale (ext4 directory entry limit ~10M files). Using hashed filenames mitigates collision risk.

**Implication**: New service should introduce optional directory sharding (e.g., first 2 chars of hash as subdirectory) while maintaining legacy flat path as a compatibility adapter.

### Pattern 5: Variant as Filename Convention

**Evidence**: Thumbnails stored as `thumb_{physical_filename}` in the same directory. The `thumbnail` boolean column in `phpbb_attachments` tracks whether a variant exists.
**Prevalence**: Only thumbnails, no other variant types in legacy.
**Quality**: Ad-hoc — no generic variant model, no multiple sizes.

**Implication**: Generalize into a variant system: `{variant_prefix}_{physical_filename}` or separate variant table. Support on-demand vs eager generation.

### Pattern 6: Counter-Based Quota (Eventually Consistent)

**Evidence**: `upload_dir_size` is incremented on orphan adoption (not on upload), decremented on delete. Requires admin resync to fix drift. No per-user, no per-forum quotas.
**Prevalence**: Global scope only.
**Quality**: Fragile — race conditions possible, orphans aren't counted toward quota.

**Implication**: New quota system needs:
- Atomic quota reservation at upload time (not adoption time)
- Multiple scopes: global, per-user, per-forum
- Periodic reconciliation job

---

## Key Insights

### Insight 1: The Asset Model Is Surprisingly Uniform

Despite separate legacy implementations, a "stored file" has the same core attributes everywhere:

| Attribute | Attachments | Avatars |
|-----------|-------------|---------|
| Physical filename | `{user_id}_{md5}` | `{salt}_{user_id}.{ext}` |
| Original filename | Preserved in `real_filename` | Not preserved |
| MIME type | Detected, stored | Detected at serve time |
| File size | Stored in DB | Not stored (only config limit) |
| Dimensions | Stored for images | Stored in user row |
| Extension | Stored in DB | Extracted from `avatar` column |
| Storage path | `files/` | `images/avatars/upload/` |
| Owner | `poster_id` | `user_id` (implicit) |

A unified `StoredFile` entity can model all these with: `id, physicalName, originalName, mimeType, size, extension, width, height, storagePath, ownerId, ownerType, createdAt, metadata`.

**Confidence**: High — direct column-by-column comparison across schemas.

### Insight 2: The "Claim" Abstraction Decouples Storage from Business Logic

The orphan pattern is actually a powerful decoupling mechanism: the storage service doesn't need to know about posts, topics, or user profiles. It only knows about files that are either "claimed" or "unclaimed." The claiming is done by the consumer plugin.

This maps perfectly to the plugin architecture from the threads service design:
- `AttachmentPlugin` listens to `PostCreatedEvent` → calls `storage.claim(fileIds, postId, 'post')`
- `AvatarPlugin` on profile update → calls `storage.claim(fileId, userId, 'user_avatar')`

**Confidence**: High — confirmed by threads service HLD (decorator/event pattern) and user service spec.

### Insight 3: Quota Must Be Checked at Upload Time, Not Claim Time

Legacy checks quota at upload time (`check_attach_quota()`), but the counter is only updated at claim time (`submit_post()`). This means orphan uploads temporarily bypass quota. Under the new design:

1. **Reserve** quota at upload time (atomic increment)
2. **Confirm** reservation at claim time (no-op if already reserved)
3. **Release** reservation if orphan expires (decrement on cleanup)

This requires a `quota_reserved` vs `quota_confirmed` distinction, or simpler: count all non-expired files (including orphans) toward quota.

**Confidence**: High — validated quota gap from quota-enforcement findings.

### Insight 4: Two Serving Modes Are Architecturally Necessary

You cannot unify avatar serving (public, aggressive caching, CDN-friendly) with attachment serving (auth-gated, per-user access control) into a single serving strategy. The storage service should expose:

```php
interface StorageService {
    /** Returns a public, cacheable URL (avatars, gallery) */
    public function getPublicUrl(StoredFile $file): string;
    
    /** Returns proxy URL that requires auth middleware (attachments) */
    public function getAuthenticatedUrl(StoredFile $file): string;
}
```

For public files, the URL could be a direct path to the file or a long-lived signed URL.
For auth-gated files, always proxy through a controller that checks permissions.

**Confidence**: High — confirmed by fundamentally different security models in download-serving findings.

### Insight 5: Legacy Migration Requires Virtual Path Mapping, Not File Moves

Moving millions of files from `files/` and `images/avatars/upload/` would be disruptive and risky. Instead:

1. Register existing storage directories as "legacy adapters" with their naming conventions
2. New files use the new naming/directory scheme
3. Legacy files are served via a path resolver that understands old naming patterns
4. Optional background migration job can relocate files incrementally

This is similar to how avatar_salt-based naming coexists with user_id-based naming.

**Confidence**: High — storage-schema confirms fixed paths via config keys.

---

## Relationships and Dependencies

### Component Dependency Map

```
phpbb\storage\                          Consumers
├── StorageService (facade)  ◄─────── AttachmentPlugin (threads)
│   ├── store()                        AvatarPlugin (user service)
│   ├── claim()                        Future: media library, etc.
│   ├── delete()
│   ├── getPublicUrl()
│   └── getAuthenticatedUrl()
│
├── Adapter/
│   ├── LocalAdapter ──────────── files/, images/avatars/upload/
│   └── (future: S3Adapter, etc.)
│
├── Quota/
│   ├── QuotaService ◄────────── Config (global limits)
│   │                            Per-user quotas (new table)
│   │                            Per-forum quotas (new table)
│   └── QuotaReconciliationJob
│
├── Upload/
│   ├── UploadPipeline ◄──────── phpbb\files\upload (existing)
│   │   ├── validate
│   │   ├── scan (security)
│   │   └── move
│   └── ValidationChain
│
├── Serving/
│   ├── FileServer ◄──────────── web/download/file.php (existing)
│   └── UrlGenerator
│
├── Variant/
│   ├── VariantGenerator ◄────── Thumbnail (existing GD logic)
│   └── VariantRegistry
│
└── Cleanup/
    └── OrphanCleanupJob ◄────── Cron scheduler
```

### Integration Points

| From | To | Mechanism | Data |
|------|----|-----------|------|
| Threads `PostCreatedEvent` | `AttachmentPlugin` | Event listener | `postId, userId, attachmentIds[]` |
| `AttachmentPlugin` | `StorageService::claim()` | Method call | `fileIds[], ownerId, ownerType` |
| User `ProfileUpdatedEvent` | `AvatarPlugin` | Event listener | `userId, avatarFileId` |
| `AvatarPlugin` | `StorageService::store()` + `claim()` | Method calls | File data, userId |
| `StorageService::store()` | `QuotaService::reserve()` | Method call | `ownerId, fileSize` |
| `StorageService::delete()` | `QuotaService::release()` | Method call | `ownerId, fileSize` |
| `OrphanCleanupJob` | `StorageService::delete()` | Method call | Expired orphan fileIds |
| `FileServer` | `StorageService::getFile()` | Method call | fileId |
| `UploadPipeline` | `LocalAdapter::write()` | Method call | Stream + path |

---

## Gaps and Uncertainties

### Information Gaps

1. **Reference counting specifics**: The deletion code checks for shared `physical_filename` entries, suggesting topic copying can create multiple DB rows pointing to one physical file. How often does this happen? Should the new service use explicit reference counting (refcount column) or the current query-on-delete approach?

2. **Plupload chunked upload integration**: The chunked upload pipeline creates temp files in `{upload_path}/plupload/`. Will the new storage service handle chunking, or delegate to a separate upload middleware? The temp directory management is currently tightly coupled to plupload.

3. **Extension group vs storage service boundary**: Extension groups control which file types are allowed in which forums. This is business logic — should it live in the storage service or in the AttachmentPlugin? Likely AttachmentPlugin, but the validation pipeline in storage needs a hook for consumer-provided rules.

4. **Avatar dimensions stored in user row**: Currently `user_avatar_width` and `user_avatar_height` are columns on `phpbb_users`. Should these move to a `stored_files` table, or remain denormalized on the user? The user service spec includes these columns.

5. **Group avatars**: Groups also have avatars (`group_avatar`, `group_avatar_type`, etc.). The avatar system has `g` prefix for group avatars in URLs. How does this map to `ownerType` in the storage service?

### Unverified Claims

1. **Concurrent upload race conditions**: The quota check reads `upload_dir_size` and compares against `attachment_quota` — but another upload could be completing simultaneously. Actual race condition frequency is unknown for typical phpBB deployments.

2. **Filesystem performance at scale**: Legacy flat directory approach. No measured data on performance degradation with high file counts.

### Unresolved Inconsistencies

1. **Download count tracking is split**: Images counted on page view (in `functions_content.php`), non-images counted on download (in `file.php`). Should the storage service track downloads, or is this consumer responsibility?

2. **Config vs DB for avatar metadata**: Avatar file size isn't stored anywhere (only enforced at upload). Attachment file size is stored in DB. Should the storage service always store file size?

---

## Synthesis by Framework (Technical Research)

### Component Analysis

**What exists**:
- `phpbb\files\upload` + `phpbb\files\filespec` — reusable upload/validation pipeline
- `phpbb\filesystem\filesystem` — Symfony-wrapped filesystem abstraction
- `phpbb\mimetype\guesser` — MIME detection chain
- `phpbb\attachment\manager` — attachment-specific facade
- `phpbb\avatar\manager` — avatar-specific facade with driver pattern
- Thumbnail generation via GD in `functions_posting.php`

**What's structured well** (keep/extend):
- The `filespec` class's `clean_filename()` modes and validation chain
- The MIME guesser priority chain
- The X-Accel-Redirect/X-Sendfile support pattern
- The avatar driver pattern (extensible via DI service collection)

**What's problematic** (replace):
- Flat directory with no sharding option
- Global-only quota with stale counter
- Missing orphan cleanup
- No variant abstraction (just `thumb_` prefix convention)
- Split serving logic between avatar and attachment paths
- No streaming abstraction (raw `fopen`/`readfile`)

### Flow Analysis

**Upload flow**: Form → PHP temp → validation → randomize name → move to storage dir → DB record → quota update (deferred)

**Claim flow**: Post submit → verify orphan ownership → UPDATE SET is_orphan=0 → increment counters

**Serve flow**: URL → file.php → auth check → resolve physical path → HTTP headers → stream/X-Accel

**Delete flow**: Identify IDs → collect physical names → DELETE from DB → check refcount → delete physical + variants → decrement counters → resync parent flags

---

## Conclusions

### Primary Conclusions

1. **A unified storage service is both feasible and beneficial**. The legacy attachment and avatar subsystems share 80% of their infrastructure (upload pipeline, validation, filesystem ops, security checks) while diverging only in naming convention, metadata schema, and authorization model. A shared `phpbb\storage` service eliminates this duplication.

2. **The orphan lifecycle is the most architecturally significant pattern** to generalize. It provides clean decoupling between file storage (infrastructure concern) and business entity association (domain concern). Every consumer can use `store() → claim() → delete()` regardless of what they're storing.

3. **Multi-level quotas require a new DB table** because the legacy system's global-counter approach cannot support per-user or per-forum limits. A `storage_quotas` table with `(scope_type, scope_id, limit_bytes, used_bytes)` rows enables flexible quota targets.

4. **Two serving modes are non-negotiable**. Public (avatar) and authenticated (attachment) serving have fundamentally different security and caching requirements.

### Secondary Conclusions

5. The variant system should be **lazy/on-demand** rather than eager — generate thumbnails on first request, cache them, clean up when originals are deleted. This avoids wasting storage on thumbnails nobody views.

6. Legacy file migration should be **zero-copy** — keep files where they are, register legacy paths as storage locations, only move files if/when the admin explicitly triggers migration.

7. The upload validation pipeline (`filespec`'s security checks) should be reused, not reimplemented. The new storage service can wrap the existing `phpbb\files\upload` class rather than replacing it.

### Recommendations

1. Start with a `StoredFile` entity, `StorageService` facade, `LocalAdapter`, and `QuotaService` — these are the minimum viable components.
2. Implement `AttachmentPlugin` for threads as the first consumer to validate the API.
3. Add `AvatarPlugin` for user service as the second consumer to prove the abstraction generalizes.
4. Defer S3/cloud adapter to a future iteration.

---

## Key Design Decisions to Make (For Brainstorming)

1. **Single table vs multi-table?** — One `stored_files` table for everything, or keep `phpbb_attachments` + user avatar columns and add a thin `stored_files` table that they FK to?

2. **Naming strategy** — Standardize on `{hash}.{ext}` with optional 2-char directory sharding, or keep legacy patterns per consumer?

3. **Quota reservation model** — Pessimistic (lock + check + increment) vs optimistic (check + increment + rollback on overflow)?

4. **Variant generation timing** — Eager (at upload) vs lazy (on first request) vs hybrid (thumbnails eager, other sizes lazy)?

5. **File serving controller** — Single unified controller for all file types, or keep separate avatar/attachment endpoints for backward compatibility?

6. **Orphan TTL** — How long before unclaimed files are cleaned up? Legacy has no cleanup at all. Sensible default: 24 hours (matching plupload temp cleanup).

7. **Consumer metadata** — Should the storage service store consumer-specific metadata (download_count, attach_comment) or only generic file metadata?

8. **Stream abstraction** — PSR-7 `StreamInterface` for upload/download, or keep raw PHP file operations for simplicity?

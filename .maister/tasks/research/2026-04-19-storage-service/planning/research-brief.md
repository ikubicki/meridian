# Research Brief: phpbb\storage Service

## Research Question

How to design `phpbb\storage` service that:
1. Manages all file assets (attachments, avatars, and future asset types)
2. Provides global and per-user/per-forum quotas
3. Is available for plugins extending `phpbb\threads` (attachments) and `phpbb\user` (avatars)
4. Is compatible with the legacy phpBB file storage approach

## Research Type

**Technical** — codebase extraction + service design

## Context

### Prior Services Designed
- `phpbb\user` — user management (IMPLEMENTATION_SPEC.md exists)
- `phpbb\auth` — authorization (HLD complete)
- `phpbb\hierarchy` — forum tree/nested set (HLD complete)
- `phpbb\threads` — topics/posts with plugin content pipeline (HLD complete)

### Architecture Decisions (binding)
- **Event-driven API**: Methods return domain events
- **Auth via middleware**: Service trusts the caller
- **Request/Response Decorators**: Plugin extensibility model
- **NO legacy extension system**: No service_collection, no tagged services
- **PSR-4**: `phpbb\storage\` → `src/phpbb/storage/`

### How Storage Relates to Other Services
- `phpbb\threads` has an **AttachmentPlugin** (separate bounded context extending threads)
- `phpbb\user` manages avatars but needs storage for file operations
- Storage is a **shared infrastructure service** — not domain-specific

## Legacy System Overview

### Current File Storage
- **Attachments**: stored in `files/` directory, metadata in `phpbb_attachments` table
- **Avatars**: stored in `images/avatars/upload/`, managed by avatar driver system
- **Downloads**: served via `web/download/file.php` with auth checks
- **Filesystem**: `phpbb\filesystem\filesystem_interface` wraps Symfony filesystem
- **No external storage abstraction** (no Flysystem, no cloud adapters)

### Current Quota System
- `attachment_quota` = 50MB global quota
- `avatar_filesize` = 6144 bytes max per avatar
- `avatar_min/max_width/height` = dimension constraints
- Per-extension size limits (via `phpbb_extensions` table)
- No per-user or per-forum attachment quotas in legacy

### Key Patterns to Preserve
- **Orphan pattern**: Files uploaded as `is_orphan=1`, adopted on post submit
- **Physical filename**: Random name on disk vs original `real_filename`
- **Secure downloads**: Permission-gated serving via file.php
- **Thumbnail support**: For image attachments
- **Download counting**: Usage tracking

## Scope

### Included
- File upload/store/delete/serve lifecycle
- Quota management (global, per-user, per-forum)
- File metadata storage and retrieval
- Orphan management (temporary uploads not yet associated)
- Storage adapters (local filesystem, extensible to cloud)
- Thumbnail/variant generation (for images)
- Secure file serving (URL generation, access control)
- Plugin contracts (how attachment plugin, avatar plugin consume storage)

### Excluded
- Post/topic attachment association (→ AttachmentPlugin extending threads)
- Avatar assignment to user profiles (→ UserService / AvatarPlugin)
- BBCode `[attachment=N]` rendering (→ content pipeline plugin)
- CDN integration (future extension, not v1)
- Image manipulation beyond thumbnails (future)
- Anti-virus scanning (future)

## Key Design Questions

1. **Service boundaries**: What's the minimal storage contract plugins need?
2. **Adapter pattern**: How to abstract local filesystem for future cloud storage?
3. **Quota architecture**: How to enforce global + per-user + per-forum quotas efficiently?
4. **Orphan lifecycle**: How to manage temporary uploads and cleanup?
5. **File serving**: Generate URLs vs proxy through PHP?
6. **Metadata model**: What does a generic "stored file" entity look like?
7. **Migration**: How to preserve compatibility with existing file paths?
8. **Variants/thumbnails**: How to handle derived files (thumbnails, resized)?

## Success Criteria

1. Clear service contract that AttachmentPlugin and AvatarPlugin can consume
2. Quota system covering global, per-user, and per-forum limits
3. Storage adapter abstraction (local now, cloud-extensible)
4. Orphan management lifecycle documented
5. Backward-compatible with legacy `files/` and `images/avatars/upload/` paths
6. Secure file serving mechanism defined
7. Event catalog for storage operations
8. Integration points with threads/user services clear

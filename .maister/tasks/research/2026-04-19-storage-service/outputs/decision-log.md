# Decision Log: `phpbb\storage` Service

## Converged Decisions Summary

| # | Decision Area | Choice | ADR |
|---|---|---|---|
| 1 | Asset Table | **Single unified `stored_files` table** | ADR-001 |
| 2 | ID Strategy | **UUID v7 (BINARY(16))** | ADR-002 |
| 3 | Storage Adapter | **Flysystem integration** | ADR-003 |
| 4 | Variants/Thumbnails | **Async generation via event listeners** | ADR-004 |
| 5 | Quota Architecture | **Counter + periodic reconciliation** | ADR-005 |
| 6 | File Serving | **Hybrid (public direct + private X-Accel-Redirect)** | ADR-006 |
| 7 | Orphan Management | **DB-tracked + cron TTL (24h)** | ADR-007 |
| 8 | Directory Strategy | **Flat single directory (legacy-compatible)** | ADR-007 |

---

## ADR-001: Single Unified `stored_files` Table for All Asset Types

### Status
Accepted

### Context
The legacy phpBB codebase stores attachment metadata in `phpbb_attachments` (15 columns, 5 indexes) and avatar metadata in 4 columns on `phpbb_users` / `phpbb_groups`. These schemas diverge significantly. The new storage service needs a metadata strategy that unifies common parts (file identity, physical location, size, MIME) while allowing consumers to own domain-specific data (download_count, attach_comment for attachments; avatar_type_driver for avatars).

### Decision Drivers
- Storage service should be a single source of truth for "does this file exist and where is it?"
- New file types (exports, media library, group avatars) should need zero schema changes to storage
- Plugin architecture requires clean separation: storage owns file data, plugins own domain data
- Consumer-specific metadata (download_count, attach_comment) should be queryable with proper indexes

### Considered Options
1. **Single unified `stored_files` table** — all assets in one table with `asset_type` column
2. **Keep separate tables + thin storage reference layer** — existing tables plus FK to new `stored_files`
3. **Unified `stored_files` + type-specific extension tables** — common core + `phpbb_attachment_metadata`, `phpbb_avatar_metadata`

### Decision Outcome
Chosen option: **Option 1 (Single unified table)**, because it provides the simplest schema for storage operations (no JOINs for core file ops), while plugin-specific metadata lives in separate plugin-owned tables. This aligns with the converged decision that storage should be a thin infrastructure layer — it stores files, plugins store domain meaning.

AttachmentPlugin will maintain its own `phpbb_attachment_metadata` table for `download_count`, `attach_comment`, `extension_group_id` with a FK to `stored_files.id`. AvatarPlugin similarly owns its metadata. These extension tables are explicitly owned by the consumer plugins, not by the storage service.

### Consequences

#### Good
- Single table for all file ops — simple queries, one repository class
- No storage schema changes for new file types
- Clean API: `StoredFileRepositoryInterface` operates on one table
- Plugin-specific metadata properly separated (plugin owns its domain data)

#### Bad
- Consumer queries that need both file data and domain data require JOINs (stored_files JOIN attachment_metadata)
- Legacy code that queries `phpbb_attachments` directly won't work during gradual migration

---

## ADR-002: UUID v7 Stored as BINARY(16)

### Status
Accepted

### Context
Legacy uses autoincrement `attach_id` for attachments and implicit compound keys for avatars. The new service needs a unified ID strategy that works for URL references, API responses, cross-service correlation, and security (non-enumerable).

### Decision Drivers
- File IDs should not be guessable (no sequential probing of `/file/1`, `/file/2`, ...)
- IDs should be server-generated without DB roundtrip (INSERT already knows the PK)
- Storage format must be compact for index performance
- Time-ordering is desirable for debugging and log correlation

### Considered Options
1. **Auto-increment integer** — standard MySQL `INT UNSIGNED AUTO_INCREMENT`
2. **UUID v7 as BINARY(16)** — time-ordered UUID per RFC 9562, stored as raw bytes
3. **Content hash (SHA-256)** — file content determines the ID

### Decision Outcome
Chosen option: **UUID v7 as BINARY(16)**, because it combines non-enumerability (128-bit random component) with time-ordering (embedded timestamp prefix for B-tree locality). BINARY(16) is compact (16 bytes vs 36 for CHAR(36)) and performant for indexing. Server-side generation via `random_bytes()` requires no DB roundtrip.

Application layer converts to/from hex string with dashes for display and API responses.

### Consequences

#### Good
- Non-enumerable — infeasible to guess valid file IDs
- Time-sortable — mostly sequential inserts minimize B-tree fragmentation
- No central sequence coordination — safe for concurrent inserts and future sharding
- Generated in PHP before INSERT — no DB roundtrip for ID allocation

#### Bad
- Larger primary key than INT (16 bytes vs 4)
- Longer URLs: `/download/0193a5e7-8b3c-7def-abcd-1234567890ab`
- Requires hex encoding/decoding in application layer
- Slightly slower index lookups vs INT (wider key, more cache pressure)

---

## ADR-003: Flysystem for Storage Abstraction

### Status
Accepted

### Context
Legacy code uses `phpbb\filesystem\filesystem` (Symfony Filesystem wrapper) with hardcoded local paths. No abstraction exists for different storage backends. The service needs a storage layer that works with local filesystem today and can extend to cloud (S3, GCS) in the future.

### Decision Drivers
- Cloud storage (S3, GCS) must be reachable via configuration change, not code rewrite
- Adapter implementations (especially cloud) should be battle-tested, not hand-written
- Testing burden should be minimized — adapter correctness should be pre-verified
- The interface must support streaming for large files

### Considered Options
1. **Simple custom `StorageAdapterInterface`** — 6-8 methods, `LocalAdapter` only
2. **Flysystem integration** (`league/flysystem` v3) — wrap Flysystem as storage engine
3. **Strategy pattern with local-first + pluggable backends** — custom interface, defer adapter choice
4. **Extend Symfony Filesystem** — build on the existing dependency

### Decision Outcome
Chosen option: **Option 2 (Flysystem)**, because it provides battle-tested local, S3, GCS, Azure, SFTP, and memory adapters out of the box. Adding cloud storage later is a `composer require` + config change. The v3 API is clean modern PHP with streaming support. This reduces testing burden — Flysystem adapters are extensively tested by the community.

Domain-specific methods (`getAccelRedirectPath`, `getLocalPath` for GD) are added in the `UrlGenerator` and `FileServerController` wrappers rather than on the adapter interface.

### Consequences

#### Good
- Battle-tested adapters for local, S3, GCS, Azure, SFTP, memory
- Large community, well-maintained, v3 has clean PHP 8+ interface
- Streaming, path normalization, MIME detection built in
- Adding cloud = `composer require league/flysystem-aws-s3-v3` + config
- Testing: can use `InMemoryFilesystemAdapter` for fast unit tests

#### Bad
- New Composer dependency
- Flysystem uses its own exceptions and config conventions — must adapt to phpBB patterns
- Flysystem doesn't support `getLocalPath()` or `getAccelRedirectPath()` — need wrapper layer
- Abstraction leak: GD/imagesize operations need local file path, which Flysystem streams don't directly provide

---

## ADR-004: Async Variant Generation via Event Listeners

### Status
Accepted

### Context
Legacy generates thumbnails eagerly at upload time (GD-based, single size: max 400px width). Only one variant type exists. The new service should support multiple variant types (thumbnail, webp, medium) with configurable generation and without blocking the upload path.

### Decision Drivers
- Upload latency must be minimal — image processing is CPU-expensive
- Thumbnails are viewed for virtually every image attachment, so they should be generated promptly
- Orphaned uploads should not waste CPU on variant generation
- Future variant types (webp, medium) should be addable without modifying the upload pipeline
- The architecture is event-driven — variant generation fits naturally as an event listener

### Considered Options
1. **Eager generation** — all variants at upload time (matching legacy)
2. **Lazy/on-demand** — generate on first request, cache result
3. **Hybrid (eager thumb + lazy rest)** — common variants eager, exotic variants lazy
4. **Async via event listener** — `FileStoredEvent` triggers variant generation after upload returns

### Decision Outcome
Chosen option: **Option 4 (Async via event listener)**, because it keeps the upload path fast (no GD processing during `store()`), naturally decouples variant generation from the core upload flow, and leverages the event-driven architecture. `ThumbnailListener` subscribes to `FileStoredEvent` and generates the thumbnail after the upload response is returned. Variants are stored as child rows in `phpbb_stored_files` with `parent_id` pointing to the original.

On-demand fallback: if a variant is requested but not yet generated, `getUrl()` returns the original file URL. Future enhancement: serve endpoint can trigger generation on first request.

### Consequences

#### Good
- Upload path is fast — no image processing blocking the response
- Variant generation is decoupled — add new generators as new event listeners
- Variants stored as regular `stored_files` rows — unified cleanup via `parent_id` CASCADE
- Event listeners can skip non-image files naturally (`supports()` check)

#### Bad
- Brief window where thumbnail hasn't been generated yet (between upload and listener execution)
- On-demand fallback needs implementation for truly lazy generation
- Variants-as-StoredFile rows add noise to `findByOwner()` queries (must filter `WHERE parent_id IS NULL`)
- Variant quota accounting — variants count against uploader's quota

---

## ADR-005: Counter Table with Periodic Reconciliation for Quotas

### Status
Accepted

### Context
Legacy has a single global quota (`attachment_quota` config + `upload_dir_size` counter) that only updates on orphan adoption. No per-user or per-forum quotas exist. The counter is eventually consistent and vulnerable to TOCTOU races under concurrent uploads.

### Decision Drivers
- Quota checks must be O(1) — no `SUM()` query on every upload
- Concurrent uploads must not bypass quota limits (no TOCTOU race)
- Multiple scopes required: global, per-user, per-forum
- Counter drift (from crashes, manual file deletions) must be self-healing
- Orphan files should count against quota (prevent orphan flooding)

### Considered Options
1. **Real-time SUM queries** — `SELECT SUM(filesize) ...` on every upload
2. **Denormalized counter table** — `used_bytes` column, increment/decrement on store/delete
3. **Reservation pattern** — separate `reserved_bytes` and `confirmed_bytes`
4. **Counter + periodic reconciliation** — counter for fast checks, cron recalculates from SUM

### Decision Outcome
Chosen option: **Option 4 (Counter + reconciliation)**, because it combines O(1) performance of counter lookups with the safety net of periodic SUM-based verification. The atomic SQL pattern `UPDATE WHERE used_bytes + size <= max_bytes` provides race-free enforcement in a single statement. The reconciliation cron job (daily) catches drift from edge cases.

### Consequences

#### Good
- O(1) quota checks — single row lookup, no `SUM()` per upload
- Atomic enforcement via `UPDATE WHERE` — no TOCTOU race condition
- Flexible scoping: global/user/forum — each is a row in `phpbb_storage_quotas`
- Self-healing: periodic reconciliation corrects drift automatically

#### Bad
- Counter may drift between reconciliation runs
- Additional table and write overhead (counter update on every upload + delete)
- Reconciliation cron job adds operational complexity

---

## ADR-006: Hybrid File Serving (Public Direct + Private X-Accel-Redirect)

### Status
Accepted

### Context
Legacy serves both avatars and attachments through `web/download/file.php` with two branches: lightweight (no session) for avatars with public caching, and full bootstrap (session + ACL) for attachments with private caching. These two security models are fundamentally incompatible.

### Decision Drivers
- Avatar serving must be fast and CDN-friendly (public, aggressive caching)
- Attachment serving must enforce real-time ACL checks (per-user, per-forum permissions)
- PHP should not stream file bytes when a web server can do it more efficiently
- Environments without X-Accel (shared hosting) must still work
- The Docker deployment uses nginx (X-Accel-Redirect is available)

### Considered Options
1. **Always proxy through PHP** — single code path, PHP sets headers + streams/X-Accel
2. **Signed URLs** — time-limited tokens for direct nginx serving
3. **Hybrid (public direct + private proxied)** — avatars via nginx, attachments via PHP auth
4. **X-Accel-Redirect for all** — PHP does auth, nginx does I/O

### Decision Outcome
Chosen option: **Options 3+4 combined (Hybrid with X-Accel-Redirect)**. Public files (avatars) served directly by nginx — zero PHP involvement. Private files (attachments) go through PHP controller that checks auth, then sends X-Accel-Redirect header. Fallback: PHP streaming for environments without X-Accel.

### Consequences

#### Good
- Avatars served at nginx speed — no PHP overhead
- Attachments properly gated with real-time ACL checks
- X-Accel-Redirect: PHP only handles auth + headers (no memory for file bytes)
- CDN-friendly: public URLs can be cached aggressively

#### Bad
- Two serving paths to maintain and configure
- nginx config must define `internal` location for X-Accel-Redirect
- More complex deployment configuration

---

## ADR-007: Flat Directory Layout (Legacy-Compatible)

### Status
Accepted

### Context
Legacy stores all attachments in a flat `files/` directory and all avatars in a flat `images/avatars/upload/` directory. Hash-based sharding was analyzed for scaling, but backward compatibility and zero-migration take priority.

### Decision Drivers
- Existing files must continue working without physical relocation
- New files must coexist with legacy files in the same directories
- UUID-based filenames already prevent collisions within a flat directory
- phpBB deployments typically have thousands to tens of thousands of files, not millions

### Considered Options
1. **Flat single directory** — continue legacy approach, UUID filenames for new files
2. **Date-based hierarchy** — `YYYY/MM/DD/` subdirectories
3. **Hash-based sharding** — first 2 chars of UUID as subdirectory prefix
4. **Type-based + hash sharding** — asset_type/hash/file

### Decision Outcome
Chosen option: **Option 1 (Flat directory)**, because it requires zero migration and zero nginx config changes. UUID-based filenames guarantee uniqueness. Typical phpBB deployments don't approach ext4 directory limits. Hash-based sharding can be introduced later as a Flysystem path-prefix middleware without schema changes.

### Consequences

#### Good
- Zero migration — existing files work as-is
- Simplest possible path resolution
- nginx static serving config unchanged
- Legacy and new files coexist naturally

#### Bad
- Filesystem performance degrades beyond ~100K files per directory
- No organizational structure at the filesystem level
- If scaling needed later, requires introducing sharding

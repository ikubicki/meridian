# Solution Exploration: `phpbb\storage` Service Design

## Problem Reframing

### Research Question

How should `phpbb\storage` be designed as a shared infrastructure service consumed by plugins (AttachmentPlugin, AvatarPlugin, future plugins), providing multi-level quota enforcement, generalized orphan lifecycle, variant/thumbnail support, and backward compatibility with legacy file paths?

### How Might We Questions

1. **HMW unify file metadata** across attachments, avatars, and future file types without breaking legacy consumers or losing attachment-specific data (download_count, attach_comment)?
2. **HMW choose an ID strategy** that works for URLs, distributed systems, and migration from legacy autoincrement IDs?
3. **HMW abstract the storage backend** so the service works with local filesystems today and cloud storage tomorrow, without over-engineering?
4. **HMW handle derived files** (thumbnails, resized avatars) in a way that's efficient for the common case while extensible for future variant types?
5. **HMW enforce quotas atomically** across global, per-user, and per-forum scopes without introducing significant read/write performance overhead?
6. **HMW serve files** to users given the fundamentally different security models for avatars (public) vs attachments (auth-gated)?
7. **HMW manage temp/orphan files** reliably, closing the legacy gap of "uploads that never get claimed and persist forever"?
8. **HMW organize files on disk** to handle high file counts without filesystem degradation, while keeping legacy paths working?

---

## Decision Area 1: Asset Table Strategy

### Context

Legacy stores attachment metadata in `phpbb_attachments` (15 columns, 5 indexes) and avatar metadata in 4 columns on `phpbb_users` / `phpbb_groups`. These schemas diverge significantly: attachments track `download_count`, `attach_comment`, `is_orphan`, `topic_id`, `post_msg_id`, `in_message`; avatars track `width`, `height`, `type` on the user row. The new storage service needs a metadata strategy that unifies the common parts (file identity, physical location, size, MIME) while allowing consumers to own their domain-specific data.

### Alternative A: Single Unified `stored_files` Table

All file metadata — attachments, avatars, future types — lives in one `phpbb_stored_files` table. Consumer-specific fields (download_count, attach_comment) go into a JSON `metadata` column or are tracked entirely by consumer plugins in their own domain tables.

**Strengths:**
- Single source of truth for all files — simple queries, one repository class
- Clean separation: storage owns generic file data, consumers own domain data
- New file types (e.g., media library, group avatars) need zero schema changes to storage
- Migration script straightforward: INSERT INTO from both legacy sources

**Weaknesses:**
- Consumer-specific queries become cross-table JOINs (e.g., "get attachments for this post with download counts")
- AttachmentPlugin likely needs its own `phpbb_attachment_metadata` table for `download_count`, `attach_comment`, `extension_group_id`
- Legacy code that queries `phpbb_attachments` directly won't work during gradual migration
- JSON metadata column doesn't support indexes for filtering

**Best when:** Building a clean-slate service where consumers are fully refactored to use the new API and own their domain data separately.

**Evidence:** Research report §5 confirms a 12-attribute common core across attachment/avatar metadata. Synthesis Pattern 1 validates the Upload→Validate→Randomize→Store→Track pipeline is universal.

### Alternative B: Keep Separate Tables + Thin Storage Reference Layer

Keep `phpbb_attachments` and avatar columns as-is. Add a new `phpbb_stored_files` table that contains only the physical file identity (physical_name, adapter, storage_path, size, mime_type). `phpbb_attachments` gets a nullable `stored_file_id` FK. Avatar columns remain on the user table with a parallel `stored_file_id` column.

**Strengths:**
- Minimal migration risk — existing queries continue to work unmodified
- Consumer-specific data stays where it is (no new metadata tables needed)
- Gradual migration: new code writes to both tables, old code still reads original
- AttachmentPlugin can JOIN `attachments → stored_files` when it needs physical info

**Weaknesses:**
- Data duplication during transition (physical_filename in both tables)
- Two sources of truth for file existence — need to keep in sync
- Doesn't simplify the codebase — two table patterns persist
- Future consumers still need their own equivalent of `phpbb_attachments`
- Deferred cleanup: eventually you want to consolidate, so this is two migrations instead of one

**Best when:** Migration risk is the top concern and you need existing consumer code to keep working throughout the transition period.

**Evidence:** Research report §18 recommends option B ("less migration risk, allows gradual transition"). Legacy schema inventory (Appendix A) shows significant consumer-specific columns on `phpbb_attachments` that don't belong in generic storage.

### Alternative C: Unified `stored_files` + Type-Specific Extension Tables

One `phpbb_stored_files` table for the common core (file identity, physical location, ownership, lifecycle). Separate extension tables for consumer-specific metadata:
- `phpbb_attachment_metadata` (stored_file_id FK, download_count, attach_comment, extension_group_id)
- `phpbb_avatar_metadata` (stored_file_id FK, avatar_type_driver)
- Future consumers add their own extension tables

**Strengths:**
- Clean normalization: file infrastructure data separated from domain data
- Each consumer owns its schema — can add columns without touching storage tables
- Single authoritative source for "does this file exist?" and "where is it?"
- Supports new file types without schema changes to storage
- Queryable consumer-specific data (proper columns, not JSON)
- Aligns with the plugin architecture: AttachmentPlugin owns `attachment_metadata`, AvatarPlugin owns `avatar_metadata`

**Weaknesses:**
- More tables = more JOINs for consumer queries (stored_files JOIN attachment_metadata)
- More complex migration: must create extension tables AND migrate data from legacy tables
- Extension table per consumer is potential table proliferation
- ORM/hydration complexity (though project uses raw PDO, so manageable)

**Best when:** You want a clean architecture that fully aligns with the plugin model, and you're willing to accept a slightly more complex migration in exchange for long-term maintainability.

**Evidence:** Synthesis Insight 2 ("Claim abstraction decouples storage from business logic") directly supports this pattern. Threads HLD shows AttachmentPlugin as a separate component, implying it should own its domain data. Research report §18 Q1 notes "download_count and attach_comment are attachment-domain data, not storage data."

### Trade-Off Matrix

| Criterion | A: Single Table | B: Thin Reference | C: Unified + Extensions |
|-----------|:-:|:-:|:-:|
| **Complexity** | Low | Medium | Medium-High |
| **Performance** | High (no JOINs for file ops) | Medium (existing queries + FK JOINs) | Medium (JOINs for consumer queries) |
| **Extensibility** | High | Low (each consumer reinvents) | High |
| **Legacy Compat** | Low (breaks existing queries) | High (existing queries untouched) | Medium (new queries needed) |
| **Migration Risk** | Medium | Low | Medium |
| **Plugin Alignment** | High | Low | **High** |
| **Long-term Maintenance** | Medium (JSON metadata is messy) | Low (two patterns persist) | **High** |

### Recommendation: Alternative C — Unified + Extension Tables

**Rationale:** C provides the cleanest separation of concerns and directly mirrors the plugin architecture established by the threads service HLD. Storage owns file infrastructure, plugins own domain data. The extra JOINs are a minor cost relative to long-term clarity.

**Trade-offs accepted:** Slightly more complex initial migration and an extra JOIN for consumer queries. This is acceptable because consumer queries are already JOIN-heavy in legacy code.

**Key assumptions:** AttachmentPlugin and AvatarPlugin will be fully implemented as plugins with their own domain tables. If we were keeping legacy code paths long-term, Alternative B would be safer.

**Risk:** Medium. Migration must create three tables and populate them atomically. If the migration fails mid-way, partial state is harder to recover in C vs B.

---

## Decision Area 2: ID Strategy

### Context

Legacy uses autoincrement `attach_id` for attachments and implicit compound keys (user_id + avatar_type) for avatars. The new service needs a unified ID strategy for `phpbb_stored_files` that works for URL references, API responses, cross-service correlation, and migration from legacy IDs.

### Alternative A: Auto-Increment Integer IDs

Standard MySQL `INT UNSIGNED AUTO_INCREMENT` primary key. Simple, fast, compact.

**Strengths:**
- Smallest storage footprint (4 bytes vs 16 for UUID)
- Fastest inserts (sequential, no fragmentation on clustered index)
- Familiar to phpBB codebase (all existing PKs are autoincrement)
- Easy to reference in URLs: `/file/12345`
- Native MySQL optimization for sequential PKs

**Weaknesses:**
- Predictable/enumerable — exposes file count and enables sequential probing (`/file/1`, `/file/2`, ...)
- Single source of truth for sequence — problems if you ever shard or run parallel import jobs
- Legacy migration must remap existing `attach_id` values or start numbering after max(attach_id)
- Exposes information about total file count to anyone who sees an ID

**Best when:** Single-server deployment, no security concerns about ID enumeration, simplicity is top priority.

**Evidence:** Legacy `phpbb_attachments` uses autoincrement. Research report §5.1 lists `attach_id` as autoincrement. Synthesis confirms single-server phpBB deployments as the norm.

### Alternative B: UUIDs (v4 or v7)

`CHAR(36)` or `BINARY(16)` column with application-generated UUIDs. v4 = random, v7 = time-ordered (2024 RFC 9562).

**Strengths:**
- Non-enumerable — impossible to guess valid file IDs from the outside
- No central sequence needed — safe for concurrent inserts, imports, future sharding
- UUID v7 is time-ordered → mostly sequential inserts → minimizes B-tree fragmentation
- Serves as the directory-sharding prefix (first 2 chars for 256 subdirectories)
- Decouples ID generation from the database (generate in PHP before INSERT)

**Weaknesses:**
- Larger storage: 36 bytes (CHAR) or 16 bytes (BINARY) vs 4 bytes (INT)
- Slightly slower index lookups (wider key, more cache misses on large tables)
- Longer URLs: `/file/ab3f7e2a-1234-5678-9abc-def012345678`
- `CHAR(36)` JOINs are slower than INT JOINs
- Requires PHP UUID library or `random_bytes()` + formatting

**Best when:** Security matters (no enumeration), future-proofing for distributed/sharded deployments, or when IDs must be generated outside the database layer.

**Evidence:** Research report §5.3 proposes `CHAR(36)` UUID. §13.3 notes "UUIDs are 128-bit random — infeasible to enumerate." §14.2 uses UUID prefix for directory sharding.

### Alternative C: Content-Hash Based (SHA-256)

Use `SHA-256(file_content)` truncated to a suitable length as the file ID. Enables implicit deduplication.

**Strengths:**
- Built-in deduplication — identical files get the same ID, only one copy stored
- Content-addressable — given a file, you can compute its ID without DB lookup
- No sequence coordination needed

**Weaknesses:**
- Deduplication complicates ownership model — who "owns" a shared file? Deletion requires refcounting
- Same user uploading the same file to two posts gets the same stored_file — breaks the "one StoredFile per upload" model
- SHA-256 computation adds CPU cost per upload
- Collision handling (theoretical but must be considered for integrity)
- Variants break the model — a thumbnail of the same image at different qualities produces different hashes
- Orphan lifecycle doesn't work cleanly — an existing file hash means "already stored" but may be someone else's claimed file
- Legacy migration: must hash every existing file (I/O intensive)

**Best when:** Deduplication is a primary concern (e.g., CDN/image hosting services with many duplicate uploads). Not well suited for a forum where each upload is typically unique.

**Evidence:** Research report §4.3 lists "No file deduplication" as a gap but rates it **Low** severity. Synthesis does not identify deduplication as a cross-source pattern. phpBB forums typically have low duplicate rates.

### Trade-Off Matrix

| Criterion | A: Auto-Increment | B: UUID (v7) | C: Content-Hash |
|-----------|:-:|:-:|:-:|
| **Complexity** | Low | Low-Medium | High |
| **Performance (insert)** | **High** | Medium-High (v7 is sequential) | Medium (hash computation) |
| **Performance (lookup)** | **High** (4-byte key) | Medium (16/36-byte key) | Medium |
| **Security (enumeration)** | Low | **High** | **High** |
| **Extensibility** | Low (single sequence) | **High** (no coordination) | Medium |
| **Legacy Compat** | High (same pattern) | Medium (new ID format) | Low (must rehash all files) |
| **Deduplication** | None | None | Built-in |
| **Directory Sharding** | Needs separate strategy | Natural (UUID prefix) | Natural (hash prefix) |

### Recommendation: Alternative B — UUID v7

**Rationale:** UUID v7 combines the security benefits of non-enumerable IDs with the performance benefits of time-ordered inserts. It naturally provides the sharding prefix for directory structure (Decision Area 8). PHP 8.2+ makes UUID generation trivial via `random_bytes()`. The wider key cost is acceptable for a table that will have thousands to low millions of rows, not billions.

**Trade-offs accepted:** Larger primary key (36 bytes CHAR or 16 bytes BINARY), slightly longer URLs. Mitigated by using `BINARY(16)` storage with application-level hex formatting.

**Key assumptions:** phpBB deployments won't reach billions of files where UUID index overhead becomes significant. File deduplication is not a priority (research confirms Low severity).

**Implementation note:** Use `BINARY(16)` in MySQL for storage, convert to hex-with-dashes in PHP. UUID v7 preserves time ordering for index locality.

**Risk:** Low. UUID is a well-understood pattern. Only risk is developer UX friction with longer IDs in debugging — mitigated by keeping the hex format human-readable.

---

## Decision Area 3: Storage Adapter Architecture

### Context

Legacy code uses `phpbb\filesystem\filesystem` (Symfony Filesystem wrapper) with hardcoded local paths. There's no abstraction layer — all file I/O is direct `fopen`/`fread`/`readfile` against local paths. The new service needs a storage abstraction that starts with local filesystem and can extend to cloud (S3, GCS) in the future.

### Alternative A: Simple Custom Interface

Define a minimal `StorageAdapterInterface` with 6-8 methods (`write`, `read`, `delete`, `exists`, `size`, `getLocalPath`, `getDirectUrl`, `getAccelRedirectPath`). Implement `LocalAdapter` first.

**Strengths:**
- Complete control over the interface — tailored to phpBB's specific needs
- Minimal dependency footprint (no new Composer packages)
- Methods like `getAccelRedirectPath()` and `getLocalPath()` are domain-specific — not found in generic libraries
- Easy to understand and debug — no framework abstraction layers
- Aligns with project's "no unnecessary dependencies" philosophy

**Weaknesses:**
- Must implement and test all adapter logic yourself
- Cloud adapters (S3, GCS) are non-trivial to implement correctly (retry logic, multipart upload, presigned URLs)
- No ecosystem support — no community-maintained adapters
- Risk of reinventing what Flysystem already solves

**Best when:** The project values minimal dependencies, cloud storage is far-future, and the adapter interface needs domain-specific methods not found in generic libraries.

**Evidence:** Research report §6.2 proposes this exact interface with domain-specific methods. Current codebase uses Symfony Filesystem directly (minimal abstraction tradition).

### Alternative B: Flysystem Integration (league/flysystem v3)

Wrap `league/flysystem` v3 as the underlying storage engine. Internal `StorageAdapterInterface` delegates to Flysystem's `FilesystemOperator`. Domain-specific methods (`getAccelRedirectPath`, etc.) are added on top.

**Strengths:**
- Battle-tested local, S3, GCS, Azure, SFTP, memory adapters out of the box
- Large community, well-maintained, v3 has clean modern PHP interface
- Streaming, visibility (permissions), MIME detection built in
- Reduces testing burden — Flysystem adapters are extensively tested
- Adding cloud storage later = `composer require league/flysystem-aws-s3-v3` + config

**Weaknesses:**
- New Composer dependency (flysystem/flysystem + adapter packages)
- Flysystem doesn't support `getAccelRedirectPath()` or `getLocalPath()` — need wrapper layer anyway
- Flysystem v3 uses its own exceptions, path normalization, and config conventions — must adapt to phpBB's patterns
- Abstraction leak: some operations (image processing, GD) need a local file path, which Flysystem's stream-based API doesn't directly provide
- Flysystem v3 doesn't support PHP `resource` reads natively (uses string content or streams)

**Best when:** Cloud storage support is a realistic near-term goal, or the team wants to minimize adapter implementation and testing effort.

**Evidence:** Flysystem is the de facto PHP filesystem abstraction. Research report §6.4 notes future S3/GCS adapters as desirable. However, no existing phpBB code uses Flysystem.

### Alternative C: Strategy Pattern with Local-First + Pluggable Backends

Define the interface (like Alternative A), but implement it as a Strategy pattern where `StorageService` accepts a `StorageAdapterInterface` via DI. Ship only `LocalAdapter`. When cloud is needed, a new adapter can either wrap Flysystem or use cloud SDKs directly.

**Strengths:**
- Zero new dependencies up front (local adapter is pure PHP)
- Interface is clean and domain-specific
- When cloud is needed, the team can choose Flysystem, raw SDK, or anything else — decision is deferred
- Testable: mock the interface in unit tests, use `MemoryAdapter` for integration tests
- Most pragmatic: don't pay for abstractions you don't need yet

**Weaknesses:**
- If you eventually wrap Flysystem, you've written an interface that Flysystem already provides (some wasted effort)
- Cloud adapter becomes a future sprint, not "just add a dependency"
- `getLocalPath()` method may not translate cleanly to cloud (temp file download required)

**Best when:** You want clean architecture without premature abstraction. Local filesystem is the only target for the foreseeable future.

**Evidence:** Synthesis recommendation: "Start with LocalAdapter as minimum viable." Research report §6.1 confirms all current code is local filesystem. Cloud storage is explicitly "future iteration" in research findings §17.

### Alternative D: Symfony Filesystem + Custom Adapter Protocol

Extend the existing `phpbb\filesystem\filesystem` (Symfony Filesystem wrapper already in codebase) with adapter-like capabilities. Add a thin interface on top.

**Strengths:**
- Builds on an already-present dependency (Symfony Filesystem)
- No new packages to add
- Team already familiar with Symfony Filesystem API

**Weaknesses:**
- Symfony Filesystem is low-level (file ops, not storage abstraction) — no streaming, no cloud support path
- Would need significant extension to support `read()` as stream, `getDirectUrl()`, etc.
- Symfony Filesystem doesn't model "adapters" or "backends"
- Essentially becomes Alternative A but constrained by Symfony's API surface
- No community adapters for cloud storage

**Best when:** Not recommended — this is effectively Alternative A with unnecessary constraints from Symfony's API.

**Evidence:** Research report §6.1 notes the current Symfony Filesystem wrapper has "no streaming abstraction" and "tight coupling to local filesystem." The research explicitly identifies this as a gap.

### Trade-Off Matrix

| Criterion | A: Custom Interface | B: Flysystem | C: Strategy/Local-First | D: Symfony Extend |
|-----------|:-:|:-:|:-:|:-:|
| **Complexity** | Low-Medium | Medium | **Low** | Medium |
| **Performance** | **High** (direct I/O) | Medium (abstraction layer) | **High** | **High** |
| **Extensibility (cloud)** | Medium (DIY adapters) | **High** (ecosystem) | **High** (deferred choice) | Low |
| **Dependencies** | **None** | New package | **None** | Existing |
| **Legacy Compat** | **High** | Medium | **High** | **High** |
| **Testing** | Medium (manual) | **High** (pre-tested) | Medium + MemoryAdapter | Medium |
| **Domain-Specific Ops** | **High** (tailored API) | Low (needs wrapper) | **High** | Low |

### Recommendation: Alternative C — Strategy Pattern, Local-First

**Rationale:** C gives the best of all worlds: a clean domain-specific interface, zero new dependencies, and full freedom to adopt Flysystem or raw SDKs when cloud storage becomes a real requirement. It follows the research's explicit recommendation to "defer S3/cloud adapter to a future iteration."

**Trade-offs accepted:** Cloud adapter will be a future implementation effort rather than a configuration change. This is acceptable because the research confirms cloud storage is not a near-term requirement.

**Key assumptions:** Local filesystem remains the primary backend for the foreseeable future. If cloud storage were needed in the next 6 months, Alternative B would be preferred.

**Risk:** Low. The interface is simple enough (6-8 methods) that writing a LocalAdapter is trivial. Future cloud adapter work is bounded and can optionally wrap Flysystem at that point.

---

## Decision Area 4: Variant/Thumbnail Strategy

### Context

Legacy generates thumbnails eagerly at upload time, stored as `thumb_{filename}` in the same directory, tracked by a single boolean column. Only one thumbnail size exists (max 400px width). No other variant types. The new service needs to support multiple variant types (thumbnail, medium, small, webp) with configurable generation timing.

### Alternative A: Eager Generation (All Variants at Upload Time)

When a file is uploaded, immediately generate all configured variants (thumbnail, medium, etc.) and store them alongside the original.

**Strengths:**
- Simple serving path — variants always exist, no conditional generation logic
- First request for a variant is always fast (pre-generated)
- Matches legacy behavior for thumbnails (proven pattern)
- No complex on-demand generation infrastructure needed
- Consistent storage usage — admin can predict space consumed per upload

**Weaknesses:**
- Wastes storage for variants nobody views (e.g., medium-size image that nobody clicks)
- Upload latency increases with each additional variant type
- Changing variant configuration requires regeneration of all existing files
- If the upload is orphaned and never claimed, variants are generated for nothing
- CPU spike during upload (image processing is expensive)

**Best when:** Only a small number of variant types exist (1-2), variants are viewed frequently, and upload volume is moderate.

**Evidence:** Legacy uses eager thumbnail generation. Research report §10.1 confirms single-size-only at upload time. Synthesis Pattern 5 notes this is the established approach.

### Alternative B: Lazy/On-Demand Generation

Store only the original. Generate variants when first requested. Cache the generated variant to disk for subsequent requests.

**Strengths:**
- Zero storage waste — only generates variants that are actually viewed
- Upload is fast — no image processing during upload
- Easy to add new variant types — generate on first request, no backfill needed
- Orphaned files cost minimal storage (no wasted variants)
- Configuration changes (e.g., new thumbnail size) apply to all future requests without migration

**Weaknesses:**
- First request for a variant is slow (must generate on the fly)
- Serving logic becomes more complex (check if variant exists → generate → serve)
- Concurrent first requests for the same variant can trigger duplicate generation (race condition)
- Requires write access from the serving path (which may be a different process)
- GD image processing during HTTP request increases response time and memory usage

**Best when:** Many variant types configured, most variants rarely viewed, or upload volume is high and upload latency matters.

**Evidence:** Research report §10.3 notes lazy as an option but recommends hybrid. Synthesis conclusions §5 recommends "lazy/on-demand for thumbnails" — however, the research report's detailed analysis in §10.3 contradicts this by noting thumbnails are "almost always needed."

### Alternative C: Hybrid (Common Variants Eager, Exotic Variants Lazy)

Generate the most commonly used variant(s) at upload time (e.g., thumbnail). Other variants (medium, webp, etc.) are generated on first request.

**Strengths:**
- Best of both worlds — thumbnails available instantly (they're displayed in post listings and topic views)
- Less common variants don't waste storage or upload time
- Legacy-compatible for the thumbnail case
- Flexible: per-variant-type configuration of eager vs lazy

**Weaknesses:**
- More complex implementation — two code paths (eager + lazy)
- Must decide which variants are "common enough" for eager generation
- Lazy path still has the first-request latency issue (needs locking for concurrent generation)
- Configuration is more complex (per-variant timing rules)

**Best when:** Thumbnails are nearly always viewed (confirmed by phpBB usage patterns) but additional variants may be added in the future.

**Evidence:** Research report §10.3 explicitly recommends hybrid: "thumbnails eager, other sizes lazy." Legacy thumbnails are displayed inline in topic views — almost always viewed. Research findings §17 item 5 proposed lazy but the detailed recommendation in §10.3 refined this to hybrid.

### Alternative D: Variants as Separate StoredFiles with `parent_id` Link

Instead of a separate `file_variants` table, store variants as regular `stored_files` rows with a `parent_id` foreign key. Variant type is an attribute on the stored file record.

**Strengths:**
- Single table for all files — variants and originals use the same infrastructure
- Variants inherit all StoredFile features (quota tracking, adapter awareness, cleanup)
- No separate variant table or repository needed
- Deletion: ON DELETE CASCADE on parent_id, or the same orphan cleanup logic

**Weaknesses:**
- Conflates two concepts: a variant is not an independent "stored file" — it's derived and has no independent lifecycle
- Queries for "all files owned by user X" must filter out variants (noise in results)
- Quota accounting becomes confusing — should variants count against the user's quota?
- `findByOwner()` must exclude variants or include a flag — complicates the core API
- Breaks the clean `stored_files` + `file_variants` separation proposed in the research

**Best when:** You want maximum simplicity in the schema and are willing to add filtering logic in queries.

**Evidence:** Research report §5.4 proposes a separate `phpbb_file_variants` table with FOREIGN KEY to `phpbb_stored_files`, explicitly choosing the separated model.

### Trade-Off Matrix

| Criterion | A: Eager | B: Lazy | C: Hybrid | D: Variants-as-Files |
|-----------|:-:|:-:|:-:|:-:|
| **Complexity** | **Low** | Medium | Medium-High | Low |
| **Performance (upload)** | Low (CPU during upload) | **High** | Medium | **High** |
| **Performance (first view)** | **High** (pre-generated) | Low | **High** for thumbs | **High** (pre-gen or lazy) |
| **Storage Efficiency** | Low (all variants always) | **High** | **High** | Depends on strategy |
| **Extensibility** | Low (must regenerate) | **High** (add types freely) | **High** | **High** |
| **Legacy Compat** | **High** (matches legacy) | Low | **High** (thumbnails match) | Medium |
| **Schema Cleanliness** | — | — | — | Low (conflated concepts) |

### Recommendation: Alternative C — Hybrid (Thumbnails Eager, Others Lazy)

**Rationale:** Thumbnails are displayed in topic views and post listings — they're viewed for virtually every image attachment. Generating them eagerly eliminates first-view latency for the most common case. Future variant types (medium, webp) are much less frequently requested and can be generated lazily, avoiding storage waste and upload CPU overhead.

**Trade-offs accepted:** Two code paths for variant generation. Lazy path needs basic locking (filesystem lock or DB flag) to prevent duplicate concurrent generation.

**Key assumptions:** Thumbnails are indeed viewed for most image uploads. If the forum had a use case where thumbnails were rarely displayed, eager would be wasteful.

**Implementation note:** Use the separate `phpbb_file_variants` table from the research report. Eager variants are generated after the file is stored, before `store()` returns. Lazy variants are generated on first `getVariant()` call.

**Risk:** Low for eager thumbnails (proven legacy pattern). Medium for lazy generation (first-request latency, concurrent generation races). Mitigate with filesystem-level locking.

---

## Decision Area 5: Quota Architecture

### Context

Legacy has a single global quota (`attachment_quota` config + `upload_dir_size` counter). The counter is only updated on orphan adoption, not upload — creating a consistency gap. No per-user or per-forum quotas exist. Avatars have no quota at all (implicit: one file per user × max file size). The new system needs multi-level quotas (global + per-user + per-forum) with atomic enforcement.

### Alternative A: Real-Time SUM Queries

No denormalized counters. Every quota check runs `SELECT SUM(size) FROM stored_files WHERE ...` against the actual file table.

**Strengths:**
- Always accurate — no counter drift, no reconciliation needed
- Simplest mental model — one source of truth
- No additional table or counter infrastructure needed
- Orphan files naturally included in quota (they're in the table)

**Weaknesses:**
- Performance: `SUM()` over potentially thousands of rows on every upload
- Even with indexes (`owner_id, owner_type`), this adds latency under concurrent uploads
- Per-forum quota requires JOINing through `owner_ref` to resolve forum → SUM, which is O(N) in attachments
- Database load increases linearly with file count
- Under high concurrency, two uploads can both read the SUM before either inserts — TOCTOU race

**Best when:** File counts per scope are small (< 1,000), upload frequency is low, and simplicity trumps performance.

**Evidence:** Research report §9.2 identifies the TOCTOU race in the legacy counter approach. Real-time SUM avoids counter staleness but doesn't solve the race.

### Alternative B: Denormalized Counter Table (Fast Check)

A `phpbb_storage_quotas` table with `(scope_type, scope_id, limit_bytes, used_bytes, file_count)`. Counters are incremented/decremented on store/delete.

**Strengths:**
- Fast quota checks — single row lookup, no SUM needed
- Atomic enforcement via `UPDATE WHERE used_bytes + :size <= limit_bytes` (single statement, no race)
- Flexible scoping: global, per-user, per-forum — each is a row
- Clean API: `QuotaService::reserve()` / `release()` / `reconcile()`
- Matches the well-understood pattern from e-commerce (inventory reservation)

**Weaknesses:**
- Counter drift possible if store/delete fails mid-operation (crash between file write and counter update)
- Needs periodic reconciliation job to correct drift
- Additional table and write overhead (update counter on every upload + delete)
- Orphan files must be either counted in quota (reserve on upload) or reconciled separately

**Best when:** Upload frequency is moderate to high, multiple quota scopes are needed, and you can tolerate occasional counter drift with reconciliation.

**Evidence:** Research report §9.3–§9.5 proposes exactly this pattern with atomic SQL update. Synthesis Pattern 6 identifies the legacy counter approach as "fragile" but confirms the pattern is workable with atomic updates.

### Alternative C: Quota Reservation Pattern (Pre-allocate → Commit → Release)

Three-phase approach: (1) **Reserve** quota on upload (atomic increment), (2) **Confirm** on claim (mark reservation as permanent), (3) **Release** if orphan expires (decrement). Separate `reserved_bytes` and `confirmed_bytes` columns.

**Strengths:**
- Most accurate: distinguishes between tentative (orphan) and confirmed (claimed) usage
- Admin can see both "actual usage" and "reserved (pending) usage"
- Clean integration with the orphan lifecycle: reserve on store, confirm on claim, release on cleanup
- No quota bypass through orphan flooding (reserved space counts against limit)

**Weaknesses:**
- Most complex implementation — three operations per file lifecycle instead of two
- Additional columns and logic: `reserved_bytes` + `confirmed_bytes` vs just `used_bytes`
- Over-engineered for a forum where orphan volumes are typically small
- Confirmation step is essentially a no-op in most quota implementations (quota is already consumed)
- If the only consumer distinction is "count orphans or not," a simpler boolean suffices

**Best when:** Orphan volumes are significant, and the distinction between "reserved" and "confirmed" usage affects admin decisions.

**Evidence:** Research report §9.4 proposes reserve/confirm/release. Synthesis Insight 3 emphasizes quota must be checked at upload time. However, the detailed implementation in §9.5 simplifies to a single `used_bytes` with atomic UPDATE (no reserved/confirmed distinction).

### Alternative D: Hybrid — Counter for Fast Check + Periodic SUM Reconciliation

Use the denormalized counter (Alternative B) for all real-time checks and enforcement. Run a periodic reconciliation job that recalculates actual usage from `SUM(size)` and corrects counter drift.

**Strengths:**
- Fast real-time checks (counter lookup)
- Atomic enforcement (same as B)
- Self-healing: drift is automatically corrected on schedule
- Simple mental model: "counter is the source of truth for enforcement, SUM is the audit"
- Admin can trigger reconciliation manually if needed

**Weaknesses:**
- Between reconciliations, counter may be slightly inaccurate (but always in a safe direction if we only undercount)
- Reconciliation job adds cron complexity
- Same as Alternative B in practice — the reconciliation is just a safety net

**Best when:** You want the performance of counters with the correctness guarantee of periodic verification.

**Evidence:** Synthesis Pattern 6 explicitly recommends "periodic reconciliation job." Research report §9.5 provides the atomic SQL pattern. §9.4 proposes reconciliation as part of the quota service interface.

### Trade-Off Matrix

| Criterion | A: Real-Time SUM | B: Counter Table | C: Reservation | D: Counter + Reconciliation |
|-----------|:-:|:-:|:-:|:-:|
| **Complexity** | **Low** | Medium | High | Medium |
| **Performance** | Low (SUM per upload) | **High** | **High** | **High** |
| **Accuracy** | **Perfect** (real-time) | Good (drift possible) | **Perfect** | Good + self-healing |
| **Race Safety** | Low (TOCTOU) | **High** (atomic SQL) | **High** | **High** |
| **Multi-Level Scopes** | Medium (complex SUMs) | **High** | **High** | **High** |
| **Legacy Compat** | Different pattern | Evolved pattern | Different pattern | Evolved pattern |
| **Maintenance** | **Low** | Medium | High | Medium |

### Recommendation: Alternative D — Counter + Periodic Reconciliation

**Rationale:** D is effectively B with a safety net. The atomic `UPDATE WHERE used_bytes + size <= limit_bytes` pattern from the research report provides race-free enforcement with O(1) performance. The reconciliation job catches drift from edge cases (crashes, manual file deletions). This is the pattern explicitly synthesized from the research findings.

**Trade-offs accepted:** Counter may drift slightly between reconciliation runs. In practice, drift direction is conservative (undercount, not overcount) since stores always increment.

**Key assumptions:** Counter drift is rare and small. phpBB cron system can reliably schedule reconciliation (daily is sufficient).

**Risk:** Low. The atomic SQL pattern is proven across many systems. Reconciliation is a simple `SUM()` vs counter comparison.

---

## Decision Area 6: File Serving Strategy

### Context

Legacy uses `web/download/file.php` as a single entry point with two branches: lightweight (no session) for avatars with public caching, and full bootstrap (session + ACL) for attachments with private caching. Both support X-Accel-Redirect/X-Sendfile. The new service must serve public files (avatars, gallery) and private files (attachments) with fundamentally different security models.

### Alternative A: Always Proxy Through PHP

All file requests go through a PHP controller that resolves the file, applies headers, and streams content (or delegates to X-Accel-Redirect).

**Strengths:**
- Maximum control over access, headers, logging, download counting
- Single code path for all file types — just different auth middleware per endpoint
- PHP sets correct MIME type, Content-Disposition, cache headers, security headers
- X-Accel-Redirect/X-Sendfile eliminates the actual streaming cost — PHP only does auth + headers
- Easy to add features: rate limiting, hotlink protection, download counting
- Legacy-compatible: same pattern as existing `file.php`

**Weaknesses:**
- Every file request boots PHP (even with opcache, there's overhead)
- For public files (avatars), PHP is unnecessary overhead
- CDN integration is less natural — CDN must cache the proxied response rather than serving directly
- Higher server CPU usage under heavy avatar serving load

**Best when:** Security and control are paramount, X-Accel-Redirect is available (nginx setups), or all files need some form of access logging.

**Evidence:** Legacy uses this exact pattern. Research report §11 confirms X-Accel-Redirect support. Docker setup uses nginx (X-Accel available).

### Alternative B: Signed URLs to Static Files

Generate time-limited signed URLs that allow direct nginx/CDN serving of files. PHP generates the URL, nginx serves the file directly using a signed token.

**Strengths:**
- Zero PHP involvement in file serving (highest performance)
- CDN-native — signed URLs work with CloudFront, Cloudflare, etc.
- Scales horizontally — nginx serves files without hitting PHP
- Reduces PHP memory usage (no streaming)

**Weaknesses:**
- Complex setup: nginx must validate signatures (needs `ngx_http_secure_link_module` or similar)
- Revoking access is delayed — signed URLs remain valid until expiry
- Attachment permissions are dynamic (ACL changes, topic moves, soft-deletes) — signed URLs can't reflect real-time permission changes
- Different signing logic needed for each web server (nginx, Apache, IIS)
- Per-file-size limits and download counting require additional infrastructure
- Doesn't match existing phpBB deployment patterns

**Best when:** Very high traffic on file serving, CDN is the primary serving path, and access control can tolerate some delay in permission changes.

**Evidence:** Research report §11.2 mentions signed URLs as a possibility for public files. However, the research also notes that attachment serving requires real-time ACL checks per-forum.

### Alternative C: Hybrid — Public Assets Direct, Private Assets Proxied

Two distinct serving modes:
- **Public mode** (avatars, gallery): Direct nginx serving from static paths, or lightweight PHP proxy without auth
- **Private mode** (attachments): Full PHP proxy with session + ACL + forum auth middleware

**Strengths:**
- Optimal performance per file type — avatars served at nginx speed, attachments properly gated
- Mirrors the legacy architecture's two-branch approach (but cleaner)
- Public files can be CDN-cached with long TTLs
- Private files maintain real-time access control
- Auth-unaware storage service exposes both URL types — consumer decides which to use

**Weaknesses:**
- Two serving paths to maintain and configure
- Nginx config must be aware of public file paths (direct access) vs protected paths (proxy only)
- Changing a file from public to private (or vice versa) requires URL changes
- More complex deployment configuration

**Best when:** Performance matters for public assets (avatars, gallery) and security matters for private assets (attachments). This is the standard phpBB use case.

**Evidence:** Research report §11 and Synthesis Insight 4 explicitly state "Two serving modes are architecturally necessary" and "cannot unify avatar serving with attachment serving into a single strategy." This is the most strongly supported option.

### Alternative D: X-Accel-Redirect / X-Sendfile (PHP Auth → Web Server Serves)

PHP handles authentication and header generation, then sends an `X-Accel-Redirect` (nginx) or `X-Sendfile` (Apache) header. The web server serves the file directly from disk, bypassing PHP's streaming.

**Strengths:**
- PHP handles auth (full control), web server handles I/O (maximum performance)
- No PHP memory used for file streaming
- Works for both public and private files — PHP just sets different cache headers
- Single code path in PHP; web server config determines actual serving behavior
- Already supported in legacy codebase

**Weaknesses:**
- Requires web server configuration (nginx `internal` location, Apache mod_xsendfile)
- Not available on all hosting environments (shared hosting typically lacks this)
- PHP still boots for every request (auth overhead for public files)
- Fallback needed for environments without X-Accel support (PHP streaming)

**Best when:** nginx or Apache with mod_xsendfile is the deployment target, and you want clean auth + fast serving in a single code path.

**Evidence:** Research report §11.1 confirms X-Accel-Redirect is already implemented in legacy. Docker config uses nginx. The existing `functions_download.php` has X-Accel support.

### Trade-Off Matrix

| Criterion | A: Always Proxy | B: Signed URLs | C: Hybrid | D: X-Accel-Redirect |
|-----------|:-:|:-:|:-:|:-:|
| **Complexity** | **Low** | High | Medium | Medium |
| **Performance (public files)** | Low | **High** | **High** | Medium-High |
| **Performance (private files)** | Medium (PHP streams) | **High** | Medium | **High** |
| **Security Control** | **High** | Low (delayed revocation) | **High** | **High** |
| **Legacy Compat** | **High** | Low | **High** | **High** |
| **CDN Friendly** | Low | **High** | Medium | Medium |
| **Deployment Simplicity** | **High** | Low | Medium | Medium |

### Recommendation: Alternative C + D Combined — Hybrid with X-Accel-Redirect

**Rationale:** The research explicitly concludes that two serving modes are "non-negotiable." Public files (avatars) should be served directly or via lightweight proxy. Private files (attachments) should use the X-Accel-Redirect pattern (PHP performs auth, nginx serves file). This combines the best of C and D:

1. **Public files**: Direct nginx serving (or lightweight PHP redirect to static path). `StorageService::getPublicUrl()` returns a path nginx serves directly.
2. **Private files**: PHP controller checks auth, sends `X-Accel-Redirect` header. Fallback: PHP streaming for environments without X-Accel. `StorageService::getAuthenticatedUrl()` returns a controller URL.

**Trade-offs accepted:** Two serving paths, nginx configuration required. Fallback to PHP streaming for shared hosting.

**Key assumptions:** nginx is the primary deployment target (confirmed by Docker config). X-Accel-Redirect is available.

**Risk:** Low. This mirrors the existing legacy architecture but with cleaner separation. The X-Accel pattern is proven in the current codebase.

---

## Decision Area 7: Orphan/Temp File Management

### Context

Legacy attachments use an orphan pattern (INSERT with `is_orphan=1` → UPDATE to claim on post submit) but **never clean up** abandoned orphan DB rows or their physical files. Plupload temp files have a separate cleanup mechanism. Avatars don't use orphans at all — uploads are immediately associated. The new system needs reliable orphan management with guaranteed cleanup.

### Alternative A: DB-Tracked Orphans with TTL-Based Cron Cleanup

All uploads create a `StoredFile` row with `claimed_at = NULL`. Cron job periodically queries `WHERE claimed_at IS NULL AND created_at < (NOW - TTL)` and deletes both the row and physical file.

**Strengths:**
- Simple, proven pattern (legacy approach minus the cleanup gap)
- Single storage location — no separate "temp" zone to manage
- Orphan files are queryable (admin can see pending uploads)
- Quota reservation at upload time naturally includes orphans
- Matches the `store() → claim() → cleanup()` lifecycle from the research
- Minimal infrastructure: a cron job + one query

**Weaknesses:**
- DB rows for files that may never be claimed
- Cron job must be reliable — if cron fails, orphans accumulate
- TTL must be long enough for editing sessions (24h recommended)
- Between cron runs, orphans beyond TTL still exist

**Best when:** Simplicity is valued, the orphan pattern is well-understood, and the cron infrastructure is reliable.

**Evidence:** Research report §8 proposes exactly this pattern. Synthesis Pattern 2 validates the orphan-then-claim lifecycle. §8.4 proposes 24h TTL matching plupload temp cleanup.

### Alternative B: Temp Storage Zone → Move to Permanent on Claim

Uploaded files go to a `storage/temp/` directory. On claim, the file is **moved** to the permanent `storage/files/` directory. Cleanup is filesystem-level: delete everything in temp/ older than TTL.

**Strengths:**
- Clear physical separation — temp files can't be confused with permanent files
- Filesystem-level cleanup is simpler than DB queries (just delete old files)
- Don't need a DB row until the file is claimed (reduces orphan row noise)

**Weaknesses:**
- File move during claim adds I/O overhead (potentially cross-filesystem if temp is on different mount)
- Must track temp files somewhere (DB or filesystem) for ownership verification
- Without DB tracking, can't enforce quota on temp files or validate ownership
- Rename/move failures during claim create complex error recovery
- Two storage paths to configure and manage
- Physical filenames may change on move (breaking any pre-claim references)

**Best when:** You want physical isolation of temp files and don't need DB tracking of orphans.

**Evidence:** Research does not propose this pattern. The research consistently assumes all files (including orphans) are in the `stored_files` table. Moving files adds unnecessary I/O.

### Alternative C: Same Storage, Soft-Delete Flag, Cleanup on Schedule

All files stored in the same location. Unclaimed files get a `status` column (`orphan`, `claimed`, `soft_deleted`). Cron job processes each status separately: orphans beyond TTL → delete, soft-deleted files → permanent delete after grace period.

**Strengths:**
- Unified status model — can track multiple lifecycle states
- Grace period for soft-deletes (admin can recover accidentally deleted files)
- Single storage location, single table, one cron job handles all cleanup

**Weaknesses:**
- More complex status model than needed — `claimed_at IS NULL` is sufficient for orphan detection
- Soft-delete flag conflates storage-level deletion with domain-level deletion (which is consumer responsibility)
- AttachmentPlugin's soft-delete (post visibility states) is a domain concern, not a storage concern
- Over-engineered: the research question is about orphans, not general lifecycle management

**Best when:** You need soft-delete with recovery at the storage level (not typical for a storage infrastructure service).

**Evidence:** Research report doesn't propose soft-delete at the storage level. The threads HLD handles visibility states (soft-delete equivalent) in the domain layer, not infrastructure.

### Alternative D: Event-Based ClaimTimeout After TTL

Instead of cron polling, emit a `ClaimTimeoutEvent` after TTL expires (using a delayed queue or scheduler). Event listeners decide the fate of each expired orphan.

**Strengths:**
- Decoupled: storage service doesn't decide what happens on timeout — listeners do
- Consumers can implement custom timeout behavior (e.g., extend TTL for drafts)
- Aligns with the event-driven architecture pattern

**Weaknesses:**
- Requires a reliable delayed event/queue system (phpBB cron is simple periodic, not a delayed queue)
- Over-engineered for "delete after 24h" — a cron query is simpler and more reliable
- Event listeners still need to call `storage.delete()` — just adds indirection
- phpBB doesn't have message queue infrastructure; implementing one for orphan cleanup is disproportionate

**Best when:** The application has a robust message queue/delayed event system. Not appropriate for phpBB's cron-based scheduling.

**Evidence:** Research report §8.3 proposes the simple cron approach, not events. The phpBB cron system is a simple periodic checker, not a delayed queue.

### Trade-Off Matrix

| Criterion | A: DB-Tracked + Cron | B: Temp Zone + Move | C: Soft-Delete Flags | D: Event-Based |
|-----------|:-:|:-:|:-:|:-:|
| **Complexity** | **Low** | Medium | Medium | High |
| **Performance** | **High** (no file moves) | Low (move on claim) | **High** | Medium |
| **Reliability** | High (DB + cron) | Medium (filesystem) | High | Low (needs queue) |
| **Extensibility** | Medium | Low | Medium | **High** |
| **Legacy Compat** | **High** (same pattern) | Low (different approach) | Medium | Low |
| **Quota Integration** | **Easy** (files in DB) | Hard (temp not tracked) | **Easy** | Medium |

### Recommendation: Alternative A — DB-Tracked Orphans with TTL Cron

**Rationale:** This is the simplest approach that solves the problem. It's the pattern already established by legacy (minus the cleanup gap), well-understood by the codebase, and directly supported by the `claimed_at IS NULL` column on `stored_files`. No file moves, no extra statuses, no event infrastructure.

**Trade-offs accepted:** Orphan DB rows exist briefly. Cron must be reliable.

**Key assumptions:** phpBB cron runs reliably. 24h TTL covers long editing sessions. Orphan volume is small (users don't upload thousands of files without submitting).

**Configuration:** `storage_orphan_ttl = 86400` (24h), `storage_orphan_gc_interval = 3600` (hourly cron check).

**Risk:** Very low. This is a proven pattern with minimal moving parts.

---

## Decision Area 8: Directory/Path Strategy

### Context

Legacy stores all attachments in a flat `files/` directory and all avatars in a flat `images/avatars/upload/` directory. With high file counts, flat directories degrade filesystem performance (especially ext4 directory entry lookup). The new service needs a directory structure that scales while maintaining backward compatibility with legacy files.

### Alternative A: Flat Single Directory (Legacy Approach)

Continue using flat directories. New files go into `storage/files/` without subdirectories.

**Strengths:**
- Simplest possible path resolution
- Matches legacy behavior exactly
- No directory creation logic needed
- Easy to list/search all files

**Weaknesses:**
- Filesystem performance degrades beyond ~100K files per directory (ext4 `dir_index` helps but has limits)
- Cannot be used with cloud storage that has per-prefix listing limits
- No organizational structure — all files mixed together

**Best when:** File count will remain small (< 50K total) and simplicity is the only concern.

**Evidence:** Research report §4.3 lists "Flat directory (no sharding)" as a gap with **Medium** severity. Synthesis Pattern 4 identifies this as a scaling concern.

### Alternative B: Date-Based Hierarchy (YYYY/MM/DD/)

Organize files by upload date: `storage/files/2026/04/19/{uuid}.jpg`.

**Strengths:**
- Human-readable directory structure — easy to find files from a specific date
- Natural archival: old months/years can be moved to cold storage
- Directories are evenly distributed over time (assuming steady upload rate)
- Common pattern in WordPress, Drupal, etc.

**Weaknesses:**
- Uneven distribution: busy days have many files, quiet days have few
- Requires knowing the upload date to resolve a path (extra DB lookup if not in URL)
- Large forums may still have 10K+ files per day-directory
- Date is already in the `created_at` column — redundant in the path
- Doesn't help with legacy file compatibility (legacy files aren't date-organized)

**Best when:** Files have a natural temporal organization and archival by date is useful.

**Evidence:** Not proposed in the research report. The research favors hash-based sharding over date-based organization.

### Alternative C: Hash-Based Sharding (First 2 Chars of UUID)

Use the first 2 hex characters of the UUID as a subdirectory: `storage/files/ab/{uuid}.jpg`. Creates 256 subdirectories with even distribution.

**Strengths:**
- Even distribution guaranteed (UUID v7 has random component in lower bits)
- 256 directories × ~4,000 files each = ~1M files before any directory exceeds ext4 comfort zone
- UUID prefix is always available — no extra lookup needed for path resolution
- Simple implementation: `substr($uuid, 0, 2) . '/' . $uuid . '.' . $ext`
- Scales to millions of files without directory entry performance issues
- Natural fit with UUID-based ID strategy (Decision Area 2)

**Weaknesses:**
- UUID v7 time-ordering means first 2 chars may cluster temporally (top bits are timestamp)
- Slight complexity over flat directory
- Legacy files don't follow this pattern (separate adapter needed)
- Directory listing requires knowing the prefix (can't `ls` all files easily)

**Best when:** UUID IDs are chosen and file count may grow to hundreds of thousands or more.

**Evidence:** Research report §14.2 proposes exactly this: `{first2chars}/{uuid}.{ext}`. §14.3 shows the directory structure. Note: for UUID v7, the first 2 chars are time-based (top 8 bits of timestamp). Using chars from the middle/random portion would give better distribution, or simply accepting that time-clustering is fine since the goal is to avoid single-directory overload, not achieve perfect distribution.

### Alternative D: Type-Based + Hash Sharding

Combine file type and hash sharding: `storage/files/attachments/ab/{uuid}.jpg`, `storage/files/avatars/cd/{uuid}.jpg`.

**Strengths:**
- Physical separation by file type — can apply different backup/replication policies per type
- Easier admin operations: "how much space do attachments use?" → `du -sh storage/files/attachments/`
- Can mount different filesystems per type (avatars on SSD for fast public serving, attachments on HDD)
- Hash sharding within each type handles scaling

**Weaknesses:**
- More complex path resolution (must know file type to find file)
- Type must be stored somewhere or derived from `owner_type`
- Directory move if a file's "type" changes (unlikely but theoretically possible)
- Three levels deep (type/hash/file) vs two levels (hash/file) — slightly more complex
- Legacy paths don't follow this pattern

**Best when:** ops/admin requirements dictate separate physical storage per file type.

**Evidence:** Not explicitly proposed in the research, but the research does note that avatars and attachments have different serving requirements, which could benefit from physical separation.

### Trade-Off Matrix

| Criterion | A: Flat | B: Date-Based | C: Hash (UUID prefix) | D: Type + Hash |
|-----------|:-:|:-:|:-:|:-:|
| **Complexity** | **Low** | Medium | **Low** | Medium |
| **Scalability** | Low (dir limits) | Medium | **High** | **High** |
| **Distribution Evenness** | N/A | Low (time-skew) | **High** | **High** |
| **Legacy Compat** | **High** (same pattern) | Low | Medium | Low |
| **Path Resolution** | **Trivial** | Needs date | **UUID → path** | Needs type + UUID |
| **Admin Operations** | Easy (ls) | Good (date browse) | Medium | **Good** (per-type stats) |
| **UUID Integration** | — | — | **Natural** | Good |

### Recommendation: Alternative C — Hash-Based Sharding (UUID Prefix)

**Rationale:** Given the UUID v7 ID strategy (Decision Area 2), using the first 2 hex characters as a subdirectory prefix is the natural and simplest sharding approach. It scales to millions of files, requires no extra metadata for path resolution, and aligns directly with the research report's explicit proposal.

**Implementation detail:** For UUID v7, consider using characters 9-10 (from the random portion, after the timestamp prefix) instead of characters 0-1 (timestamp prefix) for better distribution. Alternatively, accept that timestamp-based clustering just means "files from the same time period are in the same directory" — which is fine since the goal is to avoid single-directory overload.

**Trade-offs accepted:** Legacy files don't follow this pattern. Mitigated by the legacy adapter approach (Decision Area 3): legacy files use `legacy_attachments` adapter with flat path resolution, new files use `local` adapter with hash-sharded resolution.

**Key assumptions:** File counts will grow to tens or hundreds of thousands over the forum's lifetime.

**Risk:** Very low. Hash-based sharding is a well-proven pattern. The only consideration is UUID v7 character selection for the directory prefix.

---

## Trade-Off Analysis — Cross-Decision Summary

| Decision | Recommended | Primary Trade-Off | Confidence |
|----------|------------|-------------------|------------|
| 1. Asset Table | C: Unified + Extensions | More tables/JOINs → cleaner architecture | **High** |
| 2. ID Strategy | B: UUID v7 | Wider keys → non-enumerable, self-sharding | **High** |
| 3. Storage Adapter | C: Strategy/Local-First | Future cloud work → zero dependencies now | **High** |
| 4. Variant Strategy | C: Hybrid (eager thumb, lazy rest) | Two code paths → optimized for common case | **High** |
| 5. Quota Architecture | D: Counter + Reconciliation | Eventual accuracy → O(1) enforcement | **High** |
| 6. File Serving | C+D: Hybrid + X-Accel | Two serving paths → optimal per file type | **High** |
| 7. Orphan Management | A: DB-Tracked + Cron TTL | Orphan DB rows → simple, reliable cleanup | **High** |
| 8. Directory Strategy | C: Hash-Based (UUID prefix) | Slightly more complex paths → scalable | **High** |

### User Preferences (From Hard Constraints)

- **Event-driven API** → Validated: storage methods return domain events (StoredEvent, ClaimedEvent, DeletedEvent)
- **Auth via external middleware** → Validated: storage is auth-unaware, serving mode determined by consumer
- **Plugin-friendly** → Validated: C (unified + extensions) gives plugins their own tables; interface supports any consumer
- **NO legacy extension system** → Validated: all new code, PSR-4, DI
- **PSR-4 namespace `phpbb\storage\`** → Validated: all recommendations fit this structure
- **Legacy-compatible** → Validated: legacy adapter resolves old paths, zero-copy migration
- **Multi-level quotas** → Validated: counter table with (scope_type, scope_id) supports global/user/forum

---

## Recommended Approach — Summary

A `phpbb\storage\` service with:

1. **`phpbb_stored_files` table** as the unified source of truth for all file metadata, with consumer-specific extension tables (`phpbb_attachment_metadata`, `phpbb_avatar_metadata`) owned by the respective plugins.

2. **UUID v7 IDs** stored as `BINARY(16)`, formatted as hex-with-dashes at the application level. Provides non-enumerable IDs and natural directory sharding prefix.

3. **`StorageAdapterInterface`** with domain-specific methods (write, read, delete, exists, getLocalPath, getDirectUrl, getAccelRedirectPath). Ship `LocalAdapter` only. Inject via DI (Strategy pattern).

4. **Hybrid variant generation**: thumbnails generated eagerly at upload time (matching legacy behavior), other variant types generated lazily on first request. Variants stored in `phpbb_file_variants` table with FK CASCADE to parent.

5. **`phpbb_storage_quotas` counter table** with atomic `UPDATE WHERE used_bytes + size <= limit_bytes` for race-free enforcement. Periodic reconciliation job for drift correction.

6. **Hybrid file serving**: public files (avatars) served directly by nginx or via lightweight proxy; private files (attachments) authenticated through PHP controller with X-Accel-Redirect to nginx for actual I/O.

7. **DB-tracked orphan lifecycle**: `store()` creates unclaimed row, `claim()` sets `claimed_at`, hourly cron deletes orphans beyond 24h TTL.

8. **Hash-based directory sharding**: `{uuid_prefix}/{uuid}.{ext}` within the storage root. Legacy files served via separate legacy adapter with flat path resolution.

---

## Why Not Others

| Rejected Alternative | Reason |
|---------------------|--------|
| 1A: Single table (no extensions) | Consumer metadata in JSON column is unqueryable; forces AttachmentPlugin to manage download_count awkwardly |
| 1B: Thin reference layer | Perpetuates two-table pattern; doesn't converge toward clean architecture |
| 2A: Auto-increment IDs | Enumerable, no sharding benefit, exposes file count |
| 2C: Content-hash IDs | Over-complex, deduplication not a priority (Low severity per research), breaks orphan/claim lifecycle |
| 3A: Custom interface (identical to C in practice) | C is A with explicit Strategy commitment — minimal difference |
| 3B: Flysystem | Premature dependency for local-only use case; cloud adapter is future work |
| 3D: Symfony FS extend | Doesn't add meaningful value over custom interface |
| 4A: All eager | Wastes storage on variants nobody views |
| 4B: All lazy | First thumbnail request slow; thumbnails are viewed for virtually every image attachment |
| 4D: Variants as StoredFiles | Conflates derived files with independent uploads; pollutes findByOwner() queries |
| 5A: Real-time SUM | O(N) per upload; TOCTOU race under concurrency |
| 5B: Counter-only (no reconciliation) | Undetected drift; D adds safety net at minimal cost |
| 5C: Reservation pattern | Over-engineered; reserved/confirmed distinction adds complexity without proportional benefit |
| 6A: Always proxy | Unnecessary PHP overhead for public avatar serving |
| 6B: Signed URLs | Requires specialized nginx modules; delayed permission revocation |
| 7B: Temp zone + move | File move on claim adds I/O; breaks pre-claim preview references |
| 7C: Soft-delete flags | Conflates storage lifecycle with domain lifecycle |
| 7D: Event-based timeout | Requires delayed queue infrastructure phpBB doesn't have |
| 8A: Flat directory | Hits filesystem limits at scale |
| 8B: Date-based | Uneven distribution; redundant with created_at column |
| 8D: Type + hash | Extra path complexity without proportional benefit |

---

## Deferred Ideas

1. **Content deduplication** — Hash-based deduplication could save storage for forums with many re-uploads of the same file. Deferred because research rates this Low severity and it complicates the ownership/deletion model significantly.

2. **WebP auto-conversion** — Generate WebP variants of all image uploads for smaller file sizes. Deferred to the variant system's lazy generation path — can be added as a new `VariantGeneratorInterface` implementation.

3. **Chunked upload support** — Plupload's chunk assembly is currently handled by legacy code. The new storage service could absorb this into the upload pipeline, but it's a separate concern from the core `store()` API.

4. **CDN integration** — Signed URL generation for CloudFront/CloudFlare. Deferred to when cloud adapters are implemented (Alternative 3B at that point).

5. **File versioning** — Keep old versions when a file is replaced (e.g., avatar re-upload). Useful for audit trails. Deferred because legacy has no versioning and the use case is weak for forums.

6. **Download resumption for large files** — Range request support exists in legacy for attachments. The new file server controller should preserve this capability but it's a serving detail, not an architectural decision.

7. **Virus scanning integration** — Hook in the upload pipeline for external scanner (ClamAV). Deferred but the `UploadPipelineInterface` design accommodates this as an additional validation step.

8. **Group avatar storage** — Groups have avatar columns too. Deferred pending confirmation of `owner_type = 'group_avatar'` model (Research report §18, Q2).

# Research Plan: `phpbb\storage` Service Design

## Research Overview

**Question**: How to design `phpbb\storage` service managing assets (attachments, avatars, etc.) with global/per-user/per-forum quotas, plugin-friendly, legacy-compatible?

**Research Type**: Technical (codebase extraction + service design)

**Scope**:
- **Included**: File upload/store/delete/serve lifecycle, quota management (global/per-user/per-forum), metadata, orphan management, storage adapters, thumbnail/variants, secure serving, plugin contracts
- **Excluded**: Post/topic association (AttachmentPlugin), avatar assignment (UserService), BBCode rendering, CDN, anti-virus

**Context**:
- Existing designed services: `phpbb\user`, `phpbb\auth`, `phpbb\hierarchy`, `phpbb\threads`
- Plugin model: events + request/response decorators (NO legacy service_collection)
- Target namespace: `phpbb\storage\` → `src/phpbb/storage/`

---

## Methodology

### Primary Approach
**Codebase extraction** — Read all legacy attachment, avatar, file upload, and serving code to extract business rules, validation logic, quota enforcement, and storage patterns. Map these onto a modern service design.

### Analysis Framework
For each subsystem (attachments, avatars, file infra, serving):
1. **Entity extraction** — Identify data objects, their fields, and relationships
2. **Lifecycle mapping** — Upload → validate → store → adopt → serve → delete
3. **Rule extraction** — Extension whitelist, size limits, quota checks, permission gates
4. **Integration points** — How other services (threads, user, auth) interact with storage
5. **Plugin surface** — Where decorators/events should fire (pre-upload, post-store, pre-serve, pre-delete)

### Fallback Strategies
- If legacy code is too tangled: focus on DB schema + config entries as source of truth
- If quota logic is sparse: extrapolate from config keys and error messages

---

## Data Sources Summary

| Category | File Count | Total Lines | Key Sources |
|----------|-----------|-------------|-------------|
| Attachment lifecycle | 4 files | ~1,284 | manager.php, upload.php, delete.php, resync.php |
| Avatar system | 7 files | ~1,430 | manager.php, driver/*.php, driver_interface.php |
| File infrastructure | 9 files | ~1,400+ | upload.php, filespec.php, types/*.php, factory.php |
| Storage schema | 1 file (SQL) | Targeted regions | phpbb_attachments, phpbb_extensions, phpbb_extension_groups, phpbb_config |
| Download/serving | 2 files | ~1,050 | file.php, functions_download.php |
| Quota/config | Multiple | Targeted lines | config entries in SQL dump, upload.php quota methods |

**Total estimated reading**: ~5,500 lines of legacy code + targeted SQL regions

---

## Research Phases

### Phase 1: Broad Discovery (Structure & Dependencies)
**Goal**: Map all files, classes, and DI wiring involved in storage operations.
**Actions**:
- Catalog all files in `attachment/`, `avatar/`, `files/`, `filesystem/`, `mimetype/`, `plupload/`
- Read DI config: `services_attachment.yml`, `services_avatar.yml`, `services_files.yml`, `services_filesystem.yml`, `services_mimetype_guesser.yml`
- Map class dependencies and service graph
- Identify entry points: `web/download/file.php`, `web/posting.php`, `web/ucp.php`

### Phase 2: Targeted Reading (Business Logic Extraction)
**Goal**: Extract all business rules from each subsystem.
**Actions**:
- Read full attachment lifecycle: upload validation → file move → DB insert → orphan flag → adopt → delete
- Read avatar driver architecture: interface, base class, each driver's upload/delete/get methods
- Read file validation: extension whitelist, MIME detection chain, size checks, image dimension checks
- Read serving logic: permission checks, download counting, secure download mode, browser detection

### Phase 3: Deep Dive (Quota, Security, Edge Cases)
**Goal**: Extract all quota enforcement, security rules, and orphan management.
**Actions**:
- Trace quota check flow: `check_attach_quota()`, `check_disk_space()`, extension-specific `max_filesize`
- Extract all config keys related to storage (attachment_quota, max_filesize, avatar_filesize, etc.)
- Understand thumbnail generation: `img_create_thumbnail`, `img_max_thumb_width`
- Map orphan lifecycle: plupload temp storage → is_orphan=1 → cron cleanup (`tidy_plupload`)
- Extract security: content-type sniffing (`check_attachment_content`), secure downloads (`secure_downloads`), path traversal prevention

### Phase 4: Synthesis & Verification
**Goal**: Cross-reference DB schema with code, identify gaps, compile design inputs.
**Actions**:
- Verify all `phpbb_attachments` columns are used in code
- Verify all config keys are enforced somewhere
- Identify missing features (per-user quota, per-forum quota — may not exist in legacy)
- Map legacy patterns → new service method signatures
- Catalog all places where plugins should hook in

---

## Gathering Strategy

### Instances: 6

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|-------------|------------|-------|---------------|
| 1 | attachment-lifecycle | Full attachment upload/store/adopt/delete lifecycle from manager.php, upload.php, delete.php, resync.php + DI wiring | Read, Grep | attachment-lifecycle |
| 2 | avatar-system | Avatar driver architecture, upload/delete/get flows, driver interface contract, gallery vs upload vs remote patterns | Read, Grep | avatar-system |
| 3 | file-infrastructure | Upload class, filespec validation, file types (form/local/remote), MIME detection chain, plupload integration, filesystem abstraction | Read, Grep | file-infrastructure |
| 4 | storage-schema | DB tables (phpbb_attachments, phpbb_extensions, phpbb_extension_groups), all config entries for upload/avatar/quota settings | Read, Grep | storage-schema |
| 5 | download-serving | file.php full flow (avatar mode + attachment mode), functions_download.php helpers, permission checks, download counters, browser compat | Read, Grep | download-serving |
| 6 | quota-enforcement | Quota check methods in upload.php, config keys (attachment_quota, max_filesize, avatar_filesize), extension-group per-file limits, disk space checks, orphan GC | Read, Grep | quota-enforcement |

### Rationale
Each category maps to a distinct subsystem with its own files and concerns. The 6-way split ensures focused deep reading without overlap:
- Categories 1-2 are **consumer-facing** subsystems (attachments, avatars) that define business workflows
- Category 3 is the **shared infrastructure** layer (file validation, MIME, filesystem)
- Category 4 is the **data layer** (schema + config) that provides ground truth
- Category 5 is the **serving path** which is separate from upload and has its own security model
- Category 6 is a **cross-cutting concern** (quotas) that must be extracted from multiple files

---

## Success Criteria

### Research Complete When:
- [ ] Full attachment lifecycle documented (upload → validate → store → is_orphan → adopt → serve → delete)
- [ ] Avatar driver architecture extracted (interface methods, per-driver behaviors, storage paths)
- [ ] File validation pipeline understood (extension whitelist, MIME detection, size/dimension checks, content sniffing)
- [ ] All DB schema fields mapped to code usage (phpbb_attachments, phpbb_extensions, phpbb_extension_groups)
- [ ] All config keys cataloged with their enforcement points (~25+ keys)
- [ ] Download/serving security model documented (auth checks, per-forum permissions, secure mode, hotlink protection)
- [ ] Quota enforcement rules extracted (global, per-extension-group, per-PM-vs-post, disk space)
- [ ] Orphan lifecycle documented (creation, cleanup cron, admin orphan management)
- [ ] Thumbnail/image variant handling understood
- [ ] Plugin hook points identified for each lifecycle stage

### Quality Criteria:
- Every finding backed by specific file:line reference
- Config keys include their default values
- DB schema includes column types and index definitions
- Business rules stated as testable assertions

---

## Expected Outputs

### Primary: Research Report (`outputs/research-report.md`)
- Comprehensive findings organized by subsystem
- Entity catalog with field mappings
- Lifecycle diagrams (upload, serve, delete)
- Config key reference table
- Security model summary
- Gap analysis (what legacy lacks that new design needs)

### Secondary: Design Inputs (`outputs/design-inputs.md`)
- Proposed service boundaries (StorageManager, QuotaService, FileValidator, StorageAdapter interface)
- Plugin contract surface (events, decorator points)
- Migration path from legacy tables
- Recommended schema changes for new features (per-user quota, per-forum quota)

### Tertiary: Decision Log (`outputs/decision-log.md`)
- Key design decisions with rationale
- Trade-offs considered
- Legacy compatibility constraints

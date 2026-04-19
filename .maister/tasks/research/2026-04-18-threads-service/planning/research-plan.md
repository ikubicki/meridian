# Research Plan — `phpbb\threads` Service Design

## Research Overview

**Question**: How to design `phpbb\threads` service for topics/posts with content pipeline plugins (BBCode/markdown/attachments as plugins), event-driven API, auth via middleware?

**Type**: Technical (codebase extraction + service design)

**Scope boundaries**:
- **In scope**: Legacy posting workflow, submit_post(), viewtopic.php, topic types/states, soft-delete, polls, drafts, text storage format, content pipeline, attachment/BBCode/markdown as plugins
- **Out of scope**: ACL (→ `phpbb\auth`), hierarchy (→ `phpbb\hierarchy`), users (→ `phpbb\user`), search, moderation queue UI, legacy extensions

**Sub-questions**:
1. What is the complete state machine for topic lifecycle (create → edit → lock → soft-delete → hard-delete)?
2. What does `submit_post()` actually do across its ~1000 LOC for each mode (post/reply/edit/delete)?
3. How is content stored (BBCode UID, bitfield, s9e XML) and what transformations happen on write vs read?
4. How do attachments hook into the posting flow — can this pattern generalize to a plugin architecture?
5. What are the poll CRUD operations and how do they couple to topics?
6. How does content_visibility manage soft-delete states and counter synchronization?
7. What does viewtopic.php's rendering pipeline look like (query → process → render)?
8. How should drafts work in a service that doesn't own the HTTP layer?

---

## Methodology

**Primary approach**: Deep codebase analysis — read legacy code, extract data flows, map state transitions, catalog DB operations

**Secondary approach**: Schema-first analysis — use `phpbb_dump.sql` DDL to understand data model, constraints, relationships

**Analysis framework**: Technical extraction with design synthesis
- Component identification (what entities/operations exist)
- State machine extraction (topic/post lifecycle)
- Data flow tracing (write path: form → validate → parse → store; read path: query → hydrate → render)
- Integration point mapping (where threads touches auth, hierarchy, notifications, search)
- Plugin boundary analysis (attachment manager as reference architecture)

---

## Research Phases

### Phase 1: Broad Discovery
- Map all functions in `functions_posting.php` (16 functions, ~3009 LOC)
- Scan `posting.php` mode routing (post/reply/quote/edit/delete/bump/smilies — 2123 LOC)
- List all DB tables related to threads (topics, posts, drafts, polls, attachments, bookmarks, tracking)
- Catalog content_visibility methods and states
- Identify textformatter interface contracts

### Phase 2: Targeted Reading
- Read `submit_post()` end-to-end (~L1668–L2712) — trace every branch per mode
- Read `delete_post()` (~L1373–L1667) — hard-delete vs soft-delete flows
- Read `viewtopic.php` query construction and post processing loop
- Read attachment `manager.php` / `upload.php` / `delete.php` — extract plugin pattern
- Read `parse_message` class (extends `bbcode_firstpass` extends `bbcode`) — L1095–L2086
- Read s9e `parser.php` and `renderer.php` — modern textformatter implementation

### Phase 3: Deep Dive
- Trace content transformation: raw text → `parse_message` → s9e parser → DB storage (with UID/bitfield)
- Trace rendering: DB text → s9e renderer → HTML output (with attachment inline replacement)
- Map `submit_post()` SQL operations per mode: which tables get INSERT/UPDATE/DELETE
- Extract poll lifecycle from `submit_post()` poll handling sections
- Trace draft save/load flow from `load_drafts()` and posting.php draft handling
- Map counter synchronization: `topic_posts_approved`, `topic_posts_unapproved`, `topic_posts_softdeleted`, forum-level counters

### Phase 4: Verification
- Cross-reference extracted state machine against constants (ITEM_UNAPPROVED=0, ITEM_APPROVED=1, ITEM_DELETED=2, ITEM_REAPPROVE=3)
- Verify topic type constants (POST_NORMAL=0, POST_STICKY=1, POST_ANNOUNCE=2, POST_GLOBAL=3)
- Verify topic status constants (ITEM_UNLOCKED=0, ITEM_LOCKED=1, ITEM_MOVED=2)
- Validate that all DB columns in schema match fields accessed in code
- Compare with `phpbb\user` IMPLEMENTATION_SPEC.md for design pattern consistency

---

## Gathering Strategy

### Instances: 7

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | posting-workflow | `submit_post()` complete flow, `posting.php` mode routing, state machine extraction | Read, Grep | posting-workflow |
| 2 | topic-post-schema | DB tables (phpbb_topics, phpbb_posts, phpbb_topics_posted, phpbb_bookmarks, phpbb_topics_track, phpbb_topics_watch), column types, indexes, FK relationships | Read (SQL dump), Grep | topic-post-schema |
| 3 | content-format | Text storage format, BBCode UID/bitfield, s9e textformatter parser/renderer, `parse_message` class hierarchy, message_parser.php | Read, Grep | content-format |
| 4 | polls-drafts | Poll tables (phpbb_poll_options, phpbb_poll_votes), poll CRUD in submit_post(), draft table (phpbb_drafts), load_drafts(), draft save in posting.php | Read, Grep | polls-drafts |
| 5 | topic-display | `viewtopic.php` query construction, pagination, post rendering pipeline, `functions_display.php` helpers | Read, Grep | topic-display |
| 6 | soft-delete-visibility | `content_visibility.php` class (910 LOC), visibility states, set_post_visibility(), set_topic_visibility(), counter sync, approval workflow | Read, Grep | soft-delete-visibility |
| 7 | attachment-patterns | `attachment/manager.php`, `upload.php`, `delete.php`, `resync.php` — how attachments hook into posting flow, plugin architecture reference | Read, Grep | attachment-patterns |

### Rationale
Seven categories chosen because the research spans distinct functional domains that rarely overlap:
- **posting-workflow** is the central orchestrator (submit_post) — understanding it requires dedicated focus
- **topic-post-schema** is pure data modeling — independent from code logic analysis
- **content-format** is a deep technical domain (BBCode UID encoding, s9e XML, bitfields) requiring careful tracing
- **polls-drafts** are self-contained subsystems embedded within posting but with their own tables and logic
- **topic-display** is the read path — completely separate from the write path in posting-workflow
- **soft-delete-visibility** is a cross-cutting concern with its own complex state machine and counter sync
- **attachment-patterns** serves dual purpose: understanding current attachment integration AND extracting the plugin architecture pattern for the new service

---

## Success Criteria

| Criterion | Measurable Output |
|-----------|-------------------|
| Topic lifecycle state machine fully documented | States, transitions, triggers, and guard conditions for both topics and posts |
| `submit_post()` decomposed by mode | Per-mode (post/reply/edit/delete) list of DB operations, validations, side-effects |
| Content pipeline fully traced | Write path (raw → parsed → stored) and read path (stored → rendered → HTML) with format details |
| All DB tables and columns cataloged | Complete schema for 8+ tables with types, indexes, relationships |
| Poll CRUD operations extracted | Create/update/delete/vote flows with table operations |
| Draft mechanism documented | Save/load/delete lifecycle with schema |
| Soft-delete state machine mapped | Visibility states, transitions, counter sync operations |
| Attachment plugin pattern extracted | How attachments hook into posting — generalizable to plugin architecture |
| Design patterns from existing services identified | Consistency with `phpbb\user` IMPLEMENTATION_SPEC.md patterns |

## Expected Outputs

1. **Research report** (`outputs/research-report.md`) — Comprehensive findings organized by gathering category
2. **State machine diagrams** — Topic and post lifecycle state machines
3. **Data model documentation** — Complete schema with relationships
4. **Content pipeline specification** — Write and read path transformations
5. **Plugin architecture analysis** — Attachment pattern generalized for content pipeline plugins
6. **Design recommendations** — Service structure, interface contracts, event catalog

# Research Plan: phpbb\hierarchy Service Design

## Research Overview

### Research Question
How to design `phpbb\hierarchy` service for category/forum management based on legacy nested set code, with plugin architecture, excluding ACL?

### Research Type
**Technical** — codebase extraction + service design

### Scope & Boundaries
- **In scope**: Nested set tree, forum CRUD, display/navigation, 4 DB tables, tracking, subscriptions, plugin architecture, integration contracts with `phpbb\auth` and `phpbb\user`
- **Out of scope**: ACL/permissions (→ `phpbb\auth`), user management (→ `phpbb\user`), topics/posts, search, moderation

---

## Methodology

### Primary Approach
Codebase analysis — systematic extraction of legacy PHP code into service design artifacts:
1. Read and map all source classes, functions, and DB schema
2. Identify entity boundaries and operation contracts
3. Analyze integration points with auth/user services
4. Extract plugin/event patterns from phpBB extension system

### Analysis Framework
For each gathering category, produce:
- **Entity mapping**: Classes, functions → new service interfaces
- **Data model**: DB columns → PHP 8.2 entity properties
- **Operation catalog**: Legacy functions → service method signatures
- **Event points**: Where plugins should hook in
- **Integration contracts**: How `phpbb\auth` and `phpbb\user` are consumed

### Fallback Strategy
- If code is too tangled to separate: document coupling and propose bridge interfaces
- If DB schema has unclear columns: cross-reference with `acp_forums.php` form fields and `display_forums()` usage

---

## Research Phases

### Phase 1: Broad Discovery
- Map all files touching forum/category/hierarchy concepts
- Identify full function/method inventories in key files
- Extract complete DB schema with all columns, types, indexes

### Phase 2: Targeted Reading
- Read nested set algorithm implementation end-to-end (875 lines)
- Read forum CRUD operations in `acp_forums.php` (2245 lines)
- Read display logic in `functions_display.php` (1781 lines)
- Read tracking/subscription code in `functions.php`
- Read extension/event system interfaces

### Phase 3: Deep Dive
- Trace CRUD operations: create → nested set insert → DB writes
- Trace display flow: index.php → display_forums() → template vars
- Trace move/copy/delete with subtree handling
- Map all `trigger_event()` calls (8 events in display alone)
- Analyze forum_type/forum_status semantics

### Phase 4: Verification
- Cross-reference entity model against all ~48 columns in `phpbb_forums`
- Validate operation catalog covers all legacy functions
- Confirm plugin hook points cover existing phpBB events
- Verify integration contract completeness with auth/user

---

## Gathering Strategy

### Instances: 6

| # | Category ID | Focus Area | Primary Files | Output Prefix |
|---|------------|------------|---------------|---------------|
| 1 | nested-set-core | Tree/nested set algorithm — insert, delete, move, reorder, path queries, subtree ops, regeneration | `tree/nestedset.php`, `tree/nestedset_forum.php`, `tree/tree_interface.php` | nested-set-core |
| 2 | forum-schema | DB schema & data model — all columns, types, indexes, relationships across 4 tables, forum_type/status semantics | `phpbb_dump.sql` (tables), `acp_forums.php` (form fields mapping) | forum-schema |
| 3 | forum-crud | Admin CRUD operations — create, edit, delete, move, copy forums, subtree handling, content migration | `acp_forums.php` (all functions), `functions_admin.php` (helpers) | forum-crud |
| 4 | forum-display | Display/navigation logic — hierarchy rendering, subforum lists, category grouping, template vars, read status display | `functions_display.php`, `web/index.php`, `web/viewforum.php` | forum-display |
| 5 | forum-tracking | Read tracking & subscriptions — markread(), forum_track table, forum_watch table, notification integration | `functions.php` (markread, update_forum_tracking_info), `notification/type/forum.php` | forum-tracking |
| 6 | plugin-patterns | Extension/plugin architecture patterns — phpBB event system, extension interface, DI patterns, existing event hooks | `event/dispatcher*.php`, `extension/*.php`, `di/extension/*.php`, viglink ext example | plugin-patterns |

### Rationale
Six categories align with the natural boundaries of the hierarchy service:
- Categories 1-2 cover the **data layer** (algorithm + schema)
- Categories 3-4 cover the **operation layer** (write + read)
- Category 5 covers the **user interaction layer** (tracking/subscriptions)
- Category 6 covers the **architecture layer** (how to make it extensible)

Each gatherer produces independent findings that feed into a unified service design.

---

## Success Criteria

### Completeness
- [ ] All methods in `tree_interface.php` mapped to new service methods
- [ ] All functions in `acp_forums.php` (10 functions) cataloged with signatures
- [ ] All ~48 columns of `phpbb_forums` table documented with purpose
- [ ] All 4 DB tables (`phpbb_forums`, `_access`, `_track`, `_watch`) fully mapped
- [ ] All 8+ `trigger_event()` calls in display identified
- [ ] Tracking flow (markread → DB → display) traced end-to-end
- [ ] phpBB extension/event system patterns extracted

### Design Readiness
- [ ] Entity model (Category, Forum) with typed properties
- [ ] Service interface with PHP 8.2 method signatures
- [ ] Plugin hook architecture proposal
- [ ] Integration contracts with `phpbb\auth` and `phpbb\user`
- [ ] Event model for hierarchy mutations

---

## Expected Outputs

1. **Findings files** (per gathering category): `analysis/findings/{category}-*.md`
2. **Synthesis report**: `analysis/synthesis.md` — unified entity model, service interfaces, plugin architecture
3. **High-level design**: `outputs/high-level-design.md` — service architecture, component diagram
4. **Decision log**: `outputs/decision-log.md` — key design decisions with rationale

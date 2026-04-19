# Research Plan — `phpbb\messaging` Service Design

## Research Overview

- **Question**: How to design `phpbb\messaging` service replacing legacy privmsgs with modern conversations, folders, rules, notifications, attachment support via storage plugin?
- **Type**: Technical (codebase extraction + service design)
- **Scope included**: Conversation/message model, folders, filtering rules, multi-participant, read tracking, editing/forwarding/quoting, drafts, quotas, notifications, reporting, blocking, archiving
- **Scope excluded**: File storage implementation (→ `phpbb\storage`), email delivery, permissions (→ `phpbb\auth`), user profiles, real-time/WebSocket, full-text search

### Sub-Questions

1. **Message lifecycle**: What is the full compose → send → receive → read → edit → delete flow? How does `submit_pm()` orchestrate DB writes, recipient resolution, folder placement, and notification dispatch?
2. **Conversation model**: How does `root_level` threading work? How are reply chains and message history managed? What's the relationship between `phpbb_privmsgs` and `phpbb_privmsgs_to`?
3. **Folder system**: What are the system folders (inbox, outbox, sentbox)? How do custom folders work? What are folder limits, counts, and quota enforcement via `full_folder_action`?
4. **Rules engine**: What are all check types (CHECK_SUBJECT, CHECK_SENDER, etc.), connection operators (RULE_IS_LIKE, etc.), and actions (ACTION_PLACE_INTO_FOLDER, etc.)? How does `check_rule()` + `place_pm_into_folder()` evaluate and apply rules?
5. **Participant model**: How do TO/BCC recipients work (`to_address`/`bcc_address` text fields)? How are groups resolved to users? How does blocking via `phpbb_zebra.foe` interact?
6. **Read tracking**: How are `pm_new`, `pm_unread`, `pm_replied`, `pm_forwarded`, `pm_marked` flags used? How does `update_unread_status()` work?
7. **Drafts**: How does the shared `phpbb_drafts` table handle PM drafts (`topic_id = 0, forum_id = 0`)?
8. **Attachments**: How does `phpbb_attachments.in_message = 1` attach files to PMs? How does `allow_pm_attach` gate this?
9. **Notifications**: How does `phpbb\notification\type\pm` create/find/mark notifications?
10. **Reporting**: How does `report_handler_pm` + `mcp_pm_reports` handle PM moderation?
11. **Quotas & limits**: How do `pm_max_msgs`, `pm_max_boxes`, `pm_max_recipients`, `pm_edit_time`, per-group overrides work?

---

## Methodology

### Primary Approach
Static codebase analysis — read and decompose ~7,600 LOC across 11 legacy PHP files plus 4 DB schema definitions. Extract domain model, workflows, business rules, and data relationships.

### Fallback Strategies
- If function behavior is ambiguous from code reading, trace SQL queries to understand data flow
- If constants/config meaning is unclear, check how UCP submodules consume them
- Cross-reference DB migration files for historical schema context

### Analysis Framework
For each domain area:
1. **Entity extraction** — What data objects exist (tables, arrays, constants)?
2. **Workflow mapping** — What sequences of function calls execute business operations?
3. **Rule cataloging** — What business rules, validations, and constraints apply?
4. **Integration points** — Where does this domain touch other services (auth, storage, notifications)?
5. **Gap identification** — What modern capabilities are missing from legacy (conversations, multi-participant threads, etc.)?

---

## Research Phases

### Phase 1: Broad Discovery (Schema + Constants)
- Read all 4 `phpbb_privmsgs*` CREATE TABLE definitions
- Read `phpbb_drafts` and `phpbb_attachments` schema (PM-relevant columns)
- Read `phpbb_zebra` schema (friend/foe blocking)
- Read `phpbb_users` PM-related columns (`user_new_privmsg`, `user_unread_privmsg`, `user_last_privmsg`, `user_allow_pm`)
- Catalog all `define()` constants in `functions_privmsgs.php` (RULE_*, ACTION_*, CHECK_*)
- Catalog all PM config entries from `phpbb_config`

### Phase 2: Targeted Reading (Core Functions)
- Read `functions_privmsgs.php` function by function (22 functions, 2368 LOC)
- Read `ucp_pm.php` main router (445 LOC)
- Read `ucp_pm_compose.php` — compose/reply/forward/quote flow (1670 LOC)
- Read `ucp_pm_viewfolder.php` — folder listing and message browsing (624 LOC)
- Read `ucp_pm_viewmessage.php` — single message display (482 LOC)
- Read `ucp_pm_options.php` — rules/folders/settings UI (887 LOC)

### Phase 3: Deep Dive (Integration Points)
- Read `notification/type/pm.php` — notification lifecycle (205 LOC)
- Read `notification/type/report_pm.php` + `report_pm_closed.php` — report notifications (441 LOC)
- Read `report/report_handler_pm.php` — PM report creation flow (137 LOC)
- Read `mcp_pm_reports.php` — moderator report management (330 LOC)
- Read `attachment/delete.php` — PM attachment cleanup hooks
- Read `attachment/resync.php` — PM attachment resync

### Phase 4: Synthesis & Design
- Map legacy entities to modern OOP domain model
- Identify required new concepts (conversation entity, participants table, etc.)
- Design service API surface
- Define event/notification integration points
- Identify storage plugin attachment delegation pattern

---

## Gathering Strategy

### Instances: 6

| # | Category ID | Focus Area | Primary Files | Output Prefix |
|---|------------|------------|---------------|---------------|
| 1 | **message-lifecycle** | `submit_pm()` full flow, compose/send/receive/read/edit/delete, messaging modes (post/reply/quote/forward), BBCode/signature/attachments in compose | `functions_privmsgs.php` (L1629-2230), `ucp_pm_compose.php`, `ucp_pm_viewmessage.php` | `message-lifecycle` |
| 2 | **folder-system** | System folders (INBOX=-1, OUTBOX=-2, SENTBOX=-3), custom folder CRUD, `get_folder()`, `get_folder_status()`, folder quota/limits, `clean_sentbox()`, `move_pm()`, `full_folder_action` config, `mark_folder_read()` | `functions_privmsgs.php` (L116-255, L783-935), `ucp_pm_viewfolder.php`, `ucp_pm_options.php` (folder management section) | `folder-system` |
| 3 | **rules-engine** | All CHECK_* / RULE_* / ACTION_* constants, `check_rule()` evaluation logic, `place_pm_into_folder()` rule orchestration, `$global_privmsgs_rules` structure, `ucp_pm_options` rule CRUD UI | `functions_privmsgs.php` (L28-52, L257-415), `ucp_pm_options.php` (rules section) | `rules-engine` |
| 4 | **participant-model** | TO/BCC address encoding (`u_N`, `g_N` format), `write_pm_addresses()`, `rebuild_header()`, `get_recipient_strings()`, group → user resolution, `phpbb_zebra` foe blocking, `pm_max_recipients`, `allow_mass_pm`, per-user `user_allow_pm` | `functions_privmsgs.php` (L1411-1595, L2253-2368), `ucp_pm_compose.php` (recipient handling sections), `phpbb_zebra` schema | `participant-model` |
| 5 | **pm-schema** | All 4 `phpbb_privmsgs*` tables with columns/indexes, `phpbb_drafts` (PM usage), `phpbb_attachments.in_message`, `phpbb_users` PM columns, `phpbb_config` PM entries, `phpbb_reports` PM usage, migration history | `phpbb_dump.sql` (schema + config), `release_3_0_0.php` migration, `m_pm_report.php` migration | `pm-schema` |
| 6 | **notifications-reporting** | `notification\type\pm` (create/find/mark/email), `report_pm` + `report_pm_closed` notifications, `report_handler_pm` create flow, `mcp_pm_reports` moderation UI, `pm_reporting_disabled_exception`, `update_pm_counts()`, user PM counters | `notification/type/pm.php`, `notification/type/report_pm.php`, `notification/type/report_pm_closed.php`, `report/report_handler_pm.php`, `mcp/mcp_pm_reports.php` | `notifications-reporting` |

### Rationale
The legacy PM system cleanly divides into 6 functional areas that map to distinct files and concerns. Each gatherer focuses on a cohesive slice and can work independently. The `pm-schema` gatherer provides the structural foundation while others explore behavioral logic. This split avoids overlap: message lifecycle covers send/receive flow but not folder mechanics; folder-system covers folder management but not rule evaluation; rules-engine covers filtering but not recipient resolution.

---

## Success Criteria

### Completeness
- [ ] All 22 functions in `functions_privmsgs.php` documented with purpose, parameters, SQL queries, and side effects
- [ ] All 4 DB tables fully mapped: every column explained, all indexes documented, all relationships identified
- [ ] All constants cataloged: 14 RULE_* operators, 4 ACTION_* actions, 5 CHECK_* types
- [ ] All 9 PM config entries documented with defaults and effects
- [ ] All UCP PM modes covered: view, compose, drafts, options
- [ ] Notification lifecycle (create → find → mark → email) fully traced
- [ ] Report workflow (user report → notification → MCP review → close) fully traced

### Quality
- [ ] Every claim backed by specific file + line reference
- [ ] Data flow diagrams for: submit_pm, rule evaluation, folder placement
- [ ] Legacy→modern mapping table for each entity
- [ ] Integration points with excluded services clearly identified (storage, auth, user)

### Design Readiness
- [ ] Sufficient detail to design `phpbb\messaging\` namespace structure
- [ ] Sufficient detail to design conversation/message/participant entities
- [ ] Sufficient detail to design folder + rule services
- [ ] Sufficient detail to specify event hooks and notification types
- [ ] Sufficient detail to define storage plugin attachment interface

---

## Expected Outputs

### Primary
1. **`analysis/findings/`** — 6 detailed findings files (one per gathering category)
2. **`outputs/research-report.md`** — Consolidated research report with:
   - Legacy system architecture summary
   - Entity/relationship model
   - Workflow diagrams
   - Business rules catalog
   - Gap analysis (legacy vs. modern requirements)

### Secondary
3. **`outputs/high-level-design.md`** — Proposed `phpbb\messaging` service architecture:
   - Namespace structure
   - Entity classes
   - Service interfaces
   - Event definitions
   - Migration path from legacy tables
4. **`outputs/decision-log.md`** — Key design decisions with rationale

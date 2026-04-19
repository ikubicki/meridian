# Research Plan: Users Service (`phpbb\user`)

## Research Overview

**Research Question**: How should the `phpbb\user` service be designed to provide user management, preferences, group membership, banning (shadow+permanent), session management, and authentication — as a standalone PSR-4 service with zero legacy dependencies, based on existing DB schemas, extensible via events + decorators, and exposed through REST API?

**Research Type**: Mixed (Technical + Requirements + Literature)

**Scope & Boundaries**:
- IN: User CRUD, Authentication, Preferences, Groups, Banning (shadow+permanent), Profile, Sessions, REST API, Events, Extensibility
- OUT: Authorization/ACL (phpbb\auth), Email delivery, Admin UI, BBCode, Search

**Constraints**: PHP 8.2+, PDO, Symfony DI/EventDispatcher, no legacy imports, reuse existing DB schemas, DB opaque tokens (not JWT)

---

## Methodology

### Primary Approach
1. **Evaluate existing spec** — `IMPLEMENTATION_SPEC.md` covers ~50 files; gap-analyze against new requirements (shadow banning, decorator extensibility, REST API, cross-service contracts)
2. **Legacy code analysis** — Extract business rules from `session.php` (1886 lines), `functions_user.php` (3884 lines, 43 functions), `auth.php` (1139 lines), `profilefields/manager.php`
3. **Database schema deep-dive** — Understand all columns/indexes/constraints across 8+ tables to validate entity model completeness
4. **Cross-service integration mapping** — Define precise interfaces consumed by Auth, Notifications, Messaging, Threads, REST API
5. **Literature research** — Shadow banning patterns, user management REST API best practices, PHP 8.2 extensibility patterns (events + decorators)

### Fallback Strategies
- If shadow banning literature is sparse: study implementations in Discourse, Reddit (open docs), Flarum
- If decorator pattern unclear: reference Hierarchy/Threads pattern from existing researches
- If session-to-token migration unclear: reference REST API ADR-002 and Auth HLD

### Analysis Framework
- **Gap analysis**: Existing spec vs. new requirements → list of additions/modifications
- **Flow extraction**: Legacy code → documented business rule flows (login, ban check, session GC, group management)
- **Contract definition**: What other services need from User → interface surface area
- **Pattern design**: Shadow banning behavioral spec + extensibility model alignment

---

## Data Sources (Summary)

| Category | Source Count | Key Sources |
|----------|-------------|-------------|
| Existing spec | 1 major file | `src/phpbb/user/IMPLEMENTATION_SPEC.md` (~800 lines) |
| Legacy code | 4 files, ~7000 lines | `session.php`, `functions_user.php`, `auth.php`, `profilefields/manager.php` |
| Database | 8 tables | `phpbb_users`, `phpbb_sessions`, `phpbb_sessions_keys`, `phpbb_banlist`, `phpbb_groups`, `phpbb_user_group`, `phpbb_profile_fields`, `phpbb_profile_fields_data` |
| Cross-service | 5 HLDs | Auth, Hierarchy, Threads, Notifications, REST API |
| Architecture docs | 3 files | services-architecture, cross-cutting-assessment, research-brief |
| External | Web resources | Shadow banning patterns, REST user API best practices, PHP extensibility |

---

## Research Phases

### Phase 1: Broad Discovery (Existing Spec Audit)
- Read full `IMPLEMENTATION_SPEC.md` to catalog all entities, services, events, exceptions
- Identify what's covered vs. what's missing (shadow ban, decorators, REST endpoints, profile fields)
- Map spec entities to DB tables — find unmapped columns/tables
- Check alignment with Auth Service HLD expectations

### Phase 2: Legacy Code Flow Extraction
- **Session flow**: `session_begin()` → `session_create()` → `session_kill()` → `session_gc()` — extract business rules (IP validation, auto-login keys, ban checking during session start)
- **Ban flow**: `user_ban()` → `check_ban()` → `user_unban()` — extract timing logic, exclude handling, multi-mode (user/IP/email)
- **Group flow**: `group_create()` → `group_user_add()` → `group_user_del()` → `group_set_user_default()` — understand default group propagation, leader/pending states
- **User lifecycle**: `user_add()` → `user_active_flip()` → `user_delete()` — registration side effects, activation keys, cleanup scope
- **Validation**: `validate_username()`, `validate_password()`, `phpbb_validate_email()` — extract rules for the new service

### Phase 3: Schema Deep-Dive & Entity Refinement
- Analyze all 100+ columns of `phpbb_users` — identify columns that map to profile, preferences, auth, session concerns
- Analyze `phpbb_profile_fields` + `phpbb_profile_fields_data` + `phpbb_profile_fields_lang` — define custom profile field model
- Determine which columns are needed by User entity vs. should live in separated sub-entities
- Design schema extension for shadow banning (new column or new table)

### Phase 4: Cross-Service Integration & REST API Design
- Define exact interfaces Auth Service needs: `User` entity shape, `AuthenticationService` contract, `GroupRepository` for permission resolver
- Define event contracts consumed by Notifications (user events), Messaging (PM preferences), Threads (post count)
- Design REST API endpoints following existing API patterns (versioned `/api/v1/users/`, bearer token auth)
- Define extension points: which service methods get pre/post events, which are decoratable

### Phase 5: Shadow Banning Design & Extensibility Patterns
- Research shadow banning best practices (behavioral spec: posts visible only to author, reduced notifications, no error messages)
- Design shadow ban storage (new column on `phpbb_banlist`? new table? flag on User entity?)
- Define the events + decorators model aligned with Hierarchy/Threads pattern
- Document extension scenarios: custom profile field types, custom validation, ban reason plugins, login provider adapters

### Phase 6: Synthesis & Verification
- Cross-reference all findings against success criteria
- Verify no gaps in entity coverage, event catalog, or API surface
- Confirm all cross-cutting blocker requirements met
- Produce coherent design recommendation

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|-------------|------------|-------|---------------|
| 1 | existing-spec | Evaluate `IMPLEMENTATION_SPEC.md` against requirements: gap analysis for shadow banning, decorators, REST API, profile fields, cross-service contracts | Read, Grep | existing-spec |
| 2 | legacy-code | Extract business rules from `session.php`, `functions_user.php`, `auth.php`, `profilefields/manager.php` — login flow, ban logic, group management, validation rules, session lifecycle | Read, Grep | legacy-code |
| 3 | database-schema | Deep-dive into all user-related tables (`phpbb_users`, `phpbb_sessions*`, `phpbb_banlist`, `phpbb_groups`, `phpbb_user_group`, `phpbb_profile_fields*`) — column semantics, indexes, constraints, unmapped data | Read, Grep | database |
| 4 | cross-service | Map integration requirements from Auth HLD, REST API, Notifications, Messaging, Threads — exact interface contracts, event expectations, shared value objects | Read, Grep | cross-service |
| 5 | patterns-literature | Shadow banning behavioral spec, PHP 8.2 decorator/events extensibility patterns, REST API design for user management (CRUD, profiles, groups, bans), token session management | WebSearch, Read | patterns |

### Rationale

Five categories because:
1. **Existing spec** needs dedicated evaluation — it's 800+ lines and already covers 80% of the design; gap-analyzing it is a distinct activity from reading legacy code
2. **Legacy code** contains the business rules not documented anywhere else (ban timing, session IP matching, group cascading) — 7000 lines across 4 files requires focused extraction
3. **Database schema** is the source of truth for what the entities must model — 8 tables with 150+ columns requires dedicated mapping
4. **Cross-service** contracts are the most critical output (resolves Critical Blocker #1) and requires reading 5 other research outputs
5. **Patterns/literature** is essential for shadow banning (NEW requirement) and extensibility alignment (cross-cutting contradiction)

---

## Success Criteria

| # | Criterion | Measurable Outcome |
|---|-----------|-------------------|
| 1 | Complete service decomposition | All responsibility boundaries defined (which service owns which operations) |
| 2 | Entity model complete | All user-related data covered (User, Group, Ban, Session, Profile, Preferences, ProfileField) |
| 3 | Shadow ban design | Behavioral spec + storage design + integration with existing ban flow documented |
| 4 | REST API endpoints | All endpoints defined with request/response DTOs and HTTP methods |
| 5 | Event catalog | Every state change has a corresponding domain event documented |
| 6 | Extension model | Events + decorators documented with examples, aligned with Hierarchy/Threads pattern |
| 7 | Cross-service contracts | Exact interfaces for Auth, Notifications, Messaging, Threads documented |
| 8 | Critical blocker resolved | `phpbb\user\Entity\User` shape and `AuthenticationService` interface finalized |
| 9 | Session/token lifecycle | Token creation, refresh, revocation, GC — aligning with REST API DB token approach |

---

## Expected Outputs

1. **High-Level Design** (`outputs/high-level-design.md`) — Architecture, component decomposition, entity model, service interfaces
2. **Decision Log** (`outputs/decisions.md`) — ADRs for key design choices (shadow ban storage, session→token, profile field model, extension model)
3. **Solution Exploration** (`outputs/solution-exploration.md`) — Trade-off analysis for alternatives considered
4. **Research Report** (`outputs/research-report.md`) — Synthesized findings from all gathering categories

# Full Refactoring Reality Check V3

**Date**: 2026-04-20
**Scope**: Complete phpBB3 ground-up rewrite readiness assessment
**Previous**: V1 (2026-04-19), V2 (2026-04-20 — F1-F19 HLD fixes)
**Assessor**: Manual audit of all 15 research tasks + standards + cross-cutting docs

---

## Decision: ⚠️ CONDITIONAL GO — Ready to Start with Known Gaps

The research phase is **substantially complete**. 12 of 15 research tasks have full HLDs with interfaces, schemas, events, and integration points. Shared standards (domain events, counters, REST API, backend patterns) are documented. Cross-cutting decisions D1-D13 are finalized. The F1-F19 consistency fixes from V2 have been verified as applied.

**You CAN start implementation.** However, 8 gaps exist — 2 critical (must address in parallel with Phase 0-1), 3 high (before Phase 4-5), and 3 medium (during implementation).

---

## 1. Service Readiness Matrix

### Infrastructure (Phase 0)

| # | Service | HLD | Interfaces | Schema | Events | API | Status |
|---|---------|-----|-----------|--------|--------|-----|--------|
| 1 | Composer Autoload | research-report | n/a | n/a | n/a | n/a | ✅ Ready |
| 2 | Root Path Elimination | research-report | n/a | n/a | n/a | n/a | ✅ Ready |
| 3 | REST API Framework | HLD + decisions | ✅ | n/a | n/a | ✅ | ✅ Ready |
| 4 | Database Service | HLD + decisions | ✅ 6 interfaces | ✅ YAML DDL | ❌ none | n/a | ✅ Ready |

### Core Services (Phase 1-8)

| # | Service | HLD | Interfaces | Schema | Events | API | Status |
|---|---------|-----|-----------|--------|--------|-----|--------|
| 5 | Cache | HLD + decisions | ✅ | n/a (config) | ✅ | n/a | ✅ Ready |
| 6 | Users | HLD + decisions | ✅ 11 services | ✅ reuse | ✅ 20+ events | ⚠️ partial | ✅ Ready |
| 7 | Auth Unified | HLD + decisions | ✅ 3 interfaces | ✅ DDL | ⚠️ consumed only | ⚠️ partial | ⚠️ Needs minor work |
| 8 | Hierarchy | HLD + decisions | ✅ complete | ✅ reuse | ✅ events | ✅ | ✅ Ready |
| 9 | Threads | HLD + decisions | ✅ complete | ✅ reuse + encoding_engine | ✅ events | ✅ | ✅ Ready |
| 10 | Storage | HLD + decisions | ✅ complete | ✅ new table | ✅ events | ✅ | ✅ Ready |
| 11 | Messaging | HLD + decisions | ✅ complete | ✅ 7 new tables | ✅ events | ✅ | ✅ Ready |
| 12 | Notifications | HLD + decisions | ✅ complete | ✅ reuse | ✅ events | ✅ | ✅ Ready |
| 13 | Search | HLD + decisions | ✅ 4 ISP interfaces | ✅ 3 tables | ✅ consumed+emitted | ✅ 6 endpoints | ✅ Ready |

### Cross-Cutting Systems

| # | System | HLD | Interfaces | Status |
|---|--------|-----|-----------|--------|
| 14 | Plugin System | HLD + decisions | ✅ lifecycle + decorators | ✅ Ready |
| 15 | Auth (old, SUPERSEDED) | HLD | n/a | ⛔ Superseded by #7 |

---

## 2. What's Well-Designed (Strengths)

1. **Domain Events standard** — `DomainEvent` base class and `DomainEventCollection` fully specified. All 8 domain service HLDs now consistently return `DomainEventCollection` from mutations (verified post-F1-F19 fixes).

2. **Service boundaries clean** — no circular dependencies. One-way event flows. Services auth-unaware (ACL at API layer).

3. **Database Service** — table prefix handling, nested transactions, migration runner, YAML schema compiler. Addresses the "shared DB wrapper" gap from V1.

4. **Plugin System** — unified macrokernel architecture. Lifecycle (install/activate/deactivate/update/uninstall). Event + decorator integration. Schema management for plugins. Replaces all legacy tagged DI.

5. **Search Service** — ISP-segregated backends (native, MySQL fulltext, PostgreSQL fulltext). Event-driven indexing from Threads events. Permission-aware result caching.

6. **Coding standards comprehensive** — 6 standard docs covering global conventions, backend patterns, REST API, domain events, counter pattern, testing.

7. **Implementation order well-defined** — Phase 0-8 with dependency-aware sequencing in `services-architecture.md`.

---

## 3. Critical Gaps (Must Address Before/During Phase 0-1)

### GAP-1: ❌ Shared Kernel Package Not Implemented

**Impact**: Blocks ALL service implementation.

The following shared types are referenced by every service but exist only as documentation:

| Class | Defined In | Used By |
|-------|-----------|---------|
| `phpbb\common\Event\DomainEvent` | DOMAIN_EVENTS.md | All 8 services |
| `phpbb\common\Event\DomainEventCollection` | DOMAIN_EVENTS.md | All 8 services |
| `phpbb\common\Exception\*` | STANDARDS.md (names only) | All 8 services |

**What's missing**:
- No actual PHP code exists for these classes
- Exception hierarchy has type names but no class structure, inheritance, or HTTP mapping
- Users HLD lists 14 specific exceptions but the shared base classes aren't defined

**Action**: Create `src/phpbb/common/` package with `DomainEvent`, `DomainEventCollection`, and base exception classes BEFORE Phase 1.

### GAP-2: ❌ Auth Unified — Missing Emitted Events + REST API Spec

**Impact**: Other services cannot react to auth events. Frontend can't integrate without API contract.

- Auth defines events it **consumes** (PasswordChangedEvent, UserBannedEvent, UserDeletedEvent) but NOT events it **emits** (e.g., `UserLoggedInEvent`, `TokenIssuedEvent`, `TokenRevokedEvent`, `UserLoggedOutEvent`, `SessionElevatedEvent`)
- REST API endpoints mentioned in flow diagrams (`POST /auth/login`, `/auth/refresh`, `/auth/logout`, `/auth/elevate`) but no formal request/response JSON schemas

**Action**: Add auth emitted events catalog + formal REST API contract table to auth-unified HLD.

---

## 4. High-Priority Gaps (Before Phase 4-5)

### GAP-3: ⚠️ Data Migration Scripts Not Designed

**Impact**: Big bang cutover requires data migration tooling.

Decided: big bang migration (D9). Three areas need migration scripts:
- **PM data**: `phpbb_privmsgs*` (4 tables) → `messaging_conversations` (7 tables) — completely new schema
- **Attachments**: `phpbb_attachments` → `phpbb_stored_files` with UUID v7 IDs — new schema
- **Schema additions**: `encoding_engine` column on `phpbb_posts`, UUID columns on existing tables

No migration script designs, no data mapping docs, no testing strategy for migration accuracy.

**Action**: Design migration scripts for PM and attachment data. Can happen during Phase 5b-7 (when Messaging/Storage are built).

### GAP-4: ⚠️ Content Pipeline Plugins Not Designed

**Impact**: Threads ContentPipeline has the framework but no actual content processors.

The `ContentPipeline` + `ContentPluginInterface` architecture is designed. But the actual plugins that phpBB needs are not:
- **s9e Text Formatter** integration (the default — how does it render?)
- **BBCode processing** (custom BBCodes, standard BBCodes)  
- **Smilies/Emoji** replacement
- **AutoLink** (URL detection)
- **Censoring** (word filter)
- **@mention** handling

These are essential for rendering ANY post content.

**Action**: Design at least the s9e integration plugin before Threads implementation (Phase 6).

### GAP-5: ⚠️ Email/Mailer Service Not Designed

**Impact**: Notifications, password resets, registration confirmations all need email delivery.

- Notifications HLD references email as a delivery method (`NotificationMethodInterface`)
- Users HLD references `PasswordResetRequestDTO` dispatching events (implies email)
- No mailer service, SMTP configuration, or email template system is designed

**Action**: Design a lightweight mailer service. Can be a Symfony Mailer wrapper. Needed before Notifications (Phase 8) and Users password reset flow.

---

## 5. Medium-Priority Gaps (During Implementation)

### GAP-6: ⚠️ Configuration Service Not Designed

Services reference config values (`messaging_edit_window`, cache TTLs, quota limits, `forum_topics_per_page`) but no unified configuration mechanism is designed. Legacy uses `$config` from `phpbb_config` table.

**Action**: Design simple config service (read-only, cached, from `phpbb_config` table). Low complexity, can be done alongside any phase.

### GAP-7: ⚠️ i18n/Language Service Not Designed  

All user-facing error messages and notifications need translation. phpBB supports 50+ languages. No internationalization strategy exists.

**Action**: Design language service. Can wrap Symfony Translator. Needed before any user-facing features go live.

### GAP-8: ⚠️ Moderation/Admin Panel API Not Designed

Legacy phpBB has a full MCP (Moderator Control Panel) and ACP (Admin Control Panel). Threads/Hierarchy HLDs define `m_*` and `a_*` permissions but no moderation service or admin API exists.

**Action**: Can be designed incrementally as services are built. Not a blocker for core service implementation.

---

## 6. Cross-Cutting Consistency (V3 Verification)

### ✅ F1-F19 Fixes Verified

| Check | Status |
|-------|--------|
| All mutations return `DomainEventCollection` | ✅ 121 references across HLDs |
| No stale `void` returns on mutation methods | ✅ Clean (only read/helper methods return void) |
| No stale "RAW TEXT ONLY" in HLDs | ✅ Replaced with s9e XML + encoding_engine |
| No stale sync counter refs in HLDs | ✅ Event-driven per D8 |
| Forum-level counters removed from Threads | ✅ Only topic-level counters remain |
| Users HLD exceptions expanded | ✅ 14 typed exceptions with HTTP codes |
| Cache pools on Hierarchy/Users | ✅ Added |

### ⚠️ Remaining Stale References (Non-Critical)

These exist in decision logs, solution explorations, and research reports (historical docs — NOT active HLDs):

1. **Cross-cutting-assessment.md** — Service inventory table still says "10 research tasks" but there are 15. Dependency graph Mermaid still shows "User Service NOT RESEARCHED" (it IS researched). Notifications row still says "tagged DI types".
2. **Hierarchy research-report.md** — Line 29 mentions "service_collection pattern with tagged DI" — this is historical text in the research report, not the HLD.
3. **Threads decision-log.md** — References "Raw text only storage" as original decision with amendment note — correct (it IS annotated as superseded).

**Recommendation**: Update cross-cutting-assessment.md service inventory and dependency graph to reflect current 15-task state. Low priority — it's a reference doc, not an implementation spec.

---

## 7. Recommended Implementation Order (Updated)

| Phase | Service(s) | Prerequisite | Notes |
|-------|-----------|-------------|-------|
| **0** | Shared Kernel (`phpbb\common\`) | None | DomainEvent, DomainEventCollection, Exception base classes. **GAP-1** |
| **0** | Composer Autoload + Root Path | None | Infrastructure prerequisites |
| **1** | Database Service | Phase 0 | ConnectionInterface, TransactionManager, table prefix |
| **2** | Cache Service | Phase 0 | TagAwareCacheInterface, pool isolation |
| **3** | Users Service | Phase 1 | User entity is required by Auth and all services |
| **4** | Auth Unified Service | Phase 2, 3 | JWT + ACL. Fix **GAP-2** first (add emitted events + REST spec) |
| **5** | REST API Framework | Phase 4 | Auth subscriber, route config, JSON responses |
| **6a** | Hierarchy Service | Phase 1 | Nested set, forum CRUD, tracking |
| **6b** | Storage Service | Phase 1 | Flysystem, UUID v7, orphan cleanup |
| **7** | Threads Service | Phase 6a | Topics, posts, ContentPipeline. Needs **GAP-4** (s9e plugin) |
| **8** | Messaging Service | Phase 6b | Conversations, participants. Needs **GAP-3** (PM migration) |
| **9** | Search Service | Phase 7 | Indexing from Threads events. 3 backends |
| **10** | Notifications Service | Phase 7, 8 | All event sources. Needs **GAP-5** (mailer) |
| **11** | Plugin System | Phase 1-10 | Can be built progressively alongside services |

---

## 8. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| PM data migration corrupts message history | Medium | High | Design migration with rollback + test on production dump |
| s9e integration harder than expected | Medium | High | s9e library is well-documented; prototype early |
| Cache stampede on popular topics | Medium | Medium | Accept for v1 per Cache ADR-003; add locking in v1.1 |
| React SPA scope creep | High | Medium | Start with forum index prototype (mocks/forum-index already exists) |
| No rollback strategy (big bang) | Design decision | High | Accept risk; fix-forward philosophy. Test migration thoroughly |
| Plugin system too complex for MVP | Medium | Low | Plugin system is optional for core forum functionality |

---

## 9. Summary

### What's Done (15 research tasks)

| Category | Count | Tasks |
|----------|-------|-------|
| Infrastructure | 3 | Composer Autoload, Root Path, REST API |
| Database | 1 | Database Service (connection, schema, migration) |
| Core domain | 8 | Cache, Users, Auth Unified, Hierarchy, Threads, Messaging, Notifications, Storage |
| Supporting | 2 | Search, Plugin System |
| Superseded | 1 | Auth (old) — replaced by Auth Unified |

### What's Missing (8 gaps)

| Priority | Gap | Blocks |
|----------|-----|--------|
| ❌ Critical | GAP-1: Shared kernel package | All services |
| ❌ Critical | GAP-2: Auth emitted events + REST spec | Frontend, event consumers |
| ⚠️ High | GAP-3: Data migration scripts | Big bang cutover |
| ⚠️ High | GAP-4: Content pipeline s9e plugin | Post rendering |
| ⚠️ High | GAP-5: Email/mailer service | Notifications, password resets |
| ⚠️ Medium | GAP-6: Configuration service | Runtime config |
| ⚠️ Medium | GAP-7: i18n/language service | User-facing text |
| ⚠️ Medium | GAP-8: Moderation/admin panel API | MCP/ACP features |

### Verdict

**⚠️ CONDITIONAL GO** — Start implementation with Phase 0 (shared kernel + infrastructure). Address GAP-1 immediately (code it, not design it — specs exist). Address GAP-2 as a quick HLD amendment before Phase 4. Remaining gaps can be addressed in parallel with their dependent phases.

The research body is **high quality, internally consistent (post-V2 fixes), and architecturally sound**. No fundamental design flaws. The gaps are about completeness at the edges, not about the core architecture.

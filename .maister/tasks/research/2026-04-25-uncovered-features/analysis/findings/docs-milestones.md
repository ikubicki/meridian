# Findings: Planned/Uncovered Features from Documentation

**Sources read**:
- `.maister/MILESTONES.md` (last updated 2026-04-25)
- `.maister/docs/project/roadmap.md`
- `.maister/docs/project/services-architecture.md`

**Overall status**: M0–M8 implemented and passing (436 PHPUnit tests, 168 E2E tests). M9–M14 planned.

---

## A. Incomplete Tasks in Completed Milestones

Tasks with ⏳ status found inside milestones otherwise marked as done.

### M0 — Core Infrastructure (otherwise complete)

| # | Task | Status |
|---|------|--------|
| 0.10 | GitHub Actions CI pipeline (Lint + test + `composer audit`) | ⏳ |

**Evidence**: `MILESTONES.md` row 0.10; also duplicated as cross-cutting item X.5.

---

### M1 — Cache Service (otherwise complete)

| # | Task | Status |
|---|------|--------|
| 1.9 | RedisBackend (Phase 2, optional) | ⏳ |

**Evidence**: `MILESTONES.md` row 1.9 — "Phase 2, optional" per `plans/2026-04-22-cache-service.md` (Group 7).

---

### M6 — Threads Service (otherwise complete)

| # | Task | Status |
|---|------|--------|
| 6.6 | Hybrid counters — Tiered Counter Pattern (cron flush) | ⏳ |

**Evidence**: `MILESTONES.md` row 6.6 — "Future optimization (not critical for MVP)".

---

### M8 — Notifications Service (otherwise complete)

| # | Task | Status |
|---|------|--------|
| 8.11 | React frontend polling component | ⏳ |

**Evidence**: `MILESTONES.md` row 8.11 — deferred to M10.

---

### M9 — Search Service (partially started: research only)

| # | Task | Status |
|---|------|--------|
| 9.1 | Research search | ✅ |
| 9.2 | Implementation plan | ⏳ |
| 9.3 | ISP architecture + backends (MySQL FT / Sphinx / pluggable) | ⏳ |
| 9.4 | PHPUnit tests | ⏳ |
| 9.5 | Playwright E2E tests (`/api/v1/search/`) | ⏳ |

**Evidence**: `MILESTONES.md` M9 block. Research is done (`tasks/research/`), but implementation not started. M9 is listed as next priority in "Current Focus" section.

---

### M10 — React SPA Frontend (partially started: mock only)

| # | Task | Status |
|---|------|--------|
| 10.1 | Mock forum-index (Vite + React) | ✅ |
| 10.2 | Design system / base components | ⏳ |
| 10.3 | Auth flow (login, logout, protected routes) | ⏳ |
| 10.4 | Forum index + hierarchy | ⏳ |
| 10.5 | Topic list + post view | ⏳ |
| 10.6 | Notifications component (polling) | ⏳ |
| 10.7 | Messaging UI | ⏳ |

**Evidence**: `MILESTONES.md` M10 block. `mocks/forum-index/` exists as a static mock, but no real SPA consuming `/api/v1/` has been built yet.

---

## B. Planned Milestones Overview (M9–M14)

All tasks in these milestones are ⏳ unless noted above.

### M9 — Search Service (`phpbb\search`)
**Scope**: Pluggable backend architecture (ISP pattern) supporting MySQL full-text search, Sphinx, and other backends. REST API at `/api/v1/search/`.  
**Research**: ✅ done. Implementation plan, code, and tests: all ⏳.  
**phpBB3 equivalent**: `search/` subsystem with `fulltext_native`, `fulltext_sphinx`, `fulltext_postgres` backends.

---

### M10 — React SPA Frontend
**Scope**: Full SPA consuming `/api/v1/`, built with Vite + TypeScript. Replaces Twig/prosilver completely. Covers: design system, auth flow, forum index, topic/post views, notifications polling UI, messaging UI.  
**Research**: Not listed separately; ADR decided "React SPA, complete break from legacy" (Cross-Cutting resolution 2026-04-20).  
**phpBB3 equivalent**: Entire `styles/prosilver/` + `includes/template/` + all TPL/Twig rendering.

---

### M11 — Content Formatting Plugins
**Scope**: Pluggable content pipeline via s9e text-formatter. Plugins: BBCode, Markdown, Smilies. `encoding_engine` column on posts table for format-aware storage.  
**All tasks ⏳**: research, implementation plan, s9e integration, three plugins (BBCode/Markdown/Smilies), PHPUnit tests.  
**phpBB3 equivalent**: `includes/bbcode.php`, `phpbb\textformatter\` subsystem.

---

### M12 — Moderation Service (`phpbb\moderation`)
**Scope**: Report entity + ReportRepository, moderation queue service, moderator actions (lock/delete/move topics), REST API at `/api/v1/moderation/`, PHPUnit + E2E tests.  
**All tasks ⏳**: research through E2E tests (8 tasks total: 12.1–12.8).  
**phpBB3 equivalent**: `includes/mcp.php`, `phpbb\report\`, `phpbb\content_visibility\` subsystem.

---

### M13 — Configuration Service (`phpbb\config`)
**Scope**: ConfigRepository (DBAL, reusing `phpbb_config` table), ConfigService (get/set/delete), REST API at `/api/v1/config/` (admin-only). Replaces legacy `$config` global.  
**All tasks ⏳**: research through PHPUnit tests (6 tasks total: 13.1–13.6). No E2E task listed yet.  
**phpBB3 equivalent**: `phpbb\config\config` class + `phpbb_config` table access throughout codebase.

---

### M14 — Admin Panel (`phpbb\admin`)
**Scope**: ACP REST API controllers + React SPA admin UI. Replaces legacy `adm/` directory. PHPUnit + E2E tests.  
**All tasks ⏳**: research through E2E tests (6 tasks total: 14.1–14.6).  
**phpBB3 equivalent**: Entire `adm/` directory, ACP modules for users/forums/config/bans/extensions/styles.

---

## C. Security Reviews & Load Tests

### S.1 — Security Review: Auth Layer (trigger: post-M3, still pending)

M3 done but **all 7 S.1 tasks remain ⏳**:

| # | Task | Status |
|---|------|--------|
| S1.1 | JWT attack surface: alg:none, weak secret, expiry bypass | ⏳ |
| S1.2 | Brute-force / rate-limiting on `POST /api/v1/auth/login` | ⏳ |
| S1.3 | ACL bypass: horizontal privilege escalation (user → user) | ⏳ |
| S1.4 | ACL bypass: vertical privilege escalation (user → admin) | ⏳ |
| S1.5 | Session fixation / token reuse after logout | ⏳ |
| S1.6 | Argon2id config audit (memory, iterations, parallelism) | ⏳ |
| S1.7 | Document findings + remediation | ⏳ |

**Evidence**: `MILESTONES.md` S.1 block — "M3 done — review pending."

---

### M6.x — Load Tests (trigger: post-M6, still pending)

M6 done but **all 6 load test tasks remain ⏳**:

| # | Task | Status | Target |
|---|------|--------|--------|
| L.1 | k6 scaffolding (`tests/load/`) + docker-compose profile | ⏳ | — |
| L.2 | `GET /api/v1/forums` — forum index read path | ⏳ | p95 < 100ms @ 50 VU |
| L.3 | `GET /api/v1/forums/{id}/topics` — topic list | ⏳ | p95 < 150ms @ 50 VU |
| L.4 | `GET /api/v1/topics/{id}/posts` — post fetch | ⏳ | p95 < 200ms @ 50 VU |
| L.5 | Cache hit/miss ratio baseline (FilesystemBackend) | ⏳ | Decide if Redis needed |
| L.6 | `composer test:load` script + CI gate (optional) | ⏳ | — |

**Evidence**: `MILESTONES.md` M6.x block.

---

### S.2 — Security Review: Full API Surface (trigger: post-M6.x, still pending)

**All 9 S.2 tasks remain ⏳**:

| # | Task | Status |
|---|------|--------|
| S2.1 | OWASP ZAP automated scan: full API surface | ⏳ |
| S2.2 | IDOR on resource endpoints (forums, topics, posts, files) | ⏳ |
| S2.3 | XSS via content pipeline (s9e output) | ⏳ |
| S2.4 | Insecure file upload (Storage Service: type, size, path) | ⏳ |
| S2.5 | SQL injection smoke test (prepared statements audit) | ⏳ |
| S2.6 | Mass assignment / over-posting on POST/PATCH endpoints | ⏳ |
| S2.7 | Sensitive data exposure in error responses | ⏳ |
| S2.8 | Security headers audit (CORS, CSP, X-Frame-Options) | ⏳ |
| S2.9 | Document findings + remediation | ⏳ |

**Evidence**: `MILESTONES.md` S.2 block — depends on M6.x load tests completing first.

---

## D. Cross-Cutting Gap Items

| # | Task | Status | Notes |
|---|------|--------|-------|
| X.1 | Domain events (`DomainEventCollection`) | ✅ | `phpbb\common\Event\DomainEventCollection` implemented |
| X.2 | Tiered Counter Pattern (cron flush) | ⏳ | Standard documented in `COUNTER_PATTERN.md`; cron flush not implemented |
| X.3 | Common exceptions (`phpbb\common\Exception\*`) | ⏳ | Base classes with HTTP error mapping — not yet created |
| X.4 | Database migration scripts (PM + attachments) | ⏳ | Legacy `phpbb_privmsgs*` → messaging; `phpbb_attachments` → stored_files |
| X.5 | GitHub Actions CI pipeline | ⏳ | Lint + test + `composer audit`; also listed as 0.10 |

**Evidence**: `MILESTONES.md` Cross-Cutting block.

---

## Summary: Count of ⏳ Tasks by Category

| Category | ⏳ tasks |
|----------|----------|
| Incomplete tasks in done milestones (M0, M1, M6, M8) | 4 |
| M9 — Search Service | 4 |
| M10 — React SPA | 6 |
| M11 — Content Formatting | 6 |
| M12 — Moderation Service | 8 |
| M13 — Configuration Service | 6 |
| M14 — Admin Panel | 6 |
| S.1 — Auth Security Review | 7 |
| M6.x — Load Tests | 6 |
| S.2 — Full API Security Review | 9 |
| Cross-Cutting (X.2–X.5) | 4 |
| **Total** | **66** |

---

## phpBB3 Capabilities NOT Covered in Any Milestone

The following phpBB3 features have no corresponding milestone or task (gap analysis based on known phpBB3 capabilities vs. documented M0–M14 scope):

| Feature | phpBB3 Location | Milestone coverage |
|---------|----------------|-------------------|
| Email notifications (SMTP sending) | `includes/email/`, `phpbb\notification\method\email` | Not mentioned; M8 covers HTTP polling only |
| Extension system (Ext Manager, hooks, events) | `phpbb\extension\` | Cross-cutting ADR resolved: events+decorators, no tagged DI — **no full ext manager planned** |
| Styles / themes system | `styles/`, `phpbb\template\` | M10 replaces with React SPA — **no theme/style system** |
| Language packs / i18n | `language/`, `phpbb\language\` | Not mentioned in any milestone |
| Installation/update wizard | `install/` | Not mentioned in any milestone |
| Captcha / anti-spam (CONFIRM) | `phpbb\captcha\` | Not mentioned in any milestone |
| User registration flow (validate email, activation) | `phpbb\user\` partially | Not explicitly listed as a task in M2; no activation endpoint visible |
| Password reset flow | `phpbb\user\`, UCP | Not explicitly listed in M2 or M3 |
| User Control Panel (UCP) | `ucp.php`, `phpbb\ucp\` | Not mentioned in any milestone |
| Feed / RSS / Atom generation | `feed.php` | Not mentioned in any milestone |
| Cron / Scheduled tasks | `phpbb\cron\` | Tiered counter cron (X.2) only; no general cron framework |
| Rate limiting | Not in phpBB3 natively | S1.2 flags need — no milestone planned |
| Pagination (reusable service) | `phpbb\pagination\` | Used per-controller but no shared service planned |

**Confidence**: Medium — gaps inferred from absence in MILESTONES.md and roadmap.md, not confirmed by explicit exclusion ADRs.

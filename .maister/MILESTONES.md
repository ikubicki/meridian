# phpBB4 "Meridian" — Milestones

Central implementation progress tracker.  
Update after each completed task.  
Related plans: `.maister/plans/`

---

## Legend

- `✅` Done
- `🔄` In progress (current priority)
- `⏳` Planned
- `🔬` Research only (no implementation)

---

## M0 — Core Infrastructure

| # | Task | Status | Plan / Commit |
|---|---------|--------|---------------|
| 0.1 | Composer PSR-4 autoload | ✅ | — |
| 0.2 | Root path elimination (`PHPBB_FILESYSTEM_ROOT`) | ✅ | — |
| 0.3 | Symfony 8.x Kernel + MicroKernelTrait | ✅ | `ac4aeda` |
| 0.4 | Dockerfile PHP 8.4 → 8.4-fpm-alpine | ✅ | `ac4aeda` |
| 0.5 | docker-compose JWT secret + env | ✅ | `ac4aeda` |
| 0.6 | PHPUnit 10 + phpunit.xml | ✅ | `ac4aeda` |
| 0.7 | Playwright E2E scaffolding | ✅ | `ac4aeda` |
| 0.8 | PHP CS Fixer config + composer scripts | ✅ | `fe36990` |
| 0.9 | File headers + CREDITS.txt (Meridian) | ✅ | `fe36990` |
| 0.10 | GitHub Actions CI pipeline | ⏳ | — |

---

## M1 — Cache Service (`phpbb\cache`)

| # | Task | Status | Plan / Commit |
|---|---------|--------|---------------|
| 1.1 | Research cache service | ✅ | `tasks/research/2026-04-19-cache-service/` |
| 1.2 | Implementation plan | ✅ | `plans/2026-04-22-cache-service.md` |
| 1.3 | Interfaces (CacheInterface, TagAware, Backend, Marshaller, Factory) | ✅ | `1abc94b` |
| 1.4 | FilesystemBackend + NullBackend | ✅ | `1abc94b` |
| 1.5 | VarExportMarshaller | ✅ | `1abc94b` |
| 1.6 | TagVersionStore + CachePool | ✅ | `1abc94b` |
| 1.7 | CachePoolFactory + DI wiring (services.yaml) | ✅ | `1abc94b` |
| 1.8 | PHPUnit tests (76 tests, 146 assertions) | ✅ | `1abc94b` |
| 1.9 | RedisBackend (Phase 2, optional) | ⏳ | `plans/2026-04-22-cache-service.md` (Group 7) |

---

## M2 — User Service (`phpbb\user`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 2.1 | Research user service | ✅ | `tasks/research/2026-04-19-users-service/` |
| 2.2 | Implementation plan | ✅ | — |
| 2.3 | User entity + value objects | ✅ | `df82bb3` |
| 2.4 | UserRepository (DbalUserRepository + Group/Ban) | ✅ | `df82bb3` |
| 2.5 | UserSearchService, UserDisplayService, BanService | ✅ | `df82bb3` |
| 2.6 | REST API controller (`/api/v1/users/`) | ✅ | `df82bb3` |
| 2.7 | PHPUnit tests | ✅ | `df82bb3` |
| 2.8 | Playwright E2E tests (`/api/v1/users/`) | ✅ | `df82bb3` |

---

## M3 — Auth Unified Service (`phpbb\auth`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 3.1 | Research auth — AuthN | ✅ | `tasks/research/2026-04-19-auth-unified-service/` |
| 3.2 | Research auth — AuthZ / ACL | ✅ | `tasks/research/2026-04-19-auth-unified-service/` |
| 3.3 | Implementation plan | ✅ | — |
| 3.4 | JWT issuance (TokenService) + Argon2id + elevated token | ✅ | `df82bb3` |
| 3.5 | DBAL ACL resolver (AuthorizationService, groups + roles) | ✅ | `df82bb3` |
| 3.6 | AuthenticationSubscriber (JWT bearer + `_allow_anonymous`) | ✅ | `df82bb3` |
| 3.7 | REST API: login, logout, refresh, elevate | ✅ | `df82bb3` |
| 3.8 | PHPUnit tests | ✅ | `df82bb3` |
| 3.9 | Playwright E2E tests (auth flow, token validation) | ✅ | `df82bb3` |

---

## S.1 — Security Review: Auth Layer (post-M3)

> Trigger: after M3 (Auth Unified Service) is complete. **M3 done — review pending.**  
> Focus: authentication + authorisation surface — highest risk area.  
> Tool: OWASP ZAP (automated) + manual review of ACL resolver and JWT logic.

| # | Task | Status | Notes |
|---|------|--------|-------|
| S1.1 | JWT attack surface: alg:none, weak secret, expiry bypass | ⏳ | Manual review |
| S1.2 | Brute-force / rate-limiting on `POST /api/v1/auth/login` | ⏳ | OWASP ZAP + manual |
| S1.3 | ACL bypass: horizontal privilege escalation (user → user) | ⏳ | Manual review |
| S1.4 | ACL bypass: vertical privilege escalation (user → admin) | ⏳ | Manual review |
| S1.5 | Session fixation / token reuse after logout | ⏳ | Manual review |
| S1.6 | Argon2id config audit (memory, iterations, parallelism) | ⏳ | Compare vs OWASP recommendations |
| S1.7 | Document findings + remediation | ⏳ | — |

---

## M4 — REST API Framework (`phpbb\api`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 4.1 | Research REST API | ✅ | `tasks/research/2026-04-16-phpbb-rest-api/` |
| 4.2 | Symfony HttpKernel + YAML routes | ✅ | `ac4aeda` |
| 4.3 | AuthenticationSubscriber (real JWT, `_allow_anonymous`) | ✅ | `df82bb3` |
| 4.4 | Real controllers (health, auth, forums, users) | ✅ | `df82bb3` |
| 4.5 | Playwright E2E (47 tests) | ✅ | `df82bb3` |
| 4.6 | Wire topics/posts controllers (`phpbb\threads`) | ✅ | `7efa440` (5 endpoints: GET/POST topics, GET/POST posts) |
| 4.7 | Update E2E tests po wdrożeniu `phpbb\threads` | ✅ | `7efa440` (45 E2E tests, all passing) |

---

## M5a — Hierarchy Service (`phpbb\hierarchy`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 5a.1 | Research hierarchy | ✅ | `tasks/research/2026-04-18-hierarchy-service/` |
| 5a.2 | Implementation plan | ✅ | — |
| 5a.3 | Forum/category entities (nested set) | ✅ | `df82bb3` |
| 5a.4 | DbalForumRepository + TreeService + TrackingService + SubscriptionService | ✅ | `df82bb3` |
| 5a.5 | HierarchyService (CRUD + move + delete) | ✅ | `df82bb3` |
| 5a.6 | REST API controller (`/api/v1/forums/`) + elevated token | ✅ | `df82bb3` |
| 5a.7 | PHPUnit tests (238 total) | ✅ | `df82bb3` |
| 5a.8 | Playwright E2E tests (`/api/v1/forums/`) | ✅ | `df82bb3` |

---

## M5b — Storage Service (`phpbb\storage`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 5b.1 | Research storage | ✅ | `tasks/research/2026-04-19-storage-service/` |
| 5b.2 | Implementation plan | ⏳ | — |
| 5b.3 | Flysystem adapter + UUID v7 | ⏳ | — |
| 5b.4 | StoredFile entity + stored_files table | ⏳ | — |
| 5b.5 | StorageService | ⏳ | — |
| 5b.6 | REST API controller (`/api/v1/files/`) | ⏳ | — |
| 5b.7 | PHPUnit tests | ⏳ | — |
| 5b.8 | Playwright E2E tests (`/api/v1/files/`) | ⏳ | — |

---

## M6 — Threads Service (`phpbb\threads`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 6.1 | Research threads | ✅ | `tasks/research/2026-04-18-threads-service/` |
| 6.2 | Implementation plan | ✅ | `tasks/development/2026-04-22-threads-service/implementation-plan.md` |
| 6.3 | Topic/post entities + content pipeline | ✅ | `7efa440` |
| 6.4 | TopicRepository + PostRepository (DBAL) | ✅ | `7efa440` |
| 6.5 | ThreadsService (facade) | ✅ | `7efa440` |
| 6.6 | Hybrid counters (Tiered Counter Pattern) | ⏳ | Future optimization (not critical for MVP) |
| 6.7 | REST API controllers (TopicsController, PostsController) | ✅ | `7efa440` (all 5 endpoints) |
| 6.8 | PHPUnit tests | ✅ | 273 unit/integration tests + 45 E2E (all passing) |
| 6.9 | Playwright E2E tests (`/api/v1/topics/`, `/api/v1/posts/`) | ✅ | `a6ac5a9` (post.spec.ts verified)

---

## M6.x — Load Tests (post-M6 checkpoint)

> Trigger: after M6 (Threads) is done. Tests critical read paths with realistic concurrency.  
> Tool: [k6](https://k6.io/) — scriptable, CI-friendly, outputs p95/p99 latency + RPS.

| # | Task | Status | Notes |
|---|------|--------|-------|
| L.1 | k6 scaffolding (`tests/load/`) + docker-compose profile | ⏳ | Reuse existing app container |
| L.2 | `GET /api/v1/forums` — forum index read path | ⏳ | Target: p95 < 100ms @ 50 VU |
| L.3 | `GET /api/v1/forums/{id}/topics` — topic list | ⏳ | Target: p95 < 150ms @ 50 VU |
| L.4 | `GET /api/v1/topics/{id}/posts` — post fetch | ⏳ | Target: p95 < 200ms @ 50 VU |
| L.5 | Cache hit/miss ratio baseline (FilesystemBackend) | ⏳ | Decide if Redis needed earlier |
| L.6 | Add `composer test:load` script + CI gate (optional) | ⏳ | — |

---

## S.2 — Security Review: Full API Surface (post-M6.x)

> Trigger: after M6.x load tests — all core read/write paths exist.  
> Focus: full OWASP Top 10 sweep across the REST API surface.  
> Tool: OWASP ZAP automated scan + manual spot checks per finding.

| # | Task | Status | Notes |
|---|------|--------|-------|
| S2.1 | OWASP ZAP automated scan: full API surface | ⏳ | Run against local Docker stack |
| S2.2 | IDOR on resource endpoints (forums, topics, posts, files) | ⏳ | Manual: cross-user access |
| S2.3 | XSS via content pipeline (s9e BBCode/Markdown output) | ⏳ | Manual + ZAP active scan |
| S2.4 | Insecure file upload (Storage Service: type, size, path) | ⏳ | Manual review |
| S2.5 | SQL injection smoke test (prepared statements audit) | ⏳ | OWASP ZAP + grep for raw interpolation |
| S2.6 | Mass assignment / over-posting on POST/PATCH endpoints | ⏳ | Manual review |
| S2.7 | Sensitive data exposure in error responses | ⏳ | Manual review |
| S2.8 | Security headers audit (CORS, CSP, X-Frame-Options) | ⏳ | OWASP ZAP passive scan |
| S2.9 | Document findings + remediation, update CREDITS security section | ⏳ | — |

---

## M7 — Messaging Service (`phpbb\messaging`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 7.1 | Research messaging | ✅ | `tasks/research/2026-04-19-messaging-service/` |
| 7.2 | Implementation plan | ✅ | `tasks/development/2026-04-24-messaging-service/implementation/implementation-plan.md` |
| 7.3 | Thread-per-participant-set model | ✅ | `src/phpbb/messaging/` |
| 7.4 | DBAL Repositories (conversations, messages, participants) | ✅ | `src/phpbb/messaging/Repository/` |
| 7.5 | MessagingService + sub-services (archive, pin, delete) | ✅ | `src/phpbb/messaging/MessagingService.php` |
| 7.6 | DB migration: 4 messaging tables | ✅ | `src/phpbb/db/migrations/Version20260424MessageSchema.php` |
| 7.7 | REST API controllers (17 endpoints) | ✅ | `src/phpbb/api/Controller/Conversations/Messages/ParticipantsController.php` |
| 7.8 | PHPUnit tests (339/339) | ✅ | `tests/phpbb/messaging/`, `tests/phpbb/api/Controller/` |
| 7.9 | Playwright E2E tests (54/54) | ✅ | `tests/e2e/api.spec.ts` |

---

## M8 — Notifications Service (`phpbb\notifications`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 8.1 | Research notifications | ✅ | `tasks/research/2026-04-19-notifications-service/` |
| 8.2 | Implementation plan | ⏳ | — |
| 8.3 | NotificationsService + HTTP polling (30s) | ⏳ | — |
| 8.4 | Tag-aware cache integration | ⏳ | — |
| 8.5 | React frontend (polling component) | ⏳ | — |
| 8.6 | PHPUnit tests | ⏳ | — |
| 8.7 | Playwright E2E tests (`/api/v1/notifications/`, polling) | ⏳ | — |

---

## M9 — Search Service (`phpbb\search`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 9.1 | Research search | ✅ | `tasks/research/` |
| 9.2 | Implementation plan | ⏳ | — |
| 9.3 | ISP architecture + backends | ⏳ | — |
| 9.4 | PHPUnit tests | ⏳ | — |
| 9.5 | Playwright E2E tests (`/api/v1/search/`) | ⏳ | — |

---

## M10 — React SPA Frontend

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 10.1 | Mock forum-index (Vite + React) | ✅ | `mocks/forum-index/` |
| 10.2 | Design system / base components | ⏳ | — |
| 10.3 | Auth flow (login, logout, protected routes) | ⏳ | — |
| 10.4 | Forum index + hierarchy | ⏳ | — |
| 10.5 | Topic list + post view | ⏳ | — |
| 10.6 | Notifications component (polling) | ⏳ | — |
| 10.7 | Messaging UI | ⏳ | — |

---

## Cross-Cutting

| # | Task | Status | Notes |
|---|------|--------|-------|
| X.1 | Domain events (DomainEventCollection) | ✅ | `phpbb\common\Event\DomainEventCollection` |
| X.2 | Tiered Counter Pattern (cron flush) | ⏳ | Standard: `COUNTER_PATTERN.md` |
| X.3 | Common exceptions (`phpbb\common\Exception\*`) | ⏳ | — |
| X.4 | Database migration scripts (PM + attachments) | ⏳ | — |
| X.5 | GitHub Actions CI pipeline | ⏳ | Lint + test + audit |

---

## Current Focus

**✅ M6 — Threads Service (`phpbb\threads`)** — COMPLETE

Completed: M6 ✅ (`a6ac5a9`) · M5a Hierarchy Service ✅ (`df82bb3`) · M3 Auth ✅ · M2 User ✅ · M1 Cache ✅ (`1abc94b`)

**🔄 Next: M6.a — OpenAPI Gap Analysis + Milestone Planning (Completed)**

Completed 2026-04-22:
- Full OpenAPI vs controller endpoint comparison: 99 spec items → 19 implemented → 82 missing
- Categorized 82 missing endpoints into 7 milestone buckets
- Created `outputs/openapi-missing-endpoints.txt` artifact
- Updated `implementation-plan.md` with milestone roadmap

**⏳ Priority Backlog (Next Development Cycles):**

1. **Milestone A: Conversations & Messaging (17 endpoints)** — User-facing core
2. **Milestone B: User Management & Moderation (9 endpoints)** — Enable auth/authz workflows
3. **Milestone C: Groups & Permissions (8 endpoints)** — Permission policy foundation
4. **Milestone D: Topic/Post Moderation (7 endpoints)** — Advanced ops
5. **Milestone E: Drafts, Files, Notifications (13 endpoints)** — Content + UX
6. **Milestone F: Auth, Profile, Config, Search (11 endpoints)** — Infrastructure
7. **Milestone G: Delivery & Verification** — Full test + security sweep

See: `.maister/tasks/development/2026-04-22-threads-service/implementation/implementation-plan.md` (Section "OpenAPI Gap Milestones (Categorized Backlog)")

---

*Last updated: 2026-04-24 (threads-service complete; milestone planning added)*

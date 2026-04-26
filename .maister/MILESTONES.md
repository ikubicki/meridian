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
| 0.4 | Dockerfile PHP 8.5-fpm-alpine | ✅ | `ac4aeda` |
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
| 5a.8 | Playwright E2E tests (`/api/v1/forums/`) | ✅ | `0b36db5` (30 tests, UC-H1–H9) |

---

## M5b — Storage Service (`phpbb\storage`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 5b.1 | Research storage | ✅ | `tasks/research/2026-04-19-storage-service/` |
| 5b.2 | Implementation plan | ✅ | — |
| 5b.3 | Flysystem adapter + UUID v7 | ✅ | `d976392` |
| 5b.4 | StoredFile entity + stored_files table | ✅ | `d976392` |
| 5b.5 | StorageService (store, retrieve, delete, claim, readStream) | ✅ | `d976392` |
| 5b.6 | REST API controller (`/api/v1/files/`) — POST, GET, download, DELETE | ✅ | `d976392` |
| 5b.7 | PHPUnit tests (384 total) | ✅ | `d976392` |
| 5b.8 | Playwright E2E tests (`/api/v1/files/`) — 22 tests, UC-1–UC-14 | ✅ | `d976392` |

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
| 6.9 | Playwright E2E tests (`/api/v1/topics/`, `/api/v1/posts/`) | ✅ | `a6ac5a9` (post.spec.ts verified) |
| 6.10 | Edit/delete topics and posts (PATCH + DELETE endpoints) | ✅ | `73e30cf` |
| 6.11 | Domain events: TopicUpdated, PostUpdated, TopicDeleted, PostDeleted | ✅ | `73e30cf` |
| 6.12 | PostDTO extended: authorUsername + createdAt | ✅ | `73e30cf` |
| 6.13 | PHPUnit tests updated (510 total) | ✅ | `73e30cf` |
| 6.14 | E2E: edit/delete lifecycle tests — UC-A, UC-B, UC-C (201 total) | ✅ | `73e30cf` |

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
| 7.8 | PHPUnit tests (384/384) | ✅ | `tests/phpbb/messaging/`, `tests/phpbb/api/Controller/` |
| 7.9 | Playwright E2E tests (128/128) | ✅ | `tests/e2e/api.spec.ts` |

---

## M8 — Notifications Service (`phpbb\notifications`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 8.1 | Research notifications | ✅ | `tasks/research/2026-04-19-notifications-service/` |
| 8.2 | Implementation plan | ✅ | `tasks/development/2026-04-25-notifications-service/` |
| 8.3 | Notification entity + NotificationType entity | ✅ | `4240553` |
| 8.4 | NotificationRepository + NotificationTypeRepository (DBAL) | ✅ | `4240553` |
| 8.5 | NotificationService (getNotifications, countUnread, markRead, markAllRead) | ✅ | `4240553` |
| 8.6 | Tag-aware cache integration (VarExportMarshaller plain-array fix) | ✅ | `4240553` |
| 8.7 | REST API: GET /count (X-Poll-Interval, Last-Modified, 304), GET /notifications, POST /{id}/read, POST /read | ✅ | `4240553` |
| 8.8 | PHPUnit tests (436 total) | ✅ | `4240553` |
| 8.9 | Playwright E2E tests — 31 tests, UC-N1..UC-N8 | ✅ | `4240553` |
| 8.10 | E2E DB seeding: mysql2 helper (helpers/db.ts), port 13306 | ✅ | `57cbbbb` |
| 8.11 | React frontend (polling component) | ⏳ | M10 |

---

## M9 — Search Service (`phpbb\search`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 9.1 | Research search | ✅ | `tasks/research/` |
| 9.2 | Initial implementation plan (LikeDriver / FullTextDriver / Elasticsearch stub) | ✅ | — |
| 9.3 | SearchQuery DTO + interface refactor (`sortBy`, `searchIn`, `dateFrom`, `dateTo`) | ✅ | `plans/2026-04-26-search-service.md` Group 1 |
| 9.4 | DB Migration: `phpbb_search_wordlist` + `phpbb_search_wordmatch` | ✅ | `plans/2026-04-26-search-service.md` Group 2 |
| 9.5 | NativeTokenizer + NativeDriver (własny indeks słów) | ✅ | `plans/2026-04-26-search-service.md` Group 3 |
| 9.6 | SearchIndexerService + NullSearchIndexer + ThreadsService wiring (create) | ✅ | `plans/2026-04-26-search-service.md` Group 4 |
| 9.7 | Cache wyników (TagAwareCacheInterface, tag `search`, TTL z config) | ✅ | `plans/2026-04-26-search-service.md` Group 5 |
| 9.8 | PHPUnit tests (494 total, +36 nowych) | ✅ | `plans/2026-04-26-search-service.md` Group 6 |
| 9.9 | Playwright E2E tests — 10 testów (`/api/v1/search/`), UC-S1–S5 + UC-SR4/5/6/9 | ✅ | `plans/2026-04-26-search-service.md` Group 7 |
| 9.10 | LikeDriver / FullTextDriver / ElasticsearchDriver (podstawowe backendy) | ✅ | Initial implementation |
| 9.11 | SearchIndexerService wiring: editPost / deletePost | ✅ | M6 6.10 dostarcza editPost/deletePost |

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

## M11a — Plugin System (`phpbb\plugin`)

> Źródło: `.maister/tasks/research/2026-04-26-content-plugins/`

### Content Pipeline

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 11a.1 | Research content plugin injection | ✅ | `tasks/research/2026-04-26-content-plugins/` |
| 11a.2 | High-level design (ADR-001…ADR-005) | ✅ | `tasks/research/2026-04-26-content-plugins/outputs/high-level-design.md` |
| 11a.3 | `ContentStage` enum (PRE_SAVE, POST_SAVE, PRE_OUTPUT) | ⏳ | — |
| 11a.4 | `PostContentPluginInterface` + `#[AutoconfigureTag]` | ⏳ | — |
| 11a.5 | `PostContentPipeline` (priority, config-driven enable) | ⏳ | — |
| 11a.6 | Injection w `ThreadsService` (PRE_SAVE) + `PostsController::postToArray()` (PRE_OUTPUT) | ⏳ | — |
| 11a.7 | `MediaPluginInterface` + `MediaPipeline` (async Messenger) | ⏳ | — |
| 11a.8 | Wbudowany plugin: Censor (`CensorPlugin`) | ⏳ | — |
| 11a.9 | Wbudowany plugin: s9e Legacy (`S9eLegacyPlugin`, `canProcess()`) | ⏳ | — |
| 11a.10 | `ConfigTextService` (serwis dla `phpbb_config_text`) | ⏳ | — |
| 11a.11 | PHPUnit tests | ⏳ | — |
| 11a.12 | Playwright E2E tests | ⏳ | — |

### Metadata Plugin System

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 11a.13 | High-level design (ADR-006…ADR-008) | ✅ | `tasks/research/2026-04-26-content-plugins/outputs/high-level-design.md` |
| 11a.14 | Kandydaci do metadata (schema analysis) | ✅ | `tasks/research/2026-04-26-content-plugins/outputs/schema-metadata-candidates.md` |
| 11a.15 | `MetadataEntity` enum (POST, TOPIC, FORUM, USER, ATTACHMENT) | ⏳ | — |
| 11a.16 | `MetadataPluginInterface` + `#[AutoconfigureTag('phpbb.metadata_plugin')]` | ⏳ | — |
| 11a.17 | `MetadataService` (read/write JSON blob, schema validation, permission filter) | ⏳ | — |
| 11a.18 | DB migration: `ADD COLUMN metadata MEDIUMTEXT NULL` (5 tabel) | ⏳ | — |
| 11a.19 | REST: pole `metadata` w odpowiedziach encji + PATCH partial update | ⏳ | — |
| 11a.20 | REST: `GET /api/v1/metadata/schema?entity={type}` | ⏳ | — |
| 11a.21 | Wbudowane pluginy `phpbb_users`: birthday, jabber, sig, rank, UI prefs (6×) | ⏳ | — |
| 11a.22 | Migracja danych: kolumny → JSON blob (15 kolumn `phpbb_users`) | ⏳ | — |
| 11a.23 | `DROP COLUMN` dla zmigrowanych kolumn | ⏳ | — |
| 11a.24 | Likwidacja tabel `phpbb_profile_fields*` (4 tabele → metadata) | ⏳ | — |
| 11a.25 | PHPUnit tests | ⏳ | — |
| 11a.26 | Playwright E2E tests | ⏳ | — |

---

## M11b — Content Formatting Plugins _(wymaga M11a)_

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 11b.1 | Research content pipeline (BBCode, Markdown, Smilies) | ⏳ | — |
| 11b.2 | Implementation plan | ⏳ | — |
| 11b.3 | s9e text-formatter integration | ⏳ | — |
| 11b.4 | BBCode plugin (`PostContentPluginInterface`) | ⏳ | — |
| 11b.5 | Markdown plugin (`PostContentPluginInterface`) | ⏳ | — |
| 11b.6 | Smilies plugin (`PostContentPluginInterface`) | ⏳ | — |
| 11b.7 | PHPUnit tests | ⏳ | — |
| 11b.8 | Playwright E2E tests | ⏳ | — |

---

## M12 — Moderation Service (`phpbb\moderation`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 12.1 | Research moderation | ⏳ | — |
| 12.2 | Implementation plan | ⏳ | — |
| 12.3 | Report entity + ReportRepository | ⏳ | — |
| 12.4 | Moderation queue service | ⏳ | — |
| 12.5 | Moderator actions (lock/delete/move topics) | ⏳ | — |
| 12.6 | REST API (`/api/v1/moderation/`) | ⏳ | — |
| 12.7 | PHPUnit tests | ⏳ | — |
| 12.8 | Playwright E2E tests | ⏳ | — |

---

## M13 — Configuration Service (`phpbb\config`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 13.1 | Research config access patterns | ✅ | `3a10891` |
| 13.2 | Implementation plan | ✅ | `3a10891` |
| 13.3 | ConfigRepository (DBAL, `phpbb_config` table, QB) | ✅ | `3a10891` |
| 13.4 | ConfigService (get/set/delete/increment, cache) | ✅ | `3a10891` |
| 13.5 | REST API (`/api/v1/config/` — admin-only, 4 endpoints) | ✅ | `3a10891` |
| 13.6 | PHPUnit unit + integration tests (22 tests) | ✅ | `3a10891` |
| 13.7 | Playwright E2E tests (15 tests) | ✅ | `3a10891` |

---

## M14 — Admin Panel (`phpbb\admin`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 14.1 | Research ACP requirements | ⏳ | — |
| 14.2 | Implementation plan | ⏳ | — |
| 14.3 | Admin REST API controllers | ⏳ | — |
| 14.4 | React SPA admin UI | ⏳ | — |
| 14.5 | PHPUnit tests | ⏳ | — |
| 14.6 | Playwright E2E tests | ⏳ | — |

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

**✅ M0–M8 — All implemented and passing**

Completed (most recent first):
- M8 Notifications ✅ (`4240553`, `57cbbbb`) — 4 REST endpoints, 436 PHPUnit tests, 31 E2E tests (UC-N1..UC-N8), mysql2 DB seeding helper
- M7 Messaging ✅ — 17 endpoints, 384 PHPUnit tests, 128 E2E tests
- M5b Storage ✅ (`d976392`) — upload, metadata, stream download, delete
- M5a Hierarchy ✅ (`0b36db5`) — 30 Playwright tests, forums/categories nested set
- M6 Threads ✅ (`a6ac5a9`) — topics + posts, E2E tests
- M3 Auth ✅ · M2 User ✅ · M1 Cache ✅ (`1abc94b`) · M0 Infrastructure ✅

**⏳ Next: M9 — Search Service (`phpbb\search`)**

Research available: `tasks/research/`

**⏳ Priority Backlog:**

1. **M9: Search Service** — MySQL FT + Sphinx + pluggable ISP backends
2. **M10: React SPA Frontend** — Vite + TypeScript, consuming `/api/v1/`
3. **M11a: Plugin System** — content pipeline (PRE_SAVE/POST_SAVE/PRE_OUTPUT), media plugins, metadata plugins + schema cleanup
4. **M11b: Content Formatting Plugins** _(wymaga M11a)_ — BBCode, Markdown, Smilies (s9e)
5. **M12: Moderation Service** — reports, queue, moderator actions
6. **M13: Configuration Service** — unified config replacing `$config` global
7. **M14: Admin Panel** — ACP REST API + React admin UI

---

*Last updated: 2026-04-26 (M11a Plugin System zaplanowany; research + HLD gotowe — M11b Content Plugins zależy od M11a)*

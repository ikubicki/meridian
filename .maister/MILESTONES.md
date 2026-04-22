# phpBB4 "Meridian" — Milestones

Centralny tracker postępu implementacji.  
Aktualizuj po każdym zakończonym zadaniu.  
Powiązane plany: `.maister/plans/`

---

## Legenda

- `✅` Ukończone
- `🔄` W trakcie (aktualny priorytet)
- `⏳` Zaplanowane
- `🔬` Tylko research (bez implementacji)

---

## M0 — Infrastruktura Fundamentalna

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 0.1 | Composer PSR-4 autoload | ✅ | — |
| 0.2 | Root path elimination (`PHPBB_FILESYSTEM_ROOT`) | ✅ | — |
| 0.3 | Symfony 8.x Kernel + MicroKernelTrait | ✅ | `ac4aeda` |
| 0.4 | Dockerfile PHP 8.4 → 8.4-fpm-alpine | ✅ | `ac4aeda` |
| 0.5 | docker-compose JWT secret + env | ✅ | `ac4aeda` |
| 0.6 | PHPUnit 10 + phpunit.xml | ✅ | `ac4aeda` |
| 0.7 | Playwright E2E scaffolding | ✅ | `ac4aeda` |
| 0.8 | PHP CS Fixer config + composer scripts | ✅ | `fe36990` |
| 0.9 | Nagłówki plików + CREDITS.txt (Meridian) | ✅ | `fe36990` |
| 0.10 | GitHub Actions CI pipeline | ⏳ | — |

---

## M1 — Cache Service (`phpbb\cache`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 1.1 | Research cache service | ✅ | `tasks/research/2026-04-19-cache-service/` |
| 1.2 | Plan implementacji | ✅ | `plans/2026-04-22-cache-service.md` |
| 1.3 | Interfejsy (CacheInterface, TagAware, Backend, Marshaller, Factory) | ✅ | `1abc94b` |
| 1.4 | FilesystemBackend + NullBackend | ✅ | `1abc94b` |
| 1.5 | VarExportMarshaller | ✅ | `1abc94b` |
| 1.6 | TagVersionStore + CachePool | ✅ | `1abc94b` |
| 1.7 | CachePoolFactory + DI wiring (services.yaml) | ✅ | `1abc94b` |
| 1.8 | PHPUnit testy (76 testów, 146 asercji) | ✅ | `1abc94b` |
| 1.9 | RedisBackend (Phase 2, opcjonalne) | ⏳ | `plans/2026-04-22-cache-service.md` (Group 7) |

---

## M2 — User Service (`phpbb\user`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 2.1 | Research user service | ✅ | `tasks/research/2026-04-19-users-service/` |
| 2.2 | Plan implementacji | ⏳ | — |
| 2.3 | User entity + value objects | ⏳ | — |
| 2.4 | UserRepository (PDO, phpbb_users schema) | ⏳ | — |
| 2.5 | UserService (profile, groups, bans) | ⏳ | — |
| 2.6 | REST API controller (`/api/v1/users/`) | ⏳ | — |
| 2.7 | PHPUnit testy | ⏳ | — |

---

## M3 — Auth Unified Service (`phpbb\auth`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 3.1 | Research auth — AuthN | ✅ | `tasks/research/2026-04-19-auth-unified-service/` |
| 3.2 | Research auth — AuthZ / ACL | ✅ | `tasks/research/2026-04-19-auth-unified-service/` |
| 3.3 | Plan implementacji | ⏳ | — |
| 3.4 | JWT issuance + Argon2id password | ⏳ | — |
| 3.5 | 5-layer permission resolver + bitfield cache | ⏳ | — |
| 3.6 | AuthSubscriber (JWT bearer) | ⏳ | — |
| 3.7 | REST API: login, logout, refresh | ⏳ | — |
| 3.8 | PHPUnit testy | ⏳ | — |

---

## M4 — REST API Framework (`phpbb\api`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 4.1 | Research REST API | ✅ | `tasks/research/2026-04-16-phpbb-rest-api/` |
| 4.2 | Symfony HttpKernel + YAML routes | ✅ | `ac4aeda` |
| 4.3 | JWT AuthSubscriber (mock) | ✅ | `ac4aeda` |
| 4.4 | Mock controllers (health, auth, forums, topics, users) | ✅ | `ac4aeda` |
| 4.5 | Playwright E2E (16 testów) | ✅ | `ac4aeda` |
| 4.6 | Podłączenie realnych kontrolerów (M2/M3 → M4) | ⏳ | — |

---

## M5a — Hierarchy Service (`phpbb\hierarchy`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 5a.1 | Research hierarchy | ✅ | `tasks/research/2026-04-18-hierarchy-service/` |
| 5a.2 | Plan implementacji | ⏳ | — |
| 5a.3 | Forum/category entities (nested set) | ⏳ | — |
| 5a.4 | HierarchyRepository (PDO, phpbb_forums schema) | ⏳ | — |
| 5a.5 | HierarchyService (5-service decomposition) | ⏳ | — |
| 5a.6 | REST API controller (`/api/v1/forums/`) | ⏳ | — |
| 5a.7 | PHPUnit testy | ⏳ | — |

---

## M5b — Storage Service (`phpbb\storage`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 5b.1 | Research storage | ✅ | `tasks/research/2026-04-19-storage-service/` |
| 5b.2 | Plan implementacji | ⏳ | — |
| 5b.3 | Flysystem adapter + UUID v7 | ⏳ | — |
| 5b.4 | StoredFile entity + stored_files table | ⏳ | — |
| 5b.5 | StorageService | ⏳ | — |
| 5b.6 | REST API controller (`/api/v1/files/`) | ⏳ | — |
| 5b.7 | PHPUnit testy | ⏳ | — |

---

## M6 — Threads Service (`phpbb\threads`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 6.1 | Research threads | ✅ | `tasks/research/2026-04-18-threads-service/` |
| 6.2 | Plan implementacji | ⏳ | — |
| 6.3 | Topic/post entities + content pipeline | ⏳ | — |
| 6.4 | ThreadsRepository (PDO, phpbb_topics / phpbb_posts) | ⏳ | — |
| 6.5 | ThreadsService + s9e ContentPipeline | ⏳ | — |
| 6.6 | Hybrid counters (Tiered Counter Pattern) | ⏳ | — |
| 6.7 | REST API controllers | ⏳ | — |
| 6.8 | PHPUnit testy | ⏳ | — |

---

## M7 — Messaging Service (`phpbb\messaging`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 7.1 | Research messaging | ✅ | `tasks/research/2026-04-19-messaging-service/` |
| 7.2 | Plan implementacji | ⏳ | — |
| 7.3 | Thread-per-participant-set model | ⏳ | — |
| 7.4 | MessagingRepository (PDO, nowy schemat) | ⏳ | — |
| 7.5 | MessagingService (pinned + archive) | ⏳ | — |
| 7.6 | Migracja: phpbb_privmsgs* → messaging | ⏳ | — |
| 7.7 | REST API controllers | ⏳ | — |
| 7.8 | PHPUnit testy | ⏳ | — |

---

## M8 — Notifications Service (`phpbb\notifications`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 8.1 | Research notifications | ✅ | `tasks/research/2026-04-19-notifications-service/` |
| 8.2 | Plan implementacji | ⏳ | — |
| 8.3 | NotificationsService + HTTP polling (30s) | ⏳ | — |
| 8.4 | Tag-aware cache integration | ⏳ | — |
| 8.5 | React frontend (polling component) | ⏳ | — |
| 8.6 | PHPUnit testy | ⏳ | — |

---

## M9 — Search Service (`phpbb\search`)

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 9.1 | Research search | ✅ | `tasks/research/` |
| 9.2 | Plan implementacji | ⏳ | — |
| 9.3 | ISP architecture + backends | ⏳ | — |
| 9.4 | PHPUnit testy | ⏳ | — |

---

## M10 — React SPA Frontend

| # | Zadanie | Status | Plan / Commit |
|---|---------|--------|---------------|
| 10.1 | Mock forum-index (Vite + React) | ✅ | `mocks/forum-index/` |
| 10.2 | Design system / komponenty bazowe | ⏳ | — |
| 10.3 | Auth flow (login, logout, protected routes) | ⏳ | — |
| 10.4 | Forum index + hierarchy | ⏳ | — |
| 10.5 | Topic list + post view | ⏳ | — |
| 10.6 | Notifications component (polling) | ⏳ | — |
| 10.7 | Messaging UI | ⏳ | — |

---

## Cross-Cutting

| # | Zadanie | Status | Notatki |
|---|---------|--------|---------|
| X.1 | Domain events (DomainEventCollection) | ⏳ | Standard: `DOMAIN_EVENTS.md` |
| X.2 | Tiered Counter Pattern (cron flush) | ⏳ | Standard: `COUNTER_PATTERN.md` |
| X.3 | Common exceptions (`phpbb\common\Exception\*`) | ⏳ | — |
| X.4 | Database migration scripts (PM + attachments) | ⏳ | — |
| X.5 | GitHub Actions CI pipeline | ⏳ | Lint + test + audit |

---

## Aktualny Focus

**🔄 Następny krok: M2 — User Service**

Poprzedni: Cache Service ✅ (`1abc94b`, 2026-04-22)

---

*Ostatnia aktualizacja: 2026-04-22*

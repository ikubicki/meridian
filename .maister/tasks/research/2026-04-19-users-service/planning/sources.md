# Research Sources: Users Service

## Existing Specification

### Primary Spec
- `src/phpbb/user/IMPLEMENTATION_SPEC.md` — Complete implementation spec (~800 lines): entities, DTOs, services, events, exceptions, repositories. Strong starting point but lacks shadow banning, decorator extensibility, REST endpoints, and profile fields system.

---

## Codebase Sources

### Legacy Code — Session & Authentication

| File | Lines | Key Functions |
|------|-------|---------------|
| `src/phpbb/forums/session.php` | 1886 | `session_begin()`, `session_create()`, `session_kill()`, `session_gc()`, `check_ban()`, `check_ban_for_current_session()`, `set_login_key()`, `reset_login_keys()`, `set_cookie()`, `validate_referer()`, `unset_admin()`, `update_session()`, `check_dnsbl()` |
| `src/phpbb/forums/auth/auth.php` | 1139 | ACL resolution, permission checking (referenced for understanding user→auth boundary) |

### Legacy Code — User Management

| File | Lines | Key Functions |
|------|-------|---------------|
| `src/phpbb/common/functions_user.php` | 3884 | **CRUD**: `user_add()`, `user_delete()`, `user_active_flip()`, `user_update_name()`, `user_get_id_name()`, `update_last_username()` |
| | | **Banning**: `user_ban()`, `user_unban()`, `phpbb_get_banned_user_ids()` |
| | | **Groups**: `group_create()`, `group_delete()`, `group_user_add()`, `group_user_del()`, `group_user_attributes()`, `group_set_user_default()`, `group_validate_groupname()`, `group_memberships()`, `group_update_listings()`, `get_group_name()`, `group_correct_avatar()` |
| | | **Validation**: `validate_username()`, `validate_password()`, `phpbb_validate_email()`, `validate_user_email()`, `validate_jabber()`, `validate_data()`, `validate_string()`, `validate_num()`, `validate_date()`, `validate_match()`, `validate_language_iso_name()`, `phpbb_validate_timezone()`, `phpbb_validate_hex_colour()` |
| | | **Profile/Avatar**: `avatar_delete()`, `get_avatar_filename()`, `phpbb_avatar_explanation_string()`, `avatar_remove_db()`, `remove_default_avatar()`, `remove_default_rank()` |
| | | **Misc**: `remove_newly_registered()`, `phpbb_style_is_active()`, `user_ipwhois()` |

### Legacy Code — Profile Fields

| File | Purpose |
|------|---------|
| `src/phpbb/forums/profilefields/manager.php` | Profile fields orchestration (CRUD, validation, display) |
| `src/phpbb/forums/profilefields/lang_helper.php` | Language handling for profile fields |
| `src/phpbb/forums/profilefields/type/type_interface.php` | Profile field type contract |
| `src/phpbb/forums/profilefields/type/type_bool.php` | Boolean field implementation |
| `src/phpbb/forums/profilefields/type/type_dropdown.php` | Dropdown field implementation |
| `src/phpbb/forums/profilefields/type/type_url.php` | URL field implementation |

### API Controller (existing)
- `src/phpbb/api/v1/controller/auth.php` — Current API auth controller (reference for REST patterns)

---

## Database Sources

### User Tables (from `phpbb_dump.sql`)

| Table | Line | Purpose | Key Columns |
|-------|------|---------|-------------|
| `phpbb_users` | (search) | Primary user data | 70+ columns: identity, auth, prefs, stats, profile |
| `phpbb_sessions` | (search) | Active sessions | session_id, user_id, IP, browser, time, page |
| `phpbb_sessions_keys` | (search) | Persistent login tokens | key_id, user_id, last_ip, last_login |
| `phpbb_banlist` | (search) | All ban types | ban_userid, ban_ip, ban_email, ban_start, ban_end, ban_exclude |
| `phpbb_groups` | (search) | Group definitions | group_id, type, name, colour, rank, permissions |
| `phpbb_user_group` | (search) | Group membership | group_id, user_id, group_leader, user_pending |
| `phpbb_profile_fields` | Line 2891 | Custom profile field definitions | 24 columns: type, validation, visibility flags |
| `phpbb_profile_fields_data` | Line 2959 | User profile field values | user_id + dynamic pf_* columns |
| `phpbb_profile_fields_lang` | Line 2991 | Profile field translations | field_id, lang_id, option_id, lang_value |

### Search Patterns for DB Analysis
```sql
-- Find all user-related tables
CREATE TABLE.*phpbb_user
CREATE TABLE.*phpbb_session
CREATE TABLE.*phpbb_ban
CREATE TABLE.*phpbb_group
CREATE TABLE.*phpbb_profile
```

---

## Documentation Sources

### Cross-Service Research Outputs

| Source | Path | Relevant Sections |
|--------|------|-------------------|
| Auth Service HLD | `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` | User entity dependency, AuthenticationService contract, GroupRepository interface, ADR-001 (AuthZ-only scope), ADR-006 (import User from phpbb\user) |
| REST API HLD | `.maister/tasks/research/2026-04-16-phpbb-rest-api/outputs/high-level-design.md` | Token auth (ADR-002), route structure, endpoint patterns |
| Hierarchy HLD | `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` | Events + decorators extensibility pattern (reference model) |
| Threads HLD | `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` | Events + decorators extensibility, user_id references, post count management |
| Notifications HLD | `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md` | User notification preferences, tagged DI (contradicts events+decorators — must align) |
| Messaging HLD | `.maister/tasks/research/2026-04-19-messaging-service/outputs/high-level-design.md` | PM preferences from user, user lookup interface |
| Cache HLD | `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md` | TagAwareCacheInterface available for session/ban caching |

### Architecture Documents

| Source | Path | Relevant Sections |
|--------|------|-------------------|
| Cross-Cutting Assessment | `.maister/tasks/research/cross-cutting-assessment.md` | Critical Blocker #1 (User Service missing), extension model contradiction (§7.1), implementation order |
| Services Architecture | `.maister/docs/project/services-architecture.md` | Implementation order (User = Phase 2), overall architecture vision |

---

## Configuration Sources

| File | Purpose |
|------|---------|
| `composer.json` | PSR-4 autoload config, dependency list |
| `config.php` | Legacy phpBB config (DB credentials, table prefix, cookie settings) |
| `docker-compose.yml` | Development environment (MySQL 8.x, PHP 8.2) |

---

## External Sources (Literature)

### Shadow Banning Patterns
- **Discourse implementation**: Discourse blog/docs on "silenced" users (posts visible only to self, no notifications to others)
- **Reddit approach**: Shadow banning behavioral patterns (content invisible to others, user unaware)
- **Flarum suspend/flag system**: Modern forum software ban patterns
- **Best practices article**: "Designing Shadow Bans — Ethical Considerations and Implementation" (general web research)

### REST API Design for User Management
- **JSON:API spec**: Resource relationships for users/groups/bans
- **OAuth 2.0 Resource Server**: Token-based user identification patterns
- **GitHub API**: `/users`, `/user/memberships`, `/orgs` — reference for user management endpoints
- **Discourse API**: User CRUD, groups, suspensions — forum-specific patterns

### PHP 8.2 Extensibility Patterns
- **Symfony EventDispatcher**: Stoppable events, event subscribers, priority ordering
- **Decorator pattern in PHP**: Interface-based decoration for service extension
- **Symfony DI decorators**: `#[AsDecorator]` attribute, `decoration_priority` in YAML

### Token Session Management
- **PSR-7 + Bearer tokens**: Stateless auth with DB-backed opaque tokens
- **Token rotation patterns**: Refresh token rotation for persistent sessions
- **Session garbage collection**: Efficient GC strategies for high-traffic forums

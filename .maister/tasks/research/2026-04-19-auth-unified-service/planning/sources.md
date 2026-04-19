# Research Sources: Unified Auth Service

## Category 1: Legacy Auth (Authentication)

### Key Files
- `src/phpbb/forums/auth/provider/provider_interface.php` — Auth provider contract
- `src/phpbb/forums/auth/provider/base.php` — Base provider implementation
- `src/phpbb/forums/auth/provider/db.php` — Database credential verification (primary)
- `src/phpbb/forums/auth/provider_collection.php` — Provider registry
- `src/phpbb/forums/session.php` — Session creation, validation, destruction
- `web/ucp.php` — Login/logout entry points (lines 27-147)
- `src/phpbb/common/functions_user.php` — User utility functions

### File Patterns
- `src/phpbb/forums/auth/provider/*.php` — All auth providers
- `src/phpbb/forums/auth/provider/oauth/*.php` — OAuth provider (reference only)

### Key Concepts to Extract
- `login_box()` function flow
- `session_create()` / `session_kill()` methods
- Password hashing (phpBB password manager)
- Credential verification pipeline
- Session cookie handling

---

## Category 2: Legacy ACL (Authorization)

### Key Files
- `src/phpbb/forums/auth/auth.php` — Core ACL class (acl_get, acl_getf, acl_gets, acl_cache, bitfield)
- `src/phpbb/install/schemas/schema_data.sql` — ACL options seed data (lines 356+)
- `phpbb_dump.sql` — Full schema including:
  - `phpbb_acl_groups` (line 26)
  - `phpbb_acl_options` (line 99)
  - `phpbb_acl_roles` (line 252)
  - `phpbb_acl_roles_data` (line 329)
  - `phpbb_acl_users` (line 777)

### Key Methods (in auth.php)
- `acl_get($opt, $f)` — Single permission check (line 178)
- `acl_getf($opt, $clean)` — Forum-level permission check (line 227)
- `acl_getf_global($opt)` — Global permission check (line 308)
- `acl_gets()` — Multi-permission check (line 352)
- `acl_get_list()` — Bulk permission list (line 389)
- `acl_cache()` / `acl_clear_prefetch()` — Bitfield cache management

### Key Concepts to Extract
- Bitfield format and storage
- Permission prefetch strategy
- Role-to-permission resolution
- Local vs global permissions (is_local, is_global flags)
- ACL cache invalidation triggers

---

## Category 3: Prior Research

### Auth Service Research (ACL-only design)
- `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md`
- `.maister/tasks/research/2026-04-18-auth-service/outputs/research-report.md`
- `.maister/tasks/research/2026-04-18-auth-service/outputs/decision-log.md`
- `.maister/tasks/research/2026-04-18-auth-service/analysis/findings/acl-core.md`
- `.maister/tasks/research/2026-04-18-auth-service/analysis/findings/acl-database.md`
- `.maister/tasks/research/2026-04-18-auth-service/analysis/findings/admin-acl.md`
- `.maister/tasks/research/2026-04-18-auth-service/analysis/findings/auth-providers.md`
- `.maister/tasks/research/2026-04-18-auth-service/analysis/synthesis.md`

### Users Service Research (group membership, user entity)
- `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md`
- `.maister/tasks/research/2026-04-19-users-service/outputs/decision-log.md`
- `.maister/tasks/research/2026-04-19-users-service/analysis/findings/database-schema-analysis.md`
- `.maister/tasks/research/2026-04-19-users-service/analysis/findings/legacy-code-business-rules.md`
- `.maister/tasks/research/2026-04-19-users-service/analysis/findings/cross-service-integration-contracts.md`

### REST API Research (subscriber chain, token design)
- `.maister/tasks/research/2026-04-16-phpbb-rest-api/outputs/high-level-design.md`
- `.maister/tasks/research/2026-04-16-phpbb-rest-api/outputs/decision-log.md`
- `.maister/tasks/research/2026-04-16-phpbb-rest-api/outputs/solution-exploration.md`

### Cross-Cutting & Verification
- `.maister/tasks/research/cross-cutting-assessment.md` — JWT vs DB token contradiction
- `.maister/tasks/research/verification/reality-check.md` — AuthN circular deferral gap

### Hierarchy Service (forum permission context)
- `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md`

---

## Category 4: JWT Patterns

### Library Source (firebase/php-jwt v6.x)
- `vendor/firebase/php-jwt/src/JWT.php` — encode(), decode(), sign(), verify()
- `vendor/firebase/php-jwt/src/Key.php` — Key wrapper (algorithm + key material)
- `vendor/firebase/php-jwt/src/JWK.php` — JSON Web Key support
- `vendor/firebase/php-jwt/src/CachedKeySet.php` — Key caching
- `vendor/firebase/php-jwt/src/ExpiredException.php` — Token expiry handling
- `vendor/firebase/php-jwt/src/SignatureInvalidException.php` — Signature validation
- `vendor/firebase/php-jwt/src/BeforeValidException.php` — nbf claim validation

### External References (for context, not fetching)
- RFC 7519 — JWT standard claims (iss, sub, aud, exp, nbf, iat, jti)
- RFC 7515 — JSON Web Signature (JWS)
- RFC 7517 — JSON Web Key (JWK)
- OWASP JWT Security Cheat Sheet
- Auth0 JWT best practices (token refresh, revocation patterns)

### Key Concepts to Extract
- Supported signing algorithms (HS256, RS256, ES256)
- Key.php constructor (keyMaterial, algorithm)
- JWT::encode($payload, $key, $alg) signature
- JWT::decode($jwt, $keyOrKeyArray) signature
- Exception handling for expired/invalid tokens
- CachedKeySet for key rotation support

---

## Category 5: Group-ACL Model

### Key Files
- `phpbb_dump.sql` — Tables:
  - `phpbb_groups` (line 1847) — Group definitions, group_type field
  - `phpbb_user_group` (line 3669) — User-group membership
  - `phpbb_acl_groups` (line 26) — Group-to-permission assignments
- `src/phpbb/forums/groupposition/groupposition_interface.php` — Group position interface
- `src/phpbb/forums/groupposition/teampage.php` — Team page group handling
- `src/phpbb/forums/groupposition/legend.php` — Legend group handling

### Schema Data (phpbb_dump.sql)
- `phpbb_acl_groups` columns: group_id, forum_id, auth_option_id, auth_role_id, auth_setting
- `phpbb_groups` columns: group_id, group_type, group_name, group_founder_manage
- `phpbb_user_group` columns: group_id, user_id, group_leader, user_pending

### Group Types (from phpBB constants)
- `GROUP_OPEN` (0) — Anyone can join
- `GROUP_CLOSED` (1) — Membership by request/invitation
- `GROUP_HIDDEN` (2) — Hidden group
- `GROUP_SPECIAL` (3) — Built-in system groups (ADMINISTRATORS, GLOBAL_MODERATORS, etc.)

### Key Concepts to Extract
- How group_type maps to admin capabilities
- ADMINISTRATORS and GLOBAL_MODERATORS special groups
- Group-based permission assignment flow (ACP: group → role → options)
- group_founder_manage flag semantics
- How MCP permissions are scoped to moderator groups

---

## Configuration Sources

- `composer.json` — firebase/php-jwt version constraint
- `config.php` — Database and application config
- `docker-compose.yml` — Runtime environment context

---

## Database Schema (for ACL table structures)

- `phpbb_dump.sql` — Complete schema with sample data
- `src/phpbb/install/schemas/schema_data.sql` — Permission option definitions (canonical list of all auth_options)

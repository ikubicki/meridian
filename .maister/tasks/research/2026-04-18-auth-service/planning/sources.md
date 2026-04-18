# Research Sources: phpbb\auth Service

---

## 1. ACL Core (`acl-core`)

### Key Files
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/auth/auth.php` | 1139 | Main ACL class — all permission checking + caching |
| `src/phpbb/forums/auth/provider_collection.php` | ~100 | Provider collection/registry |

### Methods to Analyze (auth.php)
| Line | Method | Purpose |
|------|--------|---------|
| 37 | `acl(&$userdata)` | Init permissions — loads options, fills ACL array |
| 178 | `acl_get($opt, $f)` | Check single permission (global or forum-scoped) |
| 227 | `acl_getf($opt, $clean)` | Get all forums where permission is set |
| 308 | `acl_getf_global($opt)` | Check if permission is set in ANY forum |
| 352 | `acl_gets()` | Check multiple permissions (variadic) |
| 389 | `acl_get_list($user_id, $opts, $forum_id)` | Get permission list for user(s) |
| 421 | `acl_cache(&$userdata)` | Build + store cached permission bitstring |
| 524 | `acl_clear_prefetch($user_id)` | Invalidate permission cache |
| 583 | `acl_role_data($user_type, $role_type, ...)` | Get role-based permission data |
| 616 | `acl_raw_data($user_id, $opts, $forum_id)` | Raw permission data from DB |
| 732 | `acl_user_raw_data(...)` | User-specific raw permission data |
| 784 | `acl_group_raw_data(...)` | Group-specific raw permission data |
| 840 | `acl_raw_data_single_user($user_id)` | All permissions for single user |

### Internal Methods (auth.php)
- `_fill_acl($user_permissions)` — Decode bitstring into acl array
- `_set_group_hold_ary(...)` — Merge group permissions during cache build

### Grep Patterns
- `ACL_OPTIONS_TABLE` — all ACL table constant usages
- `acl_clear_prefetch` — cache invalidation call sites
- `user_permissions` — where cached permissions are read/written
- `_fill_acl` — bitfield decode logic

---

## 2. ACL Database (`acl-database`)

### Tables
| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `phpbb_acl_options` | Permission option definitions | `auth_option_id` PK, `auth_option` (e.g. `f_read`), `is_global`, `is_local`, `founder_only` |
| `phpbb_acl_roles` | Named permission roles | `role_id` PK, `role_name`, `role_type` (`a_`/`m_`/`f_`/`u_`), `role_order` |
| `phpbb_acl_roles_data` | Options assigned to roles | `role_id` + `auth_option_id` composite PK, `auth_setting` (-1/0/1) |
| `phpbb_acl_users` | Direct user permission grants | `user_id`, `forum_id`, `auth_option_id`, `auth_role_id`, `auth_setting` |
| `phpbb_acl_groups` | Group permission grants | `group_id`, `forum_id`, `auth_option_id`, `auth_role_id`, `auth_setting` |

### Related User Table Column
| Table | Column | Type | Purpose |
|-------|--------|------|---------|
| `phpbb_users` | `user_permissions` | mediumtext | Cached ACL bitstring |
| `phpbb_users` | `user_perm_from` | mediumint(8) | User ID whose permissions are used (permission copy) |

### Constants (src/phpbb/common/constants.php)
| Line | Constant | Value |
|------|----------|-------|
| 55 | `ACL_NEVER` | 0 |
| 56 | `ACL_YES` | 1 |
| 57 | `ACL_NO` | -1 |
| 236 | `ACL_GROUPS_TABLE` | `phpbb_acl_groups` |
| 237 | `ACL_OPTIONS_TABLE` | `phpbb_acl_options` |
| 238 | `ACL_ROLES_DATA_TABLE` | `phpbb_acl_roles_data` |
| 239 | `ACL_ROLES_TABLE` | `phpbb_acl_roles` |
| 240 | `ACL_USERS_TABLE` | `phpbb_acl_users` |

### SQL Queries to Run
```sql
-- Permission option categories
SELECT auth_option, is_global, is_local, founder_only FROM phpbb_acl_options ORDER BY auth_option;

-- Roles per type
SELECT role_id, role_name, role_type FROM phpbb_acl_roles ORDER BY role_type, role_order;

-- Sample role→option mapping
SELECT r.role_name, o.auth_option, rd.auth_setting
FROM phpbb_acl_roles_data rd
JOIN phpbb_acl_roles r ON r.role_id = rd.role_id
JOIN phpbb_acl_options o ON o.auth_option_id = rd.auth_option_id
WHERE r.role_id = 1;

-- User direct grants (non-role)
SELECT * FROM phpbb_acl_users WHERE auth_role_id = 0 LIMIT 10;

-- Group grants via roles
SELECT g.group_id, ag.forum_id, r.role_name
FROM phpbb_acl_groups ag
JOIN phpbb_groups g ON g.group_id = ag.group_id
JOIN phpbb_acl_roles r ON r.role_id = ag.auth_role_id
WHERE ag.auth_role_id > 0;
```

---

## 3. Auth Providers (`auth-providers`)

### Key Files
| File | Purpose |
|------|---------|
| `src/phpbb/forums/auth/provider/provider_interface.php` | Auth provider contract: `init()`, `login()`, `autologin()`, `logout()`, `validate_session()` |
| `src/phpbb/forums/auth/provider/base.php` | Abstract base with default no-op implementations |
| `src/phpbb/forums/auth/provider/db.php` | Database auth: password check, login attempts, CAPTCHA gating |
| `src/phpbb/forums/auth/provider/ldap.php` | LDAP auth (reference for multi-provider pattern) |
| `src/phpbb/forums/auth/provider/apache.php` | Apache auth (reference for multi-provider pattern) |

### Interface Methods (provider_interface.php)
| Method | Purpose |
|--------|---------|
| `init()` | Validate provider is available |
| `login($username, $password)` | Authenticate user, returns status array |
| `autologin()` | Cookie/session-based auto-login |
| `acp()` | Admin config fields |
| `get_acp_template($new_config)` | Admin template |
| `get_login_data()` | Extra login form data |
| `get_auth_link_data($user_id)` | Account linking data |
| `logout($data, $new_session)` | Logout handler |
| `validate_session($user)` | Session validation |
| `login_link_has_necessary_data(array $data)` | Account link check |
| `link_account(array $data)` | Link external account |
| `unlink_account(array $data)` | Unlink external account |

### Login Flow (db.php) — Key Steps
1. Validate input (non-empty username/password)
2. Lookup user by `username_clean` in `phpbb_users`
3. Check IP-based login attempt limits
4. Record login attempt in `phpbb_login_attempts`
5. CAPTCHA gate if max attempts exceeded
6. Verify password via `passwords_manager->check()`
7. Return status array: `LOGIN_SUCCESS`, `LOGIN_ERROR_PASSWORD`, `LOGIN_ERROR_ATTEMPTS`, etc.

---

## 4. Admin ACL Management (`admin-acl`)

### Key Files
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/common/acp/acp_permissions.php` | 1387 | Main ACP permission management module |
| `src/phpbb/common/acp/info/acp_permissions.php` | ~50 | Module info/registration |
| `src/phpbb/common/functions_admin.php` | 3317 | Admin helper functions (permission-related subset) |
| `web/adm/style/acp_permissions.html` | — | ACP permission template (UI reference) |

### Methods in acp_permissions.php
| Line | Method | Purpose |
|------|--------|---------|
| 28 | `main($id, $mode)` | Entry point — routes to permission views/actions |
| 570 | `build_subforum_options($forum_list)` | Subforum dropdown builder |
| 615 | `build_permission_dropdown(...)` | Permission type selector |
| 639 | `check_existence($mode, &$ids)` | Validate user/group/forum IDs exist |
| 687 | `set_permissions(...)` | **Core**: Set permissions for user/group on forum |
| 781 | `set_all_permissions(...)` | Bulk permission set |
| 865 | `check_assigned_role(...)` | Match settings to existing role |
| 903 | `remove_permissions(...)` | Remove permission grants |
| 946 | `log_action(...)` | Log permission changes |
| 1008 | `permission_trace($user_id, $forum_id, $permission)` | **Trace**: Show why user has/lacks permission |
| 1236 | `copy_forum_permissions()` | Copy permissions between forums |
| 1293 | `retrieve_defined_user_groups(...)` | Get users/groups with permissions on scope |

### Permission-Related Functions in functions_admin.php
| Line | Function | Purpose |
|------|----------|---------|
| 63 | `make_forum_select(...)` | Forum select respecting ACL |
| 225 | `get_forum_list($acl_list, ...)` | Forum list filtered by ACL |
| 354 | `copy_forum_permissions(...)` | Copy permissions between forums |
| 2532 | `phpbb_cache_moderators($db, $cache, $auth)` | Rebuild moderator cache |
| 2743 | `phpbb_update_foes($db, $auth, ...)` | Update foes based on permissions |
| 3134 | `add_permission_language()` | Load permission language strings |

---

## 5. User Service Integration (`user-service-integration`)

### Key File
| File | Purpose |
|------|---------|
| `src/phpbb/user/IMPLEMENTATION_SPEC.md` | Complete implementation spec for `phpbb\user` service |

### Relevant Service Components (from spec)
| Component | Namespace | Relevance to Auth |
|-----------|-----------|-------------------|
| `AuthenticationService` | `phpbb\user\Service\` | Login/logout, password verification, session creation — **primary integration point** |
| `UserRepositoryInterface` | `phpbb\user\Contract\` | User lookup by ID/username — needed by auth for permission loading |
| `SessionRepositoryInterface` | `phpbb\user\Contract\` | Session validation — needed by API middleware |
| `PasswordHasherInterface` | `phpbb\user\Contract\` | Password verification — used by authentication flow |
| `User` entity | `phpbb\user\Entity\` | Contains `user_permissions` (cached ACL) and `user_perm_from` |
| `Group` entity | `phpbb\user\Entity\` | Group membership — feeds into group-based ACL |
| `GroupRepositoryInterface` | `phpbb\user\Contract\` | Group membership queries — needed for permission resolution |

### Integration Questions
- Does AuthenticationService return a User entity with `user_permissions`?
- Does the user service expose group memberships for a user?
- What events does AuthenticationService dispatch (login success, etc.)?
- How does session management work — does it produce a session token usable by REST API?

---

## 6. REST API Middleware (`rest-api-middleware`)

### Key Files
| File | Purpose |
|------|---------|
| `src/phpbb/api/event/auth_subscriber.php` | JWT auth middleware — validates Bearer token on API routes |
| `src/phpbb/api/v1/controller/auth.php` | Login endpoint — issues JWT tokens (currently mock admin/admin) |
| `src/phpbb/api/v1/controller/users.php` | Users controller — potential consumer of ACL checks |
| `src/phpbb/api/v1/controller/forums.php` | Forums controller — needs `f_read`, `f_post` etc. checks |
| `src/phpbb/api/v1/controller/topics.php` | Topics controller — needs `f_read`, `f_reply` etc. checks |
| `src/phpbb/api/v1/controller/health.php` | Health endpoint — public, no auth |
| `web/api.php` | API entry point |

### Current Auth Flow (auth_subscriber.php)
1. Intercepts all `/api/`, `/adm/api/`, `/install/api/` requests
2. Skips public endpoints: `/health`, `/auth/login`, `/auth/signup`
3. Extracts Bearer token from `Authorization` header
4. Decodes JWT with HS256 (hardcoded secret — **security issue**)
5. Stores decoded claims as `_api_token` request attribute
6. **Missing**: No ACL/permission checking — any authenticated user can access anything

### JWT Token Claims (current)
```json
{
  "iss": "phpBB",
  "iat": 1234567890,
  "exp": 1234571490,
  "user_id": 2,
  "username": "admin",
  "admin": true
}
```

### Gap Analysis Points
- No ACL checking middleware exists (only authentication, no authorization)
- JWT secret is hardcoded (needs config parameter)
- Login is mock-only (needs real db auth via phpbb\user service)
- No permission-aware route protection
- No per-forum permission checking for forum/topic endpoints
- Missing: refresh token flow, token revocation

---

## Configuration Sources

| File | Relevance |
|------|-----------|
| `src/phpbb/common/constants.php` | ACL table constants (line 55-57, 236-240), LOGIN_* status constants |
| `composer.json` | Dependencies: `firebase/php-jwt` (JWT library) |
| `config.php` | DB connection settings, table prefix |
| `docker-compose.yml` | Docker MySQL access for DB queries |

---

## Grep Patterns for Discovery

| Pattern | Purpose |
|---------|---------|
| `acl_get\|acl_f_get\|acl_getf\|acl_gets` | All permission check call sites across codebase |
| `acl_clear_prefetch` | Cache invalidation triggers |
| `user_permissions` | Permission cache read/write points |
| `ACL_OPTIONS_TABLE\|ACL_ROLES_TABLE\|ACL_USERS_TABLE\|ACL_GROUPS_TABLE` | All ACL table references |
| `auth_option\|auth_role\|auth_setting` | ACL column name references |
| `_api_token` | Where JWT claims are consumed in controllers |
| `LOGIN_SUCCESS\|LOGIN_ERROR` | Login status constants |

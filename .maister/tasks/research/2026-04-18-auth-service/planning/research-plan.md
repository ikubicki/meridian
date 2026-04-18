# Research Plan: phpbb\auth Service

**Date**: 2026-04-18
**Type**: Technical (codebase extraction + architecture design)
**Status**: Planning complete

---

## Research Overview

### Research Question
How to design a modern `phpbb\auth\` service extracted from legacy phpBB ACL code — covering authentication (integrating with `phpbb\user` Service), ACL permission checking, role/option management — to be used by REST API middleware.

### Sub-Questions
1. How does the legacy ACL bitfield format work (`user_permissions` column, `_fill_acl`, padding to 31 bits)?
2. What is the complete permission resolution flow: user direct → group inherited → role-based → NEVER override?
3. How do `acl_get()`, `acl_f_get()`, `acl_getf()`, `acl_gets()` differ and what modern API should replace them?
4. How does `acl_cache()` build the cached permission bitstring and when is it invalidated?
5. What do the auth providers (`db.php`, `base.php`, `provider_interface`) handle vs what `phpbb\user\Service\AuthenticationService` already covers?
6. How does the ACP permission management work (roles, assignment to users/groups, forum-scoped vs global)?
7. What does the current REST API auth subscriber do, and what's missing (ACL checking)?
8. How should the new service integrate with the already-designed `phpbb\user` service?

### Scope & Boundaries
- **In scope**: ACL core, auth providers pattern, DB schema, ACP permission management, REST API integration, user service integration
- **Out of scope**: OAuth providers, CAPTCHA, PM permissions, user CRUD

---

## Methodology

**Primary**: Static codebase analysis with DB schema mapping
**Secondary**: Cross-referencing with already-designed `phpbb\user` service spec

### Approach
1. Extract all ACL logic from legacy code (auth.php — 1139 lines, acp_permissions.php — 1387 lines, functions_admin.php permission functions)
2. Map DB schema relationships (5 ACL tables + user_permissions cache column)
3. Understand the bitfield encoding/decoding mechanism
4. Analyze auth provider pattern for authentication flow extraction
5. Review existing REST API middleware for integration gap analysis
6. Cross-reference `phpbb\user\IMPLEMENTATION_SPEC.md` for integration contract

### Analysis Framework
| Dimension | What to extract |
|-----------|----------------|
| **Data model** | Tables, columns, relationships, indexes, FK constraints |
| **Permission types** | Global (a_, m_, u_) vs local/forum-scoped (f_), founder_only flags |
| **Resolution algorithm** | User direct grants → group grants → role expansion → NEVER override |
| **Bitfield format** | Binary string encoding, 31-bit padding, global vs local sections |
| **Cache mechanism** | `user_permissions` column, `_acl_options` cache key, invalidation triggers |
| **Provider pattern** | Interface, login flow, session validation, password checking |
| **Admin workflow** | How ACP sets/gets permissions, role assignment, permission trace |
| **API integration** | JWT auth subscriber, token claims, missing ACL middleware |

---

## Research Phases

### Phase 1: Broad Discovery
- Map all files that reference ACL, permissions, auth
- Identify all phpbb_acl_* table constants and usages
- List all functions/methods in auth.php, acp_permissions.php, functions_admin.php
- Scan for any existing modern auth code under `src/phpbb/auth/` (new namespace)

### Phase 2: Targeted Reading
- Read auth.php completely — extract every method's purpose, parameters, return values
- Read acp_permissions.php — understand admin permission management flow
- Read relevant functions from functions_admin.php (copy_forum_permissions, phpbb_cache_moderators, add_permission_language)
- Read auth provider interface + db provider — extract login/validation contract
- Read phpbb\user\IMPLEMENTATION_SPEC.md — identify AuthenticationService contract
- Read REST API auth_subscriber.php and auth controller — understand current JWT flow

### Phase 3: Deep Dive
- Trace permission resolution: `acl()` → `_fill_acl()` → `acl_get()` — full bitfield decode
- Trace permission caching: `acl_cache()` → DB queries → bitstring build → `user_permissions` write
- Trace ACP permission set: `set_permissions()` → role assignment → cache invalidation
- Map auth_setting values: ACL_NEVER(0), ACL_YES(1), ACL_NO(-1) and merge logic
- Identify all `acl_clear_prefetch()` call sites (cache invalidation triggers)

### Phase 4: Verification
- Cross-reference DB schema with code (all 5 ACL tables used consistently)
- Verify permission categories (a_, m_, f_, u_) map to role_type values
- Confirm integration points with phpbb\user service spec
- Validate that REST API auth subscriber gap analysis is complete

---

## Gathering Strategy

### Instances: 6

| # | Category ID | Focus Area | Primary Tools | Output Prefix |
|---|------------|------------|---------------|---------------|
| 1 | `acl-core` | Main auth.php class: all ACL methods, permission bitfield format, `_fill_acl()`, cache read/decode mechanism | Read, Grep | `acl-core` |
| 2 | `acl-database` | All 5 phpbb_acl_* tables schema, relationships, sample queries, constants in constants.php, `user_permissions` column | Terminal (SQL), Read, Grep | `acl-database` |
| 3 | `auth-providers` | Auth provider pattern: `provider_interface.php`, `base.php`, `db.php` — login flow, session validation, password checking | Read | `auth-providers` |
| 4 | `admin-acl` | ACP permission management: `acp_permissions.php` (set/remove/trace), `functions_admin.php` (copy_forum_permissions, cache_moderators, add_permission_language) | Read, Grep | `admin-acl` |
| 5 | `user-service-integration` | `phpbb\user\IMPLEMENTATION_SPEC.md` — AuthenticationService contract, session management, password hashing, events, what's already provided vs what auth service needs | Read | `user-service-integration` |
| 6 | `rest-api-middleware` | Existing REST API: `auth_subscriber.php` (JWT middleware), `v1/controller/auth.php` (login endpoint), API routing, missing ACL middleware gap | Read, Grep | `rest-api-middleware` |

### Rationale
Six categories reflect the six distinct knowledge domains needed:
- **acl-core** (heaviest — 1139-line auth.php is the heart of the system)
- **acl-database** (schema is the source of truth for data model design)
- **auth-providers** (separate concern: authentication vs authorization)
- **admin-acl** (admin workflow reveals how permissions are managed, critical for write-side API)
- **user-service-integration** (already-designed service defines the contract we must integrate with)
- **rest-api-middleware** (target consumer — defines what the new service must expose)

---

## Success Criteria

| # | Criterion | Validation |
|---|-----------|------------|
| 1 | Complete map of all ACL methods with signatures, behavior, and SQL queries | All 13 `acl_*` methods in auth.php documented |
| 2 | Bitfield format fully understood | Can describe encode/decode algorithm for `user_permissions` |
| 3 | DB schema with all relationships mapped | ER diagram or relationship description for 5 ACL tables |
| 4 | Permission resolution algorithm documented | Step-by-step: user→group→role merge with NEVER override |
| 5 | Auth provider login flow extracted | Interface contract for authentication (separate from ACL) |
| 6 | ACP management flow documented | How admin sets permissions via roles and direct grants |
| 7 | Integration contract with phpbb\user defined | What AuthenticationService provides, what auth service needs |
| 8 | REST API ACL middleware gap identified | What auth_subscriber does now vs what's needed |
| 9 | Cache invalidation triggers listed | All code paths that call `acl_clear_prefetch()` |

---

## Expected Outputs

1. **`analysis/findings/acl-core-*.md`** — Complete ACL method analysis, bitfield format, cache mechanism
2. **`analysis/findings/acl-database-*.md`** — DB schema, relationships, sample data patterns
3. **`analysis/findings/auth-providers-*.md`** — Auth provider interface and db provider analysis
4. **`analysis/findings/admin-acl-*.md`** — ACP permission management flow, role system
5. **`analysis/findings/user-service-integration-*.md`** — Integration points with phpbb\user
6. **`analysis/findings/rest-api-middleware-*.md`** — Current REST API auth, gap analysis
7. **`analysis/report.md`** — Consolidated research report with recommendations
8. **Recommended next step** — Implementation spec draft for `phpbb\auth\` service

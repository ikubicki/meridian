# Research Brief: Users Service (`phpbb\user`)

## Research Question

How should the `phpbb\user` service be designed to provide user management (CRUD), preferences, group membership, banning (shadow + permanent), session management, and authentication — as a standalone PSR-4 service with zero legacy code dependencies, based on existing phpBB database schemas, extensible via events and decorators, and exposed through a REST API?

## Research Type

**Mixed** (Technical + Requirements + Literature)

- **Technical**: Analyze existing phpBB user tables (`phpbb_users`, `phpbb_groups`, `phpbb_user_group`, `phpbb_banlist`, `phpbb_sessions`, `phpbb_sessions_keys`, `phpbb_profile_fields*`), legacy code (`session.php`, `user.php`, `functions_user.php`, `auth.php`), and the existing `IMPLEMENTATION_SPEC.md`
- **Requirements**: Define what the Users Service must provide for downstream consumers (Auth Service, Notifications, Messaging, Threads), addressing the critical gap identified in cross-cutting assessment
- **Literature**: Best practices for shadow banning, user management APIs, PHP 8.2+ patterns, extensibility via events/decorators

## Scope

### Included

1. **User Management**: CRUD operations, registration, activation, deactivation
2. **Authentication**: Login/logout, session management, persistent tokens, password hashing/reset
3. **Preferences**: User settings (lang, timezone, dateformat, style, notification prefs, privacy prefs)
4. **Groups**: Group CRUD, membership management, leader/pending states, group types (open/closed/hidden/special)
5. **Banning**: User/IP/email bans, shadow banning (new requirement not in legacy), permanent vs timed bans, ban exclusions (whitelist)
6. **Profile**: Avatar, signature, birthday, custom profile fields
7. **Session Management**: Session lifecycle, garbage collection, "who is online" data
8. **REST API Endpoints**: User CRUD, profile, preferences, groups, bans endpoints
9. **Events**: Domain events for all state changes (consumed by Notifications, Auth cache, etc.)
10. **Extensibility**: Plugin model via events + decorators (align with cross-cutting Hierarchy/Threads pattern)

### Excluded

- Authorization/ACL (belongs to `phpbb\auth`)
- Email sending (event consumers handle delivery)
- Admin Control Panel UI (separate concern)
- Content formatting/BBCode (separate pipeline)
- Full-text search (separate service)

### Constraints

- PHP 8.2+ (readonly classes, enums, match expressions)
- PDO with prepared statements (no legacy DBAL)
- Symfony DI Container (YAML-configured)
- Symfony EventDispatcher for domain events
- Must NOT import any legacy `phpbb\` class
- Database schema based on existing tables (reuse, not recreate)
- Must resolve Critical Blocker #1 from cross-cutting assessment: Auth depends on `phpbb\user\Entity\User` and `phpbb\user\Service\AuthenticationService`
- Bearer token auth (DB opaque tokens, aligning with REST API ADR-002)

## Success Criteria

1. Complete service decomposition with clear responsibility boundaries
2. Entity model covering all user-related data (User, Group, Ban, Session, Profile, Preferences)
3. Shadow ban design documented with behavioral spec
4. REST API endpoints defined with request/response shapes
5. Event catalog for all state changes
6. Extension points via events + decorators documented
7. Integration interfaces for Auth Service, Notifications, Messaging consumers
8. Resolution of cross-cutting blocker: User entity + AuthenticationService spec
9. Session management design including token lifecycle (aligning with REST API's DB token approach)

## Pre-Existing Context

### Existing Implementation Spec

An `IMPLEMENTATION_SPEC.md` already exists at `src/phpbb/user/IMPLEMENTATION_SPEC.md` with:
- Complete entity model (User, UserProfile, UserPreferences, Session, Group, GroupMembership, Ban)
- 4 enums (UserType, BanType, GroupType, NotifyType)
- 6 repository interfaces + PDO implementations
- 9 service classes (Authentication, Registration, Password, Profile, Preferences, Session, Group, Ban, UserSearch)
- 8 domain events
- 13 exception classes
- DTOs for all inputs

This spec should be treated as a strong starting point but needs evaluation against:
- Shadow banning (not in current spec)
- Plugin/decorator extensibility model
- REST API endpoint design
- Cross-cutting alignment with other services
- Session → token migration path

### Cross-Cutting Dependencies

From the cross-cutting assessment, this service must provide:
- `phpbb\user\Entity\User` → consumed by Auth's `AuthorizationService::isGranted(User $user, ...)`
- `phpbb\user\Service\AuthenticationService` → owns login/logout/session
- `GroupRepository` interface → Auth's `PermissionResolver` needs user group membership
- `user_id` referenced by: Threads, Messaging, Notifications, Storage, Hierarchy

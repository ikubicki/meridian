# Research Brief: phpbb\auth Service

**Date**: 2026-04-18  
**Type**: Technical (codebase extraction + architecture design)  
**Status**: Active

---

## Research Question

Jak zaprojektować nowoczesny serwis `phpbb\auth\` wyekstrahowany z legacy phpBB codebase, odpowiedzialny za:

1. **Uwierzytelnianie** — weryfikacja tożsamości użytkownika (logowanie via `phpbb\user` Service, walidacja tokenów API)
2. **Autoryzacja (ACL)** — sprawdzanie uprawnień do komponentów systemu (`acl_get`, `acl_f_get`)
3. **Zarządzanie uprawnieniami** — administracja rolami, opcjami, przypisaniami user/group→permission

Serwis będzie docelowo konsumowany przez middleware REST API.

---

## Scope

### Included
- Legacy auth system: `src/phpbb/forums/auth/auth.php` (klasa `\phpbb\auth\auth`)
- ACL methods: `acl()`, `acl_get()`, `acl_gets()`, `acl_f_get()`, `acl_getf()`, `acl_cache()`
- Auth providers: `src/phpbb/forums/auth/provider/` (db.php, base.php, interface)
- DB tables: `phpbb_acl_options`, `phpbb_acl_roles`, `phpbb_acl_roles_data`, `phpbb_acl_users`, `phpbb_acl_groups`
- Cached permissions: `user_permissions` field in `phpbb_users`
- Integration z `phpbb\user\Service\AuthenticationService`
- REST API middleware pattern (token-based auth + ACL checking)

### Excluded
- User CRUD, session management (→ `phpbb\user`)
- OAuth / social login providers
- CAPTCHA / anti-spam systems
- PM permissions (→ future `phpbb\messaging`)

### Constraints
- PHP 8.2+ (enums, readonly properties, strict types)
- Zero dependency na legacy kod
- PDO + prepared statements
- Interface-first (Contract/ directory)
- Musi współistnieć z legacy ACL (te same tabele DB)

---

## Success Criteria

1. Pełna mapa legacy ACL system (tabele, metody, flow)
2. Zrozumienie permission bitfield format i cache mechanism
3. Zdefiniowany czytelny interfejs serwisu auth
4. Plan integracji z phpbb\user Service
5. Koncept middleware REST API (token auth + permission checking)
6. Lista wszystkich ACL options z bazy (kategorie: a_, m_, f_, u_)

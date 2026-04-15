# System Architecture

## Overview
**phpBB Vibed** is built on phpBB 3.3.x — a **hybrid monolithic web application** that combines a legacy PHP procedural layer with a modern OOP-based core using Symfony's Dependency Injection Container and Event Dispatcher.

The system serves traditional web forum functionality: user registration/authentication, discussion threads (topics/posts), private messaging, user/group management, and a full admin control panel — all rendered server-side via Twig templates.

---

## Architecture Pattern
**Pattern**: Hybrid Monolith — Legacy Procedural + Symfony DI OOP

The codebase has two coexisting architectural styles:

1. **Legacy layer** (`includes/`) — global procedural functions, PHP 4-era class syntax, `global` variable injection. Handles the bulk of domain logic for ACP/MCP/UCP flows and shared utilities.
2. **Modern OOP layer** (`phpbb/`) — PSR-4 namespaced classes, Symfony DI Container (YAML-configured), interface-based abstractions, constructor injection, Symfony EventDispatcher for extensibility.

HTTP entry points split between:
- **File-based routing** (`index.php`, `viewtopic.php`, `posting.php`, etc.) — legacy direct PHP pages
- **Symfony HttpKernel** (`app.php`) — controller-based routing via YAML routing config

---

## System Structure

### Core Library (`phpbb/`)
- **Location**: `phpbb/`
- **Purpose**: Modern OOP core — all new code and refactored services live here
- **Key subsystems**:
  - `phpbb/cache/driver/` — pluggable cache drivers (file, APCu, Redis, Memcached)
  - `phpbb/auth/provider/` — authentication providers (DB, LDAP, OAuth, Apache)
  - `phpbb/passwords/driver/` — password hashing algorithms (Argon2id, bcrypt, phpass)
  - `phpbb/db/driver/` — database abstraction layer (MySQL, PostgreSQL, MSSQL, SQLite3, Oracle)
  - `phpbb/db/migration/` — schema migration runner and data migrations
  - `phpbb/event/dispatcher.php` — Symfony EventDispatcher extension (extension hook system)
  - `phpbb/template/twig/` — Twig template engine integration
  - `phpbb/controller/` — HTTP controller resolver for `app.php` routes
  - `phpbb/search/` — full-text search drivers (MySQL FT, PostgreSQL FT, Sphinx)
  - `phpbb/notification/` — notification system (email, board notifications)
  - `phpbb/acl/` — Access Control List (fine-grained permissions)

### Legacy Layer (`includes/`)
- **Location**: `includes/`
- **Purpose**: Procedural functions and legacy class files — gradually being replaced by DI services
- **Key files**:
  - `includes/functions.php` — core utility functions (thousands of lines)
  - `includes/functions_posting.php` — posting/editing logic
  - `includes/functions_display.php` — template variable preparation
  - `includes/functions_user.php` — user operations (register, ban, etc.)
  - `includes/acp/` — Admin Control Panel modules
  - `includes/mcp/` — Moderator Control Panel modules
  - `includes/ucp/` — User Control Panel modules

### DI Container Configuration (`config/`)
- **Location**: `config/default/container/` (30+ YAML service definition files)
- **Purpose**: Wires all services in the DI container
- **Key files**: `services_db.yml`, `services_auth.yml`, `services_twig.yml`, `services_cache.yml`, routing configs
- `config/production/` — production environment overrides
- `config/installer/` — installer-specific service definitions

### HTTP Entry Points

| File | Routing mechanism | Purpose |
|---|---|---|
| `index.php` | Direct PHP | Forum index (category/forum listing) |
| `viewforum.php` | Direct PHP | Forum topic listing |
| `viewtopic.php` | Direct PHP | Thread/post display |
| `posting.php` | Direct PHP | Post creation/editing |
| `app.php` | Symfony HttpKernel | Controller-based routes (API/extensions) |
| `adm/index.php` | Direct PHP | Admin Control Panel |
| `ucp.php` | Direct PHP | User Control Panel |
| `mcp.php` | Direct PHP | Moderator Control Panel |
| `bin/phpbbcli.php` | Symfony Console | CLI commands |

### Template Layer (`styles/`)
- **Location**: `styles/prosilver/template/` (Twig templates), `styles/prosilver/theme/` (CSS)
- **Purpose**: Server-side rendering of all forum views
- **Template engine**: Twig (cached in `cache/twig/`)
- **Theme**: prosilver — the default phpBB theme
- **Frontend**: Vanilla JavaScript + static CSS (no bundler)

### Extension System (`ext/`)
- **Location**: `ext/`
- **Purpose**: First-party and third-party extensions (plugins)
- **Mechanism**: Extensions hook into 500+ Symfony Event Dispatcher hooks across the codebase, register their own DI services, add routes, and extend templates via Twig inheritance
- **Example**: `ext/phpbb/viglink/` — affiliate link injection extension

### Runtime Storage
| Directory | Purpose |
|---|---|
| `cache/` | DI container compilation cache, Twig compiled templates |
| `store/` | Runtime data store (search indexes, etc.) |
| `files/` | User-uploaded attachments |
| `images/` | System images (avatars, ranks, smilies) |

---

## Data Flow

```
Browser Request
    │
    ▼
Entry Point (e.g., viewtopic.php  OR  app.php)
    │
    ├── common.php included
    │       ├── DI Container bootstrapped (config/default/container/)
    │       ├── Database connection initialized (phpbb/db/driver/)
    │       ├── Session started (phpbb/session.php)
    │       ├── Auth checked (phpbb/auth/)
    │       └── Global $db, $config, $user, $template set
    │
    ├── Business logic (includes/ functions OR phpbb/ services)
    │       └── Events fired via phpbb\event\dispatcher (extensions can intercept)
    │
    ├── Template variables assigned ($template->assign_vars())
    │
    ├── Twig template rendered (styles/prosilver/template/*.html)
    │
    └── HTML response sent to browser
```

---

## External Integrations

| Integration | Library | Purpose |
|---|---|---|
| Google reCAPTCHA | `google/recaptcha` | Anti-spam for registration/posting |
| OAuth providers | `carlos-mg89/oauth` | Social login (configurable providers) |
| Sphinx Search | Native TCP | External full-text search daemon |
| LDAP | PHP `ldap_*` | Enterprise authentication |
| SMTP / IMAP | phpBB messenger | Email notifications |
| Guzzle HTTP | `guzzlehttp/guzzle` | External HTTP requests (version checks, OAuth) |

---

## Configuration

- **Service configuration**: YAML files in `config/default/container/`
- **Runtime configuration**: Database-stored `config` table (accessed via `$config` global/service)
- **Environment configuration**: `config.php` (database credentials, installation path)
- **Install-time configuration**: `config/installer/` — installer-specific DI wiring

---

## Security Architecture

- **CSRF**: `check_form_key()` on all state-changing forms — token stored in session
- **SQL injection**: `$db->sql_escape()` and parameterized DBAL query builder
- **XSS**: Twig auto-escaping + phpBB `htmlspecialchars_decode()` wrappers
- **Password security**: Multi-algorithm hashing with Argon2id as modern default
- **ACL**: Bitmask-based permissions with group inheritance (`phpbb/acl/`)
- **Session security**: IP/user-agent binding, SID rotation on privilege escalation

---

## Deployment Architecture

- **Server-side rendering only** — no separate frontend process
- **Single process** — PHP-FPM / mod_php serves all requests
- **Web server compatibility**: Apache, Nginx, IIS, lighttpd (sample configs in `docs/`)
- **Database**: Any supported engine (MySQL recommended for production)
- **Cache**: File cache by default; APCu or Redis recommended for production
- **CLI**: `bin/phpbbcli.php` for migrations, cache clearing, cron tasks

---

*Based on codebase analysis performed April 2026*
*Auto-detected from*: `app.php`, `common.php`, `config/default/container/`, `phpbb/`, `includes/`, `styles/prosilver/`, `ext/`

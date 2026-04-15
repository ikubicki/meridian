# Technology Stack

## Overview
This document describes the technology choices and rationale for **phpBB Vibed** (phpBB 3.3.15 modernization project).

---

## Languages

### PHP (7.2+ / 8.x)
- **Usage**: ~100% of backend codebase
- **Minimum version**: PHP 7.2.0 (enforced at startup in `includes/startup.php`)
- **Supported range**: `^7.2 || ^8.0` (from `composer.json`)
- **Rationale**: phpBB is a PHP-native application. PHP 8.x is the modernization target for strict typing, named arguments, enums, and readonly properties.
- **Key Features Used**:
  - PSR-4 autoloading under `phpbb\` namespace
  - Constructor-based Dependency Injection
  - PHPDoc annotations throughout `phpbb/` layer
  - Legacy procedural style in `includes/` (under active reduction)

---

## Frameworks

### Backend

#### Symfony Components (3.4 LTS — EOL, upgrade targeted)
| Component | Version | Purpose |
|---|---|---|
| `symfony/dependency-injection` | ~3.4 | DI Container (YAML service definitions) |
| `symfony/event-dispatcher` | ~3.4 | Extension event system (500+ hooks) |
| `symfony/http-foundation` | ~3.4 | Request/Response abstraction |
| `symfony/http-kernel` | ~3.4 | HTTP Kernel (`app.php` routing) |
| `symfony/routing` | ~3.4 | URL routing |
| `symfony/console` | ~3.4 | CLI tool (`bin/phpbbcli.php`) |
| `symfony/config` | ~3.4 | YAML configuration loading |
| `symfony/finder` | ~3.4 | File system traversal |
| `symfony/filesystem` | ~3.4 | File operations |
| `symfony/yaml` | ~3.4 | YAML parsing for service configs |
| `symfony/process` | ^3.4 | System process execution |
| `symfony/proxy-manager-bridge` | ~3.4 | Lazy service proxies |

> **⚠️ Upgrade Target**: Symfony 3.4 is EOL. Planned migration to Symfony 6.x/7.x LTS.

#### Twig (1.x / 2.x — upgrade targeted)
- **Version**: `^1.0 || ^2.0`
- **Bridge**: `symfony/twig-bridge ~3.4`
- **Usage**: Template rendering for all forum views (`styles/prosilver/template/`)
- **Cache**: Compiled templates stored in `cache/twig/`
- **Rationale**: phpBB's default templating engine since major refactor. Twig 3.x is the upgrade target.

### Frontend
- **No modern JS framework** — Vanilla JavaScript only (`styles/prosilver/template/ajax.js`, `forum_fn.js`)
- **No bundler** — No webpack/Vite; all CSS/JS served as static files
- **CSS**: Hand-crafted stylesheets in `styles/prosilver/theme/` (normalize.css, responsive.css, base.css)
- **Icons**: Font Awesome (`assets/css/font-awesome.min.css`)
- **File upload**: Plupload.js (`assets/plupload/`)

### Testing
| Library | Version | Purpose |
|---|---|---|
| `phpunit/phpunit` | ^7.0 | Unit and integration testing |
| `phpunit/dbunit` | ~4.0 | Database integration tests |
| `fabpot/goutte` | ~3.2 | Functional testing (HTTP client) |
| `php-webdriver/webdriver` | ~1.8 | E2E testing via Selenium WebDriver |
| `squizlabs/php_codesniffer` | ~3.4 | Code style linting |

---

## Database

### Custom Multi-Driver DBAL (factory pattern)
- **Location**: `phpbb/db/driver/`
- **Supported engines**: MySQL/MariaDB, PostgreSQL, MSSQL, SQLite3, Oracle
- **No external ORM** — direct SQL via phpBB's own DBAL
- **Query safety**: Parameterized queries via `$db->sql_build_query()` and `$db->sql_escape()`
- **Migrations**: Custom migrator in `phpbb/db/migration/` — class-based schema migrations (`phpbb/db/migration/data/v30x/` through `v33x/`)
- **Rationale**: phpBB's DBAL predates modern ORMs; provides cross-database portability without Doctrine overhead

---

## Caching

### Multi-Driver Cache Layer (`phpbb/cache/driver/`)
| Driver | Description |
|---|---|
| `file` | Default — file-based cache in `cache/` |
| `apcu` | APCu in-memory cache (recommended for production) |
| `memcached` | Memcached distributed cache |
| `redis` | Redis cache |
| `wincache` | Windows Cache Extension (for IIS deployments) |
| `null` | No-cache driver (testing/dev) |

---

## Authentication & Security

- **Password hashing**: Multi-algorithm driver (`phpbb/passwords/driver/`) — Argon2id, Argon2i, bcrypt, salted MD5, phpass
- **Auth providers**: DB, Apache, LDAP, OAuth (`phpbb/auth/provider/`)
- **OAuth**: `carlos-mg89/oauth: ^0.8.15`
- **Anti-spam**: Google reCAPTCHA v2 (`google/recaptcha: ~1.1`)
- **CSRF protection**: `check_form_key()` on all state-changing POST requests
- **ACL system**: Fine-grained permission system in `phpbb/acl/`

---

## Search

| Driver | Type |
|---|---|
| MySQL Full-Text | Native MySQL/MariaDB FTS |
| PostgreSQL Full-Text | Native PostgreSQL FTS |
| Sphinx Search | External Sphinx Search daemon |

---

## Text Processing

- **BBCode / text formatter**: `s9e/text-formatter: ^2.0`
- **Image size detection**: `marc1706/fast-image-size: ^1.1`

---

## Build Tools & Package Management

| Tool | Version | Purpose |
|---|---|---|
| Composer | (latest) | PHP dependency management |
| Phing | ~2.4 | Build system (Ant/XML-based) |
| PHP_CodeSniffer | ~3.4 | PHP code style linting |

---

## Infrastructure

### Containerization
- Not configured in this repository (no Dockerfile / docker-compose.yml detected)
- **Laravel Homestead** (`~7.0`) available as Vagrant-based dev environment

### CI/CD
- **Not configured** in this repository (no `.github/workflows/`, no `.travis.yml`)
- Upstream phpBB project uses their own CI infrastructure
- **Planned**: GitHub Actions pipeline (lint + tests + `composer audit`)

### Hosting Compatibility
- Apache (`.htaccess`-based)
- Nginx (`docs/nginx.sample.conf`)
- IIS (`web.config` present in root)
- lighttpd (`docs/lighttpd.sample.conf`)

---

## HTTP Client

- **Guzzle**: `guzzlehttp/guzzle: ~6.3` — used for external HTTP calls (version updates, OAuth flows)

---

## Key Dependencies Summary

| Package | Version | Category |
|---|---|---|
| `symfony/*` | ~3.4 | Framework (EOL — upgrade planned) |
| `twig/twig` | ^1.0 \|\| ^2.0 | Templating (upgrade planned) |
| `s9e/text-formatter` | ^2.0 | BBCode parsing |
| `guzzlehttp/guzzle` | ~6.3 | HTTP client |
| `google/recaptcha` | ~1.1 | Anti-spam |
| `carlos-mg89/oauth` | ^0.8.15 | OAuth authentication |
| `marc1706/fast-image-size` | ^1.1 | Image processing |

---

## Migration Path

| Component | Current | Target | Priority |
|---|---|---|---|
| Symfony | 3.4 (EOL) | 6.x / 7.x LTS | High |
| Twig | 1.x / 2.x | 3.x | High |
| PHP minimum | 7.2 | 8.1 | Medium |
| PHP_CodeSniffer | 3.4 | PHPStan (static analysis) | Medium |
| JavaScript | Vanilla | Evaluate: Alpine.js / minimal toolchain | Low |

---

*Last Updated*: April 2026
*Auto-detected from*: `composer.json`, `includes/startup.php`, `includes/constants.php`, `config/default/container/`, `phpbb/cache/driver/`, `phpbb/auth/provider/`

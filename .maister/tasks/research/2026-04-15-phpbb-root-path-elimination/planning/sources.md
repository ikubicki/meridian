# Research Sources

## Codebase Sources

### Bootstrap Chain — Entrypoints

| File | Relevance |
|------|-----------|
| `web/index.php` | Sets `$phpbb_root_path = './'`; defines `PHPBB_FILESYSTEM_ROOT`; includes `common.php` |
| `web/app.php` | Same pattern as index.php |
| `web/viewforum.php` | Same pattern |
| `web/viewtopic.php` | Same pattern (also many URL uses) |
| `web/memberlist.php` | Same pattern; has mixed `PHPBB_FILESYSTEM_ROOT` and `$phpbb_root_path` includes |
| `web/mcp.php` | Same pattern |
| `web/posting.php` | Same pattern |
| `web/ucp.php` | Same pattern |
| `web/search.php` | Same pattern |
| `web/report.php` | Same pattern |
| `web/viewonline.php` | Same pattern |
| `web/faq.php` | Same pattern |
| `web/feed.php` | Same pattern |
| `web/cron.php` | Same pattern |
| `web/css.php` | `$phpbb_root_path = __DIR__ . '/../'` (already absolute) |
| `web/install.php` | `require('../src/phpbb/install/app.php')` — relative path, no `$phpbb_root_path` |

### Bootstrap Chain — Core Files

| File | Relevance |
|------|-----------|
| `src/phpbb/common/common.php` | Central bootstrap; 9+ requires using `$phpbb_root_path`; where the CWD bug manifests |
| `src/phpbb/common/startup.php` | Loads `vendor/autoload.php` via `$phpbb_root_path`; PHP version check |
| `bin/phpbbcli.php` | `$phpbb_root_path = __DIR__ . '/../'` (absolute); 7 requires using it |

### File Patterns
- `web/*.php` — all entrypoints (15 files)
- `src/phpbb/common/common.php` — bootstrap
- `src/phpbb/common/startup.php` — composer bootstrap
- `bin/phpbbcli.php` — CLI entry

---

## DI Service Sources

### Service PHP Files That Carry `$phpbb_root_path`

| File | Property / Use |
|------|----------------|
| `src/phpbb/forums/di/container_builder.php` | `$this->phpbb_root_path`; constructor param; passes to `core.root_path` container parameter |
| `src/phpbb/forums/config_php_file.php` | `$this->phpbb_root_path`; builds path to `config.php` file |
| `src/phpbb/forums/finder.php` | `$this->phpbb_root_path`; builds absolute paths to extension directories |
| `src/phpbb/forums/extension/manager.php` | `$this->phpbb_root_path`; resolves `src/phpbb/ext/` directory |
| `src/phpbb/forums/path_helper.php` | `$this->phpbb_root_path`; exposes `get_phpbb_root_path()` |
| `src/phpbb/forums/user.php` | `global $phpbb_root_path`; checks for `/install` directory; includes `functions_user.php` |
| `src/phpbb/forums/auth/provider/db.php` | `$this->phpbb_root_path`; passed from container |
| `src/phpbb/forums/auth/provider/apache.php` | `$this->phpbb_root_path`; passed from container |
| `src/phpbb/forums/message/form.php` | `$this->phpbb_root_path`; URL construction |
| `src/phpbb/forums/message/admin_form.php` | `$this->phpbb_root_path`; includes `functions_user.php` |

### YAML Service Config (if exists)
- `src/phpbb/common/config/` — check for `services.yml` / `container.yml` with `%core.root_path%`

### File Patterns
- `src/phpbb/forums/**/*.php` — DI service classes (use grep for `phpbb_root_path`)
- `src/phpbb/common/config/**/*.yml` — container parameters / service definitions

---

## Install Scripts Sources

### Core Install Files

| File | Relevance |
|------|-----------|
| `src/phpbb/install/app.php` | Sets `$phpbb_root_path` with `PHPBB_ROOT_PATH` constant or `'../../../'` fallback |
| `src/phpbb/install/startup.php` | `phpbb_require_updated()` / `phpbb_include_updated()` helpers; `installer_class_loader()`; heavy `$phpbb_root_path` use |
| `src/phpbb/install/convert/convertor.php` | Multiple `include_once($phpbb_root_path . ...)` for runtime-loaded procedural files |
| `src/phpbb/install/convert/controller/convertor.php` | `$phpbb_root_path` as constructor-injected property |

### File Patterns
- `src/phpbb/install/**/*.php` — all install files (grep for `phpbb_root_path`)

---

## Configuration Sources

| File | Relevance |
|------|-----------|
| `composer.json` | PSR-4 map: `phpbb\forums\` → `src/phpbb/forums/`, `phpbb\common\` → `src/phpbb/common/` |
| `docker-compose.yml` | `PHPBB_ROOT_PATH: /var/www/phpbb/` env var; `PHPBB_FILESYSTEM_ROOT` injected via nginx |
| `docker/nginx/default.conf` | `fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/` — used by `src/phpbb/install/app.php` |
| `vendor/composer/autoload_psr4.php` | Verify actual registered PSR-4 prefixes after `composer install` |

---

## Key Grep Patterns for Gatherers

```bash
# All filesystem requires using $phpbb_root_path
grep -rn "require\|include" --include="*.php" . | grep 'phpbb_root_path'

# All files setting $phpbb_root_path
grep -rn "\$phpbb_root_path\s*=" --include="*.php" .

# DI services with phpbb_root_path property
grep -rn "this->phpbb_root_path" --include="*.php" src/

# global $phpbb_root_path usage in OOP classes
grep -rn "global \$phpbb_root_path" --include="*.php" src/

# Container parameter injection
grep -rn "core\.root_path\|phpbb_root_path" --include="*.yml" src/
```

---

## Non-Autoloadable Procedural Files (Must Stay as Explicit Requires)

These files are procedural (no class, no namespace) and cannot be PSR-4 autoloaded:

| File | Why explicit require needed |
|------|----------------------------|
| `src/phpbb/common/constants.php` | Constants definitions |
| `src/phpbb/common/functions.php` | Global function library |
| `src/phpbb/common/functions_content.php` | Global function library |
| `src/phpbb/common/functions_compatibility.php` | Global function library |
| `src/phpbb/common/compatibility_globals.php` | Variable setup |
| `src/phpbb/common/hooks/index.php` | Hook registration |
| `src/phpbb/common/hooks/*.php` | Dynamic per-hook includes |
| `src/phpbb/common/utf/utf_tools.php` | UTF utility functions |
| `vendor/autoload.php` | Composer bootstrap |

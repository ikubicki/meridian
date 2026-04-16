# Research Brief

## Research Question

How can `$phpbb_root_path` be eliminated from all filesystem `require`/`include` operations throughout the phpBB codebase, replacing class loading with Composer PSR-4 autoload and remaining procedural includes with `__DIR__`-based absolute paths?

## Background / Immediate Bug

`$phpbb_root_path = './'` is set in `web/*.php` for URL-generation purposes. `common.php` receives this value and uses it for `require($phpbb_root_path . 'src/phpbb/common/startup.php')`. Since CWD in the container is `/var/www/phpbb/web/`, this resolves to `/var/www/phpbb/web/src/phpbb/common/startup.php` which does not exist — causing a fatal error.

`PHPBB_FILESYSTEM_ROOT = __DIR__ . '/../'` = `/var/www/phpbb/` is already defined in `web/*.php`, but `common.php` does not use it.

## Research Type
**Technical** — codebase analysis, systematic fix strategy

## Scope

### Included
- All `require`/`include` that use `$phpbb_root_path` as a filesystem prefix across:
  - `src/phpbb/common/common.php`
  - `src/phpbb/common/startup.php`
  - `bin/phpbbcli.php`
  - `src/phpbb/forums/**/*.php` (services that receive `$phpbb_root_path` via DI)
  - `src/phpbb/install/**/*.php`
- How `$phpbb_root_path` is passed through DI container and services
- Which of those requires can be eliminated by Composer PSR-4 (already done for classes)
- Which must remain but can use `__DIR__` or `PHPBB_FILESYSTEM_ROOT` instead
- The `$phpbb_class_loader_ext` for ext/ path

### Excluded
- URL generation uses of `$phpbb_root_path` (must keep as `'./'`)
- `$web_root_path`, `$phpbb_adm_relative_path` (URL paths, separate concern)
- Template/Twig files

### Constraints
- PHP 8.2 in Docker container (CWD = `/var/www/phpbb/web/`)
- `$phpbb_root_path` as URL-relative path must remain `'./'` for session/redirect logic
- Composer autoload already active (`phpbb\` → `src/phpbb/forums/`)
- `PHPBB_FILESYSTEM_ROOT` constant already defined in all `web/*.php` entry points

## Success Criteria
1. Complete inventory: every `$phpbb_root_path` use in a `require`/`include` context
2. Classification: each use → [AUTOLOAD-REMOVED] | [USE-PHPBB_FILESYSTEM_ROOT] | [USE-__DIR__] | [DI-PATH-NEEDS-FIX]
3. How `$phpbb_root_path` flows into DI services (container_builder, class_loader_ext, etc.)
4. Concrete changes per file needed to fix the CWD problem
5. Can `$phpbb_root_path` be fully removed from `common.php` and `startup.php`?

## Date
2026-04-15

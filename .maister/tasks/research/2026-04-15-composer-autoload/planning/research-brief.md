# Research Brief

## Research Question

How can all `require`/`include` statements in the phpBB codebase be replaced with Composer PSR-4 autoloading?

## Research Type

**Technical** — codebase analysis, implementation strategy

## Scope

### Included
- Manual `require`/`require_once`/`include`/`include_once` statements in:
  - `web/*.php` entry point files
  - `src/phpbb/**/*.php` classes and helpers
  - `includes/*.php` legacy files
  - `common.php`, `startup.php`, `class_loader.php`
- Current `composer.json` autoload configuration
- Bootstrap and startup sequence analysis
- `PHPBB_FILESYSTEM_ROOT` / `$phpbb_root_path` usage patterns
- Existing `\phpbb\class_loader` custom autoloader

### Excluded
- Twig template files (`.html`, `.twig`)
- JavaScript/CSS/static assets
- Vendor directory (already managed by Composer)

### Constraints
- Must maintain phpBB compatibility and runtime behavior
- Must work inside Docker container (`/var/www/phpbb`)
- `$phpbb_root_path` is used for both URL generation and filesystem ops — changes must not break URL logic
- PHP 8.2 (php-fpm container)

## Success Criteria

1. Complete inventory of all manual require/include statements
2. Understanding of which requires can be replaced by autoload vs. which must remain (e.g., non-class files, configuration, startup bootstrap)
3. Current state of `composer.json` autoload config
4. Specific migration steps to enable Composer autoloading
5. Risk assessment: what could break and why
6. Recommended approach with concrete file changes

## Research Date

2026-04-15

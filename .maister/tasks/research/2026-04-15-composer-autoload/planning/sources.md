# Research Sources

## Codebase Sources

### Entry Point Files
- `web/*.php` — 17 entry point files (index, posting, search, viewtopic, viewforum, etc.)
- `web/adm/index.php` — Admin panel entry point
- `web/download/file.php` — File download entry point

Key files to read in full:
- `web/index.php` — standard forum entry
- `web/app.php` — Symfony kernel entry (likely different pattern)
- `web/posting.php` — complex entry with many includes
- `web/adm/index.php` — admin panel

### Bootstrap / Startup Chain
- `src/phpbb/common/common.php` — primary bootstrap hub (confirmed: 12+ require/include)
- `src/phpbb/common/startup.php` — autoload bootstrap, env detection
- `src/phpbb/forums/class_loader.php` — custom `\phpbb\class_loader` implementation
- `src/phpbb/common/functions.php` — global function definitions (cannot autoload)
- `src/phpbb/common/functions_content.php` — content function definitions
- `src/phpbb/common/functions_compatibility.php` — compatibility shims
- `src/phpbb/common/constants.php` — global constants
- `src/phpbb/common/utf/utf_tools.php` — UTF-8 utilities
- `src/phpbb/common/compatibility_globals.php` — procedural globals compatibility

### Hook System
- `src/phpbb/common/hooks/index.php` — hook loader
- `src/phpbb/common/hooks/*.php` — individual hook files (dynamically included)

### Source Classes (`src/phpbb/`)
- `src/phpbb/**/*.php` — 1086 files total
- Key directories:
  - `src/phpbb/forums/` — forum classes
  - `src/phpbb/admin/` — admin classes
  - `src/phpbb/ext/` — extension system
  - `src/phpbb/install/` — installer
  - `src/phpbb/styles/` — style/template system
  - `src/phpbb/language/` — language handling

### Namespace Pattern Search
- Grep target: `^namespace phpbb\\` across `src/phpbb/**/*.php`
- Grep target: `require|include` across `src/phpbb/**/*.php`

---

## Configuration Sources

### Composer Files
- `composer.json` — **Primary**: confirm absence of `autoload` section, review `require` section
- `vendor/composer/autoload_psr4.php` — currently registered PSR-4 prefixes (vendor only)
- `vendor/composer/autoload_classmap.php` — classmap autoloading
- `vendor/composer/autoload_files.php` — files autoloading entries
- `vendor/autoload.php` — Composer autoloader bootstrap

### PHP Environment
- `docker/php/php.ini` — PHP configuration (include_path, open_basedir, etc.)
- `docker/nginx/default.conf` — document root and path configuration
- `docker-compose.yml` — container environment variables (PHPBB_AUTOLOAD, paths)
- `config.php` — phpBB configuration file (may define `$phpbb_root_path`)

### Install Configuration
- `src/phpbb/install/startup.php` — installer-specific bootstrap

---

## File Pattern Searches

### Grep Patterns
```
# All require/include in entry points
grep -rn "require\|include" web/ --include="*.php"

# All require/include in src
grep -rn "require\|include" src/phpbb/ --include="*.php"

# Files with $phpbb_root_path in require context
grep -rn "require.*phpbb_root_path\|include.*phpbb_root_path" web/ src/phpbb/

# Namespace declarations
grep -rn "^namespace phpbb" src/phpbb/ --include="*.php"

# Dynamic include patterns (variable paths)
grep -rn "require\s*(\$\|include\s*(\$" src/phpbb/ web/ --include="*.php"
```

### Glob Patterns
- `web/*.php` — all web entry points
- `src/phpbb/common/*.php` — bootstrap files
- `src/phpbb/forums/class_loader.php` — custom autoloader
- `src/phpbb/**/*.php` — all project classes
- `vendor/composer/autoload_*.php` — Composer generated autoload maps

---

## Documentation Sources

### Project Documentation
- `docs/CHANGELOG.html` — history of structural changes
- `docs/coding-guidelines.html` — phpBB coding standards (may reference require patterns)
- `README.md` — project overview and Docker setup

### Existing maister docs
- `.maister/docs/` — check for any existing standards or architecture docs

### Code Comments / Inline Docs
- PHPDoc blocks in `src/phpbb/forums/class_loader.php`
- Comments in `src/phpbb/common/startup.php` (explains PHPBB_AUTOLOAD logic)
- Comments in `src/phpbb/common/common.php` (explains bootstrap order)

---

## External Sources (Reference Only)

| Resource | URL | Purpose |
|----------|-----|---------|
| Composer PSR-4 autoload docs | https://getcomposer.org/doc/04-schema.md#psr-4 | PSR-4 mapping syntax |
| Composer files autoload | https://getcomposer.org/doc/04-schema.md#files | For function file autoloading |
| PSR-4 specification | https://www.php-fig.org/psr/psr-4/ | Namespace-to-path mapping rules |
| phpBB dev docs | https://area51.phpbb.com/docs/dev/ | phpBB architecture reference |

---

## Source Priority

| Priority | Source | Reason |
|----------|--------|--------|
| P1 | `src/phpbb/common/common.php` | Central bootstrap — all requires chain from here |
| P1 | `composer.json` | Confirms no autoload section — starting point for change |
| P1 | `src/phpbb/forums/class_loader.php` | Custom autoloader to be replaced |
| P1 | `src/phpbb/common/startup.php` | Already loads vendor autoload — integration point |
| P2 | `web/*.php` | Entry points — first files to simplify |
| P2 | `src/phpbb/**/*.php` namespace scan | Determines PSR-4 eligibility of all classes |
| P3 | `vendor/composer/autoload_psr4.php` | Baseline for what's already registered |
| P3 | Docker / `config.php` | Path resolution context |

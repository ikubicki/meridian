# Research Plan: Replacing require/include with Composer PSR-4 Autoloading

## Research Overview

**Research Question**: How can all `require`/`include` statements in the phpBB codebase be replaced with Composer PSR-4 autoloading?

**Research Type**: Technical — codebase analysis, implementation strategy

**Scope**:
- `web/*.php` and `web/adm/`, `web/download/` entry points (17+ files)
- `src/phpbb/**/*.php` (1086 files)
- Bootstrap chain: `common.php` → `startup.php` → `class_loader.php`
- `composer.json` (currently has NO autoload section)
- `\phpbb\class_loader` custom autoloader in `src/phpbb/forums/class_loader.php`

**Key Discovery**: `composer.json` has **no `autoload` section** — PSR-4 for project classes is entirely absent.

---

## Sub-Questions

1. What `require`/`include` statements exist in `web/*.php` entry points, and which load class files vs. procedural files?
2. What does the `common.php` bootstrap chain load, in what order, and why?
3. Which files are loadable via PSR-4 (class-per-file, correct namespace), and which must stay as explicit requires (functions, constants, helpers, language files)?
4. How does `\phpbb\class_loader` work, and is it redundant once Composer PSR-4 is configured?
5. How is `$phpbb_root_path` / `PHPBB_FILESYSTEM_ROOT` used — is it safe to remove from require paths once autoloading takes over?
6. What `composer.json` autoload configuration is needed to register `src/phpbb/` under the `phpbb\` namespace?
7. What is the risk of each change, and what is the correct migration order?

---

## Methodology

**Primary**: Static codebase analysis (grep, file reading, pattern extraction)

**Approach**:
1. **Inventory** all `require`/`include` occurrences across in-scope directories
2. **Classify** each occurrence: OOP class file vs. functions/constants/config/language file
3. **Map** the bootstrap sequence to understand load ordering constraints
4. **Analyse** `composer.json` current state and `class_loader.php` implementation
5. **Design** the PSR-4 autoload configuration and migration steps
6. **Assess** risks: hook loading, dynamic includes, conditional includes, URL vs. filesystem path conflicts

**Fallback**: If a file is ambiguous (e.g. mixed class + functions), read it directly to classify manually.

---

## Analysis Framework

### Classification of require/include statements

| Category | Can autoload? | Action |
|----------|--------------|--------|
| OOP class file (one class, matching namespace) | ✅ Yes | Remove require, add PSR-4 mapping |
| Functions file (`functions*.php`) | ❌ No | Keep as explicit require, move to Composer `files` autoload |
| Constants file (`constants.php`) | ❌ No | Keep as explicit require or Composer `files` autoload |
| Language files (`lang/*/common.php`) | ❌ No | Keep — runtime dynamic load |
| Hook files (dynamic `include`) | ❌ No | Keep — runtime dynamic load |
| Compatibility shims | ❌ No | Keep — must run before class usage |

### Bootstrap Sequencing
Identify the strict ordering required:
1. PHP environment setup (error reporting, encoding)
2. Vendor autoload registration (`vendor/autoload.php`)
3. PSR-4 registration for `src/phpbb/` (once added to composer.json)
4. Constants definition
5. Function file loading (cannot be deferred)
6. Class instantiation (now safe via autoloading)

### `$phpbb_root_path` pattern analysis
Distinguish two roles:
- **Filesystem**: `$phpbb_root_path . 'src/phpbb/...'` → eliminate for class files once autoloaded
- **URL generation**: `$phpbb_root_path` passed to template/routing → must NOT be touched

---

## Research Phases

### Phase 1: Broad Discovery
- Glob all PHP files in `web/`, `src/phpbb/common/`, `src/phpbb/forums/`
- Count total `require`/`include` occurrences per directory
- Identify distinct file patterns loaded (e.g. `functions*.php`, `*.class.php`, `class_loader.php`)
- Read `composer.json` in full — confirm absence of autoload section

### Phase 2: Targeted Reading
- Read `src/phpbb/common/common.php` (bootstrap hub)
- Read `src/phpbb/common/startup.php` (autoload bootstrap)
- Read `src/phpbb/forums/class_loader.php` (custom autoloader implementation)
- Read 3–5 representative `web/*.php` entry points for require patterns
- Read `web/app.php` (Symfony kernel entry — likely different pattern)

### Phase 3: Deep Dive
- Extract all `require`/`include` lines from `web/` (all files) with filenames resolved
- Extract all `require`/`include` lines from `src/phpbb/common/` and `src/phpbb/install/`
- Trace which files are OOP class files (check for `class`/`interface`/`trait` keywords)
- Identify dynamic includes (variable paths, hook patterns)
- Check `src/phpbb/forums/class_loader.php` for namespace prefix and path registration

### Phase 4: Verification
- Cross-reference namespace declarations (`namespace phpbb\...`) in `src/phpbb/**/*.php` to validate PSR-4 eligibility
- Verify that `vendor/autoload.php` is already loaded before any `phpbb\` class usage
- Confirm whether `PHPBB_AUTOLOAD` env var path in `startup.php` already points to a PSR-4 autoloader

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|-------------|------------|-------|---------------|
| 1 | `entry-points` | All `require`/`include` statements in `web/*.php`, `web/adm/`, `web/download/` — classify each as class vs. procedural | Grep, Read | `entry-points` |
| 2 | `bootstrap` | Full bootstrap chain: `common.php`, `startup.php`, `class_loader.php`, `compatibility_globals.php` — load order, what is loaded, why | Read, Grep | `bootstrap` |
| 3 | `src-classes` | `src/phpbb/**/*.php` — namespace declarations, require/include usage, OOP vs. procedural files | Grep, Glob | `src-classes` |
| 4 | `composer-config` | `composer.json` autoload section (current state), `vendor/composer/autoload_psr4.php`, `vendor/autoload.php` bootstrap | Read, Grep | `composer-config` |
| 5 | `dynamic-includes` | Dynamic/conditional includes: hooks (`hooks/*.php`), language files, extension loading patterns — must-keep list | Grep, Read | `dynamic-includes` |

### Rationale
Five categories are chosen because the codebase has five structurally distinct include patterns that require different migration strategies. Entry points and bootstrap are separated because they have different roles (user-facing vs. framework bootstrap). `src-classes` is the largest volume (1086 files) and requires namespace validation. Composer config is isolated to focus on the current gap. Dynamic includes need special treatment as they cannot be autoloaded.

---

## Success Criteria

- [ ] Complete inventory of all `require`/`include` statements in scope (count + file list)
- [ ] Classification of each file loaded: OOP class (PSR-4 eligible) vs. procedural (must keep)
- [ ] Current `composer.json` autoload state documented (confirmed: no autoload section)
- [ ] Proposed `composer.json` autoload configuration (PSR-4 + files entries)
- [ ] Bootstrap sequence documented with load-order constraints
- [ ] List of requires that MUST remain explicit (non-class files)
- [ ] Risk assessment for each change category
- [ ] Concrete migration steps with file changes

---

## Expected Outputs

| Output | Location | Description |
|--------|----------|-------------|
| Research report | `outputs/research-report.md` | Full findings, analysis, and recommendations |
| Require inventory | `outputs/require-inventory.md` | Complete list of all require/include statements classified |
| Migration plan | `outputs/migration-plan.md` | Step-by-step migration with concrete file changes |
| Risk register | `outputs/risk-register.md` | Risks, severity, and mitigations |

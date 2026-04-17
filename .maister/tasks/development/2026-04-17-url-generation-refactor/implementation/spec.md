# Specification: URL Generation Refactoring — All URLs Absolute

## Goal

Replace all relative/filesystem-prefixed URL generation (e.g. `./viewtopic.php`, `./adm/`) with clean absolute web paths (e.g. `/viewtopic.php`, `/adm/`) throughout phpBB, while keeping all filesystem `require`/`include` operations intact.

## User Stories

- As a developer, I want all internal redirect URLs to be absolute paths so that HTTP `Location:` headers are unambiguous and correct from any entry point.
- As a user, I want all navigation links in forum pages to resolve correctly regardless of the browser's notion of the current directory.
- As an admin, I want admin panel URLs (`/adm/`) to work from any page, not only from within `web/adm/`.

## Core Requirements

1. Introduce a clearly-named web-root prefix (`$phpbb_web_root_path = '/'`) separate from the filesystem-only `$phpbb_root_path`.
2. `$phpbb_admin_path` must equal `/adm/` in ALL entry points, not just `web/adm/index.php`.
3. `path_helper::get_web_root_path()` must return `'/'` for ordinary (non-app.php-route) requests.
4. `path_helper::update_web_root_path()` must convert paths prefixed with `$phpbb_root_path` (`./`) into paths prefixed with `'/'`.
5. All `append_sid("{$phpbb_root_path}page.php")` call sites continue to work without source changes — the fix flows through `get_web_root_path()`.
6. Filesystem `require`/`include` in `functions_compatibility.php` must use `__DIR__`-based paths so they are independent of CWD.
7. AJAX referer-based path computation (`get_web_root_path_from_ajax_referer()`) is preserved unchanged.

## Reusable Components

### Existing Code to Leverage

| Component | File | How Used |
|-----------|------|----------|
| `PHPBB_FILESYSTEM_ROOT` constant | [web/viewforum.php](../../../../web/viewforum.php), all `web/*.php` | Already defined as `__DIR__ . '/../'`; filesystem requires in `common.php` already use it |
| `PHPBB_ADMIN_PATH` constant | [web/adm/index.php](../../../../web/adm/index.php) | Already defines `/adm/`; `common.php` respects it via `defined('PHPBB_ADMIN_PATH')` guard |
| `$phpbb_adm_relative_path = 'adm/'` | [config.php](../../../../config.php) | Already set to `'adm/'` (without leading `./`); used as the source for admin path computation |
| `path_helper::get_web_root_path()` | [src/phpbb/forums/path_helper.php](../../../../src/phpbb/forums/path_helper.php) | Central path computation; single-point fix propagates to all `append_sid()` calls |
| `path_helper::update_web_root_path()` | [src/phpbb/forums/path_helper.php](../../../../src/phpbb/forums/path_helper.php) | Already called by `append_sid()` internally; no call-site changes required |
| `path_helper::get_web_root_path_from_ajax_referer()` | [src/phpbb/forums/path_helper.php](../../../../src/phpbb/forums/path_helper.php) | Handles AJAX separately; no changes required |

### New Components Required

None. All changes are confined to existing files. No new classes, services, or constants are introduced beyond `$phpbb_web_root_path` as a local variable in `common.php` (documented for clarity, not necessarily injected into the DI container).

## Technical Approach

### Root Cause Summary

`$phpbb_root_path = './'` is set in every `web/*.php` entry point (except `web/adm/index.php`). It serves **two conflicting roles**:

1. **URL prefix** — used as `append_sid("{$phpbb_root_path}viewtopic.php")` → produces `./viewtopic.php` (relative, browser-resolved).
2. **Filesystem prefix** — used in legacy `require`/`include` statements (now superseded by `PHPBB_FILESYSTEM_ROOT` and `__DIR__`-based paths in `common.php`).

The fix separates these roles so URL generation produces `/viewtopic.php` while filesystem paths remain absolute.

### Fix Strategy — Four Targeted Changes

#### Change 1 — `path_helper::get_web_root_path()` (REQ-3, REQ-4)

**File**: [src/phpbb/forums/path_helper.php](../../../../src/phpbb/forums/path_helper.php)

The method has a branch for the common case where `$path_info === '/'` (a plain `.php` entry point without Symfony routing). Currently returns `$this->phpbb_root_path` (`./`). Change it to return `'/'`.

**Before** (line ~220):
```php
if ($path_info === '/')
{
    return $this->web_root_path = $this->phpbb_root_path;
}
```

**After**:
```php
if ($path_info === '/')
{
    return $this->web_root_path = '/';
}
```

**Cascade effect**: `update_web_root_path('./viewtopic.php')` is called by `append_sid()`. With `web_root_path = '/'`:
- Input `./viewtopic.php` does **not** start with `/` → skip first strpos guard.
- Input starts with `./` (`$this->phpbb_root_path`) → strip `./`, prepend `/` → `/viewtopic.php` ✓

The AJAX branch is entered earlier in `get_web_root_path()` and returns from `get_web_root_path_from_ajax_referer()` before reaching the `$path_info === '/'` branch — it is unaffected.

The `ADMIN_START` + `$path_info === '/'` admin-detection branch must be preserved for the `in_adm_path` flag — only the final `return` line changes.

#### Change 2 — `$phpbb_admin_path` computation in `common.php` (REQ-2)

**File**: [src/phpbb/common/common.php](../../../../src/phpbb/common/common.php)

**Before** (line ~37):
```php
$phpbb_admin_path = (defined('PHPBB_ADMIN_PATH')) ? PHPBB_ADMIN_PATH : $phpbb_root_path . $phpbb_adm_relative_path;
```

**After**:
```php
$phpbb_admin_path = (defined('PHPBB_ADMIN_PATH')) ? PHPBB_ADMIN_PATH : '/' . ltrim($phpbb_adm_relative_path, '/');
```

This produces `/adm/` in all non-adm entry points (where `PHPBB_ADMIN_PATH` is not defined) when `$phpbb_adm_relative_path = 'adm/'`. Entry points inside `web/adm/` continue to use `PHPBB_ADMIN_PATH = '/adm/'` directly.

#### Change 3 — `$phpbb_web_root_path` alias in `common.php` (REQ-1, REQ-5)

**File**: [src/phpbb/common/common.php](../../../../src/phpbb/common/common.php)

Introduce a local variable immediately after `$phpbb_root_path` is available, documenting the intent. This is a documentation aid — it does not replace downstream `$phpbb_root_path` usage because those go through `update_web_root_path()` which now resolves them correctly.

**After** (add after the `$phpbb_admin_path` line):
```php
// Absolute web prefix for URL generation. $phpbb_root_path remains './' for
// any legacy code that has not yet been updated.
$phpbb_web_root_path = '/';
```

This variable is available globally via `global $phpbb_web_root_path` in functions that need an explicit absolute URL without going through `append_sid()`, ensuring a clean migration path if any direct-string URLs are discovered.

#### Change 4 — `functions_compatibility.php` filesystem includes (REQ-6)

**File**: [src/phpbb/common/functions_compatibility.php](../../../../src/phpbb/common/functions_compatibility.php)

Two `$phpbb_root_path`-based filesystem operations remain:

| Line | Before | After |
|------|--------|-------|
| ~113 | `require($phpbb_root_path . 'src/phpbb/forums/path_helper.php');` | `require(__DIR__ . '/../forums/path_helper.php');` |
| ~194 | `include($phpbb_root_path . 'src/phpbb/common/functions_display.php');` | `include(__DIR__ . '/functions_display.php');` |

These are reached only when the DI container is not yet bootstrapped (fallback code paths). Using `__DIR__` makes them CWD-independent.

### Data Flow After the Fix

```
Request: GET /viewtopic.php?t=1
  │
  ├─ web/viewtopic.php
  │    $phpbb_root_path = './'
  │    PHPBB_FILESYSTEM_ROOT = '/var/www/phpbb/'   ← filesystem
  │
  ├─ common.php
  │    $phpbb_admin_path = '/adm/'                 ← REQ-2 fix
  │    $phpbb_web_root_path = '/'                  ← REQ-1 (alias)
  │
  ├─ append_sid("{$phpbb_root_path}viewtopic.php")
  │    = append_sid("./viewtopic.php")
  │    → update_web_root_path("./viewtopic.php")
  │       web_root_path = get_web_root_path() = '/'   ← REQ-4 fix
  │       './viewtopic.php' starts with './' → strip, prepend '/'
  │    = '/viewtopic.php?t=1'                      ← correct ✓
  │
  └─ $phpbb_admin_path used in template
       = '/adm/'                                   ← correct ✓
```

### AJAX Correctness (REQ-7)

AJAX requests enter the `is_ajax()` branch inside `get_web_root_path()` **before** the `$path_info === '/'` return. `get_web_root_path_from_ajax_referer()` computes a referer-relative path using `$this->phpbb_root_path` — this logic is unchanged. The returned relative path (e.g. `./`) is used only within that AJAX response's template, which is merged with the referer page's DOM. No change is made to this branch.

## Implementation Guidance

### Files Changed

| File | Lines Affected | Nature |
|------|---------------|--------|
| [src/phpbb/forums/path_helper.php](../../../../src/phpbb/forums/path_helper.php) | ~1 (single return) | Core fix — cascades everywhere |
| [src/phpbb/common/common.php](../../../../src/phpbb/common/common.php) | ~2 (admin_path + web_root_path) | Admin URL fix + alias |
| [src/phpbb/common/functions_compatibility.php](../../../../src/phpbb/common/functions_compatibility.php) | 2 requires | Filesystem safety |

### Testing Approach

3-5 focused tests per implementation step:

**Step 1 — path_helper::get_web_root_path() returns '/'**
- Test: plain request (path_info='/') → `get_web_root_path()` returns `'/'`
- Test: `update_web_root_path('./viewtopic.php')` → `'/viewtopic.php'`
- Test: `update_web_root_path('./adm/index.php')` → `'/adm/index.php'`
- Test: AJAX request still returns relative referer-based path (no regression)

**Step 2 — $phpbb_admin_path**
- Test: `common.php` bootstrapped without `PHPBB_ADMIN_PATH` constant → `$phpbb_admin_path === '/adm/'`
- Test: `common.php` bootstrapped with `PHPBB_ADMIN_PATH = '/adm/'` → still `/adm/`
- Test: `$phpbb_adm_relative_path = 'adm/'` → prefix computation `'/' . ltrim('adm/', '/') === '/adm/'`

**Step 3 — functions_compatibility.php**
- Test: `__DIR__` paths resolve to existing files (`path_helper.php`, `functions_display.php`)
- Test: fallback path_helper instantiation works when container not loaded

**Integration**
- Smoke test: HTTP request to `/index.php` → all links in response body start with `/` not `./`
- Smoke test: HTTP request to `/adm/index.php` → admin links reference `/adm/` not `./adm/`

Never run the full test suite per step; run only the directly related new/modified tests.

### Standards Compliance

- Legacy procedural files (`common.php`, `functions_compatibility.php`) use tabs for indentation per phpBB convention — maintain this in any edited lines.
- `path_helper.php` is OOP under `namespace phpbb` — no strict_types change required (existing file, not new).
- No user-visible strings are added; no language file changes needed.
- No SQL queries are touched; CSRF / XSS considerations do not apply to this refactor.
- All `append_sid()` calls continue to encode query strings (XSS-safe) — the refactor only changes the path prefix.

See [.maister/docs/standards/backend/STANDARDS.md](../../../../.maister/docs/standards/backend/STANDARDS.md) for namespacing and type declaration rules (not applicable to the touched legacy files, but applies to any new OOP code if added).

## Out of Scope

- Changing call sites: no `append_sid("{$phpbb_root_path}page.php")` lines are modified — the fix is in `get_web_root_path()`.
- DI container YAML services (`services.yml`, `%core.root_path%` parameters) — filesystem paths in container are already handled by the `$phpbb_filesystem_root` / `container_builder` Layer 2 fix (tracked separately).
- `bin/phpbbcli.php` — CLI already sets `$phpbb_root_path = __DIR__ . '/../'` (absolute), so it does not suffer from the URL/filesystem conflation bug.
- `src/phpbb/common/functions_transfer.php`, `functions_compress.php`, `functions_convert.php` — these use `$phpbb_root_path` for user-controlled upload paths (`$config['upload_path']`), which is a separate, non-URL concern tracked in the codebase analysis.
- `message_parser.php`, `functions_messenger.php` — lazy-loaded, already deferred to Layer 3.
- Extension compatibility: extensions that generate their own URLs via their own `$phpbb_root_path` usage are out of scope for this task.

## Success Criteria

1. `path_helper::get_web_root_path()` returns `'/'` for any regular (non-AJAX, non-app.php-route) request.
2. Every internal link on the forum index page (`/index.php`) begins with `/` when inspected via browser devtools or curl.
3. `$phpbb_admin_path` equals `'/adm/'` in all web entry points (confirmed via `var_dump` or unit test).
4. No "file not found" PHP fatal errors from `functions_compatibility.php` includes when CWD is any arbitrary directory.
5. AJAX-powered actions (e.g. quick-reply, mark-read) produce correct relative URLs pointing to the referer context.
6. Zero changes to call sites — search for `append_sid("{$phpbb_root_path}` returns same result before and after.

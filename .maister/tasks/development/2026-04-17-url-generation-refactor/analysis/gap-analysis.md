# Gap Analysis: URL-Generating Functions Refactor

## Summary
- **Risk Level**: Medium
- **Estimated Effort**: Medium
- **Detected Characteristics**: modifies_existing_code, ui_heavy

## Task Characteristics
- Has reproducible defect: no (behaviour works in browsers, but URLs are not clean absolute)
- Modifies existing code: yes
- Creates new entities: no
- Involves data operations: no
- UI heavy: yes (all generated hrefs in templates / ACP module tabs)

---

## Gaps Identified

### Gap 1 — CRITICAL: `update_web_root_path()` never converts `'./adm/'` to `'/adm/'`

**Evidence** (`path_helper.php` lines 103–132):

```
get_web_root_path() → returns $this->phpbb_root_path when path_info === '/'
                     = './'  (for non-adm pages)
                     = '../' (for adm pages)

update_web_root_path('./adm/index.php') with web_root_path='./':
  Step 1: strpos('./adm/index.php', './') === 0 → YES
          $path = './' . 'adm/index.php' = './adm/index.php'
  Step 2: strpos('./adm/index.php', './') === 0 → YES
          $path = 'adm/index.php'
          $path = clean_path('./' . 'adm/index.php') = './adm/index.php'
  RETURNS: './adm/index.php'   ← NEVER '/adm/index.php'
```

The task says "update_web_root_path() may not correctly convert './adm/' to '/adm/'" — confirmed: it **cannot** produce absolute paths when `$phpbb_root_path = './'`.

### Gap 2 — CRITICAL: `PHPBB_ADMIN_PATH` only defined in `web/adm/index.php`

**Files that define it**: `web/adm/index.php` only (line 19: `define('PHPBB_ADMIN_PATH', '/adm/');`)

**Files missing it** (produce `$phpbb_admin_path = './adm/'`):
- `web/ucp.php` (line 18: `$phpbb_root_path = './'`)
- `web/index.php`
- `web/memberlist.php`   ← **actually uses `$phpbb_admin_path` at line 796**
- `web/viewtopic.php`, `web/viewforum.php`, `web/posting.php`, `web/faq.php`, etc.

**Confirmed active usage in non-adm context**:
```php
// web/memberlist.php:796
'U_USER_ADMIN' => append_sid("{$phpbb_admin_path}index.php", 'i=users&mode=overview&u=...')
// produces './adm/index.php?...' instead of '/adm/index.php?...'
```

### Gap 3 — BLOCKING (for option B): `$phpbb_root_path` still used for filesystem in common.php

**common.php line 59**:
```php
$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
```
If `$phpbb_root_path` is changed to `'/'`, this becomes `'/ext/'` — wrong filesystem path.
Must change to `"{$phpbb_filesystem_root}ext/"` before `$phpbb_root_path` can become `'/'`.

**functions_compatibility.php lines 113, 201**:
```php
require($phpbb_root_path . 'src/phpbb/forums/path_helper.php');   // line 113
include($phpbb_root_path . 'src/phpbb/common/functions_display.php'); // line 201
```
These are legacy fallback paths. Both need `__DIR__`-based paths before `$phpbb_root_path` can be `'/'`.

### Gap 4 — VERIFIED OK: ACP files are already covered

All 54 `append_sid()` calls across 13 ACP files (`src/phpbb/common/acp/*.php`) use `$phpbb_admin_path`.
ACP files are **only ever loaded from `web/adm/index.php`**, which defines `PHPBB_ADMIN_PATH='/adm/'`.
Therefore `$phpbb_admin_path = '/adm/'` and generated URLs are `/adm/index.php?sid=...`. **No fix needed.**

### Gap 5 — INFO: Forum-path relative URLs in functions.php

`functions.php` uses `"{$phpbb_root_path}ucp.php"`, `"{$phpbb_root_path}viewforum.php"`, etc.  
With `$phpbb_root_path = './'`, these produce `'./ucp.php'`, `'./viewforum.php'` — relative URLs.  
These **work correctly in browsers** (all web/*.php are at the same `/` web depth), but are not clean absolute paths per the desired state.

Scope decision required: fix only admin paths (Gap 2) or fix all forum paths too (Gap 5)?

---

## Impact Assessment

### Approach A — Minimal (PHPBB_ADMIN_PATH everywhere)

Define `PHPBB_ADMIN_PATH='/adm/'` in all non-adm `web/*.php` entry points.

| Dimension | Assessment |
|-----------|------------|
| Files touched | 7–8 web entry points + common.php (already sets fallback) |
| Risk | Very Low — only adds a constant, no logic change |
| Forum paths | Still relative (`./viewtopic.php`) |
| Admin paths | Absolute (`/adm/index.php`) from all entry points ✓ |
| AJAX | No impact — PHPBB_ADMIN_PATH is a constant |

### Approach B — Full (change `$phpbb_root_path = '/'`)

Change `$phpbb_root_path = '/'` in non-adm web/*.php; fix 3 remaining filesystem uses.

| Dimension | Assessment |
|-----------|------------|
| Files touched | 7–8 web entry points + common.php + functions_compatibility.php |
| Risk | Medium — changes path_helper math for AJAX + route paths |
| Forum paths | Absolute (`/viewtopic.php`) ✓ |
| Admin paths | Absolute (`/adm/index.php`) ✓ |
| `get_web_root_path_from_ajax_referer()` | Returns `$this->phpbb_root_path . '../'*N` — with `phpbb_root_path='/'` this would produce `'/../../'` style paths for AJAX referer computation — **regression risk** |
| `clean_path('./' + '../' * N + '/')` formula | Produces absolute paths for $corrections>0 routes — needs regression test |

---

## Issues Requiring Decisions

### Critical (Must Decide Before Proceeding)

1. **Scope of absolute URL requirement**
   - **Issue**: The desired state says ALL URLs must be absolute (`/adm/index.php`, `/index.php`). Achieving this for forum paths requires changing `$phpbb_root_path = '/'`, which has broader side-effects in `path_helper`'s AJAX and route path computations.
   - Options:
     - `[A]` Admin paths only — define `PHPBB_ADMIN_PATH='/adm/'` in all web entry points. Forum paths stay `./viewtopic.php` (safe, browsers handle it, but not technically "clean absolute")
     - `[B]` All paths — change `$phpbb_root_path = '/'` + fix 3 filesystem usages + verify AJAX path computation
   - Recommendation: **Option A** for this iteration; Option B as a follow-up with dedicated regression tests
   - Rationale: Option A eliminates the stated admin-path bug with near-zero risk. Option B is a larger refactor touching core path logic.

2. **`functions_compatibility.php` filesystem lines 113 and 201**
   - **Issue**: Lines 113 and 201 still use `$phpbb_root_path` for require/include (filesystem). These are unreachable in the normal production boot path (common.php already directly requires these files), but they're dead-code risk if path changes.
   - Options:
     - `[A]` Fix now with `__DIR__`-based paths (safe, consistent with prior Layer 1 work)
     - `[B]` Leave as dead code (they won't execute in normal boot; low priority)
   - Recommendation: **Option A** — consistent cleanup
   - Rationale: Already in scope of the broader `$phpbb_root_path`-elimination effort

### Important (Should Decide)

1. **`common.php` line 59 — class_loader_ext filesystem path**
   - **Issue**: `new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/")` is a filesystem operation using `$phpbb_root_path`. Only becomes a blocker if going with Approach B (change `$phpbb_root_path = '/'`).
   - Options:
     - `[A]` Switch to `$phpbb_filesystem_root . 'ext/'` (fixes it, required for Approach B)
     - `[B]` No change (fine for Approach A)
   - Default: Depends on scope decision above

2. **Which non-adm entry points need `PHPBB_ADMIN_PATH` defined**
   - Currently only `web/memberlist.php` actively uses `$phpbb_admin_path` in non-adm context (line 796)
   - **Issue**: Should `PHPBB_ADMIN_PATH` be defined defensively in ALL web/*.php, or only in those that currently use admin links?
   - Options:
     - `[A]` All web/*.php entry points (defensive, future-proof)
     - `[B]` Only `web/memberlist.php` (minimal, but brittle)
   - Recommendation: **Option A** — define in all entry points via common.php so no entry point needs to remember
   - Rationale: Since common.php already has the fallback logic `(defined('PHPBB_ADMIN_PATH')) ? PHPBB_ADMIN_PATH : ...`, the cleanest fix is to define it once there using `$phpbb_adm_relative_path` as the source of truth, making `PHPBB_ADMIN_PATH` always exactly `'/' . $phpbb_adm_relative_path` when `$phpbb_root_path = './'`, and keeping the `web/adm/index.php` override for adm context.

---

## Recommendations

1. **Immediate (Approach A)**: Modify `common.php` line 38 to compute `$phpbb_admin_path` as `'/' . $phpbb_adm_relative_path` when `$phpbb_root_path === './'` and `PHPBB_ADMIN_PATH` is not defined. This covers all non-adm entry points without touching 8 separate files.

2. **Immediate**: Fix `functions_compatibility.php` lines 113 and 201 with `__DIR__`-based paths (consistent with already-applied Layer 1 fixes).

3. **Deferred (Approach B consideration)**: Before changing `$phpbb_root_path = '/'`, write a regression test that exercises `get_web_root_path()` for AJAX requests and non-trivial path_info values.

---

## Risk Assessment

- **Complexity Risk**: Low (Approach A) / Medium (Approach B)
- **Integration Risk**: Low — `PHPBB_ADMIN_PATH` constant already honoured in common.php; only value changes
- **Regression Risk**: Low (Approach A — no logic change) / Medium (Approach B — path_helper AJAX branch affected)

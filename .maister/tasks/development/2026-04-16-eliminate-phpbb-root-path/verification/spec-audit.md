# Specification Audit: Eliminate $phpbb_root_path (Layer 1+2)

**Spec file**: `implementation/spec.md`
**Audit date**: 2026-04-16
**Auditor**: spec-auditor (independent)
**Compliance status**: ❌ **Non-Compliant** — 2 Critical + 2 High issues

---

## Executive Summary

The specification correctly models the core Layer 1 changes (`__DIR__`-based requires in startup.php and common.php) and the `container_builder` Option B constructor refactor. However, it contains two critical omissions that would cause silent corruption in the installer and an incomplete fix in `web/download/file.php`, plus two high-severity issues around line numbers and non-avatar code paths.

**Issue counts by severity:**

| Severity | Count |
|----------|-------|
| Critical | 2 |
| High     | 2 |
| Medium   | 2 |
| Low      | 2 |

---

## Critical Issues

### C1 — `container_factory.php` L147: positional `$php_ext` silently corrupts after Option B signature change

**Spec reference**: Architecture Decision 2 (Option B), section 3b, Risk table row:
> "Other callers of container_builder break due to moved $php_ext | Low | Low | Search confirms no caller passes a non-default $php_ext positionally as 2nd arg"

**Evidence** — actual source:
```
src/phpbb/forums/install/helper/container_factory.php:147
    $phpbb_container_builder = new \phpbb\di\container_builder($this->phpbb_root_path, $this->php_ext);
```
(also L146: `$phpbb_config_php_file = new \phpbb\config_php_file($this->phpbb_root_path, $this->php_ext);`)

**Gap**: The spec's risk table explicitly claims the codebase search found **no** callers that pass `$php_ext` positionally as the 2nd argument. This claim is incorrect — `container_factory.php` does exactly that, and it is used by the web-based installer.

After the Option B signature change:
```php
// New signature:
__construct($phpbb_root_path, $filesystem_root_path = '', $php_ext = 'php')

// container_factory.php L147 becomes:
new \phpbb\di\container_builder($this->phpbb_root_path, $this->php_ext);
// → $filesystem_root_path = 'php'  ← truthy, overrides fallback
// → get_cache_dir()    = 'php' . 'cache/production/' = 'phpcache/production/'
// → get_config_path()  = 'php' . 'src/phpbb/common/config' = 'phpsrc/phpbb/common/config'
```

**Category**: Missing (call site not in scope but invalidates the risk claim)
**Severity**: Critical — Silently breaks the web installer. No exception, no error — paths resolve to nonsense strings. The installer would fail to build its container.
**Recommendation**: Either (a) add `container_factory.php` L147 to scope and pass `''` as `$filesystem_root_path`, or (b) reconsider Option A (append to end) to avoid positional caller breakage. Also update the risk claim accordingly.

---

### C2 — `web/download/file.php` L64: `container_builder` call absent from spec

**Spec reference**: Section 6 (web/download/file.php), sections 6a–6e. Risk table:
> "web/download/file.php remaining requires after line 60 (not reviewed) | Low | Medium"

**Evidence** — actual source:
```
web/download/file.php:64
    $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
```

**Gap**: The spec covers the avatar block up to section 6e (lines ~55-58) but the `container_builder` instantiation at L64 is inside the same `if (isset($_GET['avatar']))` block and is the primary call site that needs updating. Without this change, `file.php?avatar=1` would still pass the broken `$phpbb_root_path = './'` or `'../../'` to `container_builder`, leaving the bug fully intact for this code path.

The Risk table rates this entire omission as "Low/Medium" — which misrepresents its impact. The `container_builder` instantiation is not merely a `require`; it is the core of the fix.

**Category**: Missing
**Severity**: Critical — The spec's stated goal for file.php ("file.php?avatar=1 loads without fatal") cannot be met if L64 is unaddressed.
**Recommendation**: Add section 6f to the spec:
```diff
-    $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
+    $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
```
Update risk table severity from Low/Medium to High for this item.

---

## High Issues

### H1 — `common.php` section 2d: line reference "after line ~115" is incorrect — variable must be introduced before line 25

**Spec reference**: Section 2d header:
> "#### 2d. New `$phpbb_filesystem_root` variable + call sites (after line ~115)"

**Evidence** — actual line numbers confirmed by grep:
```
src/phpbb/common/common.php:25   $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
src/phpbb/common/common.php:102  $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
```

**Gap**: The spec's diff for section 2d shows `$phpbb_filesystem_root` introduced immediately before the `config_php_file` construction. But the header says "after line ~115", which is after the `container_builder` call (L102) and far after the first use of the variable at L25. An implementer who follows the header text and inserts the variable at line ~115+ will produce a PHP undefined variable error (`$phpbb_filesystem_root`) when L25 is evaluated.

The diff itself is logically correct but conflicts with the line reference in the header.

**Category**: Incorrect (spec self-contradicts)
**Severity**: High — Would produce runtime `PHP Warning: Undefined variable $phpbb_filesystem_root` at L25 (config_php_file) if the implementer follows the stated line number.
**Recommendation**: Change section 2d header to:
> "#### 2d. New `$phpbb_filesystem_root` variable (before line 25) + call sites (line 25, line 102)"

Also clarify the diff ordering explicitly: "Insert the variable at line 24 (just before the config_php_file construction), then update both call sites."

---

### H2 — `web/download/file.php` non-avatar block (L~155+) not addressed

**Spec reference**: Section 6 covers lines 19–58 only. Risk table notes "after line 60 not reviewed."

**Evidence** — actual source:
```
web/download/file.php:~155  include($phpbb_root_path . 'src/phpbb/common/common.php');
web/download/file.php:~156  require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');
```

**Gap**: The non-avatar code path (the implicit `else` after the avatar block closes) also uses `$phpbb_root_path` for filesystem `include`/`require`. In particular, `include($phpbb_root_path . 'src/phpbb/common/common.php')` passes control to common.php in a context where `PHPBB_FILESYSTEM_ROOT` is not defined (since file.php doesn't define it). After the spec's changes, common.php will use `realpath($phpbb_root_path) . '/'` as fallback. With `$phpbb_root_path = './'` and a broken CWD, this `realpath()` fallback produces the wrong path — the same root bug.

**Category**: Missing
**Severity**: High — The non-avatar download path is arguably the most-used path in `file.php`. Leaving `$phpbb_root_path` for the `common.php` include means the fix is incomplete for attachment downloading.
**Recommendation**: Either (a) expand section 6 to cover the non-avatar block, or (b) add `define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../../')` near the top of `file.php` (before the avatar conditional) so both paths benefit from the constant. Also note that `$phpbb_filesystem_root` must be declared before the avatar conditional to be available in both branches.

---

## Medium Issues

### M1 — `web/download/file.php` L~61: `$phpbb_class_loader_ext` filesystem path not covered

**Evidence**:
```
web/download/file.php:~61  $phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
```

**Gap**: Section 6c fixes the inner `$phpbb_class_loader` path but does not address `$phpbb_class_loader_ext` three lines later. The `ext/` directory path would still resolve incorrectly in web context.

**Spec mentions** this for common.php only: "Extension loader path is URL-relative — acceptable short-term; Layer 3 concern". However, `common.php`'s class_loader_ext paths go through the DI container (Layer 3), whereas `file.php`'s class_loader_ext is manual and direct.

**Severity**: Medium — May silently not load extension autoloaders; forum extensions may not be accessible during avatar downloads.
**Recommendation**: Add `$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_filesystem_root}ext/");` to section 6 of the spec, or explicitly document the deferral rationale for file.php specifically.

---

### M2 — Risk table underestimates file.php gap (rated Low/Medium, actual: High)

**Evidence**: The spec's Risk table entry:
```
| web/download/file.php remaining requires after line 60 (not reviewed) | Low | Medium | ...
```

**Gap**: The `container_builder` call at L64 is before line 60 (it is inside the avatar block, lines 33–75). More importantly, the non-avatar block's `require common.php` at L~155 is a High-impact omission. The combined risk from C2 and H2 above makes this gap High severity, not Low/Medium.

**Severity**: Medium — Incorrect risk rating may cause implementers to defer or deprioritize this work.
**Recommendation**: Update the risk table row to reflect the actual scope of the omission and rate it High.

---

## Low Issues

### L1 — Acceptance criteria rely entirely on a running container; no scriptable path for criteria 1, 2, 6

**Evidence**: Acceptance criteria table items 1, 2, 6:
- #1: "HTTP 200 from localhost/" — requires Docker
- #2: "HTTP 200 or graceful exit for file.php?avatar=1" — requires Docker
- #6: "$container->getParameter('core.filesystem_root_path') returns absolute path" — interactive only

**Gap**: No PHPUnit test or CLI-scriptable verification is given for these criteria. Criterion 6 verifying the new DI parameter has a testable form that could be encoded as a unit test or simple PHP script, but the spec doesn't provide it.

**Severity**: Low — All criteria are theoretically verifiable; gap is documentation quality, not functional.
**Recommendation**: Add one scriptable verification command for criterion 6:
```bash
php -r "
define('IN_PHPBB', true); define('PHPBB_ENVIRONMENT', 'production');
require 'src/phpbb/forums/di/container_builder.php';
\$b = new \phpbb\di\container_builder('/var/www/phpbb/', '/var/www/phpbb/');
\$params = (new \ReflectionMethod(\$b, 'get_core_parameters'))->invoke(\$b);
var_dump(\$params['core.filesystem_root_path']);
"
```

---

### L2 — `config_php_file` property `$phpbb_root_path` name/docblock becomes misleading after receiving absolute path

**Evidence**:
```
src/phpbb/forums/config_php_file.php:19   /** @var string phpBB Root Path */
src/phpbb/forums/config_php_file.php:20   protected $phpbb_root_path;
```

**Gap**: Section 4 correctly states "No code change needed in config_php_file." However, after the fix, callers pass an absolute filesystem path to a property documented as "phpBB Root Path". This will confuse future maintainers. The spec does not recommend adding a clarifying note or deprecation comment.

**Severity**: Low — No functional impact; maintainability concern.
**Recommendation**: Add a note to section 4: "Consider adding an inline comment: `// Receives absolute filesystem root path since Layer 1+2 fix`" or defer to a later refactoring that renames the property to `$filesystem_root_path`.

---

## Verified Correct Items

The following spec elements were independently confirmed accurate against actual source:

| Item | Spec claim | Verification |
|------|-----------|--------------|
| `startup.php` L75 `file_exists` | ✓ exact line | grep confirmed line 75 |
| `startup.php` L83 `require` | ✓ exact line | grep confirmed line 83 |
| `__DIR__` depth `/../../../vendor/autoload.php` | ✓ correct | `src/phpbb/common` → 3 levels up = project root |
| `container_builder.php` L436 self-call | ✓ exact line | grep confirmed line 436 |
| `container_builder.__construct` current signature | ✓ matches | read confirmed `($phpbb_root_path, $php_ext = 'php')` at ~L131 |
| `get_config_path()` uses `$phpbb_root_path` | ✓ matches | read confirmed `$this->phpbb_root_path . 'src/phpbb/common/config'` |
| `get_cache_dir()` uses `$phpbb_root_path` | ✓ matches | read confirmed `$this->phpbb_root_path . 'cache/' . ...` |
| `register_ext_compiler_pass()` `->in()` uses `$phpbb_root_path` | ✓ matches | read confirmed `->in($this->phpbb_root_path . 'ext')` |
| `get_core_parameters()` array structure | ✓ matches | `core.root_path`, `core.php_ext`, `core.environment`, etc. confirmed |
| `PHPBB_FILESYSTEM_ROOT` defined in all `web/*.php` | ✓ confirmed | grep found 6+ files with `define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../')` |
| `bin/phpbbcli.php` L28 `config_php_file` — no change needed | ✓ correct | `$phpbb_root_path = __DIR__ . '/../'` is absolute |
| `bin/phpbbcli.php` L41 `container_builder($phpbb_root_path)` | ✓ exact line | grep confirmed line 41 |
| `install/startup.php` L272 `container_builder($phpbb_root_path)` — backward-compat OK | ✓ correct | 1-arg call; fallback via `realpath()` works in CLI install context |
| `common.php` L102 `container_builder` | ✓ exact line | grep confirmed line 102 |
| `common.php` L25 `config_php_file` | ✓ exact line | grep confirmed line 25 |
| `file.php` L19 `$phpbb_root_path = ... '../../'` | ✓ exact | read confirmed |

---

## Out-of-Scope Call Sites (acknowledged or correctly excluded)

| File | Line | Why excluded |
|------|------|-------------|
| `src/phpbb/install/startup.php` | 272 | 1-arg call; `realpath()` fallback handles it; install context |
| `src/phpbb/install/convert/convertor.php` | 68 | Acknowledged out-of-scope; install context |
| `src/phpbb/install/convertors/convert_phpbb20.php` | 28 | Acknowledged out-of-scope; install context |
| `src/phpbb/install/convert/controller/convertor.php` | 170 | Not explicitly mentioned but same install context rationale applies |

**Note**: `src/phpbb/forums/install/helper/container_factory.php` (L146–147) was NOT properly classified. It is install-related but it passes `$php_ext` positionally, making it a Critical breaking caller under Option B — see finding C1 above.

---

## Recommendations Summary

| Priority | Action |
|----------|--------|
| **P0** | Add `container_factory.php` L147 to scope — pass `''` explicitly as `$filesystem_root_path`, OR switch to Option A (append) to avoid positional breakage |
| **P0** | Add section 6f to spec covering `web/download/file.php` L64 (`container_builder` call) |
| **P1** | Fix section 2d header: "before line 25" (not "after line ~115") |
| **P1** | Expand section 6 to cover `web/download/file.php` non-avatar block (L~155+) |
| **P2** | Add `file.php` L~61 `$phpbb_class_loader_ext` fix to spec or explicitly defer with rationale |
| **P2** | Update risk table: bump file.php gap severity from Low/Medium to High |
| **P3** | Add scriptable verification command for acceptance criterion #6 |
| **P3** | Add note to section 4 suggesting a maintainability comment in `config_php_file` |

---

## Conclusion

The specification has a solid conceptual foundation and accurately models the `__DIR__`-based Layer 1 fix. The Layer 2 constructor decision (Option B) is well-reasoned but the codebase search that validated it was incomplete, missing `container_factory.php:147` — a caller that will silently break. Additionally, `web/download/file.php` has two unaddressed paths (L64 in the avatar block, and L~155 in the non-avatar block).

These are not cosmetic issues: C1 would break the web installer, and C2+H2 mean the file.php fix is incomplete. The spec must be revised before implementation proceeds.

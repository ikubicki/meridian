# Composer Configuration Findings

**Category**: composer-config
**Date**: 2026-04-15
**Research Question**: How can all `require`/`include` statements be replaced with Composer PSR-4 autoloading?

---

## 1. Current `composer.json` Autoload Section

**Finding**: There is **NO `autoload` section** in `composer.json`.

The full `composer.json` contains these top-level keys:
- `name`, `description`, `type`, `keywords`, `homepage`, `license`
- `authors`, `support`, `scripts`, `replace`
- `require` (runtime deps)
- `require-dev` (dev deps)
- `extra`, `config`

**There is no `autoload` key.** The phpBB application classes are entirely absent from Composer's autoloading infrastructure.

**Source**: `composer.json` (full file, 95 lines)

---

## 2. Current PSR-4 Mappings (`vendor/composer/autoload_psr4.php`)

The generated file maps **only vendor packages**. There is **zero mapping for the `phpbb\` namespace**.

Full contents:

```php
return array(
    's9e\\TextFormatter\\'             => [$vendorDir . '/s9e/text-formatter/src'],
    's9e\\SweetDOM\\'                  => [$vendorDir . '/s9e/sweetdom/src'],
    's9e\\RegexpBuilder\\'             => [$vendorDir . '/s9e/regexp-builder/src'],
    'phpDocumentor\\Reflection\\'      => [...],
    'bantu\\IniGetWrapper\\'           => [$vendorDir . '/bantu/ini-get-wrapper/src'],
    'Zend\\EventManager\\'             => [$vendorDir . '/zendframework/zend-eventmanager/src'],
    'Zend\\Code\\'                     => [$vendorDir . '/zendframework/zend-code/src'],
    'Webmozart\\Assert\\'              => [$vendorDir . '/webmozart/assert/src'],
    'Twig\\'                           => [$vendorDir . '/twig/twig/src'],
    'Symfony\\Polyfill\\Php72\\'       => [$vendorDir . '/symfony/polyfill-php72'],
    'Symfony\\Polyfill\\Mbstring\\'    => [$vendorDir . '/symfony/polyfill-mbstring'],
    'Symfony\\Polyfill\\Intl\\Normalizer\\' => [...],
    'Symfony\\Polyfill\\Intl\\Idn\\'   => [...],
    'Symfony\\Polyfill\\Ctype\\'       => [...],
    'Symfony\\Component\\Yaml\\'       => [...],
    'Symfony\\Component\\Routing\\'    => [...],
    'Symfony\\Component\\Process\\'    => [...],
    'Symfony\\Component\\HttpKernel\\' => [...],
    'Symfony\\Component\\HttpFoundation\\' => [...],
    'Symfony\\Component\\Finder\\'     => [...],
    'Symfony\\Component\\Filesystem\\' => [...],
    'Symfony\\Component\\EventDispatcher\\' => [...],
    'Symfony\\Component\\DomCrawler\\' => [...],
    'Symfony\\Component\\DependencyInjection\\' => [...],
    'Symfony\\Component\\Debug\\'      => [...],
    'Symfony\\Component\\CssSelector\\' => [...],
    'Symfony\\Component\\Console\\'    => [...],
    'Symfony\\Component\\Config\\'     => [...],
    'Symfony\\Component\\BrowserKit\\' => [...],
    'Symfony\\Bridge\\Twig\\'          => [...],
    'Symfony\\Bridge\\ProxyManager\\'  => [...],
    'ReCaptcha\\'                      => [$vendorDir . '/google/recaptcha/src/ReCaptcha'],
    'Psr\\Log\\'                       => [...],
    'Psr\\Http\\Message\\'             => [...],
    'Psr\\Container\\'                 => [...],
    'Prophecy\\'                       => [...],
    'PackageVersions\\'                => [...],
    'OAuth\\'                          => [...],
    'Laravel\\Homestead\\'             => [...],
    'GuzzleHttp\\Psr7\\'               => [...],
    'GuzzleHttp\\Promise\\'            => [...],
    'GuzzleHttp\\'                     => [...],
    'Goutte\\'                         => [...],
    'FastImageSize\\'                  => [...],
    'Facebook\\WebDriver\\'            => [...],
    'Doctrine\\Instantiator\\'         => [...],
    'DeepCopy\\'                       => [...],
);
```

**Confirmed**: `phpbb\\` is absent from this array.

---

## 3. Classmap Entries (`vendor/composer/autoload_classmap.php`)

- **Total lines**: 1139
- **phpbb entries**: **0** (confirmed with `grep -c "phpbb"` → output: `0`)
- All entries are from `phing/phing` vendor classes (unnamespaced legacy classes like `AbstractFileSet`, `BuildEvent`, etc.)

**Source**: `vendor/composer/autoload_classmap.php`

---

## 4. Files Autoload Entries (`vendor/composer/autoload_files.php`)

Exists. Contains 11 entries — all vendor polyfills and utility functions:

```php
return array(
    '320cde22...' => $vendorDir . '/symfony/polyfill-ctype/bootstrap.php',
    '0e6d7bf4...' => $vendorDir . '/symfony/polyfill-mbstring/bootstrap.php',
    '25072dd6...' => $vendorDir . '/symfony/polyfill-php72/bootstrap.php',
    '7b11c4dc...' => $vendorDir . '/ralouphie/getallheaders/src/getallheaders.php',
    'e69f7f6e...' => $vendorDir . '/symfony/polyfill-intl-normalizer/bootstrap.php',
    'c964ee0e...' => $vendorDir . '/guzzlehttp/promises/src/functions_include.php',
    'a0edc830...' => $vendorDir . '/guzzlehttp/psr7/src/functions_include.php',
    '6124b4c8...' => $vendorDir . '/myclabs/deep-copy/src/DeepCopy/deep_copy.php',
    'f598d06a...' => $vendorDir . '/symfony/polyfill-intl-idn/bootstrap.php',
    '37a3dc51...' => $vendorDir . '/guzzlehttp/guzzle/src/functions_include.php',
    '2a3c2110...' => $vendorDir . '/php-webdriver/webdriver/...',
);
```

**No phpBB application files are registered**. Procedural functions files (`functions.php`, `functions_content.php`, `utf_tools.php`, etc.) are all loaded manually via `require` in `common.php`.

---

## 5. PSR-0 Namespaces (`vendor/composer/autoload_namespaces.php`)

Only two legacy PSR-0 entries:
```php
return array(
    'Twig_'         => [$vendorDir . '/twig/twig/lib'],
    'ProxyManager\\' => [$vendorDir . '/ocramius/proxy-manager/src'],
);
```

No phpBB-related PSR-0 mappings.

---

## 6. Current Custom Class Loader (the replacement target)

**File**: `src/phpbb/forums/class_loader.php` — **exists**.

The `class_loader` is the phpBB-custom SPL autoloader. It is instantiated in `src/phpbb/common/common.php` (lines 24–27 and 100–101):

```php
// common.php line 24
require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');

// line 26-27 — maps phpbb\ namespace to src/phpbb/forums/
$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}src/phpbb/forums/");
$phpbb_class_loader->register();

// line 100-101 — maps \ (root) namespace to ext/ (for extensions)
$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
$phpbb_class_loader_ext->register();
```

**Key mapping confirmed**: `phpbb\` namespace → `src/phpbb/forums/` directory.

This is a chicken-and-egg dependency: `class_loader.php` must be `require`-d manually before any `phpbb\` class can be autoloaded, because the autoloader itself is a `phpbb\` class.

---

## 7. Analysis: What Is Missing vs. What Is Needed

| Area | Current State | Needed |
|------|--------------|--------|
| `phpbb\` PSR-4 mapping | ❌ Absent | `"phpbb\\" => "src/phpbb/forums/"` |
| `phpbb\ext\*` PSR-4 mapping | ❌ Absent | Not in root composer.json (per-extension concern) |
| Procedural functions autoload | ❌ Not present | Add to `autoload.files` array |
| `autoload` key in composer.json | ❌ Missing entirely | Add full `autoload` block |
| Custom `class_loader.php` | ✅ Exists but custom | Can be retired once PSR-4 is active |

---

## 8. Exact JSON Addition Needed for `composer.json`

Insert this block **after the `"require-dev"` section** (before `"extra"`):

```json
"autoload": {
    "psr-4": {
        "phpbb\\": "src/phpbb/forums/"
    },
    "files": [
        "src/phpbb/common/functions.php",
        "src/phpbb/common/functions_content.php",
        "src/phpbb/common/functions_compatibility.php",
        "src/phpbb/common/constants.php",
        "src/phpbb/common/utf/utf_tools.php"
    ]
},
```

### Diff view (contextual):

```diff
     "require-dev": {
         ...
     },
+    "autoload": {
+        "psr-4": {
+            "phpbb\\": "src/phpbb/forums/"
+        },
+        "files": [
+            "src/phpbb/common/functions.php",
+            "src/phpbb/common/functions_content.php",
+            "src/phpbb/common/functions_compatibility.php",
+            "src/phpbb/common/constants.php",
+            "src/phpbb/common/utf/utf_tools.php"
+        ]
+    },
     "extra": {
```

### Notes on the `files` array:
- `functions.php` — defines ~300 phpBB procedural helpers, always-required
- `functions_content.php` — content rendering helpers
- `functions_compatibility.php` — BC shims (currently `include`-d, so optional)
- `constants.php` — global constants (`IN_PHPBB`, etc.)
- `utf/utf_tools.php` — UTF-8 string utilities

These files **cannot be PSR-4 autoloaded** because they define functions, not classes. They must stay in `autoload.files` so Composer loads them eagerly.

---

## 9. Command to Run After Changes

```bash
composer dump-autoload --optimize
```

- `--optimize` generates an optimized classmap in addition to PSR-4, improving cold-start performance.
- After running this, `vendor/composer/autoload_psr4.php` will include the `phpbb\\` entry.
- `vendor/autoload.php` already exists and is the single entry point.

---

## 10. Risks and Conflicts

### Risk 1: Chicken-and-Egg Bootstrap (HIGH)
`src/phpbb/common/common.php` manually `require`s `class_loader.php` before any PSR-4 is active. Once Composer autoloading is in place via `vendor/autoload.php`, this `require` becomes redundant. However, `common.php` itself is `require`-d by entry points (`web/app.php`, `web/index.php`, etc.) which also do NOT include `vendor/autoload.php` first. This must be fixed in entry points.

**Fix required**: Every entry point must add:
```php
require __DIR__ . '/../vendor/autoload.php';
```
before any phpBB class is used.

### Risk 2: Ext Namespace Conflicts (MEDIUM)
The second `class_loader` maps the root `\` namespace to `ext/`. phpBB extensions typically declare namespaces like `phpbb\extension_name\`. Adding a PSR-4 `phpbb\\` root mapping may conflict only if extensions install files into `src/phpbb/forums/` — which they don't (they live in `ext/`). **No conflict expected** for standard installations.

### Risk 3: `class_loader` Cache Dependency (LOW)
`class_loader` has a `set_cache()` mechanism (`common.php` lines 137–138). After migrating to PSR-4, this cache layer is removed. phpBB's own cache won't warm Composer's classmap — but `--optimize` flag on `dump-autoload` replaces it with a static classmap that is faster anyway.

### Risk 4: No Existing `phpbb\` Package Conflict
No installed Composer package declares `phpbb\\` as a PSR-4 namespace (confirmed by inspecting `autoload_psr4.php`). The `composer/package-versions-deprecated` package has nothing to do with this namespace. **No package conflict**.

### Risk 5: `autoload_classmap.php` Size
At 1139 lines, the classmap already covers all phing classes. Adding the phpBB classes via classmap (`--optimize`) will grow this file but not cause errors.

---

## 11. Summary

- **`composer.json` has no `autoload` section** — it is entirely absent.
- **No `phpbb\` PSR-4 mapping exists** anywhere in Composer's generated files.
- The **custom `phpbb\class_loader`** handles all `phpbb\` class loading today via SPL, pointed at `src/phpbb/forums/`.
- The correct PSR-4 root path is `src/phpbb/forums/` (maps `phpbb\` → `src/phpbb/forums/`).
- Procedural function files must be listed under `autoload.files` since they are not classes.
- After adding the `autoload` block and running `composer dump-autoload`, the manual `require` of `class_loader.php` in `common.php` can be removed.
- Entry points must be updated to `require vendor/autoload.php` first.
- No package namespace conflicts detected.

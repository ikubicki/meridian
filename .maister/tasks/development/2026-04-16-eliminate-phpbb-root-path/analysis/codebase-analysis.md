# Codebase Analysis: Eliminate $phpbb_root_path

## Summary

Analiza potwierdza ustalenia z raportu badań — wszystkie numery linii i treść kodu zgadzają się dokładnie. Zidentyfikowano 5 plików do zmiany w ramach Layer 1+2.

## Key Files

### Layer 1 (Bootstrap — bezpośrednie naprawy)

**src/phpbb/common/startup.php**
- L75: `if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))` → `__DIR__ . '/../../../vendor/autoload.php'`
- L83: `require($phpbb_root_path . 'vendor/autoload.php');` → `__DIR__ . '/../../../vendor/autoload.php'`

**src/phpbb/common/common.php**
- L23: require startup.php → `__DIR__ . '/startup.php'`
- L36: require functions.php → `__DIR__ . '/functions.php'`
- L53: require forums/filesystem/filesystem.php → `__DIR__ . '/../forums/filesystem/filesystem.php'`
- L78: require functions.php → `__DIR__ . '/functions.php'`
- L79: require functions_content.php → `__DIR__ . '/functions_content.php'`
- L80: include functions_compatibility.php → `__DIR__ . '/functions_compatibility.php'`
- L82: require constants.php → `__DIR__ . '/constants.php'`
- L83: require utf/utf_tools.php → `__DIR__ . '/utf/utf_tools.php'`
- L137: require compatibility_globals.php → `__DIR__ . '/compatibility_globals.php'`
- L142: require hooks/index.php → `__DIR__ . '/hooks/index.php'`
- L150: @include hooks/$hook.php → `__DIR__ . '/hooks/' . $hook . '.php'`

### Layer 2 (DI — dodatkowy parametr)

**src/phpbb/forums/di/container_builder.php**
- Constructor: `__construct($phpbb_root_path, $php_ext = 'php')` → dodanie `$filesystem_root_path = ''`
- L424: `get_config_path()` → użycie `$this->filesystem_root_path`
- L433: `get_cache_dir()` → użycie `$this->filesystem_root_path`
- L684: `register_ext_compiler_pass()` → użycie `$this->filesystem_root_path`

**src/phpbb/forums/config_php_file.php**
- Constructor: `__construct($phpbb_root_path, $php_ext = 'php')` → akceptuje ścieżkę absolutną
- L52: `$this->config_file` → użyje absolutnej ścieżki

**bin/phpbbcli.php**
- L41: `new \phpbb\di\container_builder($phpbb_root_path)` → dodanie `$phpbb_root_path` (tutaj już `__DIR__.'/../'` czyli absolutna) jako filesystem_root_path

## Key Finding

`PHPBB_FILESYSTEM_ROOT` jest już zdefiniowany w 6 plikach `web/*.php` jako `__DIR__ . '/../'` — gotowy do użycia w common.php przy inicjalizacji container_builder i config_php_file.

## Risk Level

**Medium** — Layer 1 to czysta arytmetyka ścieżek (low risk), Layer 2 wymaga kompatybilności wstecznej przez domyślny parametr.

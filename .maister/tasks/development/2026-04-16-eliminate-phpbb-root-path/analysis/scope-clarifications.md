# Scope Clarifications

## Decisions Made

### 1. constructor-signature-order → Option B
`container_builder::__construct($phpbb_root_path, $filesystem_root_path = '', $php_ext = 'php')`
- Też aktualizuj L436 (wewnętrzne self-instantiation)

### 2. web/download/file.php → Include
Napraw w tym samym commicie. Zakres rozszerzony.

### 3. layer3-di-param → Tak
Dodaj `core.filesystem_root_path` do `get_core_parameters()` w container_builder.
Jedna linia, zerowe ryzyko.

## Final File Scope

| Plik | Layer | Zmiany |
|------|-------|--------|
| src/phpbb/common/startup.php | 1 | 2 linie: vendor/autoload.php via __DIR__ |
| src/phpbb/common/common.php | 1+2 | 11 require/include + call sites container_builder + config_php_file |
| src/phpbb/forums/di/container_builder.php | 2 | __construct (Option B) + L436 + get_config_path + get_cache_dir + register_ext_compiler_pass + get_core_parameters |
| src/phpbb/forums/config_php_file.php | 2 | __construct + config_file path |
| bin/phpbbcli.php | 2 | L41 call site + L28 config_php_file call |
| web/download/file.php | 1+2 | require + config_php_file call |

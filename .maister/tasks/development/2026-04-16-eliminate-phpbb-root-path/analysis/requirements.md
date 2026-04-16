# Requirements: Eliminate $phpbb_root_path from Filesystem Operations

## Initial Description
Implementacja wyników badań: usuń $phpbb_root_path z require/include, zastąp __DIR__-based i absolutnymi ścieżkami. Dotyczy Layer 1+2 + web/download/file.php.

## Q&A

**Q: Czy $phpbb_root_path powinien całkowicie zniknąć ze wszystkich plików?**
A: Nie. Zmienna $phpbb_root_path='./' pozostaje w common.php i jest nadal przekazywana do container_builder jako pierwszy argument (dla URL-relative logiki sesji, np. web_root_path). Zmieniamy tylko jej użycie w filesystem operations.

**Q: Czy backward compatibility dla container_builder jest wymagana?**
A: Tak — przez domyślny pusty string. Kod poza bezpośrednim zakresem (np. install/) może nadal tworzyć container_builder bez $filesystem_root_path i musi działać (fallback do $phpbb_root_path).

**Q: Co z install/convert/convertor.php i convert_phpbb20.php wywołującymi config_php_file?**
A: Poza zakresem — te pliki działają w innym kontekście (installer). Backward compat przez domyślny parametr.

## Functional Requirements

1. `startup.php` L75+L83: vendor/autoload.php check i require przez __DIR__ (nie $phpbb_root_path)
2. `common.php` L23,36,53,78,79,80,82,83,137,142,150: wszystkie require/include przez __DIR__
3. `container_builder.__construct`: signature Option B: `($phpbb_root_path, $filesystem_root_path='', $php_ext='php')`, store $filesystem_root_path (default do realpath($phpbb_root_path) gdy pusty)
4. `container_builder.get_config_path()`: używa $this->filesystem_root_path zamiast $this->phpbb_root_path
5. `container_builder.get_cache_dir()`: używa $this->filesystem_root_path
6. `container_builder.register_ext_compiler_pass()`: używa $this->filesystem_root_path
7. `container_builder.get_core_parameters()`: dodaje 'core.filesystem_root_path' => $this->filesystem_root_path
8. `container_builder.php L436` (internal new container_builder): aktualizuj call site dla nowej sygnatury
9. `config_php_file.__construct`: przyjmuje absolutną ścieżkę (zachowa backward compat)
10. `common.php` (call sites): przekazuj PHPBB_FILESYSTEM_ROOT do container_builder i config_php_file
11. `bin/phpbbcli.php`: przekazuj $phpbb_root_path jako filesystem_root (już absolutne)
12. `web/download/file.php`: zastąp $phpbb_root_path='../../' w require/config_php_file przez absolutne ścieżki

## Similar Patterns / Reuse
- PHPBB_FILESYSTEM_ROOT już zdefiniowany w 6 web/*.php — wzorzec do naśladowania
- bin/phpbbcli.php już używa `$phpbb_root_path = __DIR__ . '/../'` — absolutna ścieżka do reuse

## Scope Boundaries
- IN: startup.php, common.php (require/include + call sites), container_builder, config_php_file, phpbbcli.php, web/download/file.php
- OUT: Layer 3 (~35 YAML services), install/convert/*, URL-related uses of $phpbb_root_path

# Gap Analysis: Eliminate $phpbb_root_path

## Current State
`$phpbb_root_path = './'` — URL-relative path set w web entry points. Gdy PHP CWD=/var/www/phpbb/web/, wszystkie `require($phpbb_root_path . 'src/phpbb/...')` rozwiązują się do `/var/www/phpbb/web/src/phpbb/...` (nieistniejące). Ta sama zepsuta ścieżka trafia do `container_builder` i `config_php_file`.

## Desired State
Wszystkie filesystem require/include w startup.php i common.php używają `__DIR__`-based. `container_builder` i `config_php_file` otrzymują absolutną ścieżkę (`PHPBB_FILESYSTEM_ROOT`). CLI (`bin/phpbbcli.php`) przekazuje swój już-absolutny `$phpbb_root_path` jako filesystem root.

## Gap Summary
5 plików, ~20 linii. Layer 1: 13 linii w startup.php + common.php. Layer 2: container_builder konstruktor + 3 metody + config_php_file + phpbbcli.php call site.

**Dodatkowe znalezisko**: container_builder.php L436 zawiera wewnętrzny `new container_builder($this->phpbb_root_path, $this->php_ext)` pozycjonalnie. Wstawienie `$filesystem_root_path` na pozycji 2 cicho przypisze 'php' jako filesystem root.

## task_characteristics
- has_reproducible_defect: true
- modifies_existing_code: true
- creates_new_entities: false
- involves_data_operations: false
- ui_heavy: false

## risk_level: medium

## integration_points
- web/*.php (6 entry points) — definiują PHPBB_FILESYSTEM_ROOT
- bin/phpbbcli.php L28 — instantiuje config_php_file($phpbb_root_path)
- container_builder.php L436 — wewnętrzne self-instantiation
- web/download/file.php — ten sam defekt, poza zakresem

## decisions_needed
### Critical
- constructor-signature-order: Option A (append $filesystem_root_path na końcu) vs Option B (insert na poz. 2, aktualizuj L436)
  - Rekomendacja gap-analyzer: Option B — czystsze API, L436 trivially do zaktualizowania

### Important
- web-download-file-scope: include or defer web/download/file.php?
- layer3-di-param: dodać core.filesystem_root_path do get_core_parameters() w tym zadaniu?

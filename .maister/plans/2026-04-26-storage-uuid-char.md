# Plan: Zamiana BINARY(16) + HEX/UNHEX na CHAR(32) UUID

**Data:** 2026-04-26  
**Zadanie:** Usunięcie `HEX()` / `UNHEX()` z warstwy repozytorium przez zmianę kolumn `id` i `parent_id` z `BINARY(16)` na `CHAR(32)` przechowujące UUID v7 bezpośrednio jako 32-znakowy hex string (bez myślników).

---

## Kontekst

UUID v7 jest generowany jako `bin2hex($bytes)` → 32-znakowy lowercase hex string, np. `019dc4fa144976fda0376bca2cf10838`.

Aktualnie kolumny `id`/`parent_id` to `BINARY(16)`, więc każdy zapis wymaga `UNHEX(:id)` a każdy odczyt `HEX(id) AS id`. To jest SQL specyficzne dla MySQL/MariaDB i utrudnia testowanie (SQLite `unhex()` dopiero od 3.41).

---

## Zakres zmiany

| Plik | Co się zmienia |
|------|---------------|
| `src/phpbb/storage/Migration/001_storage_schema.sql` | `BINARY(16)` → `CHAR(32)` dla `id` i `parent_id` |
| `src/phpbb/storage/Migration/002_storage_uuid_char.sql` | Migracja danych produkcyjnych (ALTER + UPDATE) |
| `src/phpbb/storage/Repository/DbalStoredFileRepository.php` | Usunięcie `HEX()`/`UNHEX()`, uproszczenie `buildSelectBase()` i `hydrate()` |
| `tests/phpbb/storage/Repository/DbalStoredFileRepositoryTest.php` | Dostosowanie `makeRow()` — id nie przechodzi już przez `strtolower(HEX(...))` |

---

## Implementacja krok po kroku

### Krok 1 — Schemat (001_storage_schema.sql)

Zmień definicję kolumn:
```sql
-- przed:
id            BINARY(16)      NOT NULL,
parent_id     BINARY(16)      DEFAULT NULL,

-- po:
id            CHAR(32)        NOT NULL,
parent_id     CHAR(32)        DEFAULT NULL,
```

### Krok 2 — Migracja danych (002_storage_uuid_char.sql)

Nowy plik migracji dla istniejących baz:
```sql
-- Konwersja BINARY(16) → CHAR(32) UUID hex string
ALTER TABLE phpbb_stored_files
    MODIFY COLUMN id        CHAR(32) NOT NULL,
    MODIFY COLUMN parent_id CHAR(32) DEFAULT NULL;
```

> **Uwaga:** MySQL przy ALTER TABLE z BINARY(16) na CHAR(32) dokona automatycznej konwersji (bajty binarne → hex representation). Należy przetestować na kopii danych. Bezpieczniejsza ścieżka: ADD nową kolumnę, UPDATE, DROP stara, RENAME.

Bezpieczna wersja migracji:
```sql
ALTER TABLE phpbb_stored_files
    ADD COLUMN id_new        CHAR(32)        NULL AFTER id,
    ADD COLUMN parent_id_new CHAR(32)        NULL AFTER parent_id;

UPDATE phpbb_stored_files SET id_new = LOWER(HEX(id));
UPDATE phpbb_stored_files SET parent_id_new = LOWER(HEX(parent_id)) WHERE parent_id IS NOT NULL;

ALTER TABLE phpbb_stored_files
    DROP PRIMARY KEY,
    DROP COLUMN id,
    CHANGE COLUMN id_new id CHAR(32) NOT NULL,
    ADD PRIMARY KEY (id),
    DROP COLUMN parent_id,
    CHANGE COLUMN parent_id_new parent_id CHAR(32) DEFAULT NULL;
```

### Krok 3 — Repozytorium (DbalStoredFileRepository.php)

**`buildSelectBase()`** — przed:
```php
->select(
    'HEX(id) AS id',
    ...,
    'HEX(parent_id) AS parent_id',
    ...
)
```
Po:
```php
->select(
    'id',
    ...,
    'parent_id',
    ...
)
```

**WHERE clauses** — przed:
```php
->where('id = UNHEX(:id)')
->where('parent_id = UNHEX(:parentId)')
```
Po:
```php
->where('id = :id')
->where('parent_id = :parentId')
```

**INSERT values** — przed:
```php
'id'        => 'UNHEX(:id)',
'parent_id' => 'UNHEX(:parentId)',
```
Po:
```php
'id'        => ':id',
'parent_id' => ':parentId',
```

**`hydrate()`** — usunąć `strtolower()` (UUID już są lowercase z `generateUuidV7()`):
```php
id:       (string) $row['id'],
parentId: isset($row['parent_id']) ? (string) $row['parent_id'] : null,
```

### Krok 4 — Testy (DbalStoredFileRepositoryTest.php)

`makeRow()` zwraca `'id' => 'ABC123'` — po zmianie id jest zwracane bez konwersji, więc test sprawdzający `$file->id === 'abc123'` przestanie działać (zostanie `'ABC123'`).

Zmiana: w `makeRow()` użyj uppercase lub lowercase konsekwentnie i zaktualizuj asercję `assertSame()`.

---

## Applicable Standards

### `standards/global/STANDARDS.md`
- Plik nagłówek z copyright `phpBB4 "Meridian"` / `Irek Kubicki` — zachowany
- `declare(strict_types=1)` — zachowany
- `php-cs-fixer` po każdej zmianie PHP

### `standards/backend/STANDARDS.md`
- Doctrine DBAL 4 `createQueryBuilder()` — zachowane
- Named parameters (`:name`) — zachowane, HEX/UNHEX zastąpione prostymi wartościami
- Brak raw SQL stringów w `executeQuery()`/`executeStatement()` — zachowane
- `final readonly class` dla encji — niezmieniona

### `standards/testing/STANDARDS.md`
- `#[Test]` PHP 8 attribute — zachowane
- Namespace `phpbb\Tests\storage\Repository` — zachowany
- AAA pattern — zachowany

---

## Standards Compliance Checklist

- [ ] Doctrine DBAL 4 QueryBuilder użyty we wszystkich zapytaniach (bez raw SQL)
- [ ] Named parameters (`:id`, `:parentId`) zamiast pozycyjnych `?`
- [ ] `BINARY(16)` zastąpione przez `CHAR(32)` w schemacie podstawowym
- [ ] Migracja danych (`002_storage_uuid_char.sql`) używa bezpiecznej 3-step ALTER (bez utraty danych)
- [ ] `buildSelectBase()` nie zawiera już `HEX()` wyrażeń
- [ ] `hydrate()` nie używa `strtolower()` — UUID muszą być lowercase w źródle (`generateUuidV7()`)
- [ ] Testy zaktualizowane: `makeRow()` i asercje `assertSame()` dopasowane do nowego formatu
- [ ] `php-cs-fixer` uruchomiony po zmianach — brak nowych naruszeń
- [ ] `composer test` przechodzi
- [ ] `composer test:e2e` przechodzi

---

## Ryzyka

| Ryzyko | Mitigacja |
|--------|-----------|
| MySQL konwertuje BINARY(16) → CHAR(32) z paddingiem/błędem | Użyj bezpiecznej 3-step migracji z `LOWER(HEX(id))` |
| SQLite integration tests nie obsługują `unhex()` stary | Problem znika — SQLite obsługuje CHAR(32) bez zmian |
| `strtolower()` w `hydrate()` — bez tego uppercase ID przejdzie do encji | `generateUuidV7()` zawsze zwraca lowercase; jeśli jednak stare dane mają uppercase, dodać `strtolower()` w hydrate |

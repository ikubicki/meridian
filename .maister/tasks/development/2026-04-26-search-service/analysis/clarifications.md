# Clarifications — M9 Search Service

## Architektura backendu wyszukiwania

**Decyzja użytkownika**: Warstwa abstrakcji konfigurowana przez administratora z trzema driverami:

### 1. ElasticsearchDriver (stub)
- Wymaga konfiguracji połączenia (host, port, index)
- Na razie implementujemy jako stub (nie wykonuje realnych zapytań)
- Rzuca wyjątek lub zwraca puste wyniki z logiem ostrzeżenia

### 2. FullTextDriver (natywny FTS per baza danych)
- MySQL: `MATCH(post_text, post_subject) AGAINST (:query IN BOOLEAN MODE)`
- PostgreSQL: `to_tsvector(...) @@ to_tsquery(...)`
- SQLite: FTS5 (virtual table)
- Wykrywa platformę DBAL runtime, wybiera odpowiedni dialect

### 3. LikeDriver (fallback)
- `post_text LIKE :q OR post_subject LIKE :q`
- Używany gdy FTS niedostępny lub explicite wybrany przez admina

## Konfiguracja
- Klucz w `phpbb_config`: `search_driver` (np. `fulltext`, `like`, `elasticsearch`)
- Admin może zmieniać przez API (późniejszy etap)
- Default: `fulltext` z fallback do `like`

## Pozostałe decyzje (domyślne)
- Przeszukuje: posty (`post_text`, `post_subject`)
- Auth: wymagane (JWT Bearer)
- Filtr `forum_id`: opcjonalny parametr
- Cache: brak (MVP)

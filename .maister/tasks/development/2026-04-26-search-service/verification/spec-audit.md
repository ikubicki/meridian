# Spec Audit Report — M9 Search Service

**Verdict: FAIL** — 3 krytyczne blokery

## Critical (3)
1. Brak FULLTEXT INDEX na phpbb_posts — FullTextDriver MySQL path jest broken
2. `ConfigRepository` jako `final class` — SearchServiceTest nie można mockować bez interfejsu
3. QueryBuilder jest niemockowany w unit testach — należy używać `executeQuery()` jak inne repozytoria

## Important (6)
4. perPage default: spec mówi 20, PaginationContext::fromQuery() zwraca 25
5. FullTextDriver SQLite fallback niespecyfikowany (inject LikeDriver vs trait vs duplikacja)
6. SearchService constructor signature nie pokazany (autowire ambiguity)
7. E2E seed musi ustawiać post_visibility = 1 (domyślnie 0)
8. COUNT query dla FullTextDriver niespecyfikowany
9. meta.lastPage derivation — PaginatedResult::totalPages() nie jest udokumentowane

## Minor (4)
10. ConfigRepository w src/phpbb/config/ — niekonsekwentne z konwencją modułową
11. Brak testu exception path dla ConfigRepository
12. E2E nigdy nie testuje FullTextDriver (brak seeding search_driver w phpbb_config)
13. post_subject collation mismatch (utf8mb3_unicode_ci vs bin)

## Amendments Applied
- FULLTEXT index migration dodana do file structure
- ConfigRepositoryInterface wymagany (nie opcjonalny)
- executeQuery() zamiast QueryBuilder
- PaginationContext default poprawiony na 25 / max 50 (SearchController override)
- FullTextDriver SQLite fallback: inject LikeDriver
- SearchService constructor signature pokazany
- E2E seed uwzględnia post_visibility = 1 + search_driver config

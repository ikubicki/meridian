# Codebase Analysis — M9 Search Service

## Executive Summary

Implementacja M9 Search Service będzie nowym modułem `phpbb\search\` wzorowanym na `phpbb\notifications\` (kompletny, najprostszy istniejący moduł). Wszystkie wzorce implementacyjne są dobrze ugruntowane w codebase M0-M8.

## Istniejące wzorce

### Struktura modułu (wzorzec: notifications)
- `Contract/` — interfejsy (SearchRepositoryInterface, SearchServiceInterface)
- `DTO/` — obiekty transferu danych (SearchResultDTO, Request/SearchRequest)
- `Entity/` — `final readonly class SearchResult` z `fromRow()` factory
- `Event/` — zdarzenia domenowe
- `Repository/DbalSearchRepository` — DBAL 4 QueryBuilder, named params
- `Service/SearchService` — logika biznesowa, bez HTTP

### Repository (DBAL 4)
- `createQueryBuilder()`, named parameters (`:param`), nigdy string interpolation
- `fromRow(array $row): self` factory na każdej encji
- `setFirstResult()` / `setMaxResults()` dla paginacji
- Wyjątki: `RepositoryException` wrapping `DBAL\Exception`

### Controller
- Thin layer: parse request → call service → return JsonResponse
- Auth: `$request->attributes->get('_api_user')`
- Paginacja przez `PaginationContext::fromQuery(Request $request)`
- Zdarzenia domenowe dispatch w kontrolerze

### DI (services.yaml)
- Service alias: `SearchServiceInterface` → `SearchService`
- Repository alias: `SearchRepositoryInterface` → `DbalSearchRepository`
- Autowiring przez konstruktor

## Schema phpBB3

Tabele dostępne do reuse:
- `phpbb_search_results` — cache zapytań (`search_key`, `search_time`, `search_keywords`)
- `phpbb_search_wordlist` — indeks słów (`word_id`, `word_text`, `word_common`)
- `phpbb_search_wordmatch` — mapowanie post↔word (`word_id`, `post_id`, `title_match`)

Kolumny `phpbb_posts` przydatne do FTS:
- `post_text` (mediumtext), `post_subject` (varchar 255), `post_visibility`, `post_time`, `poster_id`, `forum_id`, `topic_id`

Phpbb3 config: `search_type = fulltext_native` — sugeruje natywny FULLTEXT jako domyślny backend.

## Konwencje testowe
- PHPUnit 10: `#[Test]`, `#[DataProvider]`, mocks jako `Interface&MockObject`
- E2E: Playwright TypeScript, `tests/e2e/search.spec.ts`
- DB seeding: `tests/e2e/helpers/db.ts` (mysql2, port 13306)

# Work Log — M9 Search Service

## 2026-04-26 — Implementation Started

**Total Steps**: 47
**Task Groups**: 7 (Config Module, Contracts+DTO, Drivers, Service, Controller+DI, E2E, Test Review)

## Standards Reading Log

### Loaded Per Group
(Entries added as groups execute)

---

## Execution Progress

| Group | Status | Tests | Notes |
|-------|--------|-------|-------|
| G1: Config Module (ConfigRepository) | ✅ | 3 unit | `ConfigRepositoryInterface`, `ConfigRepository` |
| G2: Contracts & DTO (SearchDriverInterface, SearchQuery) | ✅ | 5 unit | SearchQuery: `sortBy`, `searchIn`, `dateFrom`, `dateTo`; walidacja konstruktora |
| G3: Search Drivers (Like / FullText / Elasticsearch / Native) | ✅ | 22 unit | NativeTokenizer + NativeDriver; CJK bigrams; LikeDriver fallback |
| G4: SearchService | ✅ | 7 unit | `getDriver()`, 4 drivery, `'native'` case |
| G5: Controller & DI | ✅ | 7 unit | `SearchController`, `sort_by`/`search_in`/`date_from`/`date_to` params |
| G6: SearchIndexerService + wiring | ✅ | 6 unit | `indexPost`/`deindexPost`/`reindexAll`; wiring w ThreadsService (create) |
| G7: Cache wyników | ✅ | 2 unit | `TagAwareCacheInterface`, TTL z `search_cache_ttl` config, tag `search` |
| G8: PHPUnit gap-fill | ✅ | 3 unit | `SearchQueryTest` (3 testy) |
| G9: E2E Tests | ✅ | 10 E2E | UC-S1–S5 + UC-SR4/5/6/9; `sort_by`, `search_in`, `date_from`, pagination |

## Finalne wyniki

- **Unit tests**: 494 (było 458 przed M9)
- **E2E tests**: 178 (10 search)
- **CS Fix**: 0 naruszeń

## Znane ograniczenia / TODO

- `SearchIndexerService::indexPost` nie jest wołany przy editPost/deletePost (metody nie istnieją jeszcze w ThreadsService)
- `ElasticsearchDriver` to stub — deleguje do LikeDriver z logiem warning
- `INSERT OR IGNORE` w wordmatch — działa w MySQL (alias dla `INSERT IGNORE`) i SQLite; przy dedykowanym MySQL można zmienić na `INSERT IGNORE`
- `NativeDriver`: duże `IN (...)` przy wielu post_id — akceptowalne na obecnym etapie

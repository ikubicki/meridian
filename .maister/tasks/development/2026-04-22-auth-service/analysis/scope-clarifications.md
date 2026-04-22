# Scope Clarifications — M3 Auth Service

## Decisions Made (2026-04-22)

### D1: POST /auth/elevate — **INCLUDED in M3**
User chose: włącz pełny flow elevation w M3.
**Implication**: +4 pliki (ElevatedToken entity, elevate() w AuthenticationService, walidacja `aud: phpbb-admin` w AuthSubscriber, endpoint w AuthController). Scope rośnie o ~20%.

### D2: AuthorizationService — **Minimal stub**
User chose: interfejs + AuthorizationSubscriber istnieją, flags=0, brak aktywnych route guards.
**Implication**: `AuthorizationServiceInterface` + `AuthorizationService` implementacja istnieje (isGranted() zwraca false/true na podstawie flags=0), `AuthorizationSubscriber` rejestruje się ale żaden route nie ma `_api_permission` wymóg. Odblokuje M5 (Hierarchy) do podpięcia.

### D3: _api_token → _api_user — **Hard rename**
User chose: zaktualizuj wszystkie 3 kontrolery + subscriber w M3 PR.
**Implication**: ForumsController, TopicsController, UsersController — zmiana `_api_token` na `_api_user`. AuthenticationSubscriber hydruje User entity po walidacji gen/pv (1 DB select per request na chronionych endpointach).

## Important Decisions (Defaults Applied)
- **Rate limiting**: Defer — user_login_attempts column istnieje, ale throttling nie jest w M3 scope
- **Migration mechanism**: SQL file w src/phpbb/auth/Migration/ + update phpbb_dump.sql
- **Refresh token TTL**: 30 dni (configurowalny przez constructor param)
- **PasswordService**: Extract do phpbb\user\Service\PasswordService (reusable dla registration)

## Scope Summary
M3 obejmuje: login, logout, logout-all, refresh, elevate, TokenService, AuthenticationService, AuthorizationService (stub), RefreshTokenRepository, PasswordService, DB schema changes, hard rename _api_token→_api_user, 5 nowych test plików PHPUnit, rozszerzone E2E.

**Files to create**: ~18 nowych plików w phpbb\auth\ + phpbb\user\Service\PasswordService
**Files to modify**: AuthController, AuthenticationSubscriber, User entity, PdoUserRepository, services.yaml, ForumsController, TopicsController, UsersController, AuthControllerTest (rewrite), AuthenticationSubscriberTest (update)

# Research Brief: Notifications Service

## Research Question

Jak zaprojektować usługę `phpbb\notifications` odpowiedzialną za powiadomienia użytkowników, wysyłkę emaili i powiadomienia w aplikacji frontendowej? Usługa powinna mieć lekki endpoint REST API informujący o ilości powiadomień oraz kilku najnowszych powiadomieniach — wzorem powiadomień z Facebooka.

## Research Type

**Mixed** — łączy analizę techniczną istniejącego systemu, zbieranie wymagań dla nowego API, oraz badanie best practices (real-time notifications, REST polling patterns).

## Scope

### Included
- Istniejący system powiadomień phpBB (manager, types, methods)
- Schemat bazy danych `phpbb_notifications`, `phpbb_user_notifications`, `phpbb_notification_emails`, `phpbb_notification_types`
- Mechanizm wysyłki emaili (metoda email + mail queue)
- Projektowanie lekkiego REST API endpointu (`GET /api/v1/notifications`)
- Integracja z istniejącą architekturą REST API (api.php, Symfony HttpKernel)
- Integracja z cache service (PSR-16, tag-aware) do cache'owania count/recent
- Integracja z auth service do autoryzacji endpointu
- Frontend notification badge (count + recent dropdown)
- Polling vs SSE vs WebSocket strategy dla real-time updates
- Notification grouping/aggregation (Facebook-style: "3 osoby skomentowały twój post")

### Excluded
- Implementacja nowych typów powiadomień (zostawiamy istniejące 21)
- Push notifications (PWA/native) — oddzielny research
- Modyfikacja metody Jabber
- Admin panel do zarządzania powiadomieniami
- Migracja istniejących danych

### Constraints
- Musi działać z sesyjną autentykacją (Phase 1 REST API) i być kompatybilne z przyszłym JWT
- Endpoint musi być lekki — max 50ms response time
- Kompatybilność z istniejącym DI container (Symfony)
- PSR-4 namespace: `phpbb\notifications\`
- Parametryzowane zapytania SQL — nigdy raw input interpolation

## Success Criteria
1. Zdefiniowana architektura usługi notifications (klasy, interfejsy, DI)
2. Zaprojektowany REST API endpoint z response schema
3. Strategia cache'owania count + recent notifications
4. Strategia dostarczania real-time (polling/SSE/WS)
5. Plan integracji z istniejącym notification manager
6. Facebook-style aggregation pattern

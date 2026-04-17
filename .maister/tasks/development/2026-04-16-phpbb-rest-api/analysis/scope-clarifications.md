# Scope Clarifications — phpBB REST API

**Data**: 2026-04-16

## Pytania i decyzje

### Q1: Status auth_subscriber w Fazie 1

**Decyzja**: Stub auth w Fazie 1
- `phpbb\api\event\auth_subscriber` istnieje ale zwraca `501 Not Implemented`
  z body `{"error": "API token authentication not yet implemented", "status": 501}`
- Tabela `phpbb_api_tokens` tworzona w Fazie 2
- `json_exception_subscriber` obsługuje wszystkie wyjątki (404, 500 etc.)

### Q2: Inicjacja sesji phpBB w entry pointcie

**Decyzja**: Brak sesji phpBB w entry pointcie
- `web/api.php` NIE wywołuje `session_begin()` ani `auth->acl()`
- Entry point jest minimalny: define constants → include common.php → get service → run()
- Autentykacja obsługiwana przez `auth_subscriber` (kernel.request) — stub w Fazie 1

## Zakres wynikający z decyzji

**Wykluczone z Fazy 1**:
- Tabela `phpbb_api_tokens` (DB migration)
- Pełna implementacja auth_subscriber
- Prawdziwa weryfikacja tokenów

**Włączone od razu**:
- Stub auth_subscriber (zwraca 501)
- json_exception_subscriber (priority 10, zwraca JSON dla błędów)
- Hardcoded mock data w kontrolerach
- Nginx routing (3 location bloki)

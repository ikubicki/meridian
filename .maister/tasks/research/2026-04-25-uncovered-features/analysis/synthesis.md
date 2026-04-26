# Synteza: Co nie jest jeszcze zaimplementowane?

**Pytanie badawcze**: Jakie funkcjonalności nie są jeszcze pokryte zgodnie z kamieniami milowymi?
**Data**: 2026-04-25
**Źródła**: `docs-milestones.md` (plan), `codebase-phpbb3.md` (analiza kodu phpBB3)

---

## 1. Analiza krzyżowa obu źródeł

### Co potwierdzają oba źródła?

| Obserwacja | Pewność |
|---|---|
| M9–M14 są całkowicie nierozpoczęte (oprócz M9 research) | Wysoka |
| Brak systemu email jest blokerem dla wielu funkcji | Wysoka |
| Cron scheduler jest prerequisitem dla cache flush, email queue, read pruning | Wysoka |
| Rejestracja użytkownika nie istnieje w żadnym milestone'ie | Wysoka |
| S.1 i S.2 security reviews są zaległe po M3 i M6 | Wysoka |
| Extension system ma tylko ADR (decyzję), nie ma milestone'a implementacji | Wysoka |

### Sprzeczności i niespójności

- **M6 (Threads)** liczy posty, ale nie śledzi *przeczytanych* postów — co sprawia, że UI „nowych postów" jest niemożliwe bez osobnego systemu (brak w planie)
- **M8 (Notifications)** implementuje polling HTTP, ale watchowanie topiku przez email (inna ścieżka) nie jest nigdzie zaplanowane
- **M5b (Storage)** daje infrastrukturę plików, ale awatary jako flow użytkownika (upload + resize + powiązanie z kontem) nie mają milestone'a
- **M2 (User)** przechowuje kolumny preferencji w modelu, ale żaden milestone nie dostarcza API ani UI preferencji użytkownika
- **M11 (BBCode)** zaplanuje rendering, ale cenzura słów (content filter) jako moderacyjna warstwa nie jest pokryta

---

## 2. Wzorce i tematy

### Wzorzec 1: „Infrastruktura bez user journey"
M2–M6 zbudowały solidne serwisy backendowe, ale brak kluczowych *przepływów użytkownika* na ich szczycie:
- Jest user entity → brak rejestracji, profilu publicznego, preferencji UCP
- Jest file storage → brak awatarów, zarządzania załącznikami z UCP
- Jest notification service → brak email watchingu
- Dotyczy: rejestracja, awatary, preferencje, katalog użytkowników, subskrypcje

### Wzorzec 2: „Zalegające przeglądy bezpieczeństwa"
S.1 (auth) i S.2 (full API) trigger events minęły (po M3 i M6.x), ale żaden nie był wykonany. 22 zadania ⏳ w bezpieczeństwie i load testach.

### Wzorzec 3: „Brakujące prerequisity"
Kilka planowanych funkcji M9–M14 zakłada istnienie serwisów, które nie mają własnych milestone'ów:
- M14 (Admin) będzie potrzebować Config (M13) — OK, jest
- Każdy email feature potrzebuje Email Service — **nie ma milestone'a**
- M10 (React SPA) będzie potrzebować i18n — **nie ma milestone'a**
- Cron (brak) jest prerequisitem dla M1.9 (Redis flush), M6.6 (counter flush), email queue

### Wzorzec 4: „Frontend odłożony na koniec"
M10 (React SPA) jest planowane jako jedno duże milestone po M9, ale zawiera ogromny zakres (design system + auth + wszystkie widoki + notifications UI + messaging UI). Ryzyko scope creep.

---

## 3. Kluczowe spostrzeżenia

### Spostrzeżenie 1: Forum nie może zdobywać użytkowników
Bez przepływu rejestracji (brak w jakimkolwiek milestone), systemu email (brak), CAPTCHA (brak) — forum nie może onboardować nowych użytkowników. To **bloker MVP**.

### Spostrzeżenie 2: Cron jest cichym prerequisitem
6 zaplanowanych lub częściowo zaplanowanych funkcji (email queue, counter flush, search reindex, read-mark pruning, session pruning) zależy od cron scheduler — którego nie ma w żadnym milestone.

### Spostrzeżenie 3: 27 funkcji phpBB3 jest całkowicie poza planem
Poza ⏳ zadaniami *w planie*, is 27 odrębnych obszarów funkcjonalnych z phpBB3 (14 HIGH+MEDIUM impact), które nie mają ani jednego zadania w M0–M14.

### Spostrzeżenie 4: Security debt narasta
S.1 i S.2 dotyczą systemu przechowującego sesje użytkowników i treści publiczne. Im więcej funkcji dojdzie przed ich wykonaniem, tym większy surface attack.

### Spostrzeżenie 5: Extension system = strategiczne ryzyko
phpBB jest ekosystemem zależnym od rozszerzeń. ADR wybrał model (macrokernel), ale żaden milestone nie implementuje frameworku rozszerzeń. Bez niego phpBB4 jest systemem zamkniętym.

---

## 4. Priorytety (MVP vs. parity)

### Krytyczne dla MVP (forum nie działa bez tego)
1. Rejestracja + weryfikacja email + CAPTCHA
2. System emaili (messenger + queue)
3. Cron / scheduled tasks
4. Read tracking (znaczniki „nowych postów")
5. Awatary (tożsamość wizualna użytkownika)

### Ważne dla feature parity
6. Katalog użytkowników / profile publiczne
7. Preferencje użytkownika (strefa czasowa, język, styl)
8. Topic/forum watching (subskrypcje email)
9. RSS/Atom feeds
10. Subskrypcje grup (UCP)
11. Podpisy użytkowników
12. Extension system

### Miło mieć (quality-of-life)
13. Who's online
14. Rangi użytkowników
15. Znajomi/wrogowie (Zebra)
16. Szkice postów
17. System ostrzeżeń
18. Notatki moderatora
19. Cenzura słów
20. Zarządzanie pakietami językowymi
21. FAQ / pomoc BBCode
22. Masowe emaile (ACP)
23. Formularz kontaktu z adminem
24. Boty / zarządzanie pająkami
25. Pruning forum i użytkowników

---

## 5. Luki i niepewności

- Nie zbadano, czy istnieje jakakolwiek cząstkowa implementacja email queue poza MILESTONES.md
- M11 scope nie specyfikuje explicite, ile `rendering` pokrywa — czy cenzura słów mogłaby być pluginem s9e?
- M10 scope jest ogromny; nie jest jasne, czy planowane jest podzielenie go na sub-milestone'y
- Liczba konkretnych zadań w M11 nie jest podana w MILESTONES.md — szacunek ~7 zadań

---

## 6. Wnioski

**Główny wniosek**: Plan M0–M14 dostarcza solidny backend API, ale pomija około połowy funkcjonalności potrzebnych do działającego forum publicznego. Luki koncentrują się w czterech obszarach: *lifecycle użytkownika* (rejestracja, email, captcha), *infrastruktura tła* (cron, email queue), *funkcje społecznościowe* (katalog, RSS, watching) oraz *system rozszerzeń*. Zalegające przeglądy bezpieczeństwa (22 zadania) stanowią rosnące ryzyko.

**Rekomendacja**: Przed M9 lub równolegle zaplanować M15 (Rejestracja + Email + Cron) jako prerequisit. Reszta luk może wejść do M15–M20 w kolejnych cyklach planowania.

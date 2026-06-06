# SpiderCMS

**Ultra-lekki, plikowy system zarządzania treścią (Flat-File CMS) napisany w PHP.**

SpiderCMS to prosty, szybki i w pełni plikowy CMS, który nie wymaga bazy danych. Został stworzony z myślą o wydajności, łatwości obsługi i pełnej kontroli nad kodem.

---

## ✨ Główne funkcje

- **Całkowicie plikowy** – nie potrzebuje MySQL ani żadnej bazy danych
- Nowoczesny, ciemny panel administracyjny w neonowym stylu
- **Dynamiczna stopka** – dowolna liczba kolumn, edytowalna z poziomu panelu
- Pełna personalizacja motywu (kolory, logo, czcionki, wymiary, cienie)
- Wbudowany edytor **TinyMCE** z gotowymi blokami (hero, galerie, FAQ, karty, kolumny itp.)
- Biblioteka mediów z możliwością wgrywania zdjęć i plików
- Zarządzanie menu nawigacyjnym (włącz/wyłącz + edycja pozycji)
- Ustawianie dowolnej strony jako strony głównej
- Eksport całej witryny do pliku ZIP jednym kliknięciem
- Zmiana hasła administratora bezpośrednio z panelu
- Automatyczne propagowanie zmian kolorów na wszystkich stronach
- Ochrona logowania (blokada po zbyt wielu nieudanych próbach + hashowanie Argon2id)

---

## Wymagania

- PHP 7.4 lub nowszy
- Serwer z możliwością zapisu plików
---

## Instalacja

1. Skopiuj wszystkie pliki do głównego folderu na serwerze 
2. Ustaw uprawnienia zapisu na:
   - katalog `pages/`
   - katalog `uploads/`
   - pliki zaczynające się od `.` (`.settings.json`, `.theme.json`, `.footer.json` itp.)
3. Otwórz w przeglądarce adres: `twojadomena.pl/admin.php`
4. Domyślne hasło: **`admin2026`**

**Zalecane:** Po pierwszym zalogowaniu zmień hasło w zakładce **Ustawienia**.


---

## Dla kogo jest SpiderCMS?

- Osób szukających lekkiej alternatywy dla WordPressa
- Freelancerów i agencji tworzących proste strony www
- Portfolio, stron firmowych, landing page’i i małych blogów
- Projektów, w których liczy się szybkość działania i prostota utrzymania

---

## Autor

Kamil Paprota

---

## Licencja

Projekt jest open-source. Możesz go używać prywatnie i komercyjnie.

---

**Staruj repozytorium, jeśli projekt Ci się podoba!**  
Pull Requesty oraz sugestie są mile widziane.

# 🕷 SpiderCMS

> Nowoczesny, plikowy CMS bez bazy danych — szybki, lekki i w pełni konfigurowalny.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![Flat File](https://img.shields.io/badge/Database-None-success)
![Status](https://img.shields.io/badge/Status-Active-brightgreen)
![License](https://img.shields.io/badge/License-Open--Source-blue)

SpiderCMS to nowoczesny system zarządzania treścią (**Flat-File CMS**) napisany w PHP.

Nie wymaga MySQL ani żadnej bazy danych — wszystkie dane przechowywane są w plikach, dzięki czemu instalacja trwa kilka minut, a utrzymanie projektu jest proste i szybkie.

---

# ✨ Najważniejsze funkcje

## 🎨 Edytor i wygląd

* Panel administracyjny w stylu Dark / Neon
* Dynamiczny nagłówek i stopka
* Logo + nazwa witryny w nagłówku
* Edycja stylu nazwy strony
* Regulowana szerokość treści
* Presety motywów
* Presety gotowych stron
* Responsywny interfejs panelu

---

## 📄 Zarządzanie stronami

* Tworzenie stron
* Edycja stron
* Duplikowanie stron
* Edycja nazwy po duplikacji
* Ustawianie strony głównej
* Własne foldery stron
* Import / eksport witryny

---

## ✨ Edytor LIVE

Opcjonalna edycja strony bezpośrednio w podglądzie:

* edycja tekstu,
* zmiana obrazów,
* sekcje HERO,
* CTA,
* FAQ,
* podgląd desktop / tablet / mobile,
* cofanie zmian,
* zapis skrótem `CTRL + S`,
* automatyczne kopie.

---

## 🖼 Slider Builder

Tworzenie sliderów:

* wiele zdjęć,
* wybór z galerii,
* responsywne obrazy,
* shortcode:

```txt
[slider id="hero"]
```

---

## 💬 Chat

* formularz wiadomości,
* historia rozmów,
* archiwum,
* powiadomienia e-mail,
* SMTP,
* antyspam.

---

## 📈 Statystyki

* odsłony,
* użytkownicy,
* popularne strony,
* wykresy,
* aktywni użytkownicy,
* eksport.

---

## 🔐 Bezpieczeństwo

* brak bazy danych,
* Argon2id,
* blokada brute force,
* logi działań,
* ochrona uploadów,
* zabezpieczenia `.htaccess`,
* blokada wykonywania PHP,
* bezpieczne sesje.

---

# 📁 Struktura projektu

```txt
SpiderCMS
├── admin.php
├── pages/
├── uploads/
├── assets/
├── .chat/
├── .stats/
├── .logs/
├── .backups/
├── .theme.json
├── .settings.json
└── README.md
```

---

# 🚀 Instalacja

1. Wgraj pliki na serwer.
2. Nadaj zapis:

```txt
pages/
uploads/
.logs/
.stats/
.backups/
```

3. Otwórz:

```txt
twojadomena.pl/admin.php
```

4. Zaloguj się.

Po pierwszym uruchomieniu:

* zmień hasło,
* ustaw motyw,
* wybierz stronę główną.

---

# 🧩 Roadmap

* [ ] System wtyczek
* [ ] Historia zmian
* [ ] Wersjonowanie stron
* [ ] Marketplace motywów
* [ ] Wielojęzyczność

---

# 👨‍💻 Autor

**Kamil Paprota**

---

# ⭐ Wsparcie projektu

Jeżeli SpiderCMS Ci się podoba:

⭐ zostaw gwiazdkę
🐞 zgłoś problem
🧩 zaproponuj funkcję
🚀 rozwijaj projekt razem z nami

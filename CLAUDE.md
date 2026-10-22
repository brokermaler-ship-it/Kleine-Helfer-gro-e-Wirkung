# EMC 78 Lokschuppen – Hinweise für Claude

Private Web-App für den Modellbahnclub EMC 78 Halle e. V. (Fahrzeuge, Anlagen, Wartung, Reparaturen, Magazin, Modellbahnbörse). Betrieb bei STRATO (mitglieder.emc78.de, Testumgebung test.emc78.de); installiert wird sie vom STRATO-Administrator des Vereins aus fertigen Paketen. Stand und nächste Schritte: `docs/UEBERGABE.md`.

## Sprache und Stil
- Mit dem Nutzer Deutsch, kurz und verständlich (Nicht-Entwickler). Oberfläche, Commit-Texte, Kommentare: Deutsch.
- Code wie der umgebende Code: deutsche Bezeichner, wenig Kommentare, nur wo das Warum nicht offensichtlich ist.

## Technik
- PHP 8.2+ (STRATO läuft mit 8.4), kein Framework, PDO, MariaDB 10.6+/MySQL 8. Front-Controller `public/index.php`, Routen `src/routes.php`, Verdrahtung `src/Core/App.php`.
- Migrationen `migrations/NNN_*.sql` (fortlaufend, nie ändern, nur neue); `App::brauchtNeuesSchema` leitet neue Routen vor dem Update zur Update-Seite.
- Rollen: Mitglied 1, Fahrzeugwart 2, Bearbeiter 3, Admin 4 (`src/Auth/Berechtigung.php`); Börsenwart ist ein Häkchen am Benutzer.
- Transaktionen mit `Core\Transaktion::readCommitted`; Sperrkonflikte 1205/1213 werden zur Meldung „Bitte erneut versuchen“. Sperr-Reihenfolge: Börse → Angebot → Sammlung → Artikel; Fahrzeuge zuerst.
- Geld nie als float (DECIMAL-Strings bzw. Cent, `Geld`). Änderungen ins `Aenderungsprotokoll`.
- Uploads: Bilder clientseitig verkleinert (`public/assets/app.js`), öffentliche Uploads serverseitig neu kodiert (EXIF weg).

## Tests
- TDD. Ganze Suite: `TEST_DB_PASSWORT=<pw> vendor/bin/phpunit` (Standard-DB `emc_test` auf 127.0.0.1, Benutzer root; anpassbar über TEST_DB_DSN / TEST_DB_BENUTZER / TEST_DB_PASSWORT). Vor jedem Commit grün.
- `DbTestCase` hält eine Sperre je Datenbank: parallele Läufe (z. B. Prüfer) brauchen eine eigene DB über TEST_DB_DSN.

## Composer
- Nur `--prefer-dist`. Vor und nach jedem Composer-Lauf `git remote -v` prüfen (origin muss https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung bleiben).

## Datenschutz und Geheimnisse (verbindlich)
- Niemals Passwörter (DB, SMTP, Admin) erfragen, in den Chat schreiben oder committen. Geheimnisse nur in `config.php` (nicht im Repo).
- Keine echten Personennamen, Telefonnummern, E-Mail-Adressen oder OneDrive-Freigabelinks ins Repo; Beispiel- und Testdaten sind erfunden.
- Echte Vereinslisten (z. B. Fahrzeug-Import-CSV mit Besitzernamen) bleiben außerhalb des Repos.
- Tokens nur gehasht/verschlüsselt speichern. Dateien nur nach Anmeldung, außer dem freigegebenen öffentlichen Börsenbereich (standardmäßig aus).

## Releases
- Ablauf siehe `werkzeuge/README.md` (`bin/release.sh`, PDF-Anleitung, `werkzeuge/paket-thomas.sh`).
- Keine Modellbezeichnungen in Commits oder Dateien. Commit-Trailer wie in den bisherigen Commits (`git log`).
- Kein Pull Request ohne ausdrücklichen Wunsch.

# EMC 78 Lokschuppen

Webanwendung „EMC 78 Lokschuppen“ des Eisenbahn Modellbahn Clubs 78 e.V. Halle („Die Modellbahnfamilie“) zur Verwaltung des Fahrzeugbestands: Fahrzeuge, Decoder (Adressen, Funktionstasten – auch aus Hersteller-PDFs –, CVs), Fotos und Dokumente, Anlagen, Wartung mit Fälligkeits-Ampel, Defektmeldungen und Reparaturen, kurze Codes mit QR-Etiketten, das **Magazin** (Inventar des Vereins) und die **Modellbahnbörse** mit Kasse am Handy und einem abschaltbaren öffentlichen Bereich, Personen und Benutzer. Die Anwendung läuft auf normalem PHP-Webspace und ist für PC, Tablet und Handy gedacht.

## Rollen und Rechte

Die Rollen bauen aufeinander auf; jede höhere Rolle darf alles, was die darunter darf.

| Rolle | Darf |
|---|---|
| **Mitglied** | alles lesen (Fahrzeuge, Decoder, Funktionen, CVs, Dateien, Anlagen, Wartungen und Fälligkeiten, Reparaturen); **Defekte melden** an jedem Fahrzeug und jeder Anlage; **Wartungen eintragen und Reparaturen bearbeiten an eigenen Fahrzeugen** (Eigentümer oder selbst angelegt); **eigene Fahrzeuge anlegen** (der Eigentümer ist immer das Mitglied selbst); die **selbst angelegten Fahrzeuge bearbeiten**, einschließlich Status (auch „ausgemustert“), Decoder, Funktionstasten (Formular, CSV-Import, PDF-Import, Vorlagen), CVs sowie Fotos und Dateien dieses Fahrzeugs. Nicht erlaubt: Eigentümer ändern, Fahrzeuge löschen, Fahrzeuge anderer bearbeiten (auch nicht als Eigentümer), Personen verwalten |
| **Fahrzeugwart** | zusätzlich **alles rund um alle Fahrzeuge, auch die privaten**: anlegen (Eigentümer frei wählbar), bearbeiten, **löschen**, Decoder, Funktionstasten, CVs sowie Fotos und Dokumente an Fahrzeugen; **Wartungen an allen Fahrzeugen** eintragen und löschen, den Wartungsplan der Fahrzeuge anpassen und die **Wartungsarten** pflegen (Verwaltung → Wartungsarten); **Reparaturen an allen Fahrzeugen** bearbeiten, sieht den Hinweis auf neue Defektmeldungen an Fahrzeugen; **Etiketten drucken** (Verwaltung → Etiketten). Nicht erlaubt: Personen, Benutzer, Stammdaten und Betrieb (Sicherung, Update, OneDrive); eine Datei, die mehreren Objekten zugeordnet ist, löscht nur ein Bearbeiter |
| **Bearbeiter** | zusätzlich Personen verwalten, **alle** Dateien (auch geteilte) löschen, **Anlagen** anlegen, bearbeiten und löschen sowie Wartungen, Wartungsplan und **Reparaturen der Anlagen** (mit Hinweis auf neue Defektmeldungen an Anlagen) |
| **Administrator** | zusätzlich Benutzer und Rollen, Stammdaten (Spurweiten, Magazin-Kategorien), Datensicherung, Update und OneDrive-Anbindung; eine abgeschlossene Börse wieder öffnen |

**Börsenwart** ist keine Rolle, sondern ein **Häkchen am Benutzerkonto** (Verwaltung → Benutzer), unabhängig von der Rolle – auch ein Mitglied oder ein Fahrzeugwart kann Börsenwart sein; ein Fahrzeugwart ohne Häkchen ist es nicht. Bearbeiter und Administratoren dürfen ohnehin alles, was der Börsenwart darf:

| | Darf |
|---|---|
| **alle Mitglieder** | Magazin und Börse lesen; **eigene Börsenangebote** anlegen, bearbeiten, zurückziehen und zur Veröffentlichung vorschlagen |
| **Börsenwart** (Häkchen, sowie Bearbeiter und Administrator) | zusätzlich im **Magazin buchen** (Eingang, Ausgang, Umlagerung, Gegenbuchung), Sammlungen bilden, Magazinartikel in die Börse übernehmen, Angebote prüfen und **öffentlich freigeben**, Börsen anlegen, **Kasse**, Storno, Abrechnung und Auszahlung, Börse abschließen, **Börsenanfragen** bearbeiten; ausgemusterte Vereinsfahrzeuge **ans Magazin übergeben** (das darf auch jeder Fahrzeugwart) |

Jedes Fahrzeug merkt sich, wer es angelegt hat. Fahrzeuge, die schon vor Version 0.2.0 vorhanden waren, gelten als von einem Bearbeiter angelegt; Mitglieder können sie nicht ändern. Alle Rechte werden auf dem Server für jedes einzelne Fahrzeug geprüft, nicht nur durch ausgeblendete Schaltflächen. Eine Datei, die mehreren Objekten zugeordnet ist, kann nur ein Bearbeiter löschen. Ausgemusterte Fahrzeuge stehen auf dem **Abstellgleis** (siehe dort); sie verschwinden aus „Meine Fahrzeuge“ und der Fahrzeugliste. Gespeicherte Funktionsvorlagen sind für alle sichtbar.

Beim Update auf 0.2.0 muss nach dem Hochladen der neuen Dateien einmal im Verwaltungsmenü unter „Update“ die Datenbank-Migration `003_fahrzeug_angelegt_von` ausgeführt werden.

Version 0.3.0 bringt die freiwillige OneDrive-Ablage (siehe „OneDrive anbinden“). Beim Update auf 0.3.0 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei laufen die Migrationen `004_onedrive`, `005_onedrive_auftrag_sperre` und `006_onedrive_sperr_token_index`. Ohne OneDrive-Einträge in der `config.php` ändert sich sonst nichts.

Version 0.3.1 verbessert die OneDrive-Ablage: Jede übertragene Datei merkt sich, in welchem OneDrive sie liegt (sie bleibt auch nach einem Wechsel des Zielordners abrufbar und löschbar), liegen gebliebene Übertragungen werden auch ohne Cronjob nebenbei nachgeholt, und der Cronjob läuft auch, wenn der Hoster PHP dafür als `php-cgi` startet (in 0.3.0 brach `bin/onedrive-sync.php` mit einem Syntaxfehler ab). Beim Update auf 0.3.1 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei läuft die Migration `007_onedrive_laufwerk`. Wer direkt von 0.2.x kommt, bekommt 004–007 in einem Schritt.

Version 0.4.0 bringt die Rolle **Fahrzeugwart**, die **Löschung einer Person nach DSGVO** (siehe „Person nach DSGVO löschen“) und das **Abstellgleis** für ausgemusterte Fahrzeuge (siehe „Abstellgleis“). Beim Update auf 0.4.0 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei laufen die Migrationen `008_rolle_fahrzeugwart` und `009_abstellgleis`. Bestehende Zugänge behalten ihre Rolle; bereits ausgemusterte Fahrzeuge bekommen als Abstelldatum den Tag ihrer letzten Änderung. Wer von 0.3.x oder älter kommt, bekommt alle fehlenden Migrationen (bis 009) in einem Schritt.

Version 0.5.0 bringt **„Kennwort vergessen?“** auf der Anmeldeseite (siehe „Kennwort vergessen“), eine einfache **Anlagenliste** (siehe „Anlagen“), die **Wartung** mit Wartungsarten, Wartungsplan, Wartungsprotokoll und Fälligkeits-Ampel (siehe „Wartung“) und eine neue **Startseite** mit der Kachel „Wartung“. Beim Update auf 0.5.0 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei laufen die Migrationen `010_kennwort_vergessen`, `011_anlage`, `012_wartungsart` (legt acht Wartungsarten als Startbestand an) und `013_wartung`. Vorhandene Daten bleiben unverändert. Wer von 0.4.0 oder älter kommt, bekommt alle fehlenden Migrationen (bis 013) in einem Schritt. Der E-Mail-Versand ist freiwillig: Ohne die neuen `mail_*`-Einträge in der `config.php` landen Anfragen bei den Administratoren.

Version 0.6.0 bringt **„Defekt melden“** am Handy (siehe „Defekt melden“), **Reparaturen** mit Ersatzteilen, Kosten und automatischem Fahrzeug- bzw. Anlagenstatus (siehe „Reparaturen“), den Hinweis „neue Defektmeldungen“ und die Kachel „Offene Defekte“, kurze **Codes** `F12`/`A3` mit dem Feld „Code“ in der Kopfzeile und dem Kurzlink `/c/<Code>` (siehe „Codes“) sowie **Etiketten** zum Drucken (siehe „Etiketten“). Beim Update auf 0.6.0 zuerst **alle Dateien** hochladen (auch den Ordner `vendor/` – er enthält die neue Bibliothek für die QR-Codes), dann unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei laufen die Migrationen `014_reparatur` (Reparaturen, Ersatzteile, „gesehen“-Vermerke, Kennzeichen „Status automatisch“ an Fahrzeugen und Anlagen) und `015_etikett` („Etikett gedruckt am“). Vorhandene Daten bleiben unverändert; alle vorhandenen Status gelten als von Hand gesetzt, und alle Fahrzeuge und Anlagen stehen bei den Etiketten unter „noch nicht gedruckt“. Wer von 0.5.0 oder älter kommt, bekommt alle fehlenden Migrationen (bis 015) in einem Schritt. Für die QR-Codes auf den Anlagen-Etiketten in der `config.php` `oeffentliche_url` setzen (siehe „Etiketten“).

Version 0.7.0 liest **Funktionstasten aus PDF-Anleitungen** (siehe „Funktionstasten aus PDF“) und erlaubt Tasten bis **F68** (bisher F31). Beim Update auf 0.7.0 **alle Dateien** hochladen, auch den Ordner `vendor/` – er enthält die neue Bibliothek `smalot/pdfparser`. Eine Datenbank-Änderung gibt es nicht. Außerdem: Wird ein Fahrzeug oder eine Anlage gelöscht, während jemand gerade einen Defekt daran meldet, wird die Meldung mitgelöscht (bzw. die Meldung bekommt „nicht gefunden“) statt verwaist liegen zu bleiben; Meldungen, deren Fahrzeug bzw. Anlage es nicht mehr gibt (aus älteren Versionen), zählen nicht mehr bei „neue Defektmeldungen“, „Offene Defekte“, „Reparaturen“ und „Meine Meldungen“.

Version 0.8.0 bringt **Magazin und Modellbahnbörse** (siehe „Magazin“, „Modellbahnbörse“, „Öffentlicher Bereich der Börse“). Beim Update alle Dateien hochladen und danach unter Verwaltung › Update das Datenbank-Update ausführen (Migrationen 016–019: `016_magazin` mit dem Börsenwart-Häkchen und den neun Magazin-Kategorien, `017_boerse`, `018_titelbild_index` – Indizes, damit das Löschen von Fotos schneller geht und weniger sperrt – und `019_boerse_anfrage`). Der öffentliche Bereich bleibt aus, bis `boerse_oeffentlich` und `datenschutz_url` in der config.php gesetzt sind. Vorhandene Daten bleiben unverändert; zunächst hat niemand das Börsenwart-Häkchen (Bearbeiter und Administratoren dürfen trotzdem alles). Wer von 0.7.0 oder älter kommt, bekommt alle fehlenden Migrationen (bis 019) in einem Schritt. Optional einen täglichen Cronjob `php bin/boerse-aufraeumen.php` einrichten (siehe „Öffentlicher Bereich der Börse“).

## Voraussetzungen

- PHP ab Version 8.2 mit den Erweiterungen `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `zip`, `iconv` und `zlib` (die beiden letzten braucht der PDF-Import; sie sind bei praktisch jedem Hoster aktiv), für die OneDrive-Ablage zusätzlich `curl` und `openssl`
- MySQL ab Version 8 oder MariaDB ab Version 10.6. Die App schreibt Reparaturen in Transaktionen mit `READ COMMITTED`; das verträgt sich nicht mit dem seltenen Binärlog-Format `binlog_format = STATEMENT` (Speichern scheitert dann mit Fehler 1665). Die Standardwerte (MySQL 8: `ROW`, MariaDB: `MIXED`) und Webspace ohne eigenes Binärlog sind in Ordnung.
- HTTPS (Pflicht, weil Anmeldungen darüber laufen)
- FTP-Zugang zum Webspace und Zugang zur Datenbankverwaltung des Hosters (z. B. phpMyAdmin)

## Installation

Allgemein gilt für jeden Webspace (die Schritte für STRATO stehen im nächsten Abschnitt):

1. Die Datei `emc78-verwaltung-<Version>.zip` auf dem eigenen Computer entpacken.
2. Alle entpackten Dateien und Ordner per FTP/SFTP hochladen (z. B. in ein eigenes Verzeichnis oder eine Subdomain).
3. Im Menü des Hosters eine **leere Datenbank** mit Benutzer und Passwort anlegen und die Zugangsdaten notieren.
4. Die Datei `config.example.php` als `config.php` kopieren und ausfüllen: Datenbank-DSN (`mysql:host=...;dbname=...;charset=utf8mb4`), Benutzer, Passwort, `basis_url` (leer lassen, wenn die Anwendung im Hauptverzeichnis der Domain liegt, sonst z. B. `/mitglieder`).
5. Wenn möglich, im Hostermenü den **Document-Root auf den Ordner `public/`** legen. Das ist die sicherste Einstellung. Geht das nicht, leitet die mitgelieferte `.htaccess` im Hauptordner alles nach `public/` um und sperrt die übrigen Ordner (`src`, `storage`, `vendor` …).
6. Die Adresse der Seite im Browser aufrufen. Es erscheint automatisch die Seite `/install`: Dort den ersten Administrator anlegen. Die Datenbank wird dabei eingerichtet.

## Installation bei STRATO als Subdomain mitglieder.emc78.de

Die Anwendung soll unter **https://mitglieder.emc78.de** erreichbar sein. Die Menünamen im STRATO-Kundenbereich ändern sich gelegentlich; die folgenden Bezeichnungen sind deshalb sinngemäß zu verstehen.

1. **PHP-Version:** Im STRATO-Kundenbereich (Menüpunkt sinngemäß „Paket-Verwaltung“ bzw. „Einstellungen“ → „PHP-Einstellungen“ oder „PHP-Version“) eine **PHP-Version ab 8.2** wählen und speichern. Falls Auswahlfelder für Erweiterungen angeboten werden: `gd`, `fileinfo`, `zip`, `mbstring` und `pdo_mysql` müssen aktiv sein (meist sind sie es schon).
2. **Datenbank:** Im Kundenbereich (Menüpunkt sinngemäß „Datenbanken und Webspace“ → „Datenbanken“ bzw. „MySQL-Datenbank“) eine **neue MySQL-Datenbank anlegen**. Notieren: Datenbankname, Benutzername, Passwort und den **Datenbank-Server** (bei STRATO ein eigener Servername, nicht `localhost`). Der Server steht in der Datenbankübersicht.
3. **SFTP-Zugang:** Im Kundenbereich (sinngemäß „SFTP-Zugang“ bzw. „FTP-Zugang“ im Bereich Webspace) einen SFTP-Zugang einrichten bzw. das Passwort setzen. Mit einem Programm wie FileZilla oder WinSCP verbinden.
4. **Dateien hochladen:** Das entpackte Verzeichnis des Release-ZIPs vollständig in einen neuen Ordner `/mitglieder/` auf dem Webspace hochladen. Es entstehen z. B. `/mitglieder/public`, `/mitglieder/src`, `/mitglieder/vendor`, `/mitglieder/storage`.
5. **Subdomain anlegen:** Im Kundenbereich (sinngemäß „Domains“ → „Subdomains“ bzw. „Domain verwalten“) die **Subdomain `mitglieder.emc78.de`** anlegen und als **Ziel-Verzeichnis `/mitglieder/public`** eintragen. Damit liegt nur der Ordner `public/` im Netz, alle anderen Ordner sind von außen nicht erreichbar. (Die Subdomain kann einige Minuten bis Stunden brauchen, bis sie erreichbar ist.)
6. **SSL aktivieren:** Im Kundenbereich (sinngemäß „Domains“ → „SSL-Verwaltung“ bzw. „SSL-Zertifikate“) für die Subdomain ein **SSL-Zertifikat aktivieren** (bei STRATO gibt es ein kostenloses Standard-Zertifikat) und zuweisen. Erst wenn `https://mitglieder.emc78.de` ohne Warnung im Browser öffnet, geht es weiter.
7. **`config.php` anlegen:** `/mitglieder/config.example.php` herunterladen, als `config.php` speichern und ausfüllen:
   - `db_dsn`: `mysql:host=<Datenbank-Server>;dbname=<Datenbankname>;charset=utf8mb4`
   - `db_benutzer` und `db_passwort`: die Zugangsdaten aus Schritt 2
   - `basis_url`: leer lassen (die Subdomain zeigt direkt auf `public/`)
   - `cookie_secure`: `true` lassen
   
   Die fertige Datei nach `/mitglieder/config.php` hochladen (nicht in `public/`).
8. **Installieren:** `https://mitglieder.emc78.de/install` aufrufen und den ersten Administrator anlegen. Danach `https://mitglieder.emc78.de` öffnen und anmelden.

Prüfen, ob der Ordner `/mitglieder/storage` für PHP beschreibbar ist (Foto hochladen). Wenn nicht, dem Ordner per SFTP-Programm die Schreibrechte `775` geben.

## Alternative: Unterordner (`basis_url` = `/mitglieder`)

Kann keine Subdomain eingerichtet werden, läuft die Anwendung auch in einem Unterordner der Hauptdomain, z. B. `https://emc78.de/mitglieder`:

1. Das entpackte Release-Verzeichnis nach `/mitglieder/` im Webspace der Hauptdomain hochladen.
2. In der `config.php` `basis_url` auf `/mitglieder` setzen.
3. Die mitgelieferte `.htaccess` im Ordner `/mitglieder/` leitet alle Aufrufe nach `public/` um und sperrt `src`, `storage`, `vendor` und die übrigen Ordner. Bitte nach dem Hochladen testen, dass z. B. `https://emc78.de/mitglieder/storage/` und `https://emc78.de/mitglieder/config.php` **nicht** abrufbar sind.
4. `https://emc78.de/mitglieder/install` aufrufen.

Die Installation auf dem Handy (siehe unten) funktioniert auch im Unterordner.

## Kennwort vergessen

Auf der Anmeldeseite führt **„Kennwort vergessen?“** zu einem Formular für die E-Mail-Adresse. Die Antwort ist immer dieselbe, gleich ob die Adresse bekannt ist oder nicht (so lässt sich nicht ausforschen, wer ein Konto hat). Nur für ein bekanntes, aktives Konto passiert etwas:

- **Mit Postfach** (E-Mail-Versand eingerichtet): Die Person bekommt eine E-Mail mit einem Link zum Zurücksetzen. Der Link ist **60 Minuten** gültig und funktioniert **nur einmal**; ein neu angeforderter Link macht den alten ungültig. Nach dem Setzen des neuen Kennworts (mindestens 10 Zeichen) ist man angemeldet, alle anderen Sitzungen des Kontos enden.
- **Ohne Postfach** oder wenn der Versand scheitert: Es entsteht eine **Anfrage an die Administratoren**. Sie sehen oben den Hinweis „1 Anfrage auf neues Kennwort“ und unter **Verwaltung → Benutzer** Name und Zeitpunkt. Ein Administrator setzt dann unter „Bearbeiten“ ein Startpasswort und gibt es der Person persönlich weiter (damit ist die Anfrage erledigt) oder verwirft die Anfrage.

Je E-Mail-Adresse sind höchstens 3, je Internetanschluss höchstens 10 Anfragen pro Stunde möglich; gespeichert werden dafür nur Prüfsummen, keine Adressen oder IP-Adressen im Klartext.

**E-Mail-Versand einrichten** (in der `config.php`, alle Einträge freiwillig; siehe Abschnitt „E-Mail-Versand“ in `config.example.php`):

| Schlüssel | Bedeutung |
|---|---|
| `mail_smtp_host` | SMTP-Server des Postfachs, nur der Hostname (STRATO: `smtp.strato.de`) |
| `mail_smtp_verschluesselung` | `ssl` (Standard, verschlüsselt ab Verbindungsbeginn) oder `tls` (STARTTLS) |
| `mail_smtp_port` | `465` für `ssl`, `587` für `tls`; `0` wählt den Port automatisch |
| `mail_smtp_benutzer` | Anmeldename am SMTP-Server, meist die vollständige E-Mail-Adresse |
| `mail_smtp_passwort` | Kennwort des Postfachs (nur in der `config.php`) |
| `mail_absender` | Absenderadresse, meist gleich dem Postfach |
| `mail_absender_name` | angezeigter Absendername (Standard „EMC 78 Lokschuppen“) |

Der Versand gilt als eingerichtet, sobald `mail_smtp_host`, `mail_absender` und `oeffentliche_url` (z. B. `https://mitglieder.emc78.de`) gesetzt sind; aus `oeffentliche_url` und `basis_url` entsteht der Link in der E-Mail. Beispiel für ein STRATO-Postfach: `mail_smtp_host` = `smtp.strato.de`, `mail_smtp_port` = `465`, `mail_smtp_verschluesselung` = `ssl`, Benutzer und Absender = die Postfachadresse.

**In der Testumgebung** (`'testumgebung' => true`) ohne eingerichtetes Postfach zeigt die Antwortseite für ein bekanntes Konto zusätzlich den Link zum Zurücksetzen an, damit sich der Ablauf ohne E-Mail ausprobieren lässt; die Anfrage an die Administratoren entsteht trotzdem. Im Echtbetrieb erscheint der Link nie auf der Seite.

## Anlagen

Unter **Anlagen** (Hauptmenü) stehen die Modellbahnanlagen des Vereins mit **Code `A<n>`** (automatisch fortlaufend, A1, A2 …; eine gelöschte Nummer wird nie wieder vergeben), Name, Spurweite, Kurzbeschreibung, Standort und Status („in Ordnung“, „defekt“, „in Arbeit“). Fotos und Dokumente lassen sich wie bei Fahrzeugen anhängen; das erste Foto wird Titelbild. Lesen dürfen alle, anlegen, bearbeiten und löschen ab **Bearbeiter**. Im OneDrive liegen die Dateien unter `Anlagen/A<n> <Name>/`. Stufe 3 baut die Anlagen später weiter aus; die Daten bleiben dabei erhalten.

## Wartung

**Wartungsarten** (Verwaltung → Wartungsarten, ab Fahrzeugwart) legen fest, was wie oft zu tun ist: Name, gilt für (Fahrzeuge alle, Lok/Triebwagen, Wagen oder Anlagen), Intervall in **Monaten und/oder Betriebsstunden** (mindestens eines; Anlagen nur Monate). Der Startbestand (z. B. „Räder und Radschleifer reinigen“ alle 6 Monate oder 20 h, „Gleise reinigen“ alle 3 Monate) lässt sich ändern und ergänzen. Nicht mehr benötigte Arten bitte **deaktivieren**; löschen geht nur, solange eine Art nie eingetragen wurde.

**Wartungsplan:** Eine Wartungsart gilt automatisch für alle passenden Objekte; ausgemusterte Fahrzeuge zählen nicht. Unter „Wartungsplan anpassen“ (auf der Fahrzeug- bzw. Anlagenseite) lässt sich je Objekt eine Art **abschalten** oder ein **eigenes Intervall** setzen (es ersetzt beide Werte der Art). Das dürfen bei Fahrzeugen Fahrzeugwarte, bei Anlagen Bearbeiter.

**Wartung eintragen:** Im Tab „Wartung“ des Fahrzeugs bzw. im Abschnitt „Wartung“ der Anlage: Datum, eine oder mehrere Wartungsarten, bei Fahrzeugen optional der Betriebsstundenstand, ausgeführt von, Notiz und Fotos. Ein höherer Stundenstand wird am Fahrzeug übernommen; ein Nachtrag mit kleinerem Stand ändert nichts. Den Stundenstand bitte immer zum Zeitpunkt der Arbeit eintragen, bei Nachträgen also den damaligen Stand – ohne Angabe gilt für einen älteren Eintrag kein Stundenstand, und ist das die letzte Wartung dieser Art, zeigt die Ampel „Stundenstand fehlt“. Der Fahrzeugstatus bleibt unverändert. Eintragen dürfen Mitglieder an eigenen Fahrzeugen, Fahrzeugwarte an allen Fahrzeugen und Bearbeiter an Anlagen; mit demselben Recht lassen sich eine Wartung oder einzelne Fotos wieder löschen.

**Ampel:** Maßgeblich ist die letzte Wartung dieser Art am Objekt (das jüngste Datum); fällig ist, was zuerst erreicht wird – Zeit oder Stunden.

- 🔴 **überfällig:** Fälligkeitstag überschritten (ab dem Folgetag) bzw. Stundenstand über dem Intervall.
- 🟡 **bald fällig:** höchstens ein Monat bis zum Fälligkeitstag (der Fälligkeitstag selbst ist noch gelb) bzw. höchstens 10 % des Stundenintervalls übrig; ebenso gelb sind **„noch nie erfasst“** und **„Stundenstand fehlt“** (die letzte Wartung dieser Art hat keinen Stundenstand, das Fahrzeug führt aber Stunden – oder für ein Fahrzeug ohne Betriebsstunden gilt nur ein Intervall in Stunden). „Stundenstand fehlt“ zählt in der Übersicht unter „bald fällig“.
- 🟢 **in Ordnung.**

Monate werden auf das Monatsende begrenzt (letzte Wartung am 31.08. + 6 Monate → fällig am 28.02., im Schaltjahr 29.02.). Fahrzeuge mit 0 Betriebsstunden, für die nie ein Stand eingegeben wurde (z. B. Wagen), führen keine Stunden: bei ihnen zählt nur das Intervall in Monaten (gibt es nur ein Stundenintervall, steht dort „noch nie erfasst“ bzw. nach einer Wartung „Stundenstand fehlt“).

**Anzeigen:** Die Seite **Wartung** (Hauptmenü) listet alle Fälligkeiten nach Dringlichkeit, mit der Anzahl je Stand und Filtern nach Objekt (Fahrzeuge/Anlagen), Spurweite, Eigentum (Verein/Mitglieder) und Stand. Jede Fahrzeug- und Anlagenseite zeigt die Fälligkeiten je Wartungsart und den Verlauf.

## Startseite

Nach der Anmeldung erscheint die **Startseite** mit der Kachel **„Offene Defekte“** (Anzahl offen und dringend, die fünf wichtigsten Meldungen, dringende zuerst, und „Alle Reparaturen“) und der Kachel **„Wartung“**: Anzahl „überfällig“, „bald fällig“ und „noch nie erfasst“, die fünf dringendsten Fälligkeiten mit Link aufs Objekt und „Alle Fälligkeiten“. Daneben führen Schnellzugriffe zu den Fahrzeugen, den Anlagen und zu „Defekt melden“.

## Abstellgleis

Ausgemusterte Fahrzeuge stehen auf dem **Abstellgleis** (Menü **Fahrzeuge → Abstellgleis**, dort mit Anzahl). Sie bleiben mit Decodern, Funktionen, CVs, Fotos und Dokumenten erhalten, erscheinen aber nicht mehr in der Fahrzeugliste und in „Meine Fahrzeuge“ und zählen bei der Adressprüfung nicht mit: Ihre Decoder-Adressen sind frei für andere Fahrzeuge. Die Seite zeigt je Fahrzeug, seit wann es abgestellt ist, und lässt sich wie die Fahrzeugliste nach Spurweite, Gattung und Eigentum filtern. Die Textsuche der Fahrzeugliste findet ausgemusterte Fahrzeuge trotzdem (z. B. über die Inventarnummer); sie sind dort als „Abstellgleis“ gekennzeichnet.

Auf der Seite eines Fahrzeugs stellt **„Aufs Abstellgleis“** es ab (Status „ausgemustert“, Datum heute), **„Zurück in Betrieb“** holt es wieder zurück (Status „einsatzbereit“). Beides darf, wer das Fahrzeug auch bearbeiten darf: ein Mitglied an selbst angelegten Fahrzeugen, Fahrzeugwart und höher an allen. Wird der Status im Bearbeiten-Formular auf „ausgemustert“ gesetzt oder davon weg geändert, wird das Datum genauso gesetzt bzw. entfernt. Beide Aktionen stehen im Verlauf des Fahrzeugs.

## Defekt melden

Jedes Mitglied kann einen Defekt melden, am besten direkt am Handy:

1. Objekt wählen: im Menü **„Defekt melden“** (bzw. auf der Startseite im Schnellzugriff) den **Code** eingeben (z. B. `F12`, `A3`, die Digitaladresse oder einen Teil der Bezeichnung), den **QR-Code an der Anlage** scannen und dort „Defekt melden“ wählen, oder auf der Seite eines Fahrzeugs bzw. einer Anlage auf **„Defekt melden“** tippen.
2. **Was ist los?** beschreiben (Pflicht); bei Anlagen zusätzlich **„Wo/Bauteil“** (z. B. „W12 Antrieb brummt“).
3. Einstufen: **„fährt nicht mehr“** (= dringend) oder **„fährt, aber …“** (= normal), optional Fotos direkt aus der Kamera. Fotos werden vor dem Hochladen im Browser verkleinert (längste Seite 2000 Pixel); ohne JavaScript gilt die Upload-Grenze des Webspace.
4. **Absenden.** Die Meldung bekommt den Status „gemeldet“; unter **„Meine Meldungen“** sieht jede und jeder die eigenen Meldungen mit dem aktuellen Stand.

Es werden keine E-Mails verschickt. Fahrzeugwarte (Fahrzeuge), Bearbeiter (Anlagen) und Administratoren (beides) sehen oben auf jeder Seite **„N neue Defektmeldungen“** und eine Zahl am Menüpunkt „Reparaturen“. Eine Meldung ist nicht mehr neu, sobald jemand mit diesen Rechten sie geöffnet hat oder ihr Status nicht mehr „gemeldet“ ist; die eigene Meldung zählt dabei nicht (wer selbst meldet, sieht sie nicht als neu, und das Öffnen der eigenen Meldung nimmt sie den anderen nicht weg). Wird ein Konto gelöscht, das eine Meldung als einziges geöffnet hatte, gilt sie wieder als neu, solange sie noch „gemeldet“ ist. Die Startseite zeigt allen die Kachel **„Offene Defekte“** (dringende zuerst).

## Reparaturen

Aus jeder Meldung wird eine **Reparatur**; sie steht auf der Seite des Fahrzeugs (Tab „Reparaturen“) bzw. der Anlage und unter **Reparaturen** (Hauptmenü, mit Filtern nach Status, Objekt und zuständig). Bearbeiten dürfen Fahrzeugwarte an allen Fahrzeugen, Bearbeiter an Anlagen und Mitglieder an eigenen Fahrzeugen (Eigentümer oder selbst angelegt).

- **Status:** gemeldet → geplant → in Arbeit → wartet auf Teile → erledigt, außerdem „verworfen“. **Priorität** dringend, normal oder niedrig; dazu zuständig (Person), geplanter Termin, Notizen, Fotos und beim Erledigen „Was wurde gemacht“.
- **Ersatzteile** mit Bezeichnung, Artikelnummer, Bezugsquelle, Kosten (z. B. `12,50` oder `12.50`, höchstens zwei Nachkommastellen, keine Tausenderpunkte) und „bestellt“; **Kosten gesamt** ist die Summe.
- **Verwerfen statt Löschen:** Reparaturen lassen sich nicht löschen, nur verwerfen (z. B. Fehlmeldung). Sie verschwinden nur mit dem Fahrzeug bzw. der Anlage selbst oder bei der DSGVO-Löschung eigener Fahrzeuge.
- Speichern zwei Personen gleichzeitig, bekommt die zweite einen Hinweis und ihre Eingaben bleiben im Formular stehen.

**Automatischer Status:** Eine offene, **dringende** Reparatur setzt das Fahrzeug auf **„defekt“** (Anlage „defekt“), eine Reparatur **„in Arbeit“** auf **„in Wartung“** (Anlage „in Arbeit“); „defekt“ hat Vorrang. Ist die letzte offene Reparatur erledigt oder verworfen (oder die dringende herabgestuft), kommt das Objekt zurück auf „einsatzbereit“ bzw. „in Ordnung“ – **aber nur, wenn der Status zuletzt automatisch gesetzt wurde.** Grenzen, die man kennen sollte:

- „ausgeliehen“ und „ausgemustert“ werden nie automatisch geändert.
- Wer einen automatisch gesetzten Status **von Hand** ändert (z. B. zurück auf „einsatzbereit“), behält ihn – bis zum nächsten Speichern einer Reparatur an diesem Objekt: Ist dann noch eine dringende Reparatur offen, steht es wieder auf „defekt“. Wer das nicht möchte, stuft die Reparatur herab oder schließt sie ab.
- Ein von Hand gesetztes **„in Wartung“** (z. B. für eine geplante Wartung) bleibt bei einer Reparatur „in Arbeit“ von Hand stehen. Kommt aber eine dringende Meldung dazu, wird daraus automatisch „defekt“ und nach dem Erledigen „einsatzbereit“ – das frühere „in Wartung“ merkt sich die App nicht. Umgekehrt wird ein von Hand gesetztes „defekt“ automatisch zu „in Wartung“, wenn nur noch eine Reparatur „in Arbeit“ offen ist. Im Zweifel den Status nach dem Erledigen kurz prüfen.
- Ein Fahrzeug, das mit offener dringender Reparatur vom Abstellgleis zurückgeholt wird, steht sofort wieder auf „defekt“.

Jede automatische Änderung steht im Verlauf des Objekts mit dem Zusatz „(automatisch durch Reparatur)“.

## Codes

Jedes Fahrzeug mit einer Inventarnummer `EMC-<Zahl>` hat den Code **`F<Zahl>`** (ohne führende Nullen: `EMC-0012` → `F12`), jede Anlage den Code **`A<n>`**. Andere Inventarnummern haben keinen Code; die Fahrzeuge findet man über die Suche.

Das Feld **„Code“** steht in der Kopfzeile jeder Seite, auch am Handy:

- `F12` bzw. `A3` öffnet das Objekt direkt (Groß-/Kleinschreibung und Leerzeichen egal, `f 012` geht auch). Ausgemusterte Fahrzeuge werden gefunden und als „Abstellgleis“ gekennzeichnet.
- Eine **reine Zahl** wird als **Digitaladresse** gesucht: ein Treffer öffnet das Fahrzeug, mehrere zeigen eine Auswahl mit Foto, Spurweite und Bezeichnung. So findet man z. B. Spur-N-Loks, bei denen das Klebchen auf der Verpackung sitzt.
- Alles andere wird als Suche über Bezeichnung, Baureihe und Inventarnummer behandelt.

**Kurzlink:** `<Adresse der App>/c/<Code>`, z. B. `https://mitglieder.emc78.de/c/A3`. Ohne Anmeldung kommt zuerst die Anmeldeseite, danach direkt das Objekt. Die QR-Codes auf den Anlagen-Etiketten enthalten genau diesen Kurzlink.

## Etiketten

Unter **Verwaltung → Etiketten** (ab Fahrzeugwart, auch über „Etikett drucken“ auf der Seite eines Fahrzeugs bzw. einer Anlage) entstehen Druckseiten für Etikettenbögen, gedruckt direkt aus dem Browser:

- **Fahrzeug-Klebchen:** nur der Code (z. B. `F12`, fett, etwa 3 mm hoch), Bogen 25,4 × 10 mm, 189 Etiketten je A4-Bogen (z. B. Avery Zweckform **L4731**). Variante **Verpackung** auf demselben Bogen mit Code und Bezeichnung (für Spur N gedacht: Klebchen auf die Verpackung, die Lok selbst findet man über die Digitaladresse).
- **Anlagen-Etikett:** 70 × 37 mm, 24 je A4-Bogen (randlos, z. B. Avery Zweckform **3474**) mit QR-Code (Kurzlink), Name und Code `A3`. Achtung: Zweckform 3475 sieht ähnlich aus, hat aber 70 × 36 mm – darauf säßen die Etiketten bis zu 4 mm verschoben (anpassen in `src/Etiketten/Bogen.php`, dann Probedruck).
- **Auswahl:** einzeln, alle einer Spurweite, alle Loks und Triebwagen oder **„noch nicht gedruckt“** (ohne ausgemusterte Fahrzeuge, die gibt es nur einzeln, und ohne Fahrzeuge ohne Code – diese nennt die Auswahlseite getrennt). **Startposition** auf dem Bogen frei wählbar (für angebrochene Bögen); was nicht mehr passt, kommt auf weitere Bögen.
- **Drucken:** im Druckdialog **„Tatsächliche Größe“ bzw. 100 %** und Ränder „Keine“ wählen, nicht „An Seite anpassen“. Bei einem neuen Bogen oder Drucker **zuerst einen Probedruck auf Normalpapier** machen und gegen den Bogen halten. Viele Drucker bedrucken die äußeren 3–5 mm nicht; bei den randlosen Anlagen-Etiketten liegt der QR-Code deshalb mit Abstand zum Rand.
- Nach dem Drucken **„Als gedruckt vermerken“** klicken; erst das nimmt die Etiketten aus „noch nicht gedruckt“ (das bloße Anzeigen der Druckseite nicht). Bei Fahrzeugen gibt es **einen** Vermerk für Klebchen und Verpackung gemeinsam: Wer Klebchen gedruckt und vermerkt hat, findet das Fahrzeug unter „Verpackung, noch nicht gedruckt“ nicht mehr – Verpackungsetiketten dann über „einzeln“, „Spurweite“ oder „alle Loks“ drucken.
- **„Zurück zur Auswahl“** auf der Druckseite behält Auswahl, Spurweite und Startposition.
- **QR-Codes:** Sie enthalten `<oeffentliche_url><basis_url>/c/<Code>`. Dafür in der `config.php` **`oeffentliche_url`** setzen (z. B. `'https://mitglieder.emc78.de'`); fehlt sie, nimmt die App die Adresse der aktuellen Anfrage und zeigt in einem Hinweis, auf welche Adresse die QR-Codes dann zeigen (hinter einem Proxy kann das z. B. `http://` statt `https://` sein).

Die QR-Codes erzeugt der Server als SVG mit der Bibliothek [`chillerlan/php-qrcode`](https://github.com/chillerlan/php-qrcode) (Lizenz MIT oder Apache-2.0, dazu `chillerlan/php-settings-container`, MIT). Beide sind im Release-ZIP im Ordner `vendor/` enthalten; auf dem Webspace ist kein Composer nötig.

## Funktionstasten aus PDF

Am Decoder unter **Funktionen → „PDF-Import“** lässt sich die Bedienungsanleitung des Decoders oder der Lok als PDF hochladen (wer die Funktionstasten bearbeiten darf, darf auch das). Die App liest den Text aus und zeigt die erkannten Tasten in derselben **Vorschau** wie der CSV-Import; erst „Übernehmen“ ändert etwas.

- **Erkannt** werden Zeilen, die mit einer Taste beginnen, z. B. `F0 Licht`, `F1 Sound an`, `F2 – Pfiff`, `F 3: Glocke`, `F4 = Rangiergang`, `Taste F5 Kupplung`, `Funktion f6 Rauch`, auch mit Aufzählungszeichen oder Nummer davor, Tabellen mit `|` und **zwei Spalten nebeneinander** (`F0 Licht   F8 Rangiergang`). Tasten **F0 bis F68**. Endet die Beschreibung auf `(M)`, `[M]`, `(Moment)`, `(Taster)` oder `(Impuls)`, wird die Taste als **Moment-Taste** übernommen, sonst als Dauer-Taste. Inhaltsverzeichnisse mit Punktreihen und Seitenzahl, „frei“, „nicht belegt“ und Ähnliches werden still übergangen.
- **Nicht übernommen** werden: **Bereiche** wie „F5–F8 Ansagen“, **doppelte Tasten** (die erste gilt), Tasten **über F68** und Tasten mitten im Satz („Mit F1 schalten Sie …“). Für Bereiche, doppelte und zu hohe Tasten sowie zu lange Beschreibungen (mehr als 100 Zeichen, werden gekürzt) zeigt die Vorschau **Hinweise**.
- **Übernehmen ersetzt die Belegung** des Decoders wie beim CSV-Import. Was nicht erkannt wurde, danach von Hand ergänzen.
- **Gescannte PDFs** (nur Bilder, kein Text) werden mit einem Hinweis abgelehnt; eine Texterkennung gibt es nicht. Geschützte (verschlüsselte) PDFs lassen sich ebenfalls nicht lesen.
- **Grenzen:** Dateien bis **20 MB**, gelesen werden die **ersten 150 Seiten** (bei längeren PDFs sagt die Vorschau das). Die angezeigte Grenze kann kleiner sein, wenn der Webspace weniger erlaubt (`upload_max_mb`, `upload_max_filesize`) oder wenig Speicher hat (siehe unten). Ist die Datei größer als `post_max_size` des Webspace, kommt wie beim CSV-Import die Seite „Sitzung abgelaufen“ statt „zu groß“ (PHP verwirft dann das ganze Formular samt Sicherheitsschlüssel) – dann eine kleinere Datei nehmen oder `post_max_size` erhöhen. Ungewöhnlich aufgebaute oder absichtlich bösartige PDFs (z. B. „Zip-Bomben“) werden mit „Das PDF konnte nicht gelesen werden.“ abgelehnt; einzelne zu aufwendige Seiten oder Schrift-Tabellen werden übersprungen.
- **Speicher:** Beim Auslesen hebt die App die PHP-Speichergrenze (`memory_limit`) auf 512 MB an, wenn der Webspace das erlaubt. Sonst richten sich die Grenzen nach dem vorhandenen Speicher:

  | `memory_limit` (wirksam) | Datei höchstens | entpackter Inhalt insgesamt | ein Datenstrom | Objekte im PDF |
  |---|---|---|---|---|
  | 128 MB (Anheben nicht erlaubt) | 5 MB | 25,6 MB | 8 MB | 52 428 |
  | 256 MB | 10 MB | 51,2 MB | 16 MB | 104 857 |
  | ab 512 MB bzw. Anheben erlaubt | 20 MB | 96 MB | 32 MB | 200 000 |

  Jeder PDF-Upload kann bis zu 512 MB Speicher belegen. Laden **mehrere Personen gleichzeitig** große PDFs hoch, kann das das Speicherkontingent des Webspace-Pakets überschreiten (der Hoster beendet dann Anfragen, auch andere); bei Problemen nacheinander hochladen.
- **Das PDF wird nicht dauerhaft gespeichert:** Die App liest es nur im Speicher aus; in der Sitzung bleiben nur die erkannten Tasten bis zum Übernehmen. Die hochgeladene Datei löscht PHP am Ende der Anfrage; die PDF-Bibliothek legt beim Entpacken mancher Datenströme kurz eine temporäre Datei an, die sofort wieder gelöscht wird (liegen bleiben kann sie nur, wenn der PHP-Prozess hart abstürzt). Wer die Anleitung aufheben will, lädt sie **zusätzlich als Dokument** am Fahrzeug hoch.

Den Text liest die Bibliothek [`smalot/pdfparser`](https://github.com/smalot/pdfparser) (Lizenz **LGPL-3.0**, reines PHP, ohne externe Programme), dazu [`symfony/polyfill-mbstring`](https://github.com/symfony/polyfill-mbstring) (Lizenz MIT). Beide liegen unverändert als Quelltext mit ihren Lizenzdateien im Release-ZIP im Ordner `vendor/`.

## Magazin

Das **Magazin** (Menü **Magazin**) ist die Inventarliste von allem, was der Verein rund um die Modellbahn besitzt – auch Literatur. Lesen dürfen alle Mitglieder, buchen nur Börsenwarte (siehe „Rollen und Rechte“).

- **Kategorien:** Gleise und Weichen, Fahrzeuge, Elektrik und Digital, Gebäude und Bausätze, Landschaft und Figuren, Ersatzteile, Werkzeug, Literatur, Sonstiges. Ein Administrator pflegt die Liste unter **Verwaltung → Magazin-Kategorien** (Fahrzeuge, Literatur und Sonstiges braucht das Programm; sie lassen sich umbenennen, aber nicht löschen).
- **Artikel:** Code **`M<n>`** (automatisch; im Feld „Code“ der Kopfzeile eintippen, z. B. `M37`), Bezeichnung, Kategorie, Hersteller, Artikelnummer, Spurweite, Zustand (neu, neuwertig, gebraucht, defekt), Lagerort, Fotos und Notiz; bei Literatur zusätzlich Autor/Verlag, Jahr und ISBN.
- **Bestand nur über Buchungen:** Der Bestand ist immer die Summe der Buchungen und wird nie von Hand eingetragen. **Eingang** (Herkunft Spende, Nachlass, Kauf, Fund oder Sonstiges mit Freitext, Menge, optional Wert), **Ausgang** (gegen Spende, verschrottet, ausgeliehen, sonstiges – „verkauft an der Börse“ bucht nur die Kasse – mit Menge, Betrag und „an wen“) und **Umlagerung** (neuer Lagerort). Ein Ausgang über den freien Bestand wird abgelehnt; was in einem offenen Börsenangebot steckt, zählt dabei nicht als frei.
- **Buchungen sind unveränderlich.** Ein Fehler wird mit einer **Gegenbuchung** korrigiert (Knopf an der Buchung im Verlauf). Jede Buchung steht mit Benutzer und Zeitpunkt im Verlauf des Artikels. Magazinartikel werden nicht gelöscht.
- **Sammlungen** bündeln mehrere Artikel unter einem Namen (z. B. „Nachlass Märklin-Gleise, 3 Kisten“); sie lassen sich als Ganzes umlagern und als ein Angebot in die Börse übernehmen. Ein Artikel gehört zu höchstens einer Sammlung.
- **Übergabe vom Abstellgleis:** Auf der Seite eines ausgemusterten **Vereinsfahrzeugs** übergibt **„An Magazin übergeben“** (Börsenwart oder Fahrzeugwart und höher) das Fahrzeug ans Magazin: Es entsteht ein Artikel der Kategorie Fahrzeuge mit Menge 1, dem Titelfoto und einem Verweis auf das Fahrzeug. Das Fahrzeug bleibt als Datensatz auf dem Abstellgleis; nach dem Ausgang des Artikels zeigt es „abgegeben am … (Magazin M37)“. Ein übergebenes Fahrzeug kann weder zurück in Betrieb geholt noch einer Person als Eigentum gegeben werden. **Eine Übergabe lässt sich von Benutzern nicht rückgängig machen:** Eine Gegenbuchung setzt nur den Bestand auf 0; den Artikel kann man nicht löschen und das Fahrzeug nicht noch einmal übergeben. Bei einem Versehen den Administrator fragen (eine Korrektur geht nur direkt in der Datenbank).

## Modellbahnbörse

Unter **Börse** sammelt die App alles, was abgegeben werden soll, und begleitet eine Börse (Veranstaltung) von der Vorbereitung bis zur Abrechnung.

- **Börse anlegen** (Börsenwart): Name, Datum, Ort, **Provision in %** (Vorgabe 10, erlaubt 0–100) und Status *in Vorbereitung → läuft → abgeschlossen*. Nach dem ersten Verkauf lässt sich die Provision nicht mehr ändern. Verkäufe außerhalb eines Börsentags laufen über eine eigene Börse, z. B. „Verkauf über die Webseite“.
- **Angebote:** Nummer **`B<Jahr>-<n>`** (fortlaufend je Jahr, z. B. `B2026-118`; ebenfalls im Feld „Code“ und als Kurzlink `/c/B2026-118`), Bezeichnung, Kategorie, Spurweite, Zustand, Fotos, Wunschpreis, Herkunft **Verein** (aus einem Magazinartikel mit Menge oder aus einer Sammlung, nur Börsenwart) oder **Mitglied** (eigene Sachen, Häkchen **„Erlös als Spende an den Verein“**), Zuordnung zu einer Börse oder „dauerhaft“ (an jeder Börse verkaufbar) und Status *angeboten → reserviert → verkauft* bzw. *zurück*.
- **Freigabe:** Ein Angebot ist öffentlich *nein*, *vorgeschlagen* oder *freigegeben*. Mitglieder schlagen ihr Angebot vor; sichtbar wird es erst, wenn ein Börsenwart es **freigibt**. Vereinsangebote gibt der Börsenwart direkt frei. **Ändert ein Mitglied ein freigegebenes Angebot** (Text, Preis, Fotos), fällt es auf *vorgeschlagen* zurück; Änderungen des Börsenwarts nicht.
- **Preisschilder:** An der Börse druckt **„Preisschilder“** **8 Schilder je A4-Blatt** (105 × 74 mm) mit großer Angebotsnummer, Bezeichnung, Preis und kleinem QR-Code (Kurzlink auf das Angebot). Beim Drucken **„Tatsächliche Größe“ bzw. 100 %** und Ränder „Keine“ wählen. Die **Gesamtliste** zeigt Nummer, Bezeichnung, Preis und Herkunft Verein/Mitglied.
- **Kasse am Handy** (Börsenwart, Menü **Börse → Kasse**, nur bei laufender Börse): Angebotsnummer eintippen oder suchen → Foto, Bezeichnung und Wunschpreis erscheinen → Verkaufspreis (vorbelegt, änderbar) → mehrere Stücke zu einem Verkauf mit Summe und Zahlart (bar oder Karte/Überweisung). Käufer werden nicht erfasst. Beim Abschluss wird jedes Stück *verkauft*; Vereinsstücke bekommen automatisch die Magazin-Buchung „Ausgang verkauft an der Börse“ mit Betrag. **Kein Doppelverkauf**, auch mit mehreren Handys gleichzeitig: Die zweite Kasse bekommt z. B. „B2026-118 ist bereits verkauft.“ und verkauft keines ihrer Stücke.
- **Storno** (an der Börse unter „Verkäufe“): nur **am selben Tag** und mit Begründung; die Stücke sind wieder *angeboten*, Vereinsstücke bekommen eine Magazin-Gegenbuchung. Ist für ein Mitglied schon „ausgezahlt“ vermerkt, zuerst den Haken entfernen.
- **Abrechnung je Börse:** Verein (Einnahmen aus Vereinsstücken, Provisionen, Spenden aus Mitgliederverkäufen), Summe nach Zahlart und je Mitglied (sortiert nach Nachname) verkaufte Stücke, Erlös, Provision (je Stück kaufmännisch auf den Cent gerundet) und **Auszahlungsbetrag**; bei „Erlös als Spende“ 0 € (Spende). Stornierte Verkäufe zählen nicht. Mit **„ausgezahlt“** (Datum höchstens heute) wird die Auszahlung vermerkt. Wird danach noch etwas vom selben Mitglied verkauft, warnt die Abrechnung oben („… weicht der Betrag vom vermerkten ab“), und die Druckansicht hat ein Feld für die Nachzahlung; „Haken entfernen“ und neu vermerken ersetzt Datum und Betrag (die alte Angabe steht dann nur noch im Änderungsprotokoll). Die **Druckansicht** eignet sich als Unterschriftsliste.
- **Abschluss:** **„Börse abschließen“** gibt alle unverkauften Stücke zurück: Vereinsstücke bleiben mit unverändertem Bestand im Magazin, Mitgliederstücke stehen auf *zurück*. Danach ist die Börse schreibgeschützt (keine Kasse, kein Storno, keine Änderungen an ihren Angeboten); **nur ein Administrator kann sie wieder öffnen**. Zurückgegebene Stücke kann der Börsenwart später wieder anbieten.

## Öffentlicher Bereich der Börse

Besucher der Vereinswebseite sehen unter **`<oeffentliche_url>/boerse`** ohne Anmeldung die freigegebenen Angebote (von www.emc78.de aus verlinkbar, z. B. `<a href="https://mitglieder.emc78.de/boerse">Modellbahnbörse</a>`). Der Bereich ist **aus**, bis in der `config.php` beides gesetzt ist:

- `'boerse_oeffentlich' => true` (genau so; `'true'` oder `1` schalten nicht ein) – die Entscheidung liegt beim Vorstand,
- `'datenschutz_url' => 'https://www.emc78.de/datenschutz'` (nur https) – sie wird an jedem Formular und in der Fußzeile verlinkt.

Sonst antwortet `/boerse` mit „nicht gefunden“. Steht ein Datenbank-Update aus, zeigt der Bereich eine Wartungsseite.

**Sichtbar** sind nur freigegebene Angebote mit Status *angeboten* oder *reserviert* (als „reserviert“ gekennzeichnet) einer nicht abgeschlossenen Börse sowie dauerhafte: Foto (nur die verkleinerte Anzeige-Variante, nie das Original), Bezeichnung, Beschreibung, Kategorie, Spurweite, Zustand, Preis und Angebotsnummer, dazu Filter, Suche und die nächste Börse mit Datum und Ort. **Nie** sichtbar sind Personennamen, die Herkunft Verein/Mitglied, Notizen, Magazin-Codes, Lagerorte und Originaldateinamen. Nicht freigegebene, verkaufte und zurückgegebene Angebote antworten wie unbekannte Nummern.

Besucher können zu einem Angebot eine **Kaufanfrage** stellen (Name, E-Mail oder Telefon, Nachricht; reserviert nicht automatisch) oder unter „Sammlung anbieten“ eine **Sammlung anbieten** (Name, Kontakt, Beschreibung, bis zu 5 Fotos, Wunsch „an den Verein verkaufen“ oder „spenden“). Beides braucht das Häkchen zur Einwilligung mit Link auf die Datenschutzerklärung.

**Spamschutz:** CSRF-Schutz, ein unsichtbares Fangfeld und eine Mindestausfüllzeit von 3 Sekunden (Bots bekommen dieselbe Dankeseite, gespeichert wird nichts), höchstens **5 Anfragen je Stunde und Absender** (IPv6 je /56-Netz) und **höchstens 60 Anfragen je Stunde insgesamt**; darüber hinaus „Bitte später erneut versuchen.“

**Hinweise für die Datenschutzerklärung des Vereins:**

- Beim ersten Aufruf einer Seite mit Formular setzt der öffentliche Bereich ein **Sitzungscookie** (technisch notwendig für den CSRF-Schutz; Angebotsliste und Fotos kommen ohne Cookie aus). Besucher werden nie angemeldet.
- Die **IP-Adresse** wird nicht gespeichert, nur ein **HMAC-Hash** (mit einem geheimen Salz der Installation) für die Begrenzung; diese Zeilen werden nach **24 Stunden** gelöscht.
- **Fotos** werden auf dem Server neu kodiert (höchstens 24 Megapixel) und **ohne Metadaten** wie Aufnahmeort oder Kamera gespeichert; das Original wird nie abgelegt. Fotos aus Anfragen gehen nicht nach OneDrive.
- Anfragen samt Fotos werden **12 Monate** nach Eingang automatisch gelöscht (beim Aufruf der Seiten, höchstens einmal je Stunde, und mit dem Cronjob unten), einzeln jederzeit von Hand.

**Cronjob (optional, empfohlen):** täglich

```bash
php bin/boerse-aufraeumen.php
```

Er löscht abgelaufene Anfragen auch dann, wenn niemand die Seiten aufruft (STRATO: wie beim OneDrive-Cronjob unter „Cronjob-Verwaltung“, Skript `/mitglieder/bin/boerse-aufraeumen.php`, PHP ab 8.2, Intervall täglich). Ausgabe z. B. `0 Börsenanfragen gelöscht.`

**Testinstanz:** Mit `'testumgebung' => true` zeigt der öffentliche Bereich eine deutliche Leiste „Testinstanz – hier gibt es nichts zu kaufen …“, und alle Seiten tragen `noindex` (Suchmaschinen nehmen sie nicht auf). Im Echtbetrieb sind Liste und Angebote indexierbar, Danke- und Fehlerseiten nie.

**Anfragen bearbeiten** (Börsenwart, **Börse → Anfragen**): Neue Anfragen erscheinen oben als Hinweis „N neue Börsenanfragen“ und als Zahl am Menüpunkt „Börse“. Öffnen setzt *offen → in Bearbeitung*, danach *erledigt*. **Antworten** geht über **„Antwort per E-Mail“**: Der Link öffnet das **eigene E-Mail-Programm** mit vorbereitetem Betreff (die App verschickt selbst nichts); bei einer Telefonnummer gibt es einen Anruf-Link. Eine angebotene Sammlung lässt sich nach Annahme mit **„Ins Magazin übernehmen“** direkt als Magazin-Eingang anlegen (Herkunft Spende oder Kauf nach Wunsch, Fotos übernommen, das erste wird Titelbild); die Anfrage ist dann erledigt und bleibt es. Das Formular wird bewusst **nicht mit dem Text des Besuchers vorbelegt** – die Beschreibung steht daneben; bitte eine eigene Bezeichnung ohne Namen und Kontaktdaten eingeben. Der **Name des Besuchers steht nie im Buchungstext** („Anfrage über die Webseite (Nr. 12)“), weil Buchungen unveränderlich sind und die Anfrage nach 12 Monaten gelöscht wird. Übernommene Fotos bleiben am Artikel, auch wenn die Anfrage gelöscht wird; erst danach werden sie (bei eingerichtetem OneDrive) zum Hochladen vorgemerkt.

## Testumgebung test.emc78.de

Neuerungen und Beispieldaten lassen sich gefahrlos auf einer eigenen Testinstanz ausprobieren:

1. Eine **zweite, eigene Datenbank** anlegen (nie dieselbe wie im Echtbetrieb).
2. Das Release-ZIP in ein **eigenes Verzeichnis `/test/`** hochladen und die Subdomain `test.emc78.de` mit dem Ziel `/test/public` anlegen; SSL wie oben aktivieren.
3. In der `config.php` der Testinstanz die zweite Datenbank eintragen, **`'testumgebung' => true`** setzen (genau so, ohne Anführungszeichen; `'true'`, `'false'` oder `1` schalten die Testumgebung nicht ein) und unter **`'testumgebung_admin_passwort'`** ein eigenes, geheimes Passwort für den Test-Administrator eintragen (mindestens 10 Zeichen). Ohne dieses Passwort werden keine Beispieldaten geladen. Die Testinstanz zeigt dann oben eine gelbe Leiste „Testumgebung“ und auf der Anmeldeseite die Test-Zugänge für Mitglied und Bearbeiter.
4. **Testinstanz zusätzlich mit einem Verzeichnisschutz sichern:** Im STRATO-Kundenbereich (sinngemäß „Sicherheit“ bzw. „Webspace“ → „Verzeichnisschutz“) für das Verzeichnis `/test` einen Verzeichnisschutz (htaccess-Passwort) mit Benutzername und Passwort einrichten und diese nur an Vereinsmitglieder weitergeben, die testen sollen. Die Test-Zugänge stehen auf der Anmeldeseite der Testinstanz; ohne Verzeichnisschutz könnte sie jeder im Internet benutzen. Danach `https://test.emc78.de` aufrufen: Erst fragt der Browser nach dem Verzeichnisschutz-Passwort, dann erscheint die Anmeldeseite. Erscheint stattdessen ein Fehler, hat der Verzeichnisschutz eine mitgelieferte `.htaccess` ersetzt; dann deren Zeilen aus dem Release-ZIP wieder ergänzen.
5. `https://test.emc78.de/install` aufrufen und einen Administrator anlegen.
6. Beispieldaten laden, entweder
   - im Browser als Administrator unter **Verwaltung → Testdaten** mit „Beispieldaten neu laden“ oder
   - per SSH (falls das STRATO-Paket SSH bietet) im Verzeichnis `/test/`: `php bin/demo-daten.php --zuruecksetzen`

   Beides **löscht alle vorhandenen Daten der Testinstanz** und legt neu an: 7 erfundene Personen, 13 Fahrzeuge in H0 und N (Verein und Mitglieder, zwei ausgemusterte), Decoder mit Funktionstasten und CVs, Beispielfotos, absichtlich **eine doppelte DCC-Adresse in H0**, an der man die Warnung der Adressprüfung sieht, die Anlage **A1 „Clubanlage H0“**, einige Wartungen (eine überfällige, eine bald fällige, eine in Ordnung; der Rest „noch nie erfasst“) und drei Reparaturen: eine dringende Meldung an der BR 01 (die Lok steht dadurch automatisch auf „defekt“), „W12 Antrieb brummt“ an der Anlage in Arbeit (Anlage „in Arbeit“) und eine erledigte Reparatur an der V 200 mit zwei Ersatzteilen. Vier Fahrzeuge gelten als schon beschriftet; die übrigen stehen bei den Etiketten unter „noch nicht gedruckt“ – bis auf das ausgemusterte Fahrzeug, das es dort (wie alle ausgemusterten) nur einzeln gibt. Dazu **Magazin und Börse**: Greta Wagner (Fahrzeugwart) hat das **Börsenwart-Häkchen**; im Magazin liegen u. a. Märklin C-Gleise (Bestand 12), ein defektes Fahrpult, ein Buch und die Sammlung „Nachlass Märklin-Gleise, 3 Kisten“; die ausgemusterte Vereinslok **„Köf II“** ist ans Magazin übergeben (die ausgemusterte BR 103 von Dieter bleibt privat). Die **„Herbstbörse“** läuft am selben Tag mit Vereinsangeboten aus dem Magazin, drei Angeboten von Carla (nicht öffentlich, vorgeschlagen, freigegeben mit „Erlös als Spende“), einem dauerhaften Angebot, zwei Verkäufen an der Kasse und einer offenen Kaufanfrage aus dem öffentlichen Bereich. Die Wartungsarten und Magazin-Kategorien der Testinstanz bleiben dabei erhalten. Nach dem Laden im Browser wird man abgemeldet.
7. Als Mitglied (Carla) kannst du die E 94 279 und den Eilzugwagen Bymb 421 bearbeiten – die hat Carla selbst angelegt; andere Fahrzeuge siehst du nur.
8. Anmelden mit `bearbeiter@test.emc78.de`, `fahrzeugwart@test.emc78.de` (Greta Wagner) oder `mitglied@test.emc78.de` (Passwort `Test-EMC78!`) oder als Administrator mit `admin@test.emc78.de` und dem Passwort aus `testumgebung_admin_passwort`. Den Admin-Zugang erfahren Tester beim Vorstand; er steht bewusst nicht auf der Anmeldeseite.

Anmeldungen gelten nur für die Instanz, an der man sich angemeldet hat: Echtbetrieb und Testinstanz speichern ihre Sitzungen getrennt (jeweils in `storage/sitzungen/`) und erkennen fremde Sitzungen, auch wenn beide im selben STRATO-Paket liegen.

`testumgebung` im Echtbetrieb **nie** auf `true` setzen; Testdaten-Seite und Test-Zugänge sind dort bewusst nicht vorhanden.

**OneDrive in der Testinstanz:**

- „Beispieldaten neu laden“ trennt OneDrive in der Testinstanz (Verbindung und Zielordner werden mit gelöscht) und löscht nur die Dateien auf dem Webspace der Testinstanz. Testdateien, die schon in den **Testordner** übertragen wurden, bleiben dort liegen; bei Bedarf im OneDrive von Hand löschen. Danach in der Testinstanz neu verbinden und wieder den Testordner wählen.
- **Nie eine Sicherung des Echtbetriebs in die Testinstanz einspielen, solange die Testinstanz mit dem Vereinskonto verbunden ist** oder danach verbunden wird. Die Sicherung enthält den Zielordner des Echtbetriebs und die Kennungen der echten Vereinsdateien; würde die Testinstanz dann mit dem Vereinskonto verbunden, könnte das Löschen von Testfahrzeugen **echte Dateien im Vereinsordner löschen**. Ist es doch passiert: in der Testinstanz sofort unter **Verwaltung → OneDrive** auf **„Trennen“** klicken, vor dem nächsten Verbinden **einen eigenen Testordner** als Zielordner wählen und die eingespielten Fahrzeuge nicht löschen, sondern mit „Beispieldaten neu laden“ ersetzen.

## Auf dem Handy installieren

Die Anwendung lässt sich wie eine App auf den Startbildschirm legen (sie öffnet dann ohne Adresszeile). Sie benötigt weiterhin eine Internetverbindung, damit die Daten aktuell sind.

- **Android (Chrome):** Seite öffnen, anmelden, Chrome-Menü (drei Punkte) → **„App installieren“** bzw. **„Zum Startbildschirm hinzufügen“**.
- **iPhone/iPad (Safari):** Seite in Safari öffnen, **Teilen**-Symbol → **„Zum Home-Bildschirm“**.

Das Symbol zeigt das Vereinslogo auf weißem Grund. Es wird aus `public/assets/marke/logo-512.png` mit `php bin/erzeuge-bilder.php` erzeugt (`icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png` in `public/assets/icons/`).

## Gestaltung anpassen

Alle Vereinsfarben stehen als CSS-Variablen ganz oben in `public/assets/app.css` (`--emc-rot`, `--emc-rot-dunkel`, `--emc-schwarz`, `--emc-grau`, `--emc-grau-hell`, dazu `--emc-rot-rgb`); Bootstrap ist dort auf diese Werte abgebildet. Logo, Slogan und Gleisgrafik liegen in `public/assets/marke/`. Den Anzeigenamen stellt `app_name` in der `config.php` ein.

## Link auf der Vereinswebseite

Die Mitgliederverwaltung ist nicht öffentlich, aber leicht erreichbar, wenn auf der Vereinswebseite emc78.de ein Verweis steht, z. B. im Menü oder in der Fußzeile:

```html
<a href="https://mitglieder.emc78.de">Mitgliederbereich (Fahrzeugverwaltung)</a>
```

Alle Inhalte sind erst nach der Anmeldung sichtbar – bis auf den öffentlichen Bereich der Börse, wenn er eingeschaltet ist (siehe „Öffentlicher Bereich der Börse“; Link dann auf `https://mitglieder.emc78.de/boerse`). Benutzer legt ein Administrator unter **Verwaltung → Benutzer** an.

Hinweise:

- **Fehler 500 direkt nach dem Hochladen?** Manche Hoster erlauben die Anweisung `Options` in `.htaccess` nicht. Dann in der Datei `public/.htaccess` die Zeile `Options -Indexes` entfernen.
- Die Einstellung `cookie_secure` in der `config.php` muss im Betrieb auf `true` bleiben (HTTPS). Nur für lokale Tests ohne HTTPS darf sie `false` sein.
- Der Ordner `storage/` muss für PHP beschreibbar sein (hier liegen die hochgeladenen Dateien und unter `storage/sitzungen/` die Anmeldesitzungen). Er ist per `.htaccess` gegen direkten Abruf gesperrt; Dateien werden nur nach Anmeldung über die Anwendung ausgeliefert.
- Eine Anmeldung verfällt nach `sitzung_minuten` (Standard 480 = 8 Stunden) ohne Aufruf und spätestens beim Schließen des Browsers.
- Die maximale Upload-Größe richtet sich nach `upload_max_mb` in der `config.php` und den PHP-Grenzen des Hosters (`upload_max_filesize`, `post_max_size`).

## Update

1. Als Administrator zuerst eine Sicherung erstellen (Verwaltung → Sicherung).
2. Das neue ZIP entpacken und die Dateien per FTP hochladen, bestehende Dateien überschreiben. **Dabei `config.php` und den Ordner `storage/` nicht löschen.** Das ZIP enthält keine `config.php` und keine hochgeladenen Dateien. Es enthält nur `storage/.htaccess`, `storage/dateien/.gitkeep` und `storage/sitzungen/.gitkeep`; diese drei Dateien beim Hochladen zu überschreiben ist harmlos, vorhandene Fotos, Dokumente und Sitzungen bleiben erhalten.
3. Als Administrator anmelden. Gibt es Datenbank-Änderungen, erscheint oben der Hinweis „Es steht ein Datenbank-Update an.“ Dort auf den Link klicken oder **Verwaltung → Update** öffnen und „Datenbank jetzt aktualisieren“ wählen.

Solange ein Datenbank-Update aussteht, sehen alle anderen Benutzer eine Wartungsseite. Administratoren können lesen, aber außer Update, Sicherung und Abmelden nichts speichern; sie werden mit dem Hinweis „Bitte zuerst das Datenbank-Update ausführen.“ zur Update-Seite geleitet. Nach dem Update ist alles wieder normal nutzbar.

## Sicherung und Wiederherstellung

**Sicherung:** Als Administrator unter **Verwaltung → Sicherung** auf „Sicherung herunterladen“ klicken. Man erhält eine ZIP-Datei mit

- `datenbank.sql` – alle Daten der Datenbank,
- `dateien/` – alle hochgeladenen Fotos und Dokumente,
- `info.txt` – Datum, Programmversion und letzte Datenbank-Änderung.

Die ZIP-Datei bitte an einem sicheren Ort außerhalb des Webspace aufbewahren. Ist die letzte Sicherung älter als 30 Tage, erinnert die Anwendung die Administratoren daran. Sicherungen enthalten alle Personendaten, auch von inzwischen gelöschten Personen: **Sicherungen nach 12 Monaten vernichten.**

**Wiederherstellung:**

1. Die Anwendung wie unter „Installation“ auf dem Webspace bereitstellen (Schritte 1–5), aber `/install` nicht aufrufen.
2. Das Sicherungs-ZIP entpacken.
3. `datenbank.sql` im Verwaltungswerkzeug des Hosters (phpMyAdmin → Importieren) in die leere Datenbank einspielen. Bestehende Tabellen mit gleichem Namen werden dabei ersetzt.
4. Den Ordner `dateien` aus dem entpackten ZIP per FTP **in den Ordner `storage/` hinein** hochladen, sodass z. B. `storage/dateien/2026/…` entsteht (nicht `storage/dateien/dateien/…`).
5. Die Anwendung aufrufen und wie gewohnt anmelden.

Eine Sicherung des Echtbetriebs **nie in die Testinstanz** einspielen, solange dort OneDrive mit dem Vereinskonto verbunden ist (siehe „Testumgebung“: sonst können echte Vereinsdateien gelöscht werden). Ist es doch geschehen, in der Testinstanz sofort **„Trennen“** klicken und vor dem nächsten Verbinden einen eigenen Testordner wählen.

## Person nach DSGVO löschen

Verlangt ein Mitglied die Löschung seiner Daten, öffnet ein **Administrator** die Person unter **Personen → Bearbeiten** und wählt **„Person endgültig löschen (DSGVO)“**. Die Vorschau zeigt, was passiert:

- Die Person (Name, E-Mail, Telefon, Notiz) und ihr Benutzerkonto werden gelöscht, ebenso ihre Anmeldeversuche (außer bei einer E-Mail-Adresse, die auch eine andere Person nutzt).
- **Eigene Fahrzeuge** der Person werden samt Fotos und Dokumenten (Webspace und OneDrive), Decodern, Funktionstasten, CVs, **Wartungen (mit Fotos), Wartungsplan-Ausnahmen und Reparaturen (mit Ersatzteilen und Fotos)** mitgelöscht. Fahrzeuge, die in der Vorschau mit **„an den Verein übergeben“** angehakt werden, bleiben samt Wartungen und Reparaturen erhalten; Eigentümer wird der Verein. Die Vorschau nennt je Fahrzeug die Zahl der Dokumente, Wartungen und Reparaturen.
- Bezüge in Vereinsdaten (z. B. „angelegt von“, „hochgeladen von“, bei Wartungen „ausgeführt von“ und „eingetragen von“, bei Reparaturen „gemeldet von“ und „zuständig“) werden entfernt und als „ehemaliges Mitglied“ angezeigt (bei „zuständig“ bleibt das Feld danach leer). Wartungen und Reparaturen an Vereinsfahrzeugen, fremden Fahrzeugen und Anlagen bleiben erhalten; die Vorschau nennt ihre Zahl. Die „gesehen“-Vermerke des Kontos verschwinden mit ihm.
- Speichert gerade jemand eine Reparatur an einem Fahrzeug der Person, wartet die Löschung kurz darauf und löscht die Reparatur dann mit.
- „Kennwort vergessen“: Links und Anfragen der Person verschwinden mit dem Konto, ihre gezählten Anfragen ebenfalls.
- **Börsenangebote** der Person, die nicht verkauft sind, werden samt Fotos gelöscht. **Verkaufte** bleiben ohne Personenbezug in Verkäufen und Abrechnung („ehemaliges Mitglied“, Beträge unverändert), ebenso vermerkte **Auszahlungen**. Ein Angebot, dessen Verkauf storniert wurde, bleibt erhalten (der stornierte Verkauf verweist darauf) – zurückgezogen und ohne Person, Fotos, Beschreibung und Notiz. Die Vorschau nennt die Zahlen („<n> nicht verkaufte Börsenangebote werden gelöscht, <m> verkaufte bleiben ohne Namen in der Abrechnung.“). Börsenanfragen aus dem öffentlichen Bereich sind keinem Mitglied zugeordnet; sie werden nach 12 Monaten automatisch gelöscht.
- **Freitexte in Magazin und Börse** (Buchungstext wie „Spende von …“, Notizen an Magazinartikeln und Sammlungen, Name einer Sammlung, Beschreibung und Notiz von Angeboten, Storno-Grund) werden wie das Protokoll durchsucht: Name, E-Mail und Telefon werden durch „ehemaliges Mitglied“ ersetzt – die einzige Änderung an sonst unveränderlichen Buchungen. Die Vorschau nennt die Zahl der Fundstellen. Bezüge wie „gebucht von“, „angelegt von“ und „bearbeitet von“ leeren sich wie überall.
- Im Änderungsprotokoll verschwinden die Einträge zur Person und zu ihren Zugängen; in allen übrigen Einträgen werden Name, E-Mail und Telefon (auch frühere Werte) durch „ehemaliges Mitglied“ ersetzt (außer einer E-Mail-Adresse, die auch eine andere Person nutzt, z. B. eine Familienadresse). Übrig bleibt genau ein Nachweis „Person Nr. N nach DSGVO gelöscht“ mit dem ausführenden Administrator.

Zur Bestätigung den **Nachnamen** eingeben. Der Datenbankteil läuft ganz oder gar nicht; erst danach werden die Dateien vom Webspace entfernt und die OneDrive-Löschungen angestoßen. Meldet die Seite „Bitte erneut versuchen“, wurde nichts gelöscht. Die eigene Person und den letzten aktiven Administrator kann man so nicht löschen.

**Von Hand prüfen** (die Löschung kann das nicht):

- Freitexte in den Daten selbst (Notizen an Fahrzeugen, Decoder- und CV-Felder, Dateititel, Beschreibung, Notizen und „Was wurde gemacht“ bei Reparaturen, Wartungsnotizen, Bezeichnungen von Magazinartikeln und Angeboten) werden nicht durchsucht – nur die oben genannten Freitexte in Magazin und Börse. Namen in anderer Schreibweise (z. B. nur der Nachname) findet die Ersetzung nicht.
- An den Verein übergebene Fahrzeuge behalten alle Dokumente. **Rechnungen** mit Name oder Adresse vorher löschen.
- Telefonnummern werden nur in der gespeicherten Schreibweise ersetzt.
- Läuft die OneDrive-Verbindung über das persönliche Microsoft-Konto der Person, unter **Verwaltung → OneDrive** mit dem Vereinskonto neu verbinden.
- **Ältere Datensicherungen** enthalten die Person weiterhin. Deshalb Sicherungen nach **12 Monaten vernichten**.

Die bisherige einfache Löschung („Löschen“ in der Personenliste) bleibt für Personen ohne Zugang und ohne Fahrzeuge bestehen.

## OneDrive anbinden

Fotos und Dokumente können zusätzlich im OneDrive des Vereins abgelegt werden, lesbar sortiert nach Fahrzeug: `Fahrzeuge/<Inventarnummer> <Bezeichnung>/<Titel>.<Endung>`, Dokumente ohne Fahrzeug unter `Sonstiges/`. Die Anbindung ist **freiwillig**: Ohne sie arbeitet die Anwendung wie bisher und speichert alles nur auf dem Webspace. Vorschaubilder bleiben immer auf dem Webspace, damit Listen und Fotos schnell laden.

Die Einrichtung dauert etwa eine Stunde und wird nur einmal gemacht. Beteiligt sind der **Vorstand** (Vereinskonto, Freigabe des Ordners) und der **Administrator des Webspace** (App-Registrierung, `config.php`). Die Menünamen bei Microsoft ändern sich gelegentlich; die Bezeichnungen sind deshalb sinngemäß zu verstehen.

### 1. Vereins-Microsoft-Konto anlegen (Vorstand)

1. Unter <https://signup.live.com> ein **neues, kostenloses Microsoft-Konto** für den Verein anlegen, z. B. mit einer Vereins-E-Mail-Adresse. Kein privates Konto eines Mitglieds verwenden: Das Konto gehört dem Verein und bleibt bestehen, wenn Ämter wechseln.
2. Passwort und Wiederherstellungsdaten (Telefonnummer, Ersatz-E-Mail) sicher beim Vorstand hinterlegen, am besten zusätzlich die Zwei-Faktor-Anmeldung einschalten.
3. Einmal unter <https://onedrive.live.com> mit dem Vereinskonto anmelden, damit das OneDrive des Kontos eingerichtet wird.

### 2. Vorhandenen EMC-Ordner freigeben (Vorstand)

Liegt der bisherige Vereinsordner im OneDrive einer Privatperson, bleibt er dort; er wird nur für das Vereinskonto freigegeben:

1. Die Person, der der Ordner gehört, meldet sich bei OneDrive an, klickt den Ordner mit der rechten Maustaste an → **„Teilen“** bzw. **„Zugriff verwalten“**.
2. Die E-Mail-Adresse des **Vereinskontos** eintragen und **„Kann bearbeiten“** wählen (nur Lesen reicht nicht, die Anwendung legt Ordner und Dateien an). Senden.
3. Im **Vereinskonto** bei OneDrive anmelden → **„Geteilt“** bzw. **„Für mich freigegeben“** → beim EMC-Ordner **„Zu meinen Dateien hinzufügen“** wählen. Erst danach ist der Ordner bei der Zielordner-Auswahl der Anwendung sicher zu finden.

Gibt es noch keinen Ordner, legt man im Vereinskonto einfach einen neuen an (z. B. „EMC 78 Fahrzeuge“).

### 3. App bei Microsoft registrieren (Administrator)

Damit die Anwendung im Namen des Vereinskontos Dateien ablegen darf, wird sie einmal bei Microsoft „registriert“. Das kostet nichts.

1. Zuerst in der Anwendung als Administrator **Verwaltung → OneDrive** öffnen. Oben steht die **Rückruf-URL**, z. B. `https://mitglieder.emc78.de/admin/onedrive/rueckruf`. Erscheint dort noch keine Adresse, zuerst `oeffentliche_url` in der `config.php` eintragen (siehe Schritt 4) und die Seite neu laden.
2. Das **Microsoft Entra Admin Center** (<https://entra.microsoft.com>) oder das **Azure-Portal** (<https://portal.azure.com>) öffnen und mit dem **Vereinskonto** anmelden. Beim ersten Mal fragt Microsoft eventuell nach Angaben zum Konto; das ist kostenlos.
3. Sinngemäß **„Identität“ → „Anwendungen“ → „App-Registrierungen“** (im Azure-Portal: „App-Registrierungen“) → **„Neue Registrierung“**:
   - **Name:** z. B. `EMC 78 Lokschuppen`
   - **Unterstützte Kontotypen:** **„Nur persönliche Microsoft-Konten“**
   - **Umleitungs-URI:** Plattform **„Web“**, als Adresse **exakt** die Rückruf-URL von der OneDrive-Seite der Anwendung (Groß-/Kleinschreibung, `https://`, kein Schrägstrich am Ende). Weicht sie nur um ein Zeichen ab, meldet Microsoft beim Verbinden einen Fehler.
   - **„Registrieren“** klicken.
4. Auf der Übersichtsseite der neuen App die **„Anwendungs-ID (Client)“** kopieren. Das ist später `onedrive_client_id`.
5. **„Zertifikate & Geheimnisse“** → **„Geheime Clientschlüssel“** → **„Neuer geheimer Clientschlüssel“**: Beschreibung z. B. `Webspace`, Ablauf wählen (höchstens 24 Monate). Danach sofort den **„Wert“** kopieren (nicht die „Geheimnis-ID“): Er wird nur dieses eine Mal angezeigt. Das ist später `onedrive_client_secret`.
   **Wichtig:** Das **Ablaufdatum notieren** und rechtzeitig vorher eine Erinnerung setzen (Kalender des Vorstands). Vor dem Ablauf auf demselben Weg einen neuen Schlüssel anlegen, in der `config.php` ersetzen und den alten löschen. Ist der Schlüssel abgelaufen, zeigt die OneDrive-Seite einen Anmeldefehler (z. B. `invalid_client`), und neue Dateien bleiben nur auf dem Webspace, bis der neue Schlüssel eingetragen ist.
6. **„API-Berechtigungen“** → **„Berechtigung hinzufügen“** → **„Microsoft Graph“** → **„Delegierte Berechtigungen“** und diese drei auswählen:
   - `Files.ReadWrite.All` (Dateien lesen und schreiben, auch in freigegebenen Ordnern)
   - `offline_access` (Verbindung bleibt bestehen, ohne jedes Mal neu anzumelden)
   - `User.Read` (Name des verbundenen Kontos anzeigen)

   „Berechtigungen hinzufügen“ klicken. Eine Administratorzustimmung ist bei persönlichen Konten nicht nötig; die Zustimmung erfolgt beim Verbinden.

**Testumgebung:** Die Testinstanz hat eine eigene Rückruf-URL (`https://test.emc78.de/admin/onedrive/rueckruf`). Entweder eine **zweite App-Registrierung** für die Testinstanz anlegen (empfohlen, mit eigenem Testordner) oder bei derselben App unter **„Authentifizierung“** beide Umleitungs-URIs eintragen. Die Testinstanz sollte nie den echten Vereinsordner als Zielordner verwenden und nie mit einer eingespielten Sicherung des Echtbetriebs verbunden werden (siehe „Testumgebung“).

### 4. `config.php` ergänzen (Administrator)

Die `config.php` per SFTP herunterladen, diese Einträge ergänzen (Vorlage: `config.example.php`) und wieder hochladen:

```php
'oeffentliche_url' => 'https://mitglieder.emc78.de',      // Testinstanz: 'https://test.emc78.de'
'onedrive_client_id' => '<Anwendungs-ID (Client) aus Schritt 3.4>',
'onedrive_client_secret' => '<Wert des geheimen Clientschlüssels aus Schritt 3.5>',
'app_schluessel' => '<44 Zeichen, siehe unten>',
'onedrive_originale_lokal_behalten' => true,
'onedrive_zeitlimit_s' => 20,
```

- **`oeffentliche_url`:** Adresse der Installation, nur `https://`, ohne Pfad und ohne Schrägstrich am Ende. Liegt die Anwendung in einem Unterordner, kommt dieser über `basis_url` automatisch dazu.
- **`app_schluessel`:** Damit werden die Anmeldedaten von Microsoft verschlüsselt in der Datenbank gespeichert. Es sind 32 Zufallsbytes, base64-kodiert (44 Zeichen). Entweder den **Vorschlag auf der OneDrive-Seite** übernehmen (er wird bei jedem Aufruf neu erzeugt und nirgends gespeichert) oder per SSH erzeugen:

  ```bash
  php -r "echo base64_encode(random_bytes(32));"
  ```

  **Den Schlüssel später nicht mehr ändern.** Ein neuer Schlüssel macht die gespeicherte Verbindung unlesbar; die Seite zeigt dann „abgelaufen“, und OneDrive muss neu verbunden werden. Den Schlüssel zusammen mit der `config.php` aufbewahren. Echtbetrieb und Testinstanz bekommen je einen eigenen Schlüssel.
- **`onedrive_originale_lokal_behalten`:** `true` (Standard und empfohlen): Die Originale bleiben zusätzlich auf dem Webspace; die Datensicherung der Anwendung bleibt dadurch vollständig. `false`: Originale werden nach der Übertragung vom Webspace entfernt und liegen nur noch im OneDrive (spart Speicherplatz). Die Datensicherung enthält diese Originale dann nicht mehr; die Seite „Sicherung“ weist darauf hin, und der OneDrive-Ordner muss selbst gesichert werden.
- **`onedrive_zeitlimit_s`:** Wartezeit je Anfrage an OneDrive in Sekunden (Standard 20). Nur bei sehr langsamer Verbindung erhöhen.

`onedrive_client_secret` und `app_schluessel` stehen nur in der `config.php`. Diese Datei niemals weitergeben, per E-Mail verschicken oder in `public/` ablegen.

Für die Verbindung braucht PHP die Erweiterungen `curl` und `openssl` (bei STRATO in der Regel aktiv).

### 5. Verbinden (Administrator)

1. **Verwaltung → OneDrive** neu laden. Steht bei allen Einträgen „gesetzt“, erscheint der Knopf **„Mit OneDrive verbinden“**.
2. Klicken, bei Microsoft mit dem **Vereinskonto** anmelden (nicht mit einem privaten Konto) und den Zugriff erlauben.
3. Man landet wieder in der Anwendung. Status: **verbunden**, darunter das Konto.

Bitte nur in **einem** Browserfenster verbinden; parallele Versuche brechen sich gegenseitig ab („ungültig oder abgelaufen“), dann einfach erneut verbinden.

### 6. Zielordner wählen (Administrator)

Nach dem ersten Verbinden öffnet sich die Ordnerauswahl (später über **„Zielordner wählen“**). Den EMC-Ordner aus Schritt 2 öffnen bzw. auswählen und speichern. Darin legt die Anwendung die Unterordner `Fahrzeuge/` und `Sonstiges/` an. Ab jetzt wird jede neu hochgeladene Datei sofort übertragen. Solange eine Übertragung noch aussteht, steht in der Dateiliste des Fahrzeugs „wird übertragen“; für die Mitglieder ändert sich sonst nichts.

### 7. Vorhandene Dateien umziehen (Administrator)

Fotos und Dokumente, die schon vor der Anbindung hochgeladen wurden, liegen nur auf dem Webspace. Mit **„Vorhandene Dateien nach OneDrive umziehen“** werden sie alle für die Übertragung vorgemerkt. Pro Klick werden nur wenige Dateien gleich übertragen, damit die Seite nicht hängt; den Rest erledigt der Cronjob (Schritt 8) oder wiederholtes Klicken auf **„Jetzt übertragen“**. Der Zähler „wartet“ zeigt, wie viele noch offen sind. Der Knopf kann gefahrlos mehrfach gedrückt werden; nichts wird doppelt übertragen. Er setzt außerdem **fehlgeschlagene Löschaufträge** (Status „Fehler“) zurück, sodass sie erneut versucht werden.

### 8. Optional: automatische Übertragung per Cronjob

Ohne Cronjob werden Dateien beim Hochladen und bei Klicks auf „Jetzt übertragen“ übertragen. Außerdem erledigt die Anwendung bei jedem erfolgreichen Hochladen und nach dem Löschen einer Datei oder eines Fahrzeugs nebenbei bis zu zwei weitere liegen gebliebene Aufträge. Wartet ein Auftrag länger als eine Stunde, zeigt die Anwendung den Administratoren oben einen Hinweis. Ein Cronjob holt alles zuverlässig und ohne Zutun nach, was wegen einer Störung liegen geblieben ist. Empfohlen: **alle 15 Minuten**

```bash
php bin/onedrive-sync.php
```

- **STRATO:** Im Kundenbereich (sinngemäß „Datenbanken und Webspace“ → „Cronjob-Verwaltung“ bzw. „Cronjobs“; nicht in jedem Paket enthalten) einen neuen Cronjob anlegen: als Skript `/mitglieder/bin/onedrive-sync.php` angeben, eine **PHP-Version ab 8.2** wählen und als Intervall alle 15 Minuten (oder das kleinste angebotene Intervall) einstellen. Für die Testinstanz entsprechend `/test/bin/onedrive-sync.php`.
- **Mit SSH/crontab:** `*/15 * * * * php /pfad/zu/mitglieder/bin/onedrive-sync.php`

Das Skript liegt außerhalb von `public/` und ist nicht über den Browser erreichbar; es arbeitet nur, wenn es ohne Web-Anfrage gestartet wird (Kommandozeile, Cronjob – auch wenn der Hoster PHP dafür als `php-cgi` startet). Läuft es gleichzeitig mit einem Klick auf „Jetzt übertragen“, wird trotzdem nichts doppelt übertragen. **Kontrolle:** In der Ausgabe bzw. im Protokoll des Cronjobs muss nach dem ersten Lauf eine Zeile wie `OneDrive: 0 übertragen, 0 fehlgeschlagen; offen: 0, dauerhaft fehlerhaft: 0` oder `OneDrive ist nicht verbunden oder nicht eingerichtet; nichts zu tun.` stehen. Steht dort nichts oder eine Fehlermeldung, läuft der Cronjob nicht richtig (PHP-Version und Pfad prüfen).

### Verhalten bei Störungen

- **OneDrive kurz nicht erreichbar oder überlastet:** Nichts geht verloren. Die Datei ist bereits auf dem Webspace gespeichert und wartet in der **Warteschlange**. Neue Versuche folgen nach 1, 5, 30 und 120 Minuten, danach stündlich (per Cronjob oder Klick). Nach 20 erfolglosen Versuchen oder bei einem dauerhaften Fehler (z. B. Ordner nicht mehr beschreibbar, OneDrive voll) steht der Auftrag auf **„Fehler“**; die OneDrive-Seite zeigt die letzten Fehlermeldungen. Nach Behebung der Ursache **„Vorhandene Dateien nach OneDrive umziehen“** klicken, dann werden auch diese Dateien erneut übertragen und fehlgeschlagene Löschaufträge erneut versucht.
- **Status „abgelaufen – bitte neu verbinden“:** Microsoft hat die Anmeldung beendet (z. B. Passwort des Vereinskontos geändert, Zugriff widerrufen, lange nicht benutzt) oder der `app_schluessel` wurde geändert. Administratoren sehen dann oben einen Hinweis. Neue Dateien werden weiter angenommen und warten. Auf der OneDrive-Seite **„Mit OneDrive verbinden“** klicken und mit dem Vereinskonto anmelden; danach wird die Warteschlange fortgesetzt.
- **Anmeldefehler wie `invalid_client`:** meist ein abgelaufener oder falsch kopierter Clientschlüssel (Schritt 3.5). Neuen Schlüssel anlegen und in der `config.php` eintragen.
- **Original nur im OneDrive, OneDrive nicht erreichbar** (nur bei `onedrive_originale_lokal_behalten` = `false`): Fotos werden weiter angezeigt (Vorschau und Anzeigegröße liegen auf dem Webspace); nur das Herunterladen des Originals ist so lange nicht möglich, die Anwendung zeigt dann einen Hinweis.

### Gut zu wissen

- **Anderes Microsoft-Konto:** Wird bei „Neu verbinden“ ein anderes Konto angemeldet als vorher, setzt die Anwendung den Zielordner zurück (der alte gehört zum alten Konto) und öffnet die Ordnerauswahl. Lässt sich das Konto beim Verbinden nicht erkennen, wird vorsichtshalber nichts übertragen, bis der Zielordner geprüft ist. Alle Änderungen an Verbindung und Zielordner stehen im Änderungsprotokoll.
- **Zielordner in einem anderen OneDrive wählen** (z. B. erst ein Ordner im Vereinskonto, später der freigegebene EMC-Ordner einer Privatperson, oder nach einem Kontowechsel): Bereits übertragene Dateien werden **nicht verschoben**; sie bleiben im bisherigen Ordner. Jede Datei merkt sich ihr OneDrive, deshalb kann die Anwendung sie weiter anzeigen und löschen, **solange das verbundene Konto Zugriff auf den bisherigen Ordner hat**. Die Ordnerauswahl warnt in diesem Fall („… Dateien liegen in einem anderen OneDrive …“). Wird der Zugriff auf den alten Ordner entzogen, sind Originale, die nur dort liegen (`onedrive_originale_lokal_behalten` = `false`), nicht mehr abrufbar.
- **Viele Dateien auf einmal hochladen:** Je Seitenaufruf beginnt die Anwendung nach etwa 25 Sekunden keine neuen Übertragungen mehr (`onedrive_zeitbudget_s` in der `config.php`), damit die Seite nicht in eine Zeitüberschreitung läuft. Die übrigen Dateien sind trotzdem gespeichert, stehen in der Warteschlange und werden später übertragen („wird übertragen“).
- **Fahrzeug umbenannt:** Bereits übertragene Dateien bleiben im Ordner mit dem alten Namen; neue Dateien kommen in einen Ordner mit dem neuen Namen. Wer es aufgeräumt haben möchte, benennt den Ordner im OneDrive von Hand um und verschiebt ggf. die Dateien. Die Anwendung findet ihre Dateien weiterhin, weil sie sie über eine interne Kennung anspricht.
- **Trennen** auf der OneDrive-Seite: Die Anmeldung bei Microsoft wird gelöscht, der Zielordner bleibt gespeichert. Bereits übertragene Dateien **bleiben im OneDrive**. Neue Dateien werden weiter auf dem Webspace gespeichert und **in der Warteschlange gesammelt**; auch Löschungen werden vorgemerkt. Beides wird nach dem erneuten Verbinden nachgeholt (bei einem anderen Konto erst nach Wahl des neuen Zielordners). Bei `onedrive_originale_lokal_behalten` = `false` lassen sich Originale, die nur im OneDrive liegen, bis zum erneuten Verbinden nicht herunterladen; Vorschau und Anzeige funktionieren weiter.
- **Entfernen der OneDrive-Einträge aus der `config.php`:** Die Anwendung arbeitet wieder rein lokal; neue Dateien werden nicht mehr vorgemerkt. Bereits übertragene Dateien bleiben im OneDrive, und wird eine solche Datei in der Anwendung gelöscht, bleibt sie im OneDrive liegen (dort von Hand löschen). Originale, die nur im OneDrive liegen, sind dann nicht mehr abrufbar.
- **Datenschutz:** Die Anwendung erzeugt **keine öffentlichen Freigabelinks**. Mitglieder sehen und laden Dateien weiterhin nur nach Anmeldung in der Anwendung; einen direkten Zugriff auf das OneDrive brauchen sie nicht. Die Anmeldedaten von Microsoft (Tokens) liegen nur verschlüsselt in der Datenbank, der Clientschlüssel nur in der `config.php`. Nur Administratoren können verbinden, trennen, den Zielordner ändern und Übertragungen starten. Wer Zugang zum Vereinskonto bei Microsoft hat, sieht alle Dateien im Ordner – das Passwort deshalb nur dem Vorstand geben.

## Entwicklung

Voraussetzungen: PHP ≥ 8.2, Composer, MariaDB oder MySQL.

```bash
composer install
# Testdatenbank anlegen (einmalig)
mysql -u root -e "CREATE DATABASE emc_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# Tests ausführen (Zugangsdaten über Umgebungsvariablen anpassbar)
TEST_DB_PASSWORT=geheim vendor/bin/phpunit
# Lokal starten (config.php aus config.example.php erstellen, cookie_secure auf false)
php -S localhost:8080 -t public
```

Umgebungsvariablen für die Tests: `TEST_DB_DSN` (Standard `mysql:host=127.0.0.1;dbname=emc_test;charset=utf8mb4`), `TEST_DB_BENUTZER` (Standard `root`), `TEST_DB_PASSWORT`. Ein Test der Wiederherstellung legt kurzzeitig die Datenbank `emc_test_wiederherstellung` an und löscht sie wieder; der Datenbankbenutzer braucht dafür das Recht `CREATE`/`DROP`.

**Datenbank-Änderungen** erfolgen ausschließlich als neue, aufsteigend nummerierte Dateien in `migrations/` (z. B. `003_xyz.sql`). Bereits veröffentlichte Dateien werden nicht mehr geändert.

**Release bauen:**

```bash
bash bin/release.sh
```

Das Skript baut aus dem aktuellen Stand von `HEAD` (nur eingecheckte Dateien) im Ordner `build/` ein Paket ohne Tests (nur die Beispielfotos der Testumgebung liegen bei) und Entwicklungsdateien, installiert die Produktions-Abhängigkeiten (`chillerlan/php-qrcode` mit `chillerlan/php-settings-container` und `smalot/pdfparser` mit `symfony/polyfill-mbstring`; lädt Composer ein Paket aus den Quellen statt als ZIP, wird es wie beim ZIP ohne `.git`, Tests und Doku des Pakets übernommen) und erzeugt `dist/emc78-verwaltung-<Version>.zip`. Die Version steht in der Datei `VERSION`.

Die App-Symbole (`public/assets/icons/`, aus dem Vereinslogo) und die Beispielfotos (`tests/fixtures/demo/`, Platzhalter) sind mit `php bin/erzeuge-bilder.php` erzeugt und eingecheckt; das Skript muss nur laufen, wenn sich das Aussehen ändern soll.

# EMC 78 Lokschuppen

Webanwendung „EMC 78 Lokschuppen“ des Eisenbahn Modellbahn Clubs 78 e.V. Halle („Die Modellbahnfamilie“) zur Verwaltung des Fahrzeugbestands: Fahrzeuge, Decoder (Adressen, Funktionstasten, CVs), Fotos und Dokumente, Personen und Benutzer. Die Anwendung läuft auf normalem PHP-Webspace und ist für PC, Tablet und Handy gedacht.

## Rollen und Rechte

Die Rollen bauen aufeinander auf; jede höhere Rolle darf alles, was die darunter darf.

| Rolle | Darf |
|---|---|
| **Mitglied** | alles lesen (Fahrzeuge, Decoder, Funktionen, CVs, Dateien); **eigene Fahrzeuge anlegen** (der Eigentümer ist immer das Mitglied selbst); die **selbst angelegten Fahrzeuge bearbeiten**, einschließlich Status (auch „ausgemustert“), Decoder, Funktionstasten (Formular, CSV-Import, Vorlagen), CVs sowie Fotos und Dateien dieses Fahrzeugs. Nicht erlaubt: Eigentümer ändern, Fahrzeuge löschen, Fahrzeuge anderer bearbeiten (auch nicht als Eigentümer), Personen verwalten |
| **Fahrzeugwart** | zusätzlich **alles rund um alle Fahrzeuge, auch die privaten**: anlegen (Eigentümer frei wählbar), bearbeiten, **löschen**, Decoder, Funktionstasten, CVs sowie Fotos und Dokumente an Fahrzeugen. Nicht erlaubt: Personen, Benutzer, Stammdaten und Betrieb (Sicherung, Update, OneDrive); eine Datei, die mehreren Objekten zugeordnet ist, löscht nur ein Bearbeiter |
| **Bearbeiter** | zusätzlich Personen verwalten und **alle** Dateien (auch geteilte) löschen |
| **Administrator** | zusätzlich Benutzer und Rollen, Stammdaten (Spurweiten), Datensicherung, Update und OneDrive-Anbindung |

Jedes Fahrzeug merkt sich, wer es angelegt hat. Fahrzeuge, die schon vor Version 0.2.0 vorhanden waren, gelten als von einem Bearbeiter angelegt; Mitglieder können sie nicht ändern. Alle Rechte werden auf dem Server für jedes einzelne Fahrzeug geprüft, nicht nur durch ausgeblendete Schaltflächen. Eine Datei, die mehreren Objekten zugeordnet ist, kann nur ein Bearbeiter löschen. Ausgemusterte Fahrzeuge verschwinden aus „Meine Fahrzeuge“ und sind über den Statusfilter der Fahrzeugliste zu finden. Gespeicherte Funktionsvorlagen sind für alle sichtbar.

Beim Update auf 0.2.0 muss nach dem Hochladen der neuen Dateien einmal im Verwaltungsmenü unter „Update“ die Datenbank-Migration `003_fahrzeug_angelegt_von` ausgeführt werden.

Version 0.3.0 bringt die freiwillige OneDrive-Ablage (siehe „OneDrive anbinden“). Beim Update auf 0.3.0 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei laufen die Migrationen `004_onedrive`, `005_onedrive_auftrag_sperre` und `006_onedrive_sperr_token_index`. Ohne OneDrive-Einträge in der `config.php` ändert sich sonst nichts.

Version 0.3.1 verbessert die OneDrive-Ablage: Jede übertragene Datei merkt sich, in welchem OneDrive sie liegt (sie bleibt auch nach einem Wechsel des Zielordners abrufbar und löschbar), liegen gebliebene Übertragungen werden auch ohne Cronjob nebenbei nachgeholt, und der Cronjob läuft auch, wenn der Hoster PHP dafür als `php-cgi` startet (in 0.3.0 brach `bin/onedrive-sync.php` mit einem Syntaxfehler ab). Beim Update auf 0.3.1 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei läuft die Migration `007_onedrive_laufwerk`. Wer direkt von 0.2.x kommt, bekommt 004–007 in einem Schritt.

Version 0.4.0 bringt die Rolle **Fahrzeugwart** und die **Löschung einer Person nach DSGVO** (siehe „Person nach DSGVO löschen“). Beim Update auf 0.4.0 unter **Verwaltung → Update** einmal „Datenbank jetzt aktualisieren“ wählen; dabei läuft die Migration `008_rolle_fahrzeugwart`. Bestehende Zugänge behalten ihre Rolle.

## Voraussetzungen

- PHP ab Version 8.2 mit den Erweiterungen `pdo_mysql`, `mbstring`, `gd`, `fileinfo` und `zip`, für die OneDrive-Ablage zusätzlich `curl` und `openssl`
- MySQL ab Version 8 oder MariaDB ab Version 10.6
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

   Beides **löscht alle vorhandenen Daten der Testinstanz** und legt neu an: 7 erfundene Personen, 12 Fahrzeuge in H0 und N (Verein und Mitglieder, ein ausgemustertes), Decoder mit Funktionstasten und CVs, Beispielfotos und absichtlich **eine doppelte DCC-Adresse in H0**, an der man die Warnung der Adressprüfung sieht. Nach dem Laden im Browser wird man abgemeldet.
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

Alle Inhalte sind erst nach der Anmeldung sichtbar. Benutzer legt ein Administrator unter **Verwaltung → Benutzer** an.

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

Solange ein Datenbank-Update aussteht, sehen alle anderen Benutzer eine Wartungsseite. Nach dem Update ist alles wieder normal nutzbar.

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
- **Eigene Fahrzeuge** der Person werden samt Fotos und Dokumenten (Webspace und OneDrive), Decodern, Funktionstasten und CVs mitgelöscht. Fahrzeuge, die in der Vorschau mit **„an den Verein übergeben“** angehakt werden, bleiben erhalten; Eigentümer wird der Verein.
- Bezüge in Vereinsdaten (z. B. „angelegt von“, „hochgeladen von“) werden entfernt und als „ehemaliges Mitglied“ angezeigt.
- Im Änderungsprotokoll verschwinden die Einträge zur Person und zu ihren Zugängen; in allen übrigen Einträgen werden Name, E-Mail und Telefon (auch frühere Werte) durch „ehemaliges Mitglied“ ersetzt (außer einer E-Mail-Adresse, die auch eine andere Person nutzt, z. B. eine Familienadresse). Übrig bleibt genau ein Nachweis „Person Nr. N nach DSGVO gelöscht“ mit dem ausführenden Administrator.

Zur Bestätigung den **Nachnamen** eingeben. Der Datenbankteil läuft ganz oder gar nicht; erst danach werden die Dateien vom Webspace entfernt und die OneDrive-Löschungen angestoßen. Meldet die Seite „Bitte erneut versuchen“, wurde nichts gelöscht. Die eigene Person und den letzten aktiven Administrator kann man so nicht löschen.

**Von Hand prüfen** (die Löschung kann das nicht):

- Freitexte in den Daten selbst (Notizen an Fahrzeugen, Decoder- und CV-Felder, Dateititel) werden nicht durchsucht.
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

Das Skript baut aus dem aktuellen Stand von `HEAD` (nur eingecheckte Dateien) im Ordner `build/` ein Paket ohne Tests (nur die Beispielfotos der Testumgebung liegen bei) und Entwicklungsdateien, installiert die Produktions-Abhängigkeiten und erzeugt `dist/emc78-verwaltung-<Version>.zip`. Die Version steht in der Datei `VERSION`.

Die App-Symbole (`public/assets/icons/`, aus dem Vereinslogo) und die Beispielfotos (`tests/fixtures/demo/`, Platzhalter) sind mit `php bin/erzeuge-bilder.php` erzeugt und eingecheckt; das Skript muss nur laufen, wenn sich das Aussehen ändern soll.

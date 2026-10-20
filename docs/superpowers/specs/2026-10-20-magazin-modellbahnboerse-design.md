# Teil 2e – Magazin und Modellbahnbörse

Ergänzung zur Hauptspezifikation `2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md` und zur Stufe-2-Ergänzung `2026-10-20-stufe-2-wartung-reparatur-design.md`. Umsetzung als **Teil 2e** nach 2a–2d.

Stand: 20.10.2026 · abgestimmt mit dem Vorstand im Gespräch.

## 1. Ziel und Erfolgskriterien

- Das **Magazin** ist die Inventarliste von allem, was der Verein rund um die Modellbahn besitzt (auch Literatur), mit nachvollziehbarem **Eingang und Ausgang**.
- Die **Modellbahnbörse** sammelt alles, was abgegeben werden soll – Vereinsstücke aus dem Magazin (einzeln oder als Sammlung) und **eigene Sachen von Mitgliedern** – und unterstützt die Vorbereitung und Durchführung einer Modellbahnbörse (Veranstaltung): Preisschilder, Kasse am Handy, Abrechnung.
- **Besucher der Webseite** sehen freigegebene Angebote und können ohne Anmeldung eine **Kaufanfrage** stellen oder eine **Sammlung anbieten**.
- PayPal oder andere Online-Zahlungen sind **nicht** Teil dieses Teils; das Datenmodell verhindert eine spätere Ergänzung nicht.

## 2. Rechte

- **Börsenwart** ist eine **Zusatzaufgabe** am Benutzerkonto (Häkchen `ist_boersenwart`), unabhängig von der Rolle (Mitglied, Fahrzeugwart …). Bearbeiter und Administrator dürfen alles, was der Börsenwart darf.
- **Alle Mitglieder:** Magazin lesen, Börse intern lesen, **eigene Angebote** anlegen, bearbeiten, zurückziehen und zur Veröffentlichung vorschlagen.
- **Börsenwart (und höher):** Magazin buchen (Eingang, Ausgang, Umlagerung), Sammlungen bilden, Magazinartikel in die Börse übernehmen, Angebote prüfen und **öffentlich freigeben**, Börsen anlegen, Kasse, Abrechnung, Auszahlung, Anfragen bearbeiten.
- **Administrator:** zusätzlich abgeschlossene Börse wieder öffnen, Kategorien pflegen.

## 3. Magazin

**Kategorien** (Stammliste, Startbestand): Gleise und Weichen · Fahrzeuge · Elektrik und Digital (Trafos, Zentralen, Decoder, Kabel) · Gebäude und Bausätze · Landschaft und Figuren · Ersatzteile · Werkzeug · Literatur (Bücher, Zeitschriften, Kataloge, Anleitungen) · Sonstiges.

**Artikel:** Code `M<n>` (automatisch, vom Feld „Code“ erkannt), Bezeichnung, Kategorie, Hersteller, Artikelnummer, Spurweite (optional), Zustand (`neu`, `neuwertig`, `gebraucht`, `defekt`), **Bestand** (Menge, aus den Buchungen berechnet), Lagerort, Fotos, Notiz; bei Literatur zusätzlich Autor/Verlag, Jahr, ISBN. Optional Verweis auf ein Fahrzeug (siehe unten).

**Sammlung:** Bündel mehrerer Artikel mit Name und Notiz (z. B. „Nachlass Märklin-Gleise, 3 Kisten“); lässt sich als Ganzes umlagern oder in die Börse übernehmen.

**Buchungen** (unveränderlich; Korrektur durch Gegenbuchung):

| Art | Angaben |
|---|---|
| Eingang | Datum, Herkunft (`spende`, `nachlass`, `kauf`, `fund`, `sonstiges`) mit Freitext („Spende von …“), Menge, optional Wert |
| Ausgang | Datum, Art (`gegen_spende`, `verkauft_boerse`, `verschrottet`, `ausgeliehen`, `sonstiges`), Menge, Betrag, an wen (Freitext) |
| Umlagerung | neuer Lagerort |

Der Bestand darf nicht negativ werden. Jede Buchung steht im Verlauf des Artikels mit Benutzer und Zeitpunkt.

**Fahrzeuge vom Abstellgleis:** Auf der Seite eines ausgemusterten Fahrzeugs gibt es „An Magazin übergeben“ (Börsenwart oder Fahrzeugwart und höher). Daraus entsteht ein Magazinartikel (Kategorie Fahrzeuge, Menge 1, Titelfoto übernommen, Verweis auf das Fahrzeug). Nach dem Ausgang des Artikels zeigt das Fahrzeug „abgegeben am … (Magazin M37)“; das Fahrzeug selbst bleibt als Datensatz erhalten (Status `ausgemustert`).

## 4. Modellbahnbörse intern

**Börse (Veranstaltung):** Name, Datum, Ort, **Provision in %** (Vorgabe 10, erlaubt 0–100), Status `in_vorbereitung → laeuft → abgeschlossen`.

**Angebot:** Angebotsnummer (fortlaufend je Jahr, z. B. `B2026-118`), Bezeichnung, Kategorie, Spurweite, Zustand, Fotos, Wunschpreis, Herkunft **Verein** (Verweis auf Magazinartikel oder Sammlung, Menge) oder **Mitglied** (Person), Häkchen **„Erlös als Spende an den Verein“** (nur bei Mitgliedern), Zuordnung zu einer Börse oder „dauerhaft“, Status `angeboten → reserviert → verkauft` bzw. `zurueck`, **öffentlich**: `nein` / `vorgeschlagen` / `freigegeben`.

- Mitglieder können ihr Angebot zur Veröffentlichung **vorschlagen**; sichtbar wird es erst nach **Freigabe** durch den Börsenwart. Vereinsangebote gibt der Börsenwart direkt frei.
- Wird ein freigegebenes Angebot geändert (Preis, Fotos, Text) durch ein Mitglied, fällt es auf `vorgeschlagen` zurück.

**Preisschilder:** Druck aus dem Browser mit Angebotsnummer (groß), Bezeichnung, Preis, kleinem QR-Code (Kurzlink auf das Angebot); Gesamtliste je Börse (Nummer, Bezeichnung, Preis, Herkunft Verein/Mitglied).

## 5. Börsentag: Kasse und Abrechnung

**Kasse am Handy** (Börsenwart und höher): Angebotsnummer eintippen oder suchen → Foto, Bezeichnung, Wunschpreis → Verkaufspreis (vorbelegt, änderbar) → mehrere Stücke zu einem **Verkauf** mit Summe und Zahlart (`bar`, `karte_ueberweisung`). Käufer werden **nicht** erfasst.

- Abschluss setzt jedes Stück auf `verkauft`; Vereinsstücke erhalten automatisch die Magazin-Buchung Ausgang `verkauft_boerse` mit Betrag.
- **Kein Doppelverkauf**, auch bei mehreren gleichzeitigen Kassen (Sperre beim Abschluss; zweiter Versuch erhält eine Meldung).
- **Storno** eines Verkaufs am selben Tag mit Begründung → Stücke wieder `angeboten`, Magazin-Gegenbuchung.

**Abrechnung je Börse:**
- Verein: Einnahmen aus Vereinsstücken, Provisionen, Spenden aus Mitgliederverkäufen, Summe nach Zahlart.
- Je Mitglied: verkaufte Stücke, Erlös, Provision (kaufmännisch auf Cent gerundet, je Stück), **Auszahlungsbetrag**; bei „Erlös als Spende“ Auszahlung 0 € und Ausweis als Spende. Haken „ausgezahlt am …“. Druckansicht.

**Börse abschließen:** Unverkaufte Vereinsstücke gehen zurück ins Magazin (Bestand unverändert, Angebot `zurueck`), Mitgliederstücke `zurueck`. Danach ist die Börse schreibgeschützt; nur der Administrator kann sie wieder öffnen.

## 6. Öffentlicher Bereich (ohne Anmeldung)

Erster Bereich der App ohne Anmeldung, unter `<oeffentliche_url>/boerse` (Link von www.emc78.de möglich).

- **Angebotsliste:** nur `freigegeben`e Angebote mit Status `angeboten` oder `reserviert` (als „reserviert“ gekennzeichnet); Foto (Anzeigegröße, keine Originale), Bezeichnung, Kategorie, Spurweite, Zustand, Preis, Angebotsnummer; Filter Kategorie und Spurweite, Suche; nächste Börse mit Datum und Ort. **Nie** angezeigt: Personennamen, Herkunft Verein/Mitglied, Notizen, Magazindaten, Lagerorte.
- **Kaufanfrage** (an einem Angebot): Name, E-Mail oder Telefon (eines Pflicht), Nachricht. Reserviert nicht automatisch.
- **Sammlung anbieten:** Name, Kontakt, Beschreibung, bis zu 5 Fotos (Bilder nach derselben Prüfung wie intern, Metadaten wie Aufnahmeort werden entfernt), Auswahl „verkaufen an den Verein“ oder „spenden“.
- **Schutz:** CSRF-Token, unsichtbares Fangfeld, Mindestausfüllzeit 3 s, höchstens 5 Anfragen je Stunde und IP-Adresse (IP nur gehasht und nur für diese Begrenzung, nach 24 h gelöscht), Größenbegrenzung der Fotos wie `upload_max_mb`.
- **Datenschutz:** Pflicht-Häkchen zur Einwilligung mit Link auf die Datenschutzerklärung (Konfigurationsschlüssel `datenschutz_url`); ohne gesetzte `datenschutz_url` ist der öffentliche Bereich **abgeschaltet**. Anfragen samt Fotos werden **12 Monate** nach Eingang automatisch gelöscht (bei Seitenaufrufen bzw. Cronjob), einzeln jederzeit von Hand.
- **Abschaltbar:** Konfigurationsschlüssel `boerse_oeffentlich` (Standard `false`), damit der Bereich erst nach Freigabe durch den Vorstand online geht. Die Testinstanz zeigt den Bereich nur mit deutlichem Testhinweis.

**Eingang in der App:** Anfragen erscheinen beim Börsenwart als Hinweis „N neue Börsenanfragen“ (wie Defektmeldungen); Status `offen → in_bearbeitung → erledigt`; Antwort per E-Mail aus dem eigenen Postfach über einen `mailto:`-Link mit vorbereitetem Betreff. Aus einer Anfrage „Sammlung anbieten“ kann der Börsenwart nach Annahme direkt einen Magazin-Eingang anlegen (Herkunft und Fotos übernommen).

## 7. Datenmodell (Überblick)

Neue Tabellen: `magazin_kategorie`, `magazin_artikel`, `magazin_sammlung`, `magazin_sammlung_artikel`, `magazin_buchung`, `boerse`, `boerse_angebot`, `boerse_verkauf`, `boerse_verkauf_position`, `boerse_auszahlung`, `boerse_anfrage`, `boerse_anfrage_sperre` (gehashte IP, Zeitpunkt). Änderung: `benutzer.ist_boersenwart` (bool). Fotos über `dokument_zuordnung` (Objekttypen `magazin_artikel`, `boerse_angebot`, `boerse_anfrage`). Geldbeträge als `DECIMAL(10,2)`. DSGVO-Löschung (Teil 2a) wird ergänzt: Angebote der Person werden gelöscht, sofern nicht verkauft; Verkaufs- und Abrechnungsposten bleiben ohne Personenbezug („ehemaliges Mitglied“).

## 8. Fehlerfälle

- Kasse: Angebot bereits verkauft / nicht in dieser Börse / Börse nicht `laeuft` → klare Meldung, kein Verkauf.
- Magazin-Ausgang über den Bestand → Fehler, nichts gebucht.
- Öffentliches Formular: Fangfeld gefüllt oder zu schnell → stilles Verwerfen mit normaler Dankeseite; Begrenzung überschritten → Meldung „Bitte später erneut versuchen“.
- Gleichzeitige Bearbeitung → bestehender Stand-Schutz.

## 9. Tests

PHPUnit für: Rechte (Börsenwart-Häkchen unabhängig von der Rolle, Mitglied nur eigene Angebote, Freigabe-Rückfall bei Änderung), Bestandsberechnung und Verbot negativen Bestands, Übergabe Fahrzeug → Magazin, Kasse (Doppelverkauf bei gleichzeitigen Abschlüssen, Storno mit Gegenbuchung), Abrechnung (Provision je Stück gerundet, 0 %, Spende), Abschluss der Börse, öffentlicher Bereich (nur freigegebene Angebote, keine Personendaten im HTML, Fangfeld, Mindestzeit, Begrenzung, abgeschaltet ohne `datenschutz_url`), automatische Löschung nach 12 Monaten.

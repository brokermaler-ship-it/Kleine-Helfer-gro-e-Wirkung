# Übergabe-Prompt: Auditprotokoll HSL CZ (Vorfälle und EBA-Meldungen)

> In eine Claude-Sitzung kopieren, die im Projektordner
> `007_Qualitätsmanagement/06_Kleine Helfer grosse Wirkung` arbeitet.

---

**Aufgabe:** Übernimm das fertige Auditprotokoll für das anlassbezogene Audit bei der HSL CZ in die Projektablage und schließe es nach Projektstandard ab.

**Hintergrund**
- Erstellt mit dem Skill `audit-protokoll-erstellen` als leere Vorlage für ein anlassbezogenes internes Audit.
- Anlass: Vorfälle und EBA-Meldungen.
- Auditor: Enrico Görzen (QMB).
- Ort und Zeit: Pirna, 10:00–14:00 Uhr (4 Stunden); der Auditplan steht auf Uhrzeiten.
- Datum und Teilnehmer sind noch offen.
- Quelle: GitHub-Repo `brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung`, Branch `ccr-4f6a765f-snbb46`, Ordner `docs/audits/`:
  - `Auditprotokoll_HSL-CZ_Vorfaelle_EBA-Meldungen.docx`: das Protokoll (19 Seiten)
  - `build_auditprotokoll_hsl_cz.py`: Generator (python-docx), erwartet `bowtie.png` im selben Ordner
  - `bowtie_img.py`: erzeugt `bowtie.png` (Pillow)
  - `bowtie.png`: Bow-Tie-Schaubild

**Inhalt des Protokolls**
1. Kopf „Auditdurchführung“ mit geplanter Dauer, Auditbericht und Übersicht über die 16 Kapitel samt Bewertungsskala.
2. Auditplan für 4 Stunden:
   - Zeitplan mit Gesprächspartnern
   - Liste der vorab anzufordernden Unterlagen: Ereignisregister der letzten 12 Monate, EBA-/DÚ-Meldeliste mit Aktenzeichen, Ursachenanalysen, Melde- und Notfallregelungen, SQAS-Bericht mit Maßnahmenplan, Risikotabelle, LP-One-Nachweise
3. Kapitel 1–15 nach HSL-Standardkatalog.
   - Kapitel 11 SQAS ist auf 12 Fragen erweitert (RID 1.3, 1.8.5, 1.10, Notfallübungen, Kennzahlen, Umwelt).
4. Kapitel 16 „Ereignis- und Meldemanagement / Top-Ereignisse“ mit 12 Fragen zu Meldewegen, Meldekette CZ/DE (EBA, Untersuchungsstelle, DÚ, DI), Einstufung der Meldungen, Fristen, Ereignisregister, Abgleich mit den Behördenmeldungen, Ursachenanalyse, Top-Ereignissen und Bow-Tie.
5. Abschließende Auswertung, Empfehlung mit Nachaudit (gemeinsam auf einer Seite) und Unterschriften.
6. Anlagen:
   - Anlage 1 Management Summary und Anlage 2 Team Summary, beide leer
   - Anlage 3 „Top-Ereignisse und Bow-Tie-Analyse“: Ranking nach Schwere × Häufigkeit, Bow-Tie-Schaubild und drei Bow-Tie-Arbeitsblätter mit Barrierenbewertung

**Schritte**
1. Lies `CLAUDE.md` und `ÜBERSICHT.md` im Projektordner und halte dich an die Ablagekonventionen.
2. Hole die Dateien aus dem GitHub-Branch, z. B. so:
   `git clone -b ccr-4f6a765f-snbb46 https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung.git`
3. Lege sie unter `Ausgaben/Audit_HSL_CZ_Vorfaelle_EBA-Meldungen/` ab.
   - Der Ordner ist in OneDrive bereits angelegt und leer.
   - Docx in den Ordner selbst, die drei Generator-Dateien in einen Unterordner `_Erzeugung/`.
4. Öffne das Dokument in Word und prüfe die Darstellung, besonders Anlage 3 und die Seitenumbrüche.
   - Bei Bedarf eine PDF-Fassung daneben ablegen.
5. Lass vor der Freigabe die Pflichtkette der Prüf-Agenten laufen (Tabelle in `CLAUDE.md`), mindestens `dokument-qs`. Bei Bedarf zusätzlich `norm-gap` für die Bezüge zu RID, EUV und VO 402/2013.
6. Passe die markierten Annahmen an oder lass sie bestätigen:
   - Tätigkeit der HSL CZ: „Eisenbahnverkehrsleistungen CZ/DE inkl. grenzüberschreitender Verkehre“
   - Bewertungsschwellen der Bow-Tie: ab 15 zwingend, 8–14 empfohlen. Das ist ein Vorschlag; an die HSL-Risikomatrix angleichen bzw. mit `HSL_Risikobewertung_v2_Konzept_v1.md` abgleichen (Bow-Tie statt RPZ).
   - Die Fachkapitel ISO 50001, EfB und Rangierbetrieb treffen bei der HSL CZ eventuell nicht zu (im Audit dann „0 = entfällt“).
7. Trage Datum und Teilnehmer ein, sobald sie feststehen.
8. Liegen Ereignisregister oder EBA-Meldeliste vor: Ermittle die Top-Ereignisse und fülle die Tabelle 3.1 sowie die Bow-Tie-Entwürfe (3.3 ff.) vor. Die Bewertung der Barrieren bleibt dem Audit vor Ort vorbehalten.
9. Nach dem Audit: Bewertungen eintragen und den Skill `audit-protokoll-auswerten` für Auswertung, Maßnahmen und Summaries verwenden.

**Fertig, wenn** das Protokoll mit den Generator-Dateien im Ausgaben-Ordner liegt, die Prüf-Agenten ohne offene Befunde durchgelaufen sind und die offenen Annahmen bestätigt oder angepasst sind.

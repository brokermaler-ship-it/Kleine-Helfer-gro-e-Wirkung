# Unterlagen für Betriebsrat und Datenschutz

> **Entwürfe, keine Rechtsberatung.** Die Unterlagen sind aus dem tatsächlichen Stand des
> Prototyps abgeleitet. Vor der Verwendung müssen Datenschutzbeauftragte, Rechtsabteilung
> bzw. Personalabteilung sie prüfen und an das Unternehmen anpassen. Platzhalter stehen in
> eckigen Klammern, z. B. `[Unternehmen]`.

| Nr. | Dokument | Für wen | Zweck |
| :--- | :--- | :--- | :--- |
| 1 | [Unterrichtung des Betriebsrats](01-betriebsrat-unterrichtung.md) | Betriebsrat | Frühzeitige, vollständige Information über System und Pilot |
| 2 | [Entwurf Betriebsvereinbarung Pilot](02-betriebsvereinbarung-pilot.md) | Betriebsrat, HR, Rechtsabteilung | Verhandlungsgrundlage für den Pilot |
| 3 | [Eintrag Verzeichnis von Verarbeitungstätigkeiten](03-verarbeitungsverzeichnis.md) | Datenschutzbeauftragte | Art. 30 DSGVO |
| 4 | [Datenschutz-Folgenabschätzung](04-dsfa.md) | Datenschutzbeauftragte | Art. 35 DSGVO |
| 5 | [Datenschutzhinweise für Teilnehmende](05-datenschutzhinweise.md) | Pilot-Teilnehmende | Art. 13 DSGVO, Transparenz nach Art. 50 KI-VO |
| 6 | [Prüfliste Anbieter](06-anbieterpruefung.md) | Einkauf, Datenschutz, IT | AVV, Drittlandübermittlung, Aufbewahrung |

## Empfohlene Reihenfolge

1. **Datenschutzbeauftragte früh einbinden** (Dokumente 3, 4, 6). Die DSFA klärt, welche
   Anbieter und Maßnahmen tragfähig sind. Davon hängt ab, was dem Betriebsrat vorgelegt wird.
2. **Betriebsrat unterrichten** (Dokument 1), sobald die Anbieterfrage grob geklärt ist,
   spätestens bevor Verträge geschlossen werden.
3. **Betriebsvereinbarung verhandeln** (Dokument 2). Die DSFA und die Prüfliste dienen als Anlagen.
4. **Datenschutzhinweise finalisieren** (Dokument 5) und vor dem Pilot an die Teilnehmenden geben.
5. **Pilot starten** erst, wenn Betriebsvereinbarung, AVVs und DSFA vorliegen.

## Offene Entscheidungen, die alle Dokumente betreffen

| # | Frage | Empfehlung | Auswirkung |
| :--- | :--- | :--- | :--- |
| E1 | Anbieter mit Sitz/Verarbeitung in den USA (Anthropic, Deepgram, Cartesia) oder EU-/On-Premise-Alternativen? | Für den Pilot US-Anbieter mit AVV, Standardvertragsklauseln bzw. DPF-Zertifizierung und EU-Region, wo verfügbar; Sovereign-Pfad als Rückfallebene | Dokumente 3, 4, 5, 6 |
| E2 | LiveKit selbst betreiben oder LiveKit Cloud? | Selbst betreiben (VM im Unternehmensnetz), dann entfällt ein Auftragsverarbeiter | Dokumente 3, 4, 6 |
| E3 | Lernziel-Status (`objectives`) mit Pilot-Kennung in `sessions.jsonl` speichern? | Ja für den Pilot, aber nur aggregierte Auswertung und kurze Löschfrist; alternativ ohne Kennung speichern | Dokumente 2, 4 (KI-VO-Einstufung) |
| E4 | Wer verwahrt die Zuordnungsliste Pilot-Kennung ↔ Person? | Eine benannte Person außerhalb der Führungslinie der Teilnehmenden (z. B. Personalentwicklung), getrennt von den Ergebnisdaten | Dokumente 2, 4, 5 |
| E5 | Löschfristen | Ergebnis- und Sitzungsdaten 3 Monate nach Pilotende, Zuordnungsliste 1 Monat nach letztem Wissenscheck | Dokumente 2, 3, 4, 5 |

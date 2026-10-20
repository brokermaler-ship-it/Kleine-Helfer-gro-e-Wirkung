# Offene Punkte nach Stufe 1

Stand 18.10.2026. Kleinere Punkte aus den Code-Prüfungen von Stufe 1, die bewusst zurückgestellt wurden. Keiner davon verhindert die Installation.

## Vor Eingabe echter Mitgliederdaten
- ~~DSGVO: Beim Löschen einer Person personenbezogene Daten auch im Änderungsprotokoll entfernen (erster Punkt von Stufe 2).~~ Erledigt in Version 0.4.0 (Stufe 2a): „Person endgültig löschen (DSGVO)“, siehe README „Person nach DSGVO löschen“.

## Rückstellungen aus den Prüfungen (technisch)
- Task 1: Request basis_url prefix match not segment-safe (/app vs /application)
- Task 1: path double-decoded (Request + Router)
- Task 1: bootstrap errors outside try/catch; display_errors not tied to debug in index.php
- Task 1: fehlerSeite swallows secondary render exception without error_log
- Task 1: url() uses $GLOBALS basis_url set in App ctor
- Task 1: View extract EXTR_SKIP drops reserved keys; redundant merge
- Task 1: no App::handle tests (500 path, debug disclosure); HEAD not answered; no autoload-dev; bootstrap sourcemap comment
- Task 1: Options -Indexes may 500 on hosts without AllowOverride Options — verify on target webspace (README note)
- Task 1: Router::dispatch duplicates 404/405 logic of dispatchOderWirf
- Task 2: Migrator splitter — document "simple statements, ; at line end only"
- Task 2: failed 001 midway not re-runnable; error should name failed version
- Task 2: SET NAMES without COLLATE utf8mb4_unicode_ci
- Task 2: DbTestCase::setUpBeforeClass not final
- Task 3: GET_LOCK result ignored in install
- Task 3: lockout check+insert not atomic
- Task 3: logout clears only benutzer_id
- Task 3: no max password length (bcrypt 72 bytes)
- Task 3: partly migrated DB → 500 on /install
- Task 3: failures not reset after success; no IP throttling (password spraying)
- Task 3: ATTRAPPE hash cost 10 must track password_hash cost if ever raised
- Task 4: feldFuer() maps errors to fields by message text
- Task 4: change + protocol entry not in one transaction
- Task 4: PersonRepository catches any PDOException as "wird noch verwendet" (check 23000)
- Task 4: self-demotion redirects to 403 without explanation
- Task 4: verlauf partial shows raw field keys
- Task 4: PHPUnit deprecation (1) in suite output — carry fix into Task 5 dispatch
- Task 5: (string) cast of array input (?bearbeiten[]=1) → warning/500
- Task 5: FILTER_VALIDATE_INT no 32-bit range → PDO out-of-range 500
- Task 5: HTTP negative tests only on GET
- Task 6: INSERT + Inventarnummer::merke not in one transaction
- Task 6: delete + protocol entry not in one transaction
- Task 7: save warning shown for ausgemustert vehicle's decoder (tab suppresses)
- Task 7: decoder delete + protocol not in one transaction
- Task 7: vehicle Verlauf tab doesn't show decoder entries yet
- Task 8: is_uploaded_file CLI bypass — prefer injectable seam
- Task 8: csv_vorschau wording deviates from exact sentence when functions exist
- Task 8: >100 char descriptions silently truncated in CSV
- Task 8: unbounded CSV error list stored in session (cap ~50)
- Task 8: protocol for functions only counts; CV comment-only change not logged
- Task 8: Excel "Unicode-Text" (UTF-16LE, tab) silently yields 0 rows — realistic export path; detect UTF-16 BOM and convert
- Task 8: stale preview not cleared on failed re-upload
- Task 9: post-size fallback redirect literal '/' not url('/')
- Task 9: confirm() with addslashes breaks on newline titles
- Task 9: auto-Titelbild requires GD variant
- Task 9: display_errors startup warning breaks redirect in dev
- Task 9: fixture generator not committed
- Task 9: vehicle delete — doc deletes not in one transaction with vehicle delete
- Task 10: Sicherung::literal strtr unsafe under NO_BACKSLASH_ESCAPES
- Task 10: release.sh no dirty-tree warning
- Task 10: no emoji round-trip test
- Task 11: web demo reset not atomic
- Task 11: no maskable icon / mobile-web-app-capable meta; .webmanifest MIME AddType
- Task 11: empty() on config 'testumgebung' treats string 'false' as true
- Endprüfung: nicht beschreibbarer Sitzungsordner führt zu irreführender Meldung „Sitzung abgelaufen“ (is_writable-Prüfung ergänzen)
- Endprüfung: Login-Sperre nur je E-Mail (gezieltes Aussperren möglich); Personenname im Protokoll nach Löschen eines Benutzers „unbekannt“; Funktionsvorlagen nicht lösch-/umbenennbar

<?php
/** @var array<string,mixed> $person */
/** @var array{person: array<string,mixed>, benutzer: ?array<string,mixed>, fahrzeuge: list<array{id:int, inventarnummer:string, bezeichnung:string, dokumente:int, rechnungen:int, wartungen:int, reparaturen:int}>, wartungen_in_vereinsdaten:int, reparaturen_in_vereinsdaten:int, protokolleintraege:int, verweigert:?string, onedrive_konto_der_person:bool, angebote_geloescht:int, angebote_verkauft:int, angebote_storniert:int, freitexte:int} $vorschau */
/** @var list<int> $uebergeben */ /** @var ?string $fehler */
$name = trim($person['vorname'] . ' ' . $person['nachname']);
$verweigert = $vorschau['verweigert'];
$fahrzeuge = $vorschau['fahrzeuge'];
$konto = $vorschau['benutzer'];
?>
<div class="row">
    <div class="col-lg-9">
        <h1 class="h3 mb-3">Person nach DSGVO löschen</h1>
        <p class="lead"><?= e($name) ?></p>

        <?php if ($fehler !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($fehler) ?></div>
        <?php endif; ?>

        <?php if ($verweigert !== null): ?>
            <div class="alert alert-warning" role="alert"><?= e($verweigert) ?></div>
            <a class="btn btn-secondary" href="<?= e(url('/personen/' . $person['id'] . '/bearbeiten')) ?>">Zurück zur Person</a>
        <?php else: ?>
        <form method="post" action="<?= e(url('/personen/' . $person['id'] . '/dsgvo')) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr><th scope="col">Daten</th><th scope="col">Ergebnis</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Person (Name, E-Mail, Telefon, Notiz)<?php if ($konto !== null): ?> und ihr Benutzerkonto <code><?= e((string) $konto['email']) ?></code><?php endif; ?></td>
                            <td><strong>gelöscht</strong></td>
                        </tr>
                        <tr>
                            <td>Eigene Fahrzeuge (Eigentümer = Person)</td>
                            <td>
                                <?php if ($fahrzeuge === []): ?>
                                    keine
                                <?php else: ?>
                                    <strong>mitgelöscht</strong> samt Fotos und Dokumenten (Webspace und OneDrive), Decodern, Funktionstasten und CVs, Wartungen (mit Fotos), Wartungsplan und Reparaturen (mit Ersatzteilen und Fotos) –
                                    außer den angehakten, die an den Verein übergeben werden:
                                    <ul class="list-unstyled mt-2 mb-0">
                                        <?php foreach ($fahrzeuge as $fz): ?>
                                            <li class="mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="uebergeben[]" value="<?= e((string) $fz['id']) ?>"
                                                           id="uebergeben-<?= e((string) $fz['id']) ?>"<?= in_array($fz['id'], $uebergeben, true) ? ' checked' : '' ?>>
                                                    <label class="form-check-label" for="uebergeben-<?= e((string) $fz['id']) ?>">
                                                        <strong><?= e($fz['inventarnummer']) ?></strong> <?= e($fz['bezeichnung']) ?>
                                                        <span class="text-muted">(<?= e((string) $fz['dokumente']) ?> <?= $fz['dokumente'] === 1 ? 'Dokument' : 'Dokumente' ?><?php if ($fz['rechnungen'] > 0): ?>, davon <?= e((string) $fz['rechnungen']) ?> <?= $fz['rechnungen'] === 1 ? 'Rechnung' : 'Rechnungen' ?><?php endif; ?>, <?= e((string) $fz['wartungen']) ?> <?= $fz['wartungen'] === 1 ? 'Wartung' : 'Wartungen' ?>, <?= e((string) ($fz['reparaturen'] ?? 0)) ?> <?= ($fz['reparaturen'] ?? 0) === 1 ? 'Reparatur' : 'Reparaturen' ?>)</span>
                                                        – an den Verein übergeben
                                                    </label>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Wartungen an Vereinsfahrzeugen, fremden Fahrzeugen und Anlagen, die die Person ausgeführt oder eingetragen hat (<?= e((string) $vorschau['wartungen_in_vereinsdaten']) ?>)</td>
                            <td>bleiben erhalten, Anzeige „<?= e(\Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>“</td>
                        </tr>
                        <tr>
                            <td>Reparaturen an Vereinsfahrzeugen, fremden Fahrzeugen und Anlagen, die die Person gemeldet hat oder für die sie zuständig ist (<?= e((string) ($vorschau['reparaturen_in_vereinsdaten'] ?? 0)) ?>)</td>
                            <td>bleiben erhalten, „gemeldet von“ und „zuständig“ zeigen „<?= e(\Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>“; Freitexte (Beschreibung, Notizen) werden nicht durchsucht</td>
                        </tr>
                        <?php if (($vorschau['angebote_geloescht'] ?? 0) + ($vorschau['angebote_verkauft'] ?? 0) + ($vorschau['angebote_storniert'] ?? 0) > 0): ?>
                        <tr>
                            <td>Börsenangebote der Person</td>
                            <td>
                                <?= e((string) $vorschau['angebote_geloescht']) ?> nicht verkaufte Börsenangebote werden gelöscht, <?= e((string) $vorschau['angebote_verkauft']) ?> verkaufte bleiben ohne Namen in der Abrechnung.
                                <?php if ($vorschau['angebote_storniert'] > 0): ?>
                                    <?= e((string) $vorschau['angebote_storniert']) ?> mit storniertem Verkauf bleiben zurückgezogen ohne Namen, Fotos, Beschreibung und Notiz.
                                <?php endif; ?>
                                Auszahlungen bleiben mit Betrag, Anzeige „<?= e(\Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>“.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Freitexte in Magazin und Börse (z. B. „Spende von …“, Notizen, Storno-Grund) mit Name, E-Mail oder Telefon (<?= e((string) ($vorschau['freitexte'] ?? 0)) ?>)</td>
                            <td>Fundstellen durch „<?= e(\Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>“ ersetzt; Börsenanfragen aus dem öffentlichen Bereich sind keiner Person zugeordnet und bleiben unberührt (Löschung nach 12 Monaten)</td>
                        </tr>
                        <tr>
                            <td>Bezüge in Vereinsdaten (z. B. „angelegt von“, „hochgeladen von“)</td>
                            <td>Bezug entfernt, Anzeige „<?= e(\Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>“</td>
                        </tr>
                        <tr>
                            <td>Änderungsprotokoll (<?= e((string) $vorschau['protokolleintraege']) ?> <?= $vorschau['protokolleintraege'] === 1 ? 'Eintrag' : 'Einträge' ?> betroffen)</td>
                            <td>Einträge zur Person und zu ihren Zugängen entfernt; in allen übrigen Einträgen Name, E-Mail und Telefon durch „<?= e(\Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>“ ersetzt</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($vorschau['onedrive_konto_der_person']): ?>
                <div class="alert alert-warning" role="alert">
                    <strong>Die OneDrive-Verbindung läuft über ein Microsoft-Konto dieser Person.</strong>
                    Bitte unter Verwaltung → OneDrive mit dem Vereinskonto neu verbinden.
                </div>
            <?php endif; ?>

            <div class="alert alert-info" role="note">
                <h2 class="h6">Bitte beachten – das prüft die Löschung nicht</h2>
                <ul class="mb-0">
                    <li>Freitexte in den Daten selbst (Notizen an Fahrzeugen, Decoder- und CV-Felder, Dateititel) werden nicht durchsucht. Bitte von Hand prüfen.</li>
                    <li>Eine E-Mail-Adresse, die auch eine andere Person nutzt (z. B. eine Familienadresse), bleibt samt deren Anmeldeversuchen und Fundstellen im Protokoll erhalten.</li>
                    <li>An den Verein übergebene Fahrzeuge behalten alle Dokumente. Rechnungen mit Name oder Adresse bitte vorher löschen.</li>
                    <li>Telefonnummern werden nur in der gespeicherten Schreibweise ersetzt.</li>
                    <li>Ältere Datensicherungen enthalten die Person weiterhin. Sicherungen nach 12 Monaten vernichten (siehe README).</li>
                    <li>Läuft die OneDrive-Verbindung über das persönliche Microsoft-Konto der Person, bitte mit dem Vereinskonto neu verbinden.</li>
                </ul>
            </div>

            <div class="border border-danger rounded p-3 mb-3">
                <p class="text-danger fw-semibold">Die Löschung kann nicht rückgängig gemacht werden.</p>
                <div class="mb-3">
                    <label for="nachname" class="form-label">Zur Bestätigung den Nachnamen eingeben</label>
                    <input type="text" class="form-control<?= $fehler === \Emc\Personen\PersonController::DSGVO_BESTAETIGUNG ? ' is-invalid' : '' ?>" id="nachname" name="nachname"
                           autocomplete="off" required>
                </div>
                <button type="submit" class="btn btn-danger">Endgültig löschen</button>
                <a class="btn btn-link" href="<?= e(url('/personen/' . $person['id'] . '/bearbeiten')) ?>">Abbrechen</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

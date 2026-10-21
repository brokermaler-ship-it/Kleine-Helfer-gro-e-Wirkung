<?php
/**
 * @var list<array{dateiname:string, anzahl:int, erstellt_am:string, anzeigename:?string}> $letzte
 * @var int $maxMb
 */
?>
<h1 class="h3 mb-3">Fahrzeuge importieren</h1>
<p>Eine Fahrzeugliste als CSV-Datei einmalig übernehmen. Zuerst zeigt eine Vorschau jede Zeile; angelegt wird erst nach
    „Import bestätigen“. Jedes Fahrzeug bekommt eine <strong>neue Inventarnummer</strong>, gehört dem Verein und ist als
    <span class="badge text-bg-warning">ungeprüft</span> markiert, bis es jemand ansieht und „Als geprüft markieren“ wählt.
    Die alte Nummer, der Besitzer laut Liste und die Quelle stehen in der Notiz.</p>

<section aria-labelledby="format-titel" class="mb-4">
    <h2 class="h5" id="format-titel">Aufbau der Datei</h2>
    <ul class="small">
        <li>Kopfzeile: <code>Alte Nummer;Bezeichnung;Gattung;Baureihe;Bahn;Epoche;Spur;Strom;Hersteller;Artikel;Besitzer;Quelle;Notiz</code>
            – die Reihenfolge ist egal, Pflicht sind <strong>Bezeichnung</strong>, <strong>Gattung</strong> und <strong>Spur</strong>.</li>
        <li>Gattung: Lok, Triebwagen, Wagen, Sonderfahrzeug oder Zugverband (Zugverbände werden nicht importiert).</li>
        <li>Spur: Name einer vorhandenen Spurweite (z. B. H0, TT, N). Strom: 2L, 3L oder leer (= 2L). Epoche: I bis VI oder leer.</li>
        <li>Excel-Dateien (Semikolon, Windows-Zeichensatz) werden erkannt; höchstens <?= (int) $maxMb ?> MB und
            <?= \Emc\Fahrzeuge\FahrzeugCsvImport::MAX_ZEILEN ?> Zeilen. Jede Datei lässt sich nur einmal importieren.</li>
    </ul>
    <p class="small"><a href="<?= e(url('/fahrzeuge/import/vorlage.csv')) ?>">CSV-Vorlage herunterladen</a></p>
</section>

<form method="post" action="<?= e(url('/fahrzeuge/import')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end mb-4">
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
    <div class="col-12 col-md-6">
        <label for="datei" class="form-label">CSV-Datei</label>
        <input type="file" class="form-control" id="datei" name="datei" accept=".csv,text/csv,text/plain" required>
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-primary">Vorschau anzeigen</button></div>
</form>

<?php if ($letzte !== []): ?>
    <section aria-labelledby="letzte-titel">
        <h2 class="h5" id="letzte-titel">Bisherige Importe</h2>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th scope="col">Datum</th><th scope="col">Datei</th><th scope="col">Fahrzeuge</th><th scope="col">von</th></tr></thead>
                <tbody>
                <?php foreach ($letzte as $i): ?>
                    <tr>
                        <td><?= e(date('d.m.Y H:i', (int) strtotime($i['erstellt_am']))) ?></td>
                        <td><?= e($i['dateiname']) ?></td>
                        <td><?= (int) $i['anzahl'] ?></td>
                        <td><?= e($i['anzeigename'] ?? \Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<p class="mt-3"><a href="<?= e(url('/fahrzeuge')) ?>">&larr; Zurück zur Fahrzeugliste</a></p>

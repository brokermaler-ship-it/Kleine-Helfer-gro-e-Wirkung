<?php
/**
 * @var array<string,mixed> $decoder
 * @var array<string,mixed> $fahrzeug
 * @var array<int,array<string,mixed>> $funktionen
 * @var array<int,string> $fehler
 * @var list<array<string,mixed>> $vorlagen
 * @var int|null $pdfMaxMb wirksame Höchstgröße für PDFs
 */
$bearbeiten = $darfFz($fahrzeug);
$zurueck = url('/fahrzeuge/' . $fahrzeug['id']) . '#digital';
$basis = url('/decoder/' . $decoder['id']);
?>
<h1 class="h3 mb-1">Funktionen: <?= e($decoder['bezeichnung']) ?></h1>
<p class="text-muted"><a href="<?= e($zurueck) ?>"><?= e($fahrzeug['inventarnummer']) ?> <?= e($fahrzeug['bezeichnung']) ?></a></p>

<input type="checkbox" class="btn-check" id="nur-belegt" autocomplete="off">
<label class="btn btn-outline-secondary btn-sm mb-3" for="nur-belegt">Nur belegte Tasten</label>

<?php if ($bearbeiten): ?>
<form method="post" action="<?= e($basis . '/funktionen') ?>" novalidate>
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
<?php endif; ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle funktionen-tabelle">
            <thead><tr><th scope="col">Taste</th><th scope="col">Beschreibung</th><th scope="col" class="text-center">Moment</th></tr></thead>
            <tbody>
            <?php for ($t = 0; $t <= \Emc\Digital\FunktionRepository::MAX_TASTE; $t++):
                $f = $funktionen[$t] ?? null;
                $text = $f['beschreibung'] ?? '';
                $moment = ($f['schaltart'] ?? 'dauer') === 'moment';
                ?>
                <tr<?= $text === '' ? ' class="leer"' : '' ?>>
                    <th scope="row">F<?= $t ?></th>
                    <td>
                        <?php if ($bearbeiten): ?>
                            <input type="text" class="form-control form-control-sm<?= isset($fehler[$t]) ? ' is-invalid' : '' ?>" name="beschreibung[<?= $t ?>]"
                                   value="<?= e($text) ?>" maxlength="100" aria-label="Beschreibung F<?= $t ?>">
                            <?php if (isset($fehler[$t])): ?><div class="invalid-feedback"><?= e($fehler[$t]) ?></div><?php endif; ?>
                        <?php else: ?>
                            <?= e($text) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($bearbeiten): ?>
                            <input type="checkbox" class="form-check-input" name="moment[<?= $t ?>]" value="1"<?= $moment ? ' checked' : '' ?> aria-label="F<?= $t ?> ist eine Moment-Taste">
                        <?php elseif ($text !== '' && $moment): ?>
                            <span title="Moment-Taste" aria-label="Moment-Taste">⏱</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
<?php if ($bearbeiten): ?>
    <button type="submit" class="btn btn-primary">Funktionen speichern</button>
    <a class="btn btn-outline-secondary" href="<?= e($zurueck) ?>">Zurück</a>
</form>

<hr class="my-4">
<section aria-labelledby="csv-titel" class="mb-4">
    <h2 class="h5" id="csv-titel">CSV-Import</h2>
    <p class="small text-muted">Spalten: Taste;Beschreibung;Schaltart (Dauer oder Moment). Excel-Dateien (Semikolon, Windows-Zeichensatz) werden erkannt.
        <a href="<?= e(url('/funktionen/vorlage.csv')) ?>">Vorlage herunterladen</a></p>
    <form method="post" action="<?= e($basis . '/funktionen/csv') ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
        <div class="col-12 col-md-6">
            <label for="datei" class="form-label">CSV-Datei</label>
            <input type="file" class="form-control" id="datei" name="datei" accept=".csv,text/csv,text/plain" required>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Vorschau anzeigen</button></div>
    </form>
</section>

<section aria-labelledby="pdf-titel" class="mb-4">
    <h2 class="h5" id="pdf-titel">PDF-Import</h2>
    <p class="small text-muted">Bedienungsanleitung des Decoders oder der Lok als PDF (höchstens <?= (int) ($pdfMaxMb ?? 20) ?> MB). Erkannt werden Zeilen wie „F0 Licht“ oder „F 3: Glocke“; gescannte PDFs ohne Text gehen nicht. Die Datei wird nicht gespeichert.</p>
    <form method="post" action="<?= e($basis . '/funktionen/pdf') ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
        <div class="col-12 col-md-6">
            <label for="pdf-datei" class="form-label">PDF-Datei</label>
            <input type="file" class="form-control" id="pdf-datei" name="datei" accept=".pdf,application/pdf" required>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Vorschau anzeigen</button></div>
    </form>
</section>

<section aria-labelledby="vorlagen-titel">
    <h2 class="h5" id="vorlagen-titel">Vorlagen</h2>
    <form method="post" action="<?= e($basis . '/vorlage') ?>" class="row g-2 align-items-end mb-3">
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
        <div class="col-12 col-md-6">
            <label for="vorlage-name" class="form-label">Aktuelle Belegung als Vorlage speichern</label>
            <input type="text" class="form-control" id="vorlage-name" name="name" maxlength="100" placeholder="z. B. ESU LokSound V 200" required>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Als Vorlage speichern</button></div>
    </form>
    <?php if ($vorlagen !== []): ?>
        <form method="post" action="<?= e($basis . '/vorlage-anwenden') ?>" class="row g-2 align-items-end"
              onsubmit="return confirm('Die bisherige Belegung wird ersetzt. Fortfahren?');">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <div class="col-12 col-md-6">
                <label for="vorlage-id" class="form-label">Vorlage anwenden</label>
                <select class="form-select" id="vorlage-id" name="vorlage_id" required>
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($vorlagen as $v): ?>
                        <option value="<?= e((string) $v['id']) ?>"><?= e($v['name']) ?> (<?= e((string) $v['anzahl']) ?> Tasten)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Anwenden</button></div>
            <div class="form-text">Die bisherige Belegung wird ersetzt.</div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

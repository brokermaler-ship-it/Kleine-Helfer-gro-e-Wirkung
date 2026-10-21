<?php
/**
 * „Defekt melden“ – mobil zuerst: eine Spalte, große Schaltflächen, Fotos aus Kamera oder Galerie.
 *
 * @var string $objektTyp fahrzeug oder anlage
 * @var array<string,mixed> $objekt
 * @var string $kennung Code (F12, A3) bzw. Inventarnummer
 * @var string $name Bezeichnung bzw. Name
 * @var string $aktion Ziel des Formulars
 * @var string $objektUrl
 * @var array<string,mixed> $werte
 * @var array<string,string> $fehler
 * @var bool $fotosVerworfen nach einem Eingabefehler: Fotos müssen neu gewählt werden
 */
use Emc\Reparatur\MeldungValidator;

include dirname(__DIR__) . '/fahrzeuge/_labels.php';
include dirname(__DIR__) . '/anlagen/_labels.php';
$anlage = $objektTyp === 'anlage';
$einstufung = ($werte['einstufung'] ?? 'normal') === 'dringend' ? 'dringend' : 'normal';
$feldFehler = function (string $feld) use ($fehler): string {
    return isset($fehler[$feld]) ? '<div class="invalid-feedback d-block" id="fehler-' . e($feld) . '">' . e($fehler[$feld]) . '</div>' : '';
};
$klasse = fn(string $feld): string => isset($fehler[$feld]) ? ' is-invalid' : '';
$aria = fn(string $feld): string => isset($fehler[$feld]) ? ' aria-invalid="true" aria-describedby="fehler-' . e($feld) . '"' : '';
?>
<div class="emc-meldung mx-auto">
    <h1 class="h3 mb-3">Defekt melden</h1>

    <a class="card card-body d-flex flex-row gap-3 align-items-center mb-3 text-decoration-none text-reset" href="<?= e($objektUrl) ?>">
        <?= $anlage ? $anlageTitelbild($objekt) : $titelbild($objekt) ?>
        <div>
            <div class="fahrzeug-code"><?= e($kennung) ?></div>
            <div class="fw-semibold"><?= e($name) ?></div>
        </div>
    </a>

    <?php if ($fehler !== []): ?>
        <div class="alert alert-danger" role="alert">
            Bitte prüfe die markierten Felder.<?= $fotosVerworfen ? ' Die Fotos bitte noch einmal auswählen.' : '' ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e($aktion) ?>" enctype="multipart/form-data" novalidate>
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>

        <div class="mb-3">
            <label for="m-beschreibung" class="form-label fw-semibold">Was ist kaputt?</label>
            <textarea class="form-control form-control-lg<?= $klasse('beschreibung') ?>"<?= $aria('beschreibung') ?> id="m-beschreibung" name="beschreibung" rows="4"
                      maxlength="<?= MeldungValidator::BESCHREIBUNG_MAX ?>" required
                      placeholder="<?= $anlage ? 'z. B. Weiche schaltet nicht um' : 'z. B. Lok bleibt auf der Weiche stehen' ?>"><?= e((string) ($werte['beschreibung'] ?? '')) ?></textarea>
            <?= $feldFehler('beschreibung') ?>
        </div>

        <?php if ($anlage): ?>
        <div class="mb-3">
            <label for="m-wo" class="form-label fw-semibold">Wo/Bauteil <span class="fw-normal text-muted">(optional)</span></label>
            <input type="text" class="form-control form-control-lg<?= $klasse('wo_bauteil') ?>"<?= $aria('wo_bauteil') ?> id="m-wo" name="wo_bauteil"
                   maxlength="<?= MeldungValidator::WO_BAUTEIL_MAX ?>" value="<?= e((string) ($werte['wo_bauteil'] ?? '')) ?>"
                   placeholder="z. B. W12 Antrieb brummt">
            <?= $feldFehler('wo_bauteil') ?>
        </div>
        <?php endif; ?>

        <fieldset class="mb-3">
            <legend class="form-label fw-semibold fs-6">Wie schlimm?</legend>
            <div class="row g-2">
                <?php foreach (MeldungValidator::EINSTUFUNGEN as $wert => $text): ?>
                    <div class="col-6">
                        <input type="radio" class="btn-check" name="einstufung" id="m-einstufung-<?= e($wert) ?>" value="<?= e($wert) ?>" autocomplete="off"<?= $einstufung === $wert ? ' checked' : '' ?>>
                        <label class="btn btn-lg w-100 emc-kachel <?= $wert === 'dringend' ? 'btn-outline-danger' : 'btn-outline-primary' ?>" for="m-einstufung-<?= e($wert) ?>"><span class="emc-kachel-haken" aria-hidden="true">✓</span><?= e($text) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="mb-4">
            <?php $fotoId = 'm-fotos'; $fotoBeschriftung = 'Fotos (optional)'; $fotoGross = true; include dirname(__DIR__) . '/partials/fotofeld.php'; ?>
        </div>

        <button type="submit" class="btn btn-danger btn-lg w-100 py-3" data-senden>Defekt melden</button>
    </form>

    <p class="mt-3 d-flex justify-content-between">
        <a href="<?= e($objektUrl) ?>">&larr; Zurück</a>
        <a href="<?= e(url('/meine-meldungen')) ?>">Meine Meldungen</a>
    </p>
</div>

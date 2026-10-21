<?php
/**
 * Buchungsformular am Artikel (nur Börsenwart): Eingang, Ausgang, Umlagerung als Reiter. Ohne JavaScript ist nur der
 * gewählte Reiter sichtbar (Standard Eingang). „verkauft (Börse)“ bucht nur die Kasse.
 *
 * @var array<string,mixed> $a Artikel
 * @var string $buchungArt
 * @var array<string,string> $buchungWerte
 * @var array<string,string> $buchungFehler
 * @var ?string $buchungMeldung
 */
use Emc\Magazin\BuchungValidator;

$aktivArt = in_array($buchungArt, BuchungValidator::ARTEN, true) ? $buchungArt : 'eingang';
$bw = fn(string $art, string $feld, string $standard = ''): string => $aktivArt === $art ? (string) ($buchungWerte[$feld] ?? $standard) : $standard;
$bf = fn(string $art, string $feld): ?string => $aktivArt === $art ? ($buchungFehler[$feld] ?? null) : null;
$feldFehler = function (?string $text): void {
    if ($text !== null) { ?><div class="invalid-feedback d-block"><?= e($text) ?></div><?php }
};
$heute = date('Y-m-d');
$datumFeld = function (string $art) use ($bw, $bf, $feldFehler, $heute): void { ?>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0" for="b-<?= $art ?>-datum">Datum</label>
        <input type="date" class="form-control<?= $bf($art, 'datum') ? ' is-invalid' : '' ?>" id="b-<?= $art ?>-datum" name="datum" value="<?= e($bw($art, 'datum', $heute)) ?>" max="<?= e($heute) ?>">
        <?php $feldFehler($bf($art, 'datum')); ?>
    </div>
<?php };
$mengeFeld = function (string $art) use ($bw, $bf, $feldFehler): void { ?>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0" for="b-<?= $art ?>-menge">Menge</label>
        <input type="number" inputmode="numeric" min="1" max="<?= BuchungValidator::MENGE_MAX ?>" step="1" class="form-control<?= $bf($art, 'menge') ? ' is-invalid' : '' ?>" id="b-<?= $art ?>-menge" name="menge" value="<?= e($bw($art, 'menge', '1')) ?>" required>
        <?php $feldFehler($bf($art, 'menge')); ?>
    </div>
<?php };
$betragFeld = function (string $art, string $label) use ($bw, $bf, $feldFehler): void { ?>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0" for="b-<?= $art ?>-betrag"><?= e($label) ?></label>
        <div class="input-group">
            <input type="text" inputmode="decimal" class="form-control<?= $bf($art, 'betrag') ? ' is-invalid' : '' ?>" id="b-<?= $art ?>-betrag" name="betrag" value="<?= e($bw($art, 'betrag')) ?>" placeholder="optional">
            <span class="input-group-text">€</span>
        </div>
        <?php $feldFehler($bf($art, 'betrag')); ?>
    </div>
<?php };
$freitextFeld = function (string $art, string $label, string $platzhalter) use ($bw, $bf, $feldFehler): void { ?>
    <div class="col-12 col-md">
        <label class="form-label small mb-0" for="b-<?= $art ?>-freitext"><?= e($label) ?></label>
        <input type="text" class="form-control<?= $bf($art, 'freitext') ? ' is-invalid' : '' ?>" id="b-<?= $art ?>-freitext" name="freitext" maxlength="<?= BuchungValidator::FREITEXT_MAX ?>" value="<?= e($bw($art, 'freitext')) ?>" placeholder="<?= e($platzhalter) ?>">
        <?php $feldFehler($bf($art, 'freitext')); ?>
    </div>
<?php };
$reiter = ['eingang' => 'Eingang', 'ausgang' => 'Ausgang', 'umlagerung' => 'Umlagerung'];
?>
<section class="card mb-4" id="buchen" aria-labelledby="buchen-titel">
    <div class="card-body">
        <h2 class="h5" id="buchen-titel">Buchen</h2>
        <?php if ($buchungMeldung !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($buchungMeldung) ?></div>
        <?php elseif ($buchungFehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <ul class="nav nav-tabs nav-fill flex-nowrap mb-3" role="tablist">
            <?php foreach ($reiter as $art => $text): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link px-2<?= $aktivArt === $art ? ' active' : '' ?>" id="reiter-<?= $art ?>" data-bs-toggle="tab" data-bs-target="#buchung-<?= $art ?>"
                            type="button" role="tab" aria-controls="buchung-<?= $art ?>" aria-selected="<?= $aktivArt === $art ? 'true' : 'false' ?>"><?= e($text) ?></button>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="tab-content">
            <div class="tab-pane<?= $aktivArt === 'eingang' ? ' show active' : '' ?>" id="buchung-eingang" role="tabpanel" aria-labelledby="reiter-eingang">
                <form method="post" action="<?= e(url('/magazin/' . (int) $a['id'] . '/buchungen')) ?>" class="row g-2 align-items-end">
                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                    <input type="hidden" name="art" value="eingang">
                    <?php $mengeFeld('eingang'); ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-0" for="b-eingang-herkunft">Herkunft</label>
                        <select class="form-select<?= $bf('eingang', 'herkunft') ? ' is-invalid' : '' ?>" id="b-eingang-herkunft" name="herkunft" required>
                            <option value="">wählen …</option>
                            <?php foreach ($magazinHerkunft as $wert => $text): ?>
                                <option value="<?= e($wert) ?>"<?= $bw('eingang', 'herkunft') === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $feldFehler($bf('eingang', 'herkunft')); ?>
                    </div>
                    <?php $datumFeld('eingang'); ?>
                    <?php $betragFeld('eingang', 'Wert'); ?>
                    <?php $freitextFeld('eingang', 'Von wem / Notiz', 'z. B. Spende von …'); ?>
                    <div class="col-12"><button type="submit" class="btn btn-primary">Eingang buchen</button></div>
                </form>
            </div>
            <div class="tab-pane<?= $aktivArt === 'ausgang' ? ' show active' : '' ?>" id="buchung-ausgang" role="tabpanel" aria-labelledby="reiter-ausgang">
                <form method="post" action="<?= e(url('/magazin/' . (int) $a['id'] . '/buchungen')) ?>" class="row g-2 align-items-end">
                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                    <input type="hidden" name="art" value="ausgang">
                    <?php $mengeFeld('ausgang'); ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-0" for="b-ausgang-art">Art</label>
                        <select class="form-select<?= $bf('ausgang', 'ausgang_art') ? ' is-invalid' : '' ?>" id="b-ausgang-art" name="ausgang_art" required>
                            <option value="">wählen …</option>
                            <?php foreach (BuchungValidator::AUSGANG_ARTEN as $wert): ?>
                                <option value="<?= e($wert) ?>"<?= $bw('ausgang', 'ausgang_art') === $wert ? ' selected' : '' ?>><?= e($magazinAusgang[$wert]) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $feldFehler($bf('ausgang', 'ausgang_art')); ?>
                    </div>
                    <?php $datumFeld('ausgang'); ?>
                    <?php $betragFeld('ausgang', 'Betrag'); ?>
                    <?php $freitextFeld('ausgang', 'An wen', 'z. B. an den Nachbarverein'); ?>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Ausgang buchen</button>
                        <div class="form-text">Verfügbar: <?= (int) $a['bestand'] ?>. Verkäufe auf der Börse bucht die Kasse.</div>
                    </div>
                </form>
            </div>
            <div class="tab-pane<?= $aktivArt === 'umlagerung' ? ' show active' : '' ?>" id="buchung-umlagerung" role="tabpanel" aria-labelledby="reiter-umlagerung">
                <form method="post" action="<?= e(url('/magazin/' . (int) $a['id'] . '/buchungen')) ?>" class="row g-2 align-items-end">
                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                    <input type="hidden" name="art" value="umlagerung">
                    <div class="col-12 col-md-5">
                        <label class="form-label small mb-0" for="b-umlagerung-ort">Neuer Lagerort</label>
                        <input type="text" class="form-control<?= $bf('umlagerung', 'lagerort_neu') ? ' is-invalid' : '' ?>" id="b-umlagerung-ort" name="lagerort_neu" maxlength="<?= BuchungValidator::LAGERORT_MAX ?>" value="<?= e($bw('umlagerung', 'lagerort_neu')) ?>" required>
                        <?php $feldFehler($bf('umlagerung', 'lagerort_neu')); ?>
                    </div>
                    <?php $datumFeld('umlagerung'); ?>
                    <div class="col-12"><button type="submit" class="btn btn-primary">Umlagern</button></div>
                </form>
            </div>
        </div>
    </div>
</section>

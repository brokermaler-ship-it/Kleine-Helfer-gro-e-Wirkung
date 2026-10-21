<?php
/**
 * „Reparatur bearbeiten“: Angaben, Status (Verwerfen statt Löschen), Ersatzteile. Stand-Feld gegen verlorene Änderungen.
 *
 * @var array<string,mixed> $reparatur
 * @var string $objektTyp
 * @var array<string,mixed> $objekt
 * @var string $kennung
 * @var string $name
 * @var array<string,string> $werte
 * @var list<array<string,string>> $teile Zeilen wie eingegeben (bezeichnung, artikelnummer, bezugsquelle, kosten, bestellt)
 * @var array<string,string> $fehler Schlüssel wie ReparaturValidator (teile.<n>.<feld> für Ersatzteile)
 * @var ?string $konflikt
 * @var string $stand
 * @var list<array<string,mixed>> $personen
 */
use Emc\Reparatur\MeldungValidator;
use Emc\Reparatur\ReparaturRepository;
use Emc\Reparatur\ReparaturStatus;
use Emc\Reparatur\ReparaturValidator;

$id = (int) $reparatur['id'];
$anlage = $objektTyp === 'anlage';
$w = fn(string $feld): string => (string) ($werte[$feld] ?? '');
$klasse = fn(string $feld): string => isset($fehler[$feld]) ? ' is-invalid' : '';
$aria = fn(string $feld, string $fid): string => isset($fehler[$feld]) ? ' aria-invalid="true" aria-describedby="fehler-' . e($fid) . '"' : '';
$feldFehler = fn(string $feld, string $fid): string => isset($fehler[$feld])
    ? '<div class="invalid-feedback d-block" id="fehler-' . e($fid) . '">' . e($fehler[$feld]) . '</div>' : '';

/** Eine Ersatzteilzeile; $n ist eine Zahl oder der Platzhalter __N__ (Vorlage für „Zeile hinzufügen“). */
$teilZeile = function (string $n, array $t) use ($fehler): void {
    $feld = function (string $f, string $label, string $spalte, int $max, string $extra = '') use ($n, $t, $fehler): void {
        $schluessel = "teile.$n.$f";
        $fid = "teil-$n-$f";
        $ungueltig = isset($fehler[$schluessel]);
        ?>
        <div class="<?= $spalte ?>">
            <label class="form-label small mb-0" for="<?= e($fid) ?>"><?= e($label) ?></label>
            <input type="text" class="form-control form-control-sm<?= $ungueltig ? ' is-invalid' : '' ?>" id="<?= e($fid) ?>"
                   name="teile[<?= e($n) ?>][<?= e($f) ?>]" value="<?= e((string) ($t[$f] ?? '')) ?>" maxlength="<?= $max ?>"<?= $extra ?>
                   <?= $ungueltig ? 'aria-invalid="true" aria-describedby="fehler-' . e($fid) . '"' : '' ?>>
            <?php if ($ungueltig): ?><div class="invalid-feedback d-block" id="fehler-<?= e($fid) ?>"><?= e($fehler[$schluessel]) ?></div><?php endif; ?>
        </div>
        <?php
    };
    ?>
    <div class="row g-2 align-items-start border-bottom pb-2 mb-2 reparatur-teil">
        <?php $feld('bezeichnung', 'Bezeichnung', 'col-12 col-md-4', ReparaturValidator::BEZEICHNUNG_MAX); ?>
        <?php $feld('artikelnummer', 'Artikelnummer', 'col-6 col-md-2', ReparaturValidator::ARTIKELNUMMER_MAX); ?>
        <?php $feld('bezugsquelle', 'Bezugsquelle', 'col-6 col-md-3', ReparaturValidator::BEZUGSQUELLE_MAX); ?>
        <?php $feld('kosten', 'Kosten (€)', 'col-6 col-md-2', 10, ' inputmode="decimal" placeholder="z. B. 12,50"'); ?>
        <div class="col-6 col-md-1">
            <div class="form-check mt-md-4 pt-md-1">
                <input class="form-check-input" type="checkbox" id="teil-<?= e($n) ?>-bestellt" name="teile[<?= e($n) ?>][bestellt]" value="1"<?= ($t['bestellt'] ?? '') === '1' ? ' checked' : '' ?>>
                <label class="form-check-label small" for="teil-<?= e($n) ?>-bestellt">bestellt</label>
            </div>
        </div>
    </div>
    <?php
};
?>
<div class="mx-auto" style="max-width: 60rem">
    <h1 class="h3 mb-1">Reparatur bearbeiten</h1>
    <p class="mb-3"><span class="fw-bold me-1"><?= e($kennung) ?></span> <?= e($name) ?></p>

    <?php if (!empty($konflikt)): ?>
        <div class="alert alert-danger" role="alert"><?= e($konflikt) ?></div>
    <?php elseif ($fehler !== []): ?>
        <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.<?= isset($fehler['teile']) ? ' ' . e($fehler['teile']) : '' ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/reparaturen/' . $id)) ?>" novalidate>
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
        <input type="hidden" name="stand" value="<?= e($stand) ?>">

        <div class="mb-3">
            <label for="r-beschreibung" class="form-label">Beschreibung</label>
            <textarea class="form-control<?= $klasse('beschreibung') ?>"<?= $aria('beschreibung', 'beschreibung') ?> id="r-beschreibung" name="beschreibung" rows="3"
                      maxlength="<?= MeldungValidator::BESCHREIBUNG_MAX ?>" required><?= e($w('beschreibung')) ?></textarea>
            <?= $feldFehler('beschreibung', 'beschreibung') ?>
        </div>

        <?php if ($anlage): ?>
        <div class="mb-3">
            <label for="r-wo" class="form-label">Wo/Bauteil</label>
            <input type="text" class="form-control<?= $klasse('wo_bauteil') ?>"<?= $aria('wo_bauteil', 'wo_bauteil') ?> id="r-wo" name="wo_bauteil"
                   maxlength="<?= MeldungValidator::WO_BAUTEIL_MAX ?>" value="<?= e($w('wo_bauteil')) ?>">
            <?= $feldFehler('wo_bauteil', 'wo_bauteil') ?>
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <label for="r-status" class="form-label">Status</label>
                <select class="form-select<?= $klasse('status') ?>"<?= $aria('status', 'status') ?> id="r-status" name="status">
                    <?php foreach (ReparaturStatus::cases() as $s): ?>
                        <option value="<?= e($s->value) ?>"<?= $w('status') === $s->value ? ' selected' : '' ?>><?= e($s->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $feldFehler('status', 'status') ?>
            </div>
            <div class="col-6 col-md-3">
                <label for="r-prioritaet" class="form-label">Priorität</label>
                <select class="form-select<?= $klasse('prioritaet') ?>"<?= $aria('prioritaet', 'prioritaet') ?> id="r-prioritaet" name="prioritaet">
                    <?php foreach (ReparaturRepository::PRIORITAETEN as $wert => $text): ?>
                        <option value="<?= e($wert) ?>"<?= $w('prioritaet') === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $feldFehler('prioritaet', 'prioritaet') ?>
            </div>
            <div class="col-12 col-md-3">
                <label for="r-zustaendig" class="form-label">zuständig</label>
                <select class="form-select<?= $klasse('zustaendig') ?>"<?= $aria('zustaendig', 'zustaendig') ?> id="r-zustaendig" name="zustaendig">
                    <option value="">– niemand –</option>
                    <?php foreach ($personen as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= $w('zustaendig') === (string) $p['id'] ? ' selected' : '' ?>><?= e(trim($p['vorname'] . ' ' . $p['nachname'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $feldFehler('zustaendig', 'zustaendig') ?>
            </div>
            <div class="col-12 col-md-3">
                <label for="r-termin" class="form-label">Geplanter Termin</label>
                <input type="date" class="form-control<?= $klasse('termin') ?>"<?= $aria('termin', 'termin') ?> id="r-termin" name="termin" value="<?= e($w('termin')) ?>">
                <?= $feldFehler('termin', 'termin') ?>
            </div>
        </div>
        <p class="form-text mt-n2 mb-3">Reparaturen werden nicht gelöscht: Doppelte oder unbegründete Meldungen bitte auf „verworfen“ stellen.</p>

        <fieldset class="mb-3">
            <legend class="h6">Ersatzteile</legend>
            <div data-teile data-naechster="<?= count($teile) ?>">
                <?php foreach ($teile as $n => $t) { $teilZeile((string) $n, $t); } ?>
            </div>
            <template id="teil-vorlage"><?php $teilZeile('__N__', []); ?></template>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-teil-hinzu hidden>Zeile hinzufügen</button>
            <div class="form-text">Leere Zeilen werden ignoriert. Wer eine Zeile leert, entfernt das Ersatzteil. Kosten mit Komma oder Punkt, z. B. 12,50.</div>
        </fieldset>

        <div class="mb-3">
            <label for="r-notizen" class="form-label">Notizen</label>
            <textarea class="form-control<?= $klasse('notizen') ?>"<?= $aria('notizen', 'notizen') ?> id="r-notizen" name="notizen" rows="3" maxlength="<?= ReparaturValidator::TEXT_MAX ?>"><?= e($w('notizen')) ?></textarea>
            <?= $feldFehler('notizen', 'notizen') ?>
        </div>

        <div class="mb-3">
            <label for="r-was" class="form-label">Was wurde gemacht <span class="text-muted small">(nötig für „erledigt“)</span></label>
            <textarea class="form-control<?= $klasse('was_gemacht') ?>"<?= $aria('was_gemacht', 'was_gemacht') ?> id="r-was" name="was_gemacht" rows="3" maxlength="<?= ReparaturValidator::TEXT_MAX ?>"><?= e($w('was_gemacht')) ?></textarea>
            <?= $feldFehler('was_gemacht', 'was_gemacht') ?>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a class="btn btn-link" href="<?= e(url('/reparaturen/' . $id)) ?>">Abbrechen</a>
        </div>
    </form>
</div>

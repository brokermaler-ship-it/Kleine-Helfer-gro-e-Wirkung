<?php
/**
 * Angebot anlegen bzw. bearbeiten. Mitglieder bieten immer eigene Sachen an (keine Wahl von Herkunft und Person);
 * der Börsenwart wählt Verein oder Mitglied (mit Person). Aus dem Magazin (Artikel bzw. Sammlung) ist die Herkunft
 * „Verein“ fest.
 *
 * @var ?array<string,mixed> $angebot null bei der Neuanlage
 * @var array<string,string> $werte
 * @var array<string,string> $fehler
 * @var ?string $meldung
 * @var string $stand
 * @var ?array<string,mixed> $quelle Magazinartikel bzw. Sammlung (art, id, bezeichnung, …)
 * @var bool $istBoersenwart
 * @var list<array<string,mixed>> $kategorien
 * @var list<array<string,mixed>> $spurweiten
 * @var list<array<string,mixed>> $personen
 * @var list<array<string,mixed>> $boersen offene Börsen
 */
use Emc\Boerse\AngebotValidator;

include __DIR__ . '/_angebot_labels.php';
$neu = $angebot === null;
$aktion = $neu ? url('/angebote') : url('/angebote/' . (int) $angebot['id']);
$abbrechen = $neu ? url('/angebote') : url('/angebote/' . (int) $angebot['id']);
$wert = fn(string $n): string => (string) ($werte[$n] ?? '');
$klasse = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$fehlerFeld = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { ?><div class="invalid-feedback d-block"><?= e($fehler[$n]) ?></div><?php }
};
// Herkunft wählbar nur für den Börsenwart bei der Neuanlage ohne Magazinbezug.
$herkunftWaehlbar = $istBoersenwart && $neu && $quelle === null;
$herkunft = $neu ? ($quelle !== null ? 'verein' : ($istBoersenwart ? ($wert('herkunft') ?: 'verein') : 'mitglied')) : (string) $angebot['herkunft'];
$personWaehlbar = $istBoersenwart && $herkunft === 'mitglied' || $herkunftWaehlbar;
$mengeFest = $quelle !== null && $quelle['art'] === 'sammlung' || (!$neu && $angebot['magazin_sammlung_id'] !== null);
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= e($titel) ?><?php if (!$neu): ?> <small class="text-muted"><?= e((string) $angebot['nummer']) ?></small><?php endif; ?></h1>
        <?php if ($meldung !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($meldung) ?></div>
        <?php elseif ($fehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <?php if ($neu): ?><p class="text-muted small">Die Angebotsnummer (z. B. B<?= date('Y') ?>-118) wird beim Speichern vergeben.</p><?php endif; ?>
        <?php if ($quelle !== null): ?>
            <div class="alert alert-info" role="note">
                <?php if ($quelle['art'] === 'artikel'): ?>
                    Vereinsangebot aus dem Magazin: <strong><?= e((string) $quelle['code']) ?></strong> <?= e((string) $quelle['bezeichnung']) ?> – frei: <?= max(0, (int) $quelle['frei']) ?> Stück.
                <?php else: ?>
                    Vereinsangebot aus dem Magazin: Sammlung <strong><?= e((string) $quelle['bezeichnung']) ?></strong> (<?= (int) $quelle['artikel'] ?> Artikel, Bestand <?= (int) $quelle['bestand'] ?>) – wird als Ganzes verkauft.
                <?php endif; ?>
                Fotos aus dem Magazin werden übernommen.
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if (!$neu): ?><input type="hidden" name="stand" value="<?= e($stand) ?>"><?php endif; ?>
            <?php if ($quelle !== null): ?>
                <input type="hidden" name="<?= $quelle['art'] === 'artikel' ? 'magazin_artikel_id' : 'magazin_sammlung_id' ?>" value="<?= (int) $quelle['id'] ?>">
            <?php endif; ?>

            <?php if ($herkunftWaehlbar): ?>
                <fieldset class="mb-3">
                    <legend class="form-label fs-6">Herkunft</legend>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="herkunft" id="herkunft-verein" value="verein"<?= $herkunft === 'verein' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="herkunft-verein">Verein</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="herkunft" id="herkunft-mitglied" value="mitglied"<?= $herkunft === 'mitglied' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="herkunft-mitglied">Mitglied</label>
                    </div>
                    <?php $fehlerFeld('herkunft'); ?>
                </fieldset>
            <?php else: ?>
                <input type="hidden" name="herkunft" value="<?= e($herkunft) ?>">
                <p class="mb-3"><span class="text-muted">Herkunft:</span> <?= e($angebotHerkunft[$herkunft] ?? $herkunft) ?><?= !$istBoersenwart ? ' (du bietest eigene Sachen an)' : '' ?></p>
            <?php endif; ?>

            <?php if ($personWaehlbar): ?>
                <div class="mb-3">
                    <label for="person_id" class="form-label">Mitglied<?= $herkunftWaehlbar ? ' (nur bei Herkunft „Mitglied“)' : '' ?></label>
                    <select class="form-select<?= $klasse('person_id') ?>" id="person_id" name="person_id">
                        <option value="">wählen …</option>
                        <?php foreach ($personen as $pers): ?>
                            <option value="<?= (int) $pers['id'] ?>"<?= $wert('person_id') === (string) $pers['id'] ? ' selected' : '' ?>><?= e(trim($pers['nachname'] . ', ' . $pers['vorname'], ', ')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $fehlerFeld('person_id'); ?>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label for="bezeichnung" class="form-label">Bezeichnung</label>
                <input type="text" class="form-control<?= $klasse('bezeichnung') ?>" id="bezeichnung" name="bezeichnung" value="<?= e($wert('bezeichnung')) ?>" maxlength="<?= AngebotValidator::BEZEICHNUNG_MAX ?>" required>
                <div class="form-text">z. B. Märklin BR 50 mit Tender, Spur H0</div>
                <?php $fehlerFeld('bezeichnung'); ?>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="kategorie_id" class="form-label">Kategorie</label>
                    <select class="form-select<?= $klasse('kategorie_id') ?>" id="kategorie_id" name="kategorie_id" required>
                        <option value="">wählen …</option>
                        <?php foreach ($kategorien as $k): ?>
                            <option value="<?= (int) $k['id'] ?>"<?= $wert('kategorie_id') === (string) $k['id'] ? ' selected' : '' ?>><?= e((string) $k['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $fehlerFeld('kategorie_id'); ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="spurweite_id" class="form-label">Spurweite</label>
                    <select class="form-select<?= $klasse('spurweite_id') ?>" id="spurweite_id" name="spurweite_id">
                        <option value="">– keine –</option>
                        <?php foreach ($spurweiten as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"<?= $wert('spurweite_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e((string) $s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $fehlerFeld('spurweite_id'); ?>
                </div>
            </div>
            <div class="row">
                <div class="col-6 col-md-4 mb-3">
                    <label for="zustand" class="form-label">Zustand</label>
                    <select class="form-select<?= $klasse('zustand') ?>" id="zustand" name="zustand">
                        <?php foreach ($angebotZustand as $v => $t): ?>
                            <option value="<?= e($v) ?>"<?= $wert('zustand') === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $fehlerFeld('zustand'); ?>
                </div>
                <div class="col-6 col-md-4 mb-3">
                    <label for="wunschpreis" class="form-label">Wunschpreis (€)</label>
                    <input type="text" inputmode="decimal" class="form-control<?= $klasse('wunschpreis') ?>" id="wunschpreis" name="wunschpreis" value="<?= e($wert('wunschpreis')) ?>" placeholder="12,50" maxlength="10" required>
                    <?php $fehlerFeld('wunschpreis'); ?>
                </div>
                <div class="col-6 col-md-4 mb-3">
                    <label for="menge" class="form-label">Menge</label>
                    <?php if ($mengeFest): ?>
                        <input type="hidden" name="menge" value="1">
                        <input type="text" class="form-control" id="menge" value="1 (ganze Sammlung)" readonly>
                    <?php else: ?>
                        <input type="number" class="form-control<?= $klasse('menge') ?>" id="menge" name="menge" value="<?= e($wert('menge') ?: '1') ?>" min="1" max="<?= AngebotValidator::MENGE_MAX ?>" inputmode="numeric">
                        <div class="form-text">Stück zu diesem Preis</div>
                    <?php endif; ?>
                    <?php $fehlerFeld('menge'); ?>
                </div>
            </div>
            <div class="mb-3">
                <label for="boerse_id" class="form-label">Börse</label>
                <select class="form-select<?= $klasse('boerse_id') ?>" id="boerse_id" name="boerse_id">
                    <option value="dauerhaft"<?= in_array($wert('boerse_id'), ['', 'dauerhaft'], true) ? ' selected' : '' ?>>dauerhaft (an jeder Börse)</option>
                    <?php foreach ($boersen as $x): ?>
                        <option value="<?= (int) $x['id'] ?>"<?= $wert('boerse_id') === (string) $x['id'] ? ' selected' : '' ?>><?= e((string) $x['name'] . ' am ' . $datum((string) $x['datum'])) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$neu && $angebot['boerse_id'] !== null && !in_array((int) $angebot['boerse_id'], array_map(fn(array $x): int => (int) $x['id'], $boersen), true)): ?>
                        <option value="<?= (int) $angebot['boerse_id'] ?>" selected><?= e((string) $angebot['boerse_name']) ?></option>
                    <?php endif; ?>
                </select>
                <?php $fehlerFeld('boerse_id'); ?>
            </div>
            <div class="mb-3">
                <label for="beschreibung" class="form-label">Beschreibung</label>
                <textarea class="form-control<?= $klasse('beschreibung') ?>" id="beschreibung" name="beschreibung" rows="4" maxlength="<?= AngebotValidator::BESCHREIBUNG_MAX ?>"><?= e($wert('beschreibung')) ?></textarea>
                <div class="form-text">Erscheint auf der Webseite, wenn das Angebot freigegeben ist. Keine Namen oder Telefonnummern.</div>
                <?php $fehlerFeld('beschreibung'); ?>
            </div>
            <?php if ($herkunft === 'mitglied' || $herkunftWaehlbar): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="erloes_spende" name="erloes_spende" value="1"<?= $wert('erloes_spende') === '1' ? ' checked' : '' ?>>
                    <label class="form-check-label" for="erloes_spende">Erlös als Spende an den Verein<?= $herkunftWaehlbar ? ' (nur bei Mitgliedern)' : '' ?></label>
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label for="notiz" class="form-label">Notiz (intern)</label>
                <textarea class="form-control<?= $klasse('notiz') ?>" id="notiz" name="notiz" rows="2" maxlength="<?= AngebotValidator::NOTIZ_MAX ?>"><?= e($wert('notiz')) ?></textarea>
                <div class="form-text">Nur für den Börsenwart und dich, nie auf der Webseite.</div>
                <?php $fehlerFeld('notiz'); ?>
            </div>
            <?php if ($neu): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="veroeffentlichen" name="veroeffentlichen" value="1">
                    <label class="form-check-label" for="veroeffentlichen"><?= $istBoersenwart ? 'Auf der Webseite zeigen (freigeben)' : 'Zur Veröffentlichung auf der Webseite vorschlagen' ?></label>
                    <?php if (!$istBoersenwart): ?><div class="form-text">Sichtbar wird es erst, wenn der Börsenwart es freigibt.</div><?php endif; ?>
                </div>
            <?php elseif (!$istBoersenwart && $angebot['oeffentlich'] === 'freigegeben'): ?>
                <div class="alert alert-warning small" role="note">Das Angebot ist öffentlich. Änderst du Bezeichnung, Beschreibung, Preis, Kategorie, Spurweite oder Zustand, muss der Börsenwart es erneut freigeben.</div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a class="btn btn-link" href="<?= e($abbrechen) ?>">Abbrechen</a>
            </div>
        </form>
    </div>
</div>

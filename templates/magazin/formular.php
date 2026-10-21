<?php
/**
 * Artikel anlegen (mit erster Eingangsbuchung) bzw. bearbeiten. Autor/Verlag, Jahr und ISBN blendet assets/app.js nur
 * bei der Kategorie Literatur ein; ohne JavaScript sind sie sichtbar (Hinweis „nur bei Literatur“).
 *
 * @var ?array<string,mixed> $artikel null bei der Neuanlage
 * @var array<string,string> $werte
 * @var array<string,string> $fehler
 * @var ?string $meldung Konflikt oder Fehler des Dienstes
 * @var string $stand
 * @var list<array<string,mixed>> $kategorien
 * @var list<array<string,mixed>> $spurweiten
 * @var ?string $formularAktion  anderes Ziel der Neuanlage (Übernahme einer Börsenanfrage)
 * @var ?string $formularAbbrechen
 * @var ?string $formularHinweis Hinweis zu den Fotos statt „Fotos fügst du nach dem Speichern …“
 */
use Emc\Magazin\BuchungValidator;

include __DIR__ . '/_labels.php';
$neu = $artikel === null;
$aktion = $neu ? ($formularAktion ?? url('/magazin')) : url('/magazin/' . (int) $artikel['id']);
$abbrechen = $neu ? ($formularAbbrechen ?? url('/magazin')) : url('/magazin/' . (int) $artikel['id']);
$fotoHinweis = $formularHinweis ?? 'Fotos fügst du nach dem Speichern auf der Artikelseite hinzu.';
$wert = fn(string $n): string => (string) ($werte[$n] ?? '');
$klasse = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$fehlerFeld = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { ?><div class="invalid-feedback d-block"><?= e($fehler[$n]) ?></div><?php }
};
$text = function (string $n, string $label, int $max, bool $pflicht = false, string $hinweis = '', string $typ = 'text', string $extra = '') use ($wert, $klasse, $fehlerFeld): void { ?>
    <div class="mb-3">
        <label for="<?= e($n) ?>" class="form-label"><?= e($label) ?></label>
        <input type="<?= e($typ) ?>" class="form-control<?= $klasse($n) ?>" id="<?= e($n) ?>" name="<?= e($n) ?>" value="<?= e($wert($n)) ?>" maxlength="<?= $max ?>"<?= $pflicht ? ' required' : '' ?><?= $extra ?>>
        <?php if ($hinweis !== ''): ?><div class="form-text"><?= e($hinweis) ?></div><?php endif; ?>
        <?php $fehlerFeld($n); ?>
    </div>
<?php };
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= e($titel) ?><?php if (!$neu): ?> <small class="text-muted"><?= e((string) $artikel['code']) ?></small><?php endif; ?></h1>
        <?php if ($meldung !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($meldung) ?></div>
        <?php elseif ($fehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <?php if ($neu): ?><p class="text-muted small">Der Code (M1, M2 …) wird beim Speichern automatisch vergeben.</p><?php endif; ?>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if (!$neu): ?><input type="hidden" name="stand" value="<?= e($stand) ?>"><?php endif; ?>
            <?php $text('bezeichnung', 'Bezeichnung', 200, true, 'z. B. Märklin C-Gleis 24188 gerade'); ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="kategorie_id" class="form-label">Kategorie</label>
                    <select class="form-select<?= $klasse('kategorie_id') ?>" id="kategorie_id" name="kategorie_id" data-literatur-wahl required>
                        <option value="">– bitte wählen –</option>
                        <?php foreach ($kategorien as $k): ?>
                            <option value="<?= (int) $k['id'] ?>" data-schluessel="<?= e((string) ($k['schluessel'] ?? '')) ?>"<?= $wert('kategorie_id') === (string) $k['id'] ? ' selected' : '' ?>><?= e((string) $k['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $fehlerFeld('kategorie_id'); ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="zustand" class="form-label">Zustand</label>
                    <select class="form-select<?= $klasse('zustand') ?>" id="zustand" name="zustand">
                        <?php foreach ($magazinZustand as $v => $t): ?>
                            <option value="<?= e($v) ?>"<?= $wert('zustand') === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $fehlerFeld('zustand'); ?>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6"><?php $text('hersteller', 'Hersteller', 100); ?></div>
                <div class="col-md-6"><?php $text('artikelnummer', 'Artikelnummer', 60); ?></div>
            </div>
            <div class="mb-3">
                <label for="spurweite_id" class="form-label">Spurweite</label>
                <select class="form-select<?= $klasse('spurweite_id') ?>" id="spurweite_id" name="spurweite_id">
                    <option value="">– keine –</option>
                    <?php foreach ($spurweiten as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= $wert('spurweite_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e((string) $s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php $fehlerFeld('spurweite_id'); ?>
            </div>
            <?php if ($neu): ?>
                <?php $text('lagerort', 'Lagerort', 100, false, 'z. B. Keller, Regal 3'); ?>
            <?php else: ?>
                <div class="mb-3">
                    <span class="form-label d-block">Lagerort</span>
                    <div><?= $wert('lagerort') === '' ? '–' : e($wert('lagerort')) ?></div>
                    <div class="form-text">Den Lagerort änderst du auf der Artikelseite über „Umlagerung“.</div>
                </div>
            <?php endif; ?>
            <fieldset class="border rounded p-3 mb-3" data-nur-literatur>
                <legend class="h6 w-auto px-1 mb-0">Literatur <span class="small text-muted fw-normal" data-nur-literatur-hinweis>(nur bei Literatur)</span></legend>
                <?php $text('autor_verlag', 'Autor/Verlag', 200); ?>
                <div class="row">
                    <div class="col-6"><?php $text('jahr', 'Jahr', 4, false, '', 'text', ' inputmode="numeric"'); ?></div>
                    <div class="col-6"><?php $text('isbn', 'ISBN', 20); ?></div>
                </div>
            </fieldset>
            <div class="mb-3">
                <label for="notiz" class="form-label">Notiz</label>
                <textarea class="form-control<?= $klasse('notiz') ?>" id="notiz" name="notiz" rows="3" maxlength="5000"><?= e($wert('notiz')) ?></textarea>
                <?php $fehlerFeld('notiz'); ?>
            </div>

            <?php if ($neu): ?>
                <fieldset class="border rounded p-3 mb-3">
                    <legend class="h6 w-auto px-1 mb-0">Erster Eingang</legend>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label for="menge" class="form-label">Menge</label>
                            <input type="number" inputmode="numeric" min="1" max="<?= BuchungValidator::MENGE_MAX ?>" step="1" class="form-control<?= $klasse('menge') ?>" id="menge" name="menge" value="<?= e($wert('menge')) ?>" required>
                            <?php $fehlerFeld('menge'); ?>
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="herkunft" class="form-label">Herkunft</label>
                            <select class="form-select<?= $klasse('herkunft') ?>" id="herkunft" name="herkunft" required>
                                <option value="">– bitte wählen –</option>
                                <?php foreach ($magazinHerkunft as $v => $t): ?>
                                    <option value="<?= e($v) ?>"<?= $wert('herkunft') === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php $fehlerFeld('herkunft'); ?>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="datum" class="form-label">Datum</label>
                            <input type="date" class="form-control<?= $klasse('datum') ?>" id="datum" name="datum" value="<?= e($wert('datum')) ?>" max="<?= e(date('Y-m-d')) ?>">
                            <?php $fehlerFeld('datum'); ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="betrag" class="form-label">Wert €</label>
                            <input type="text" inputmode="decimal" class="form-control<?= $klasse('betrag') ?>" id="betrag" name="betrag" value="<?= e($wert('betrag')) ?>" placeholder="optional">
                            <?php $fehlerFeld('betrag'); ?>
                        </div>
                        <div class="col-12">
                            <label for="freitext" class="form-label">Von wem / Notiz</label>
                            <input type="text" class="form-control<?= $klasse('freitext') ?>" id="freitext" name="freitext" maxlength="<?= BuchungValidator::FREITEXT_MAX ?>" value="<?= e($wert('freitext')) ?>" placeholder="z. B. Spende von …">
                            <?php $fehlerFeld('freitext'); ?>
                        </div>
                    </div>
                </fieldset>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a class="btn btn-link" href="<?= e($abbrechen) ?>">Abbrechen</a>
        </form>
        <?php if ($neu): ?><p class="small text-muted mt-3"><?= e($fotoHinweis) ?></p><?php endif; ?>
    </div>
</div>

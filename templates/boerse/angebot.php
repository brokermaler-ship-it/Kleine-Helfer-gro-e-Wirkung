<?php
/**
 * Ein Angebot: Kopf mit Nummer, Preis und Status, Angaben, Veröffentlichung und Status-Aktionen, Fotos, Änderungen.
 * Name des Mitglieds und interne Notiz sehen nur der Börsenwart und das Mitglied selbst.
 *
 * @var array<string,mixed> $angebot aus AngebotRepository::finde
 * @var list<array<string,mixed>> $fotos
 * @var list<array<string,mixed>> $verlauf
 * @var bool $istBoersenwart
 * @var bool $istEigenes
 * @var bool $darfBearbeiten Recht am Angebot und weder verkauft noch Börse abgeschlossen
 * @var list<string> $freigaben erlaubte Werte für „oeffentlich“
 * @var list<array<string,mixed>> $offeneBoersen
 * @var ?string $meldung
 */
use Emc\Dateien\ObjektFotos;
use Emc\Protokoll\Aenderungsprotokoll;

include __DIR__ . '/_angebot_labels.php';
$a = $angebot;
$id = (int) $a['id'];
$siehtIntern = $istBoersenwart || $istEigenes;
$zeile = function (string $label, ?string $wert, bool $html = false): void {
    if ($wert === null || $wert === '') {
        return;
    } ?>
    <dt class="col-sm-4 col-lg-3"><?= e($label) ?></dt>
    <dd class="col-sm-8 col-lg-9"><?= $html ? $wert : nl2br(e($wert)) ?></dd>
<?php };
$freigabeText = [
    'freigegeben' => 'Öffentlich freigeben',
    'vorgeschlagen' => 'Zur Veröffentlichung vorschlagen',
    'nein' => $a['oeffentlich'] === 'vorgeschlagen' && !$istBoersenwart ? 'Vorschlag zurückziehen' : 'Nicht mehr öffentlich',
];
$statusKnopf = function (string $aktion, string $text, string $klasse = 'btn-outline-secondary', string $bestaetigung = '') use ($id, $csrf): void { ?>
    <form method="post" action="<?= e(url('/angebote/' . $id . '/status')) ?>" class="m-0"<?= $bestaetigung !== '' ? ' onsubmit="return confirm(' . e(json_encode($bestaetigung, JSON_UNESCAPED_UNICODE)) . ');"' : '' ?>>
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
        <input type="hidden" name="aktion" value="<?= e($aktion) ?>">
        <button type="submit" class="btn <?= e($klasse) ?>"><?= e($text) ?></button>
    </form>
<?php };
$offen = in_array($a['status'], ['angeboten', 'reserviert'], true);
$abgeschlossen = $a['boerse_status'] === 'abgeschlossen';
?>
<?php if ($meldung !== null): ?><div class="alert alert-danger" role="alert"><?= e($meldung) ?></div><?php endif; ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h3 mb-1 text-break"><span class="display-6 fw-bold me-2"><?= e((string) $a['nummer']) ?></span><?= e((string) $a['bezeichnung']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fs-4 fw-semibold" data-preis><?= e($preis((string) $a['wunschpreis'])) ?></span>
            <?= $angebotStatusBadge((string) $a['status']) ?>
            <?= $angebotOeffentlichBadge((string) $a['oeffentlich']) ?>
        </div>
    </div>
    <?php if ($darfBearbeiten): ?>
        <a class="btn btn-primary" href="<?= e(url('/angebote/' . $id . '/bearbeiten')) ?>">Bearbeiten</a>
    <?php endif; ?>
</div>

<?php if ($abgeschlossen): ?>
    <div class="alert alert-secondary" role="note">Die Börse „<?= e((string) $a['boerse_name']) ?>“ ist abgeschlossen. Das Angebot lässt sich nicht mehr ändern<?= $a['status'] === 'zurueck' && $istBoersenwart ? ', aber in eine andere Börse wieder anbieten' : '' ?>.</div>
<?php endif; ?>

<dl class="row">
    <?php $zeile('Kategorie', (string) $a['kategorie_name']); ?>
    <?php $zeile('Spurweite', $a['spurweite_name']); ?>
    <?php $zeile('Zustand', $angebotZustand[(string) $a['zustand']] ?? (string) $a['zustand']); ?>
    <?php $zeile('Menge', (int) $a['menge'] > 1 ? (int) $a['menge'] . ' Stück' : null); ?>
    <?php if ($a['herkunft'] === 'verein'): ?>
        <?php
        $quelle = 'Verein';
        if ($a['magazin_artikel_id'] !== null) {
            $quelle .= ' · <a href="' . e(url('/magazin/' . (int) $a['magazin_artikel_id'])) . '">' . e((string) $a['magazin_code']) . '</a>';
        } elseif ($a['magazin_sammlung_id'] !== null) {
            $quelle .= ' · Sammlung <a href="' . e(url('/magazin/sammlungen/' . (int) $a['magazin_sammlung_id'])) . '">' . e((string) $a['sammlung_name']) . '</a>';
        }
        $zeile('Herkunft', $quelle, true);
        ?>
    <?php else: ?>
        <?php $zeile('Herkunft', $siehtIntern ? 'Mitglied: ' . ($a['person_name'] ?? Aenderungsprotokoll::EHEMALIGES_MITGLIED) . ($istEigenes ? ' (du)' : '') : 'Mitglied'); ?>
        <?php if ($siehtIntern && (int) $a['erloes_spende'] === 1): ?><?php $zeile('Erlös', 'als Spende an den Verein'); ?><?php endif; ?>
    <?php endif; ?>
    <?php $zeile('Börse', $a['boerse_id'] === null ? 'dauerhaft (an jeder Börse)' : '<a href="' . e(url('/boersen/' . (int) $a['boerse_id'])) . '">' . e((string) $a['boerse_name']) . '</a> am ' . e($datum((string) $a['boerse_datum'])), true); ?>
    <?php $zeile('Beschreibung', $a['beschreibung']); ?>
    <?php if ($siehtIntern) { $zeile('Notiz (intern)', $a['notiz']); } ?>
</dl>

<?php if ($freigaben !== [] || ($istBoersenwart || $istEigenes)): ?>
<section class="card mb-4" aria-labelledby="aktionen-titel">
    <div class="card-body">
        <h2 class="h5" id="aktionen-titel">Aktionen</h2>
        <?php if ($a['oeffentlich'] === 'vorgeschlagen'): ?>
            <p class="small text-muted mb-2"><?= $istBoersenwart ? 'Das Mitglied möchte das Angebot auf der Webseite zeigen. Bitte prüfen und freigeben.' : 'Der Börsenwart prüft das Angebot und gibt es frei.' ?></p>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach (array_reverse($freigaben) as $nach): ?>
                <form method="post" action="<?= e(url('/angebote/' . $id . '/oeffentlich')) ?>" class="m-0">
                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                    <input type="hidden" name="nach" value="<?= e($nach) ?>">
                    <button type="submit" class="btn <?= $nach === 'nein' ? 'btn-outline-secondary' : 'btn-success' ?>"><?= e($freigabeText[$nach]) ?></button>
                </form>
            <?php endforeach; ?>
            <?php if (!$abgeschlossen && $offen && ($istBoersenwart || ($istEigenes && $a['status'] === 'angeboten'))): ?>
                <?php $statusKnopf('zurueckziehen', 'Zurückziehen', 'btn-outline-danger', 'Angebot zurückziehen? Es wird dann nicht mehr verkauft.'); ?>
            <?php endif; ?>
            <?php if ($istBoersenwart && !$abgeschlossen && $a['status'] === 'angeboten'): ?>
                <?php $statusKnopf('reservieren', 'Reservieren'); ?>
            <?php elseif ($istBoersenwart && !$abgeschlossen && $a['status'] === 'reserviert'): ?>
                <?php $statusKnopf('reservierung_aufheben', 'Reservierung aufheben'); ?>
            <?php endif; ?>
        </div>
        <?php if ($istBoersenwart && $a['status'] === 'zurueck'): ?>
            <form method="post" action="<?= e(url('/angebote/' . $id . '/status')) ?>" class="row g-2 align-items-end mt-2">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <input type="hidden" name="aktion" value="wieder_anbieten">
                <div class="col-12 col-md-6">
                    <label for="wieder-boerse" class="form-label small mb-0">Wieder anbieten in</label>
                    <select class="form-select" id="wieder-boerse" name="boerse_id">
                        <option value="dauerhaft"<?= $a['boerse_id'] === null ? ' selected' : '' ?>>dauerhaft (an jeder Börse)</option>
                        <?php foreach ($offeneBoersen as $x): ?>
                            <option value="<?= (int) $x['id'] ?>"<?= (int) ($a['boerse_id'] ?? 0) === (int) $x['id'] ? ' selected' : '' ?>><?= e((string) $x['name'] . ' (' . $datum((string) $x['datum']) . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto"><button type="submit" class="btn btn-outline-primary">Wieder anbieten</button></div>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section id="fotos" class="mt-4" aria-labelledby="fotos-titel">
    <h2 class="h4" id="fotos-titel">Fotos</h2>
    <?php if ($fotos === []): ?>
        <p class="text-muted">Noch keine Fotos.</p>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($fotos as $f): ?>
                <?php $geteilt = (int) ($f['zuordnungen_anzahl'] ?? 0) > 1; ?>
                <div class="text-center">
                    <a href="<?= e(url('/dateien/' . $f['id'] . '/anzeige')) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(url('/dateien/' . $f['id'] . '/vorschau')) ?>" alt="<?= e((string) $f['titel']) ?>" loading="lazy" class="rounded border magazin-foto">
                    </a>
                    <?php if ((int) $f['id'] === (int) ($a['titelbild_dokument_id'] ?? 0)): ?><div><span class="badge text-bg-dark">&#9733; Titelbild</span></div><?php endif; ?>
                    <?php if ($darfBearbeiten): ?>
                        <form method="post" action="<?= e(url('/dateien/' . $f['id'] . '/loeschen')) ?>" onsubmit="return confirm(<?= e(json_encode($geteilt ? 'Dieses Foto vom Angebot entfernen? Im Magazin bleibt es.' : 'Dieses Foto löschen?', JSON_UNESCAPED_UNICODE)) ?>);">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <input type="hidden" name="angebot_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0"><?= $geteilt ? 'Vom Angebot entfernen' : 'Foto löschen' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($darfBearbeiten): ?>
        <form method="post" action="<?= e(url('/angebote/' . $id . '/fotos')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <div class="min-w-0">
                <?php $fotoId = 'a-fotos'; $fotoBeschriftung = 'Fotos hinzufügen (höchstens ' . ObjektFotos::MAX_FOTOS . ')'; $fotoGross = false; include dirname(__DIR__) . '/partials/fotofeld.php'; ?>
            </div>
            <button type="submit" class="btn btn-outline-primary" data-senden>Hochladen</button>
        </form>
        <?php if (!$istBoersenwart && $a['oeffentlich'] === 'freigegeben'): ?><p class="form-text">Neue oder gelöschte Fotos brauchen eine neue Freigabe durch den Börsenwart.</p><?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($siehtIntern) { $verlaufTitel = 'Änderungen'; include dirname(__DIR__) . '/partials/verlauf.php'; } ?>
<p class="mt-3 d-flex flex-wrap gap-3">
    <a href="<?= e(url('/angebote')) ?>">&larr; Alle Angebote</a>
    <?php if ($a['boerse_id'] !== null): ?><a href="<?= e(url('/boersen/' . (int) $a['boerse_id'])) ?>">Zur Börse</a><?php endif; ?>
</p>

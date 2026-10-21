<?php
/**
 * Magazinartikel: Kopf mit Code und Bestand, Angaben, Buchungsformular (Börsenwart), Verlauf der Buchungen, Fotos und
 * Änderungsprotokoll.
 *
 * @var array<string,mixed> $artikel aus MagazinRepository::finde
 * @var list<array<string,mixed>> $buchungen
 * @var list<array<string,mixed>> $fotos
 * @var list<array<string,mixed>> $verlauf
 * @var bool $istBoersenwart
 */
use Emc\Dateien\ObjektFotos;

include __DIR__ . '/_labels.php';
$a = $artikel;
$id = (int) $a['id'];
$zeile = function (string $label, ?string $wert, bool $html = false): void {
    if ($wert === null || $wert === '') {
        return;
    } ?>
    <dt class="col-sm-4 col-lg-3"><?= e($label) ?></dt>
    <dd class="col-sm-8 col-lg-9"><?= $html ? $wert : nl2br(e($wert)) ?></dd>
<?php };
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h3 mb-1 text-break"><span class="display-6 fw-bold me-2"><?= e((string) $a['code']) ?></span><?= e((string) $a['bezeichnung']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fs-5">Bestand <strong class="ms-1" data-bestand><?= (int) $a['bestand'] ?></strong></span>
            <?= $magazinZustandBadge((string) $a['zustand']) ?>
        </div>
    </div>
    <?php if ($istBoersenwart): ?>
        <div class="d-flex flex-wrap gap-2">
            <?php if ((int) $a['bestand'] > 0): ?>
                <a class="btn btn-outline-primary" href="<?= e(url('/angebote/neu', ['magazin' => $id])) ?>">In die Börse übernehmen</a>
            <?php endif; ?>
            <a class="btn btn-primary" href="<?= e(url('/magazin/' . $id . '/bearbeiten')) ?>">Bearbeiten</a>
        </div>
    <?php endif; ?>
</div>

<dl class="row">
    <?php $zeile('Kategorie', (string) $a['kategorie_name']); ?>
    <?php $zeile('Lagerort', $a['lagerort']); ?>
    <?php $zeile('Hersteller', $a['hersteller']); ?>
    <?php $zeile('Artikelnummer', $a['artikelnummer']); ?>
    <?php $zeile('Spurweite', $a['spurweite_name']); ?>
    <?php $zeile('Autor/Verlag', $a['autor_verlag']); ?>
    <?php $zeile('Jahr', $a['jahr'] === null ? null : (string) $a['jahr']); ?>
    <?php $zeile('ISBN', $a['isbn']); ?>
    <?php if ($a['sammlung_id'] !== null): ?>
        <?php $zeile('Sammlung', '<a href="' . e(url('/magazin/sammlungen/' . (int) $a['sammlung_id'])) . '">' . e((string) $a['sammlung_name']) . '</a>', true); ?>
    <?php endif; ?>
    <?php if ($a['fahrzeug_id'] !== null): ?>
        <?php $zeile('Fahrzeug', '<a href="' . e(url('/fahrzeuge/' . (int) $a['fahrzeug_id'])) . '">' . e((string) $a['fahrzeug_inventarnummer']) . '</a>', true); ?>
    <?php endif; ?>
    <?php $zeile('Notiz', $a['notiz']); ?>
</dl>

<?php if ($istBoersenwart) { include __DIR__ . '/_buchung_formular.php'; } ?>

<?php include __DIR__ . '/_verlauf.php'; ?>

<section id="fotos" class="mt-4" aria-labelledby="fotos-titel">
    <h2 class="h4" id="fotos-titel">Fotos</h2>
    <?php if ($fotos === []): ?>
        <p class="text-muted">Noch keine Fotos.</p>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($fotos as $f): ?>
                <div class="text-center">
                    <a href="<?= e(url('/dateien/' . $f['id'] . '/anzeige')) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(url('/dateien/' . $f['id'] . '/vorschau')) ?>" alt="<?= e((string) $f['titel']) ?>" loading="lazy" class="rounded border magazin-foto">
                    </a>
                    <?php if ((int) $f['id'] === (int) ($a['titelbild_dokument_id'] ?? 0)): ?><div><span class="badge text-bg-dark">&#9733; Titelbild</span></div><?php endif; ?>
                    <?php if ($istBoersenwart && ((int) ($f['zuordnungen_anzahl'] ?? 0) === 1 || $darf('datei_bearbeiten'))): ?>
                        <form method="post" action="<?= e(url('/dateien/' . $f['id'] . '/loeschen')) ?>" onsubmit="return confirm('Dieses Foto löschen?');">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0">Foto löschen</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($istBoersenwart): ?>
        <form method="post" action="<?= e(url('/magazin/' . $id . '/fotos')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <div class="min-w-0">
                <?php $fotoId = 'm-fotos'; $fotoBeschriftung = 'Fotos hinzufügen (höchstens ' . ObjektFotos::MAX_FOTOS . ')'; $fotoGross = false; include dirname(__DIR__) . '/partials/fotofeld.php'; ?>
            </div>
            <button type="submit" class="btn btn-outline-primary" data-senden>Hochladen</button>
        </form>
    <?php endif; ?>
</section>

<?php $verlaufTitel = 'Änderungen'; include dirname(__DIR__) . '/partials/verlauf.php'; ?>
<p class="mt-3 d-flex flex-wrap gap-3">
    <a href="<?= e(url('/magazin')) ?>">&larr; Zurück zum Magazin</a>
    <?php if ($a['sammlung_id'] !== null): ?><a href="<?= e(url('/magazin/sammlungen/' . (int) $a['sammlung_id'])) ?>">Zur Sammlung</a><?php endif; ?>
</p>

<?php
/**
 * Eine Reparatur: alle Angaben, Ersatzteile mit „Kosten gesamt“, Fotos. „Bearbeiten“, Fotos hinzufügen und löschen
 * nur mit Recht (Berechtigung::darfReparaturBearbeiten).
 *
 * @var array<string,mixed> $reparatur aus ReparaturRepository::finde
 * @var string $objektTyp
 * @var ?array<string,mixed> $objekt null, wenn das Objekt inzwischen fehlt
 * @var ?string $kennung
 * @var ?string $name
 * @var list<array<string,mixed>> $fotos
 * @var bool $darfBearbeiten
 */
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturFotos;

include __DIR__ . '/_labels.php';
$rep = $reparatur;
$id = (int) $rep['id'];
$anlage = $objektTyp === 'anlage';
$objektUrl = $objekt === null ? null : url(($anlage ? '/anlagen/' : '/fahrzeuge/') . (int) $objekt['id']);
$zeile = function (string $label, ?string $wert, bool $mehrzeilig = false): void { ?>
    <dt class="col-sm-4 col-lg-3"><?= e($label) ?></dt>
    <dd class="col-sm-8 col-lg-9"><?= $wert === null || $wert === '' ? '–' : ($mehrzeilig ? nl2br(e($wert)) : e($wert)) ?></dd>
<?php };
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Reparatur</h1>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($objekt !== null): ?>
                <a class="text-reset text-decoration-none" href="<?= e($objektUrl) ?>"><span class="fahrzeug-code me-1"><?= e((string) $kennung) ?></span> <span class="fw-semibold"><?= e((string) $name) ?></span></a>
            <?php else: ?>
                <span class="text-muted">(<?= $anlage ? 'Anlage' : 'Fahrzeug' ?> gelöscht)</span>
            <?php endif; ?>
            <?= $reparaturStatusBadge((string) $rep['status']) ?>
            <?= $prioritaetBadge((string) $rep['prioritaet'], true) ?>
        </div>
    </div>
    <?php if ($darfBearbeiten): ?>
        <a class="btn btn-primary" href="<?= e(url('/reparaturen/' . $id . '/bearbeiten')) ?>">Bearbeiten</a>
    <?php endif; ?>
</div>

<dl class="row">
    <?php $zeile('Beschreibung', (string) $rep['beschreibung'], true); ?>
    <?php if ($anlage): $zeile('Wo/Bauteil', $rep['wo_bauteil']); endif; ?>
    <?php $zeile('Gemeldet', $reparaturDatum((string) $rep['gemeldet_am']) . ' von ' . ($rep['gemeldet_von_name'] ?? Aenderungsprotokoll::EHEMALIGES_MITGLIED)); ?>
    <?php $zeile('zuständig', $rep['zustaendig_name'] ?? ($rep['zustaendig_person_id'] === null ? null : Aenderungsprotokoll::EHEMALIGES_MITGLIED)); ?>
    <?php $zeile('Geplanter Termin', $rep['termin'] === null ? null : $reparaturDatum((string) $rep['termin'])); ?>
    <?php $zeile('Notizen', $rep['notizen'], true); ?>
    <?php if ($rep['was_gemacht'] !== null || $rep['status'] === 'erledigt'): $zeile('Was wurde gemacht', $rep['was_gemacht'], true); endif; ?>
    <?php if ($rep['abgeschlossen_am'] !== null): $zeile('Abgeschlossen am', $reparaturDatum((string) $rep['abgeschlossen_am'])); endif; ?>
</dl>

<section class="mt-4" aria-labelledby="ersatzteile-titel">
    <h2 class="h5" id="ersatzteile-titel">Ersatzteile</h2>
    <?php if ($rep['ersatzteile'] === []): ?>
        <p class="text-muted">Keine Ersatzteile eingetragen.</p>
    <?php else: ?>
        <?php // Am Handy nur „Ersatzteil“ und „Kosten“ (Details unter der Bezeichnung), damit die Kosten ohne Wischen sichtbar sind. ?>
        <table class="table table-sm align-middle reparatur-ersatzteile">
            <thead>
            <tr>
                <th scope="col">Ersatzteil</th>
                <th scope="col" class="d-none d-md-table-cell">Artikelnummer</th>
                <th scope="col" class="d-none d-md-table-cell">Bezugsquelle</th>
                <th scope="col" class="d-none d-md-table-cell">bestellt</th>
                <th scope="col" class="text-end">Kosten</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rep['ersatzteile'] as $t): ?>
                <?php $details = array_filter([(string) ($t['artikelnummer'] ?? ''), (string) ($t['bezugsquelle'] ?? '')], fn(string $x): bool => $x !== ''); ?>
                <tr>
                    <td>
                        <?= e((string) $t['bezeichnung']) ?>
                        <div class="d-md-none small text-muted">
                            <?= e(implode(' · ', $details)) ?><?php if ((int) $t['bestellt'] === 1): ?><?= $details === [] ? '' : ' · ' ?>bestellt<?php endif; ?>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= e((string) ($t['artikelnummer'] ?? '')) ?></td>
                    <td class="d-none d-md-table-cell"><?= e((string) ($t['bezugsquelle'] ?? '')) ?></td>
                    <td class="d-none d-md-table-cell"><?= (int) $t['bestellt'] === 1 ? 'ja' : 'nein' ?></td>
                    <td class="text-end text-nowrap"><?= $t['kosten'] === null ? '–' : e($betrag((string) $t['kosten'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th scope="row">Kosten gesamt</th>
                <td class="d-none d-md-table-cell" colspan="3"></td>
                <td class="text-end text-nowrap fw-bold"><?= e($betrag((string) $rep['kosten_gesamt'])) ?></td>
            </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</section>

<section class="mt-4" aria-labelledby="fotos-titel">
    <h2 class="h5" id="fotos-titel">Fotos</h2>
    <?php if ($fotos === []): ?>
        <p class="text-muted">Noch keine Fotos.</p>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($fotos as $f): ?>
                <div class="text-center">
                    <a href="<?= e(url('/dateien/' . $f['id'])) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(url('/dateien/' . $f['id'] . '/vorschau')) ?>" alt="<?= e((string) $f['titel']) ?>" loading="lazy" class="rounded border" style="width:128px;height:96px;object-fit:cover">
                    </a>
                    <?php if ($darfBearbeiten): ?>
                        <form method="post" action="<?= e(url('/dateien/' . $f['id'] . '/loeschen')) ?>" onsubmit="return confirm('Dieses Foto löschen?');">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0">Foto löschen</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($darfBearbeiten): ?>
        <form method="post" action="<?= e(url('/reparaturen/' . $id . '/fotos')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <div>
                <?php $fotoId = 'r-fotos'; $fotoBeschriftung = 'Fotos hinzufügen (höchstens ' . ReparaturFotos::MAX_FOTOS . ')'; $fotoGross = false; include dirname(__DIR__) . '/partials/fotofeld.php'; ?>
            </div>
            <button type="submit" class="btn btn-outline-primary" data-senden>Hochladen</button>
        </form>
    <?php endif; ?>
</section>

<p class="mt-4 d-flex flex-wrap gap-3">
    <a href="<?= e(url('/reparaturen')) ?>">&larr; Alle Reparaturen</a>
    <?php if ($objektUrl !== null): ?><a href="<?= e($objektUrl . '#reparaturen') ?>"><?= $anlage ? 'Zur Anlage' : 'Zum Fahrzeug' ?></a><?php endif; ?>
</p>

<?php
/** @var array<string,mixed> $fahrzeug */
/** @var list<array<string,mixed>> $dateien */
$fotos = array_values(array_filter($dateien, fn(array $d): bool => $d['kategorie'] === 'foto'));
$dokumente = array_values(array_filter($dateien, fn(array $d): bool => $d['kategorie'] !== 'foto'));
$kategorieLabels = ['foto' => 'Foto', 'anleitung' => 'Anleitung', 'schaltplan' => 'Schaltplan', 'gleisplan' => 'Gleisplan', 'rechnung' => 'Rechnung', 'sonstiges' => 'Sonstiges'];
$symbole = ['pdf' => '&#128196;', 'txt' => '&#128221;', 'csv' => '&#128202;', 'xlsx' => '&#128202;', 'ods' => '&#128202;', 'docx' => '&#128209;', 'odt' => '&#128209;'];
$groesse = function (int $b): string {
    if ($b >= 1048576) {
        return number_format($b / 1048576, 1, ',', '.') . ' MB';
    }
    return number_format(max(1, $b / 1024), 0, ',', '.') . ' KB';
};
$bearbeiten = $darf('datei_bearbeiten');
$loeschenForm = function (array $d) use ($csrf): string {
    ob_start();
    ?>
    <form method="post" action="<?= e(url('/dateien/' . $d['id'] . '/loeschen')) ?>" class="d-inline"
          onsubmit="return confirm('Datei „<?= e(addslashes((string) $d['titel'])) ?>“ endgültig löschen?');">
        <input type="hidden" name="_csrf" value="<?= e($csrf->token()) ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
    </form>
    <?php
    return (string) ob_get_clean();
};
?>
<?php if ($bearbeiten) { include dirname(__DIR__) . '/dateien/upload_formular.php'; } ?>

<h2 class="h5">Fotos</h2>
<?php if ($fotos === []): ?>
    <p class="text-muted">Noch keine Fotos vorhanden.</p>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ($fotos as $f): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card h-100">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#foto-modal-<?= (int) $f['id'] ?>">
                        <img class="card-img-top" loading="lazy" alt="<?= e((string) $f['titel']) ?>" src="<?= e(url('/dateien/' . $f['id'] . '/vorschau')) ?>">
                    </a>
                    <div class="card-body p-2 small">
                        <div class="text-truncate" title="<?= e((string) $f['titel']) ?>"><?= e((string) $f['titel']) ?></div>
                        <?php if ((int) $f['id'] === (int) $fahrzeug['titelbild_dokument_id']): ?>
                            <span class="badge text-bg-primary">&#9733; Titelbild</span>
                        <?php endif; ?>
                        <?php if ($bearbeiten): ?>
                            <div class="d-flex gap-1 mt-1">
                                <?php if ((int) $f['id'] !== (int) $fahrzeug['titelbild_dokument_id']): ?>
                                    <form method="post" action="<?= e(url('/fahrzeuge/' . $fahrzeug['id'] . '/titelbild')) ?>">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf->token()) ?>">
                                        <input type="hidden" name="dokument_id" value="<?= (int) $f['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Als Titelbild">&#9734; Als Titelbild</button>
                                    </form>
                                <?php endif; ?>
                                <?= $loeschenForm($f) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="foto-modal-<?= (int) $f['id'] ?>" tabindex="-1" aria-label="<?= e((string) $f['titel']) ?>" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h6"><?= e((string) $f['titel']) ?></h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                        </div>
                        <div class="modal-body text-center">
                            <img class="img-fluid" loading="lazy" alt="<?= e((string) $f['titel']) ?>" src="<?= e(url('/dateien/' . $f['id'] . '/anzeige')) ?>">
                        </div>
                        <div class="modal-footer"><a class="btn btn-outline-secondary" href="<?= e(url('/dateien/' . $f['id'])) ?>" target="_blank" rel="noopener">Original öffnen</a></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h5">Dokumente</h2>
<?php if ($dokumente === []): ?>
    <p class="text-muted">Noch keine Dokumente vorhanden.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th></th><th>Titel</th><th>Kategorie</th><th>Größe</th><th>Datum</th><th>Hochgeladen von</th><?php if ($bearbeiten): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($dokumente as $d): ?>
                <tr>
                    <td aria-hidden="true"><?= $symbole[$d['endung']] ?? '&#128196;' ?></td>
                    <td><a href="<?= e(url('/dateien/' . $d['id'])) ?>"><?= e((string) $d['titel']) ?></a> <span class="text-muted small">.<?= e((string) $d['endung']) ?></span></td>
                    <td><?= e($kategorieLabels[$d['kategorie']] ?? (string) $d['kategorie']) ?></td>
                    <td><?= e($groesse((int) $d['groesse'])) ?></td>
                    <td><?= e(date('d.m.Y', (int) strtotime((string) $d['hochgeladen_am']))) ?></td>
                    <td><?= e($d['hochgeladen_von_name'] !== '' ? (string) $d['hochgeladen_von_name'] : '–') ?></td>
                    <?php if ($bearbeiten): ?><td class="text-end"><?= $loeschenForm($d) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

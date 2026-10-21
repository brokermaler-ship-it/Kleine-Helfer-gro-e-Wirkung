<?php
/**
 * Angebotsliste der Börse mit Filtern; am Handy als Karten, ab md als Tabelle. Namen der Mitglieder sieht nur der
 * Börsenwart (andere sehen „Mitglied“, beim eigenen Angebot „du“).
 *
 * @var \Emc\Boerse\AngebotFilter $filter
 * @var list<array<string,mixed>> $angebote
 * @var list<array<string,mixed>> $boersen
 * @var bool $istBoersenwart
 * @var int $personId
 */
include __DIR__ . '/_angebot_labels.php';
$herkunftText = function (array $a) use ($istBoersenwart, $personId): string {
    if ($a['herkunft'] === 'verein') {
        return 'Verein' . ($a['magazin_code'] !== null ? ' · ' . $a['magazin_code'] : ($a['magazin_sammlung_id'] !== null ? ' · Sammlung' : ''));
    }
    if ($a['person_id'] !== null && (int) $a['person_id'] === $personId) {
        return 'Mitglied (du)';
    }
    return $istBoersenwart ? 'Mitglied: ' . ($a['person_name'] ?? \Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) : 'Mitglied';
};
$meine = $filter->personId !== null;
$filterAktiv = $filter->boerseId !== null || $filter->dauerhaft || $filter->status !== 'offen' || $filter->oeffentlich !== null || $filter->herkunft !== null || $filter->text !== null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($titel) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('/boersen')) ?>">Börsen</a>
        <a class="btn btn-primary" href="<?= e(url('/angebote/neu')) ?>">+ Angebot anlegen</a>
    </div>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link<?= $meine ? '' : ' active' ?>" href="<?= e(url('/angebote')) ?>">Alle</a></li>
    <li class="nav-item"><a class="nav-link<?= $meine ? ' active' : '' ?>" href="<?= e(url('/angebote', ['meine' => '1'])) ?>">Meine Angebote</a></li>
</ul>

<button class="btn btn-outline-secondary d-md-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterleiste"
        aria-expanded="<?= $filterAktiv ? 'true' : 'false' ?>" aria-controls="filterleiste">Filter</button>
<form method="get" action="<?= e(url('/angebote')) ?>" id="filterleiste" class="collapse d-md-block card card-body mb-3<?= $filterAktiv ? ' show' : '' ?>">
    <?php if ($meine): ?><input type="hidden" name="meine" value="1"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label for="f-q" class="form-label small mb-0">Suche</label>
            <input type="search" class="form-control" id="f-q" name="q" value="<?= e($filter->text ?? '') ?>" placeholder="Nummer, Bezeichnung …">
        </div>
        <div class="col-6 col-md-3">
            <label for="f-boerse" class="form-label small mb-0">Börse</label>
            <select class="form-select" id="f-boerse" name="boerse">
                <option value="">alle</option>
                <option value="dauerhaft"<?= $filter->dauerhaft ? ' selected' : '' ?>>dauerhaft</option>
                <?php foreach ($boersen as $x): ?>
                    <option value="<?= (int) $x['id'] ?>"<?= $filter->boerseId === (int) $x['id'] ? ' selected' : '' ?>><?= e((string) $x['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-status" class="form-label small mb-0">Status</label>
            <select class="form-select" id="f-status" name="status">
                <option value="offen"<?= $filter->status === 'offen' ? ' selected' : '' ?>>offen</option>
                <?php foreach ($angebotStatus as $v => $t): ?>
                    <option value="<?= e($v) ?>"<?= $filter->status === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
                <option value="alle"<?= $filter->status === 'alle' ? ' selected' : '' ?>>alle</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-oeffentlich" class="form-label small mb-0">Webseite</label>
            <select class="form-select" id="f-oeffentlich" name="oeffentlich">
                <option value="">alle</option>
                <?php foreach ($angebotOeffentlich as $v => $t): ?>
                    <option value="<?= e($v) ?>"<?= $filter->oeffentlich === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-herkunft" class="form-label small mb-0">Herkunft</label>
            <select class="form-select" id="f-herkunft" name="herkunft">
                <option value="">alle</option>
                <?php foreach ($angebotHerkunft as $v => $t): ?>
                    <option value="<?= e($v) ?>"<?= $filter->herkunft === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Filtern</button>
            <?php if ($filterAktiv): ?><a class="btn btn-link" href="<?= e(url('/angebote', $meine ? ['meine' => '1'] : [])) ?>">Zurücksetzen</a><?php endif; ?>
        </div>
    </div>
</form>

<?php if ($angebote === []): ?>
    <p class="text-muted"><?= $meine ? 'Du hast gerade keine Angebote in dieser Auswahl.' : 'Keine Angebote gefunden.' ?></p>
<?php else: ?>
    <p class="small text-muted mb-2"><?= count($angebote) === 1 ? '1 Angebot' : count($angebote) . ' Angebote' ?></p>
    <div class="d-md-none list-group">
        <?php foreach ($angebote as $a): ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start" href="<?= e(url('/angebote/' . (int) $a['id'])) ?>">
                <?= $angebotTitelbild($a) ?>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold text-break"><span class="fw-bold me-1"><?= e((string) $a['nummer']) ?></span> <?= e((string) $a['bezeichnung']) ?></div>
                        <div class="text-nowrap fw-semibold"><?= e($preis((string) $a['wunschpreis'])) ?></div>
                    </div>
                    <div class="small text-muted text-break"><?= e($herkunftText($a)) ?> · <?= e($angebotBoerse($a)) ?></div>
                    <div class="mt-1 d-flex flex-wrap gap-1"><?= $angebotStatusBadge((string) $a['status']) ?> <?= $angebotOeffentlichBadge((string) $a['oeffentlich']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th><span class="visually-hidden">Foto</span></th><th>Nummer</th><th>Bezeichnung</th><th class="text-end">Preis</th><th>Herkunft</th><th>Börse</th><th>Status</th><th>Webseite</th></tr></thead>
            <tbody>
            <?php foreach ($angebote as $a): ?>
                <tr>
                    <td><?= $angebotTitelbild($a) ?></td>
                    <td class="fw-bold text-nowrap"><?= e((string) $a['nummer']) ?></td>
                    <td><a href="<?= e(url('/angebote/' . (int) $a['id'])) ?>"><?= e((string) $a['bezeichnung']) ?></a><?php if ((int) $a['menge'] > 1): ?> <span class="text-muted">(<?= (int) $a['menge'] ?> Stück)</span><?php endif; ?></td>
                    <td class="text-end text-nowrap"><?= e($preis((string) $a['wunschpreis'])) ?></td>
                    <td><?= e($herkunftText($a)) ?></td>
                    <td><?= e($angebotBoerse($a)) ?></td>
                    <td><?= $angebotStatusBadge((string) $a['status']) ?></td>
                    <td><?= $angebotOeffentlichBadge((string) $a['oeffentlich']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

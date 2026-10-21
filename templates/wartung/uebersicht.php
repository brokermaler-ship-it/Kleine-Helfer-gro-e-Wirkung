<?php
/**
 * Seite „Wartung“: alle Fälligkeiten (ohne ausgemusterte Fahrzeuge), sortiert nach Dringlichkeit.
 *
 * @var \Emc\Wartung\WartungFilter $filter
 * @var list<array<string,mixed>> $spurweiten
 * @var list<array<string,mixed>> $zeilen aus Wartungsplan::uebersicht
 */
use Emc\Wartung\Wartungsplan;

$stundenText = fn(mixed $h): string => number_format((float) $h, 1, ',', '.') . ' h';
?>
<h1 class="h3 mb-3">Wartung</h1>

<button class="btn btn-outline-secondary d-md-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterleiste"
        aria-expanded="<?= $filter->istGesetzt() ? 'true' : 'false' ?>" aria-controls="filterleiste">Filter</button>
<form method="get" action="<?= e(url('/wartung')) ?>" id="filterleiste" class="collapse d-md-block card card-body mb-3<?= $filter->istGesetzt() ? ' show' : '' ?>">
    <div class="row g-2">
        <div class="col-6 col-md-3">
            <label for="f-typ" class="form-label small mb-0">Objekt</label>
            <select class="form-select" id="f-typ" name="typ">
                <option value="">alle</option>
                <option value="fahrzeug"<?= $filter->typ === 'fahrzeug' ? ' selected' : '' ?>>Fahrzeuge</option>
                <option value="anlage"<?= $filter->typ === 'anlage' ? ' selected' : '' ?>>Anlagen</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="f-spurweite" class="form-label small mb-0">Spurweite</label>
            <select class="form-select" id="f-spurweite" name="spurweite">
                <option value="">alle</option>
                <?php foreach ($spurweiten as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= $filter->spurweiteId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="f-eigentuemer" class="form-label small mb-0">Eigentum</label>
            <select class="form-select" id="f-eigentuemer" name="eigentuemer">
                <option value="">alle</option>
                <option value="verein"<?= $filter->eigentuemer === 'verein' ? ' selected' : '' ?>>Verein (mit Anlagen)</option>
                <option value="mitglied"<?= $filter->eigentuemer === 'mitglied' ? ' selected' : '' ?>>Mitglieder (extern)</option>
            </select>
        </div>
        <div class="col-6 col-md-3 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary">Filtern</button>
            <a class="btn btn-link" href="<?= e(url('/wartung')) ?>">Zurücksetzen</a>
        </div>
    </div>
</form>

<?php if ($zeilen === []): ?>
    <p class="text-muted">Keine Fälligkeiten gefunden.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle wartung-uebersicht">
            <thead>
            <tr>
                <th scope="col">Ampel</th>
                <th scope="col">Objekt</th>
                <th scope="col">Wartungsart</th>
                <th scope="col">Letzte Wartung</th>
                <th scope="col">Fällig am / bei</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($zeilen as $z):
                $faelligkeit = $z['faelligkeit'];
                $link = url(($z['objekt_typ'] === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . (int) $z['objekt']['id']);
                ?>
                <tr>
                    <td><?php include __DIR__ . '/_ampel.php'; ?></td>
                    <td><a href="<?= e($link) ?>"><span class="fw-semibold"><?= e(Wartungsplan::objektKennung($z['objekt_typ'], $z['objekt'])) ?></span>
                        <?= e(Wartungsplan::objektName($z['objekt_typ'], $z['objekt'])) ?></a>
                        <div class="small text-muted"><?= e($z['objekt_typ'] === 'anlage' ? 'Anlage' : 'Fahrzeug') ?> · <?= e((string) $z['objekt']['spurweite_name']) ?></div></td>
                    <td><?= e((string) $z['art']['name']) ?><?php if ($z['eigenes_intervall']): ?> <span class="badge text-bg-light border">eigenes Intervall</span><?php endif; ?></td>
                    <td><?php if ($z['letzte'] === null): ?>–<?php else: ?><?= e(date('d.m.Y', (int) strtotime($z['letzte']['datum']))) ?><?php if ($z['letzte']['stundenstand'] !== null): ?> · <?= e($stundenText($z['letzte']['stundenstand'])) ?><?php endif; ?><?php endif; ?></td>
                    <td><?php include __DIR__ . '/_faellig.php'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

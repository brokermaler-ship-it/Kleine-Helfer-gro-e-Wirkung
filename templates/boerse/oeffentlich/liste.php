<?php
/**
 * Öffentliche Angebotsliste: nur freigegebene Angebote, nie Namen, Herkunft, Notizen, Magazindaten oder Lagerorte.
 *
 * @var list<array<string,mixed>> $angebote
 * @var list<array<string,mixed>> $kategorien
 * @var list<array<string,mixed>> $spurweiten
 * @var ?int $kategorieId
 * @var ?int $spurweiteId
 * @var string $q
 * @var ?array<string,mixed> $naechste
 */
$gefiltert = $kategorieId !== null || $spurweiteId !== null || $q !== '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h3 mb-0">Angebote</h1>
    <a class="btn btn-primary" href="<?= e(url('/boerse/sammlung-anbieten')) ?>">Sammlung anbieten</a>
</div>
<?php if ($naechste !== null): ?>
<p class="lead fs-6 mb-3">Nächste Börse: <?= e((string) $naechste['name']) ?> am <?= e(date('d.m.Y', (int) strtotime((string) $naechste['datum']))) ?><?= $naechste['ort'] !== null && $naechste['ort'] !== '' ? ' in ' . e((string) $naechste['ort']) : '' ?></p>
<?php endif; ?>
<p class="text-muted small">Du interessierst dich für ein Stück? Öffne das Angebot und schick uns eine Kaufanfrage – wir melden uns bei dir.</p>

<form method="get" action="<?= e(url('/boerse')) ?>" class="card card-body mb-3" role="search">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label for="b-q" class="form-label small mb-0">Suche</label>
            <input type="search" class="form-control" id="b-q" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Bezeichnung oder Nummer">
        </div>
        <div class="col-6 col-md-3">
            <label for="b-kategorie" class="form-label small mb-0">Kategorie</label>
            <select class="form-select" id="b-kategorie" name="kategorie">
                <option value="">alle</option>
                <?php foreach ($kategorien as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"<?= $kategorieId === (int) $k['id'] ? ' selected' : '' ?>><?= e((string) $k['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="b-spurweite" class="form-label small mb-0">Spurweite</label>
            <select class="form-select" id="b-spurweite" name="spurweite">
                <option value="">alle</option>
                <?php foreach ($spurweiten as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= $spurweiteId === (int) $s['id'] ? ' selected' : '' ?>><?= e((string) $s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary flex-fill">Filtern</button>
            <?php if ($gefiltert): ?><a class="btn btn-link px-1" href="<?= e(url('/boerse')) ?>">alle</a><?php endif; ?>
        </div>
    </div>
</form>

<?php if ($angebote === []): ?>
    <p class="alert alert-light border">Gerade gibt es keine Angebote<?= $gefiltert ? ' zu deiner Suche' : '' ?>.</p>
<?php else: ?>
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
    <?php foreach ($angebote as $a): $link = url('/boerse/angebot/' . $a['nummer']); ?>
    <div class="col">
        <article class="card h-100 boerse-karte">
            <?php if ($a['titelbild_dokument_id'] !== null): ?>
                <a href="<?= e($link) ?>" tabindex="-1" aria-hidden="true">
                    <img class="card-img-top boerse-vorschau" loading="lazy" alt="" src="<?= e(url('/boerse/foto/' . $a['nummer'] . '/' . (int) $a['titelbild_dokument_id'] . '/vorschau')) ?>">
                </a>
            <?php endif; ?>
            <div class="card-body">
                <h2 class="h6 card-title"><a class="stretched-link text-reset" href="<?= e($link) ?>"><?= e((string) $a['bezeichnung']) ?></a></h2>
                <?php include __DIR__ . '/_karte_daten.php'; ?>
            </div>
        </article>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

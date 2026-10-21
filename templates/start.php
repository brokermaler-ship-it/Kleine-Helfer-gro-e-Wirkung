<?php
/**
 * Startseite: Kachel „Wartung“ (Stufe 2c ergänzt „Offene Defekte“) und Schnellzugriffe.
 *
 * @var ?array{rot:int, gelb:int, nie:int, dringendste: list<array<string,mixed>>} $wartung null, solange das Datenbank-Update aussteht
 */
use Emc\Wartung\Wartungsplan;
?>
<h1 class="h3 mb-3">Start</h1>

<div class="row g-3 mb-4">
    <?php if ($wartung !== null): ?>
    <div class="col-12 col-lg-6">
        <section class="card h-100 start-kachel" aria-labelledby="kachel-wartung">
            <div class="card-body">
                <h2 class="h5 card-title" id="kachel-wartung">Wartung</h2>
                <p class="mb-2">
                    <span class="badge text-bg-danger fs-6"><?= (int) $wartung['rot'] ?> überfällig</span>
                    <span class="badge text-bg-warning fs-6"><?= (int) $wartung['gelb'] ?> bald fällig</span>
                    <span class="badge text-bg-secondary fs-6"><?= (int) $wartung['nie'] ?> noch nie erfasst</span>
                </p>
                <?php if ($wartung['dringendste'] !== []): ?>
                    <ul class="list-unstyled mb-2">
                        <?php foreach ($wartung['dringendste'] as $z):
                            $faelligkeit = $z['faelligkeit'];
                            $link = url(($z['objekt_typ'] === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . (int) $z['objekt']['id']);
                            ?>
                            <li class="mb-1">
                                <?php include __DIR__ . '/wartung/_ampel.php'; ?>
                                <a href="<?= e($link) ?>"><?= e(Wartungsplan::objektKennung($z['objekt_typ'], $z['objekt'])) ?> <?= e(Wartungsplan::objektName($z['objekt_typ'], $z['objekt'])) ?></a>
                                <span class="text-muted">· <?= e((string) $z['art']['name']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">Alles in Ordnung.</p>
                <?php endif; ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/wartung')) ?>">Alle Fälligkeiten</a>
            </div>
        </section>
    </div>
    <?php endif; ?>
    <div class="col-12 col-lg-6">
        <section class="card h-100 start-kachel" aria-labelledby="kachel-schnell">
            <div class="card-body">
                <h2 class="h5 card-title" id="kachel-schnell">Schnellzugriff</h2>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary" href="<?= e(url('/fahrzeuge')) ?>">Fahrzeuge</a>
                    <a class="btn btn-primary" href="<?= e(url('/anlagen')) ?>">Anlagen</a>
                </div>
            </div>
        </section>
    </div>
</div>

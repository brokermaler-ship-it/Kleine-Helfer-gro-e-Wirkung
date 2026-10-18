<?php
/**
 * @var array<string,mixed> $werte
 * @var array<string,string> $fehler
 * @var array<string,mixed> $fahrzeug
 * @var ?array<string,mixed> $decoder
 */
$aktion = $decoder === null ? url('/fahrzeuge/' . $fahrzeug['id'] . '/decoder') : url('/decoder/' . $decoder['id']);
$zurueck = url('/fahrzeuge/' . $fahrzeug['id']) . '#digital';
?>
<div class="row">
    <div class="col-lg-9">
        <h1 class="h3 mb-1"><?= e($titel) ?></h1>
        <p class="text-muted"><?= e($fahrzeug['inventarnummer']) ?> <?= e($fahrzeug['bezeichnung']) ?> · Spur <?= e($fahrzeug['spurweite_name']) ?></p>
        <form method="post" action="<?= e($aktion) ?>" novalidate
              data-adress-pruefung="<?= e(url('/api/adresspruefung')) ?>"
              data-fahrzeug="<?= e((string) $fahrzeug['id']) ?>"
              data-spurweite="<?= e((string) $fahrzeug['spurweite_id']) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php include __DIR__ . '/_decoder_felder.php'; ?>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a class="btn btn-outline-secondary" href="<?= e($zurueck) ?>">Abbrechen</a>
            </div>
        </form>
        <?php if ($decoder !== null): ?>
            <form method="post" action="<?= e(url('/decoder/' . $decoder['id'] . '/loeschen')) ?>" class="mt-4"
                  onsubmit="return confirm('Decoder samt Funktionen und CVs löschen?');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-danger">Decoder löschen</button>
            </form>
        <?php endif; ?>
    </div>
</div>

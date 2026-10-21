<?php
/**
 * Druckseite der Etiketten: eigenes Layout ohne Menü (View::render(…, null)). Jeder Bogen ist ein Block 210 × 297 mm,
 * jedes Feld sitzt absolut in mm nach den Maßen aus Bogen (inline style) – keine Skalierung.
 *
 * @var string $art klebchen, verpackung oder anlage
 * @var \Emc\Etiketten\Bogen $bogen
 * @var list<list<?array<string,mixed>>> $boegen
 * @var list<int> $ids gedruckte Objekte (für „Als gedruckt vermerken“)
 * @var string $typ fahrzeug oder anlage
 * @var list<string> $ohneCode Inventarnummern übersprungener Fahrzeuge
 * @var bool $urlFehlt
 * @var int $start
 * @var array<string,mixed> $zurueck Parameter für den Link zurück zur Auswahl
 */
use Emc\Etiketten\EtikettController;

$mm = fn(float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') . 'mm';
$feldStil = function (int $i) use ($bogen, $mm): string {
    $p = $bogen->position($i);
    return 'left:' . $mm($p['links']) . ';top:' . $mm($p['oben']) . ';width:' . $mm($bogen->breite) . ';height:' . $mm($bogen->hoehe);
};
$anzahl = count($ids);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($titel ?? 'Etiketten drucken') . ' – ' . ($app_name ?? 'EMC 78 Lokschuppen')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
    <?php /* Nur auf dieser Seite: A4 ohne Ränder (sonst gälte es für jeden Ausdruck der App). */ ?>
    <style>@page { size: A4; margin: 0; }</style>
</head>
<body class="druckseite">
<div class="druck-steuerung container py-3">
    <?php include dirname(__DIR__) . '/partials/meldungen.php'; ?>
    <h1 class="h4"><?= e(EtikettController::ARTEN[$art] ?? 'Etiketten') ?>: <?= $anzahl === 1 ? '1 Etikett' : $anzahl . ' Etiketten' ?><?php if ($boegen !== []): ?> auf <?= count($boegen) === 1 ? '1 Bogen' : count($boegen) . ' Bögen' ?><?php endif; ?></h1>
    <div class="alert alert-info mb-2" role="note">Beim Drucken „Tatsächliche Größe“ bzw. 100&nbsp;% und Ränder „Keine“ wählen.</div>
    <?php if ($urlFehlt): ?>
    <div class="alert alert-warning mb-2" role="alert"><?= e(EtikettController::URL_WARNUNG) ?></div>
    <?php endif; ?>
    <?php if ($ohneCode !== []): ?>
    <div class="alert alert-warning mb-2" role="alert">Übersprungen (ohne Code, Inventarnummer nicht im Format EMC-&lt;Zahl&gt;): <?= e(implode(', ', $ohneCode)) ?></div>
    <?php endif; ?>
    <?php if ($boegen === []): ?>
    <p class="mb-2">Keine Etiketten für diese Auswahl.</p>
    <?php else: ?>
    <p class="text-muted small mb-2">Start auf Feld <?= (int) $start ?> des ersten Bogens. Erst nach dem Drucken „Als gedruckt vermerken“ – dann fallen die Etiketten aus der Auswahl „noch nicht gedruckt“.</p>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if ($boegen !== []): ?>
        <button type="button" class="btn btn-primary" id="etiketten-drucken">Drucken</button>
        <form method="post" action="<?= e(url('/etiketten/gedruckt')) ?>" class="m-0">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <input type="hidden" name="typ" value="<?= e($typ) ?>">
            <?php foreach ($ids as $id): ?>
            <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-outline-success">Als gedruckt vermerken</button>
        </form>
        <?php endif; ?>
        <a class="btn btn-link" href="<?= e(url('/etiketten', $zurueck)) ?>">Zurück zur Auswahl</a>
    </div>
</div>
<?php if ($boegen !== []): ?>
<div class="boegen">
    <?php foreach ($boegen as $felder): ?>
    <section class="bogen">
        <?php for ($i = 0; $i < $bogen->proBogen(); $i++): ?>
            <?php $et = $felder[$i] ?? null; ?>
            <?php if ($et === null): ?>
                <div class="feld-leer" style="<?= e($feldStil($i)) ?>"></div>
            <?php elseif ($art === 'anlage'): ?>
                <div class="etikett etikett-anlage" style="<?= e($feldStil($i)) ?>">
                    <div class="qr" role="img" aria-label="<?= e($et['link']) ?>" title="<?= e($et['link']) ?>"><?= $et['svg'] /* SVG aus QrCode::svg, ohne Eingabetext im Markup */ ?></div>
                    <div class="text"><span class="name"><?= e($et['name']) ?></span><span class="code"><?= e($et['code']) ?></span></div>
                </div>
            <?php elseif ($art === 'verpackung'): ?>
                <div class="etikett etikett-verpackung" style="<?= e($feldStil($i)) ?>">
                    <span class="code"><?= e($et['code']) ?></span><span class="bezeichnung"><?= e($et['bezeichnung']) ?></span>
                </div>
            <?php else: ?>
                <div class="etikett etikett-klebchen" style="<?= e($feldStil($i)) ?>">
                    <span class="code"><?= e($et['code']) ?></span>
                </div>
            <?php endif; ?>
        <?php endfor; ?>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
document.getElementById('etiketten-drucken')?.addEventListener('click', function () { window.print(); });
</script>
</body>
</html>

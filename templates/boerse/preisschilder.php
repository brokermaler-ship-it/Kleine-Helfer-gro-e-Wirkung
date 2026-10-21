<?php
/**
 * Druckseite der Preisschilder: eigenes Layout ohne Menü (View::render(…, null)), Maße aus Bogen::preisschild()
 * (105 × 74 mm, 8 je A4), jedes Feld absolut in mm – keine Skalierung (wie die Etiketten).
 *
 * @var array<string,mixed> $boerse
 * @var \Emc\Etiketten\Bogen $bogen
 * @var list<list<?array<string,mixed>>> $boegen
 * @var int $anzahl
 * @var int $start
 * @var bool $urlFehlt
 * @var string $qrBasis
 * @var array<string,mixed> $zurueck Parameter für „Neu laden“ mit gleicher Auswahl
 */
use Emc\Etiketten\EtikettController;

$mm = fn(float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') . 'mm';
$feldStil = function (int $i) use ($bogen, $mm): string {
    $p = $bogen->position($i);
    return 'left:' . $mm($p['links']) . ';top:' . $mm($p['oben']) . ';width:' . $mm($bogen->breite) . ';height:' . $mm($bogen->hoehe);
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($titel ?? 'Preisschilder drucken') . ' – ' . ($app_name ?? 'EMC 78 Lokschuppen')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
    <?php /* Nur auf dieser Seite: A4 ohne Ränder. */ ?>
    <style>@page { size: A4; margin: 0; }</style>
</head>
<body class="druckseite">
<div class="druck-steuerung container py-3">
    <?php include dirname(__DIR__) . '/partials/meldungen.php'; ?>
    <h1 class="h4">Preisschilder <?= e((string) $boerse['name']) ?>: <?= $anzahl === 1 ? '1 Schild' : $anzahl . ' Schilder' ?><?php if ($boegen !== []): ?> auf <?= count($boegen) === 1 ? '1 Blatt' : count($boegen) . ' Blättern' ?><?php endif; ?></h1>
    <div class="alert alert-info mb-2" role="note">Beim Drucken „Tatsächliche Größe“ bzw. 100&nbsp;% und Ränder „Keine“ wählen.</div>
    <?php if ($urlFehlt): ?>
    <div class="alert alert-warning mb-2" role="alert"><?= e(EtikettController::URL_WARNUNG) ?> Die QR-Codes zeigen jetzt auf <strong><?= e($qrBasis) ?>/c/…</strong>.</div>
    <?php endif; ?>
    <?php if ($boegen === []): ?>
    <p class="mb-2">Keine offenen Angebote für diese Auswahl.</p>
    <?php else: ?>
    <p class="text-muted small mb-2">Start auf Feld <?= (int) $start ?> des ersten Blatts. Der QR-Code führt angemeldete Mitglieder zum Angebot.</p>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if ($boegen !== []): ?><button type="button" class="btn btn-primary" id="preisschilder-drucken">Drucken</button><?php endif; ?>
        <a class="btn btn-link" href="<?= e(url('/boersen/' . (int) $boerse['id'])) ?>">Zurück zur Börse</a>
    </div>
</div>
<?php if ($boegen !== []): ?>
<div class="boegen">
    <?php foreach ($boegen as $felder): ?>
    <section class="bogen">
        <?php for ($i = 0; $i < $bogen->proBogen(); $i++): ?>
            <?php $ps = $felder[$i] ?? null; ?>
            <?php if ($ps === null): ?>
                <div class="feld-leer" style="<?= e($feldStil($i)) ?>"></div>
            <?php else: ?>
                <div class="etikett etikett-preisschild" style="<?= e($feldStil($i)) ?>">
                    <span class="nummer"><?= e($ps['nummer']) ?></span>
                    <span class="bezeichnung"><?= e($ps['bezeichnung']) ?></span>
                    <?php if ($ps['menge'] > 1): ?><span class="menge"><?= (int) $ps['menge'] ?> Stück</span><?php endif; ?>
                    <div class="unten">
                        <div class="links">
                            <span class="preis"><?= e($ps['preis']) ?></span>
                            <span class="kurzlink"><?= e($ps['linkText']) ?></span>
                        </div>
                        <div class="qr" role="img" aria-label="<?= e($ps['link']) ?>" title="<?= e($ps['link']) ?>"><?= $ps['svg'] /* SVG aus QrCode::svg */ ?></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endfor; ?>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
document.getElementById('preisschilder-drucken')?.addEventListener('click', function () { window.print(); });
</script>
</body>
</html>

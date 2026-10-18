<?php
/**
 * @var array<string,mixed> $fahrzeug
 * @var list<array<string,mixed>> $decoderListe
 */
$decoderListe = $decoderListe ?? [];
?>
<?php if ($decoderListe === []): ?>
    <p class="text-muted">Für dieses Fahrzeug ist noch kein Decoder erfasst.</p>
<?php endif; ?>
<div class="row g-3 mb-4">
    <?php foreach ($decoderListe as $d): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 card-title">
                        <?= e($d['bezeichnung']) ?>
                        <?php if ($d['sound']): ?><span class="badge text-bg-info">Sound</span><?php endif; ?>
                        <?php if ($d['konflikte'] !== []): ?>
                            <span class="badge text-bg-warning" title="Adresse bereits vergeben an: <?= e(implode(', ', array_column($d['konflikte'], 'inventarnummer'))) ?>">⚠️ Adresse doppelt</span>
                        <?php endif; ?>
                    </h2>
                    <dl class="row mb-0 small">
                        <?php if ($d['hersteller'] !== null || $d['typ'] !== null): ?>
                            <dt class="col-4">Hersteller/Typ</dt>
                            <dd class="col-8"><?= e(trim((string) $d['hersteller'] . ' ' . (string) $d['typ'])) ?></dd>
                        <?php endif; ?>
                        <?php if ($d['firmware'] !== null): ?>
                            <dt class="col-4">Firmware</dt><dd class="col-8"><?= e($d['firmware']) ?></dd>
                        <?php endif; ?>
                        <dt class="col-4">Protokoll</dt><dd class="col-8"><?= e($d['protokoll_label']) ?></dd>
                        <dt class="col-4">Adresse</dt>
                        <dd class="col-8">
                            <?= $d['adresse'] === null ? '–' : e((string) $d['adresse']) ?>
                            <?php if ($d['adressart'] !== null): ?><span class="text-muted">(<?= e($d['adressart']) ?>)</span><?php endif; ?>
                        </dd>
                    </dl>
                    <?php foreach ($d['konflikte'] as $k): ?>
                        <div class="small text-warning-emphasis mt-1">⚠️ Auch vergeben an
                            <a href="<?= e(url('/fahrzeuge/' . $k['fahrzeug_id'])) ?>"><?= e($k['inventarnummer']) ?></a> <?= e($k['bezeichnung']) ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer d-flex gap-3 small">
                    <?php if ($darf('fahrzeug_bearbeiten')): ?>
                        <a href="<?= e(url('/decoder/' . $d['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
                    <?php endif; ?>
                    <a href="<?= e(url('/decoder/' . $d['id'] . '/funktionen')) ?>">Funktionen</a>
                    <a href="<?= e(url('/decoder/' . $d['id'] . '/cvs')) ?>">CVs</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($darf('fahrzeug_bearbeiten')): ?>
    <?php
    $werte = ['protokoll' => 'dcc'];
    $fehler = [];
    ?>
    <section aria-labelledby="decoder-neu-titel">
        <h2 class="h5" id="decoder-neu-titel">Decoder hinzufügen</h2>
        <form method="post" action="<?= e(url('/fahrzeuge/' . $fahrzeug['id'] . '/decoder')) ?>" novalidate
              data-adress-pruefung="<?= e(url('/api/adresspruefung')) ?>"
              data-fahrzeug="<?= e((string) $fahrzeug['id']) ?>"
              data-spurweite="<?= e((string) $fahrzeug['spurweite_id']) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php include dirname(__DIR__) . '/digital/_decoder_felder.php'; ?>
            <button type="submit" class="btn btn-primary mt-3">Decoder hinzufügen</button>
        </form>
    </section>
<?php endif; ?>

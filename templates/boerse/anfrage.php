<?php
/**
 * Eine Börsenanfrage (nur Börsenwart). Name, Kontakt, Nachricht und Fotos stammen von Besuchern ohne Anmeldung:
 * Ausgabe nur mit e(), die Nachricht wird nie verlinkt. Antwort über mailto: (Antwortlink, kodiert) bzw. tel:.
 *
 * @var array<string,mixed> $anfrage aus AnfrageRepository::finde
 * @var ?string $mailto
 * @var ?string $tel
 * @var \DateTimeImmutable $geloeschtAm
 * @var ?string $meldung
 */
include __DIR__ . '/_anfrage_labels.php';
$a = $anfrage;
$id = (int) $a['id'];
$eingang = date('d.m.Y H:i', (int) strtotime((string) $a['eingegangen_am']));
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h3 mb-1 text-break">
            <?php if ($a['art'] === 'kaufanfrage' && $a['angebot_id'] !== null): ?>
                Kaufanfrage zu <a href="<?= e(url('/angebote/' . (int) $a['angebot_id'])) ?>"><?= e((string) $a['angebot_nummer']) ?></a>
            <?php else: ?>
                <?= e($anfrageArt($a)) ?>
            <?php endif; ?>
        </h1>
        <?php if ($a['art'] === 'kaufanfrage' && $a['angebot_bezeichnung'] !== null): ?>
            <div class="text-muted text-break"><?= e((string) $a['angebot_bezeichnung']) ?></div>
        <?php endif; ?>
        <div class="mt-1"><?= $anfrageStatusBadge((string) $a['status']) ?></div>
    </div>
</div>
<?php if ($meldung !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($meldung) ?></div>
<?php endif; ?>

<dl class="row">
    <dt class="col-sm-4 col-lg-3">Name</dt>
    <dd class="col-sm-8 col-lg-9 text-break"><?= e((string) $a['name']) ?></dd>
    <?php if ($a['email'] !== null): ?>
        <dt class="col-sm-4 col-lg-3">E-Mail</dt>
        <dd class="col-sm-8 col-lg-9 text-break"><?= e((string) $a['email']) ?></dd>
    <?php endif; ?>
    <?php if ($a['telefon'] !== null): ?>
        <dt class="col-sm-4 col-lg-3">Telefon</dt>
        <dd class="col-sm-8 col-lg-9 text-break"><?php if ($tel !== null): ?><a href="<?= e($tel) ?>"><?= e((string) $a['telefon']) ?></a><?php else: ?><?= e((string) $a['telefon']) ?><?php endif; ?></dd>
    <?php endif; ?>
    <dt class="col-sm-4 col-lg-3"><?= $a['art'] === 'sammlung' ? 'Beschreibung' : 'Nachricht' ?></dt>
    <dd class="col-sm-8 col-lg-9 text-break"><?= nl2br(e((string) $a['nachricht'])) ?></dd>
    <dt class="col-sm-4 col-lg-3">Eingegangen am</dt>
    <dd class="col-sm-8 col-lg-9"><?= e($eingang) ?> <span class="small text-muted">· wird automatisch gelöscht am <?= e($geloeschtAm->format('d.m.Y')) ?></span></dd>
</dl>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($mailto !== null): ?>
        <a class="btn btn-primary" href="<?= e($mailto) ?>">Per E-Mail antworten</a>
    <?php elseif ($tel !== null): ?>
        <a class="btn btn-primary" href="<?= e($tel) ?>">Anrufen</a>
    <?php endif; ?>
    <?php if ($a['art'] === 'sammlung'): ?>
        <?php if ($a['magazin_artikel_id'] !== null): ?>
            <a class="btn btn-outline-success" href="<?= e(url('/magazin/' . (int) $a['magazin_artikel_id'])) ?>">übernommen als M<?= (int) $a['magazin_artikel_id'] ?></a>
        <?php else: ?>
            <a class="btn btn-outline-primary" href="<?= e(url('/anfragen/' . $id . '/uebernehmen')) ?>">Ins Magazin übernehmen</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($mailto === null && $a['email'] !== null): ?>
    <p class="small text-muted">Die E-Mail-Adresse ist ungültig; deshalb gibt es keinen Antwort-Link.</p>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-4" aria-label="Status">
    <?php foreach (['in_bearbeitung' => 'in Bearbeitung', 'erledigt' => 'erledigt'] as $wert => $text): ?>
        <?php if ($a['status'] !== $wert): ?>
            <form method="post" action="<?= e(url('/anfragen/' . $id . '/status')) ?>" class="m-0">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <input type="hidden" name="status" value="<?= e($wert) ?>">
                <button type="submit" class="btn btn-outline-secondary">Status: <?= e($text) ?></button>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<section id="fotos" class="mt-4" aria-labelledby="fotos-titel">
    <h2 class="h4" id="fotos-titel">Fotos</h2>
    <?php if ($a['fotos'] === []): ?>
        <p class="text-muted">Keine Fotos.</p>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($a['fotos'] as $i => $f): ?>
                <div class="text-center">
                    <a href="<?= e(url('/dateien/' . (int) $f['id'] . ((int) $f['hat_anzeige'] === 1 ? '/anzeige' : ''))) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(url('/dateien/' . (int) $f['id'] . '/vorschau')) ?>" alt="Foto <?= (int) $i + 1 ?>" loading="lazy" class="rounded border magazin-foto">
                    </a>
                    <?php if ((int) $f['zuordnungen_anzahl'] === 1): ?>
                        <form method="post" action="<?= e(url('/dateien/' . (int) $f['id'] . '/loeschen')) ?>" onsubmit="return confirm('Dieses Foto löschen?');">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0">Foto löschen</button>
                        </form>
                    <?php else: ?>
                        <div class="small text-muted">auch am Artikel</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<form method="post" action="<?= e(url('/anfragen/' . $id . '/loeschen')) ?>" class="mt-4" onsubmit="return confirm('Anfrage samt Fotos endgültig löschen?');">
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
    <button type="submit" class="btn btn-outline-danger">Anfrage löschen</button>
</form>
<p class="mt-3"><a href="<?= e(url('/anfragen')) ?>">&larr; Zurück zu den Anfragen</a></p>

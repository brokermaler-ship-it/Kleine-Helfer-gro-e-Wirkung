<?php
/**
 * Ergebnis des Felds „Code“: Auswahl mehrerer Fahrzeuge mit derselben Digitaladresse oder Suchergebnis.
 *
 * @var string $q Eingabe
 * @var \Emc\Codes\CodeErgebnis $ergebnis
 * @var ?int $adresse bei Auswahl die gesuchte Digitaladresse
 * @var string $zusatz an jeden Link gehängt (z. B. „/defekt“)
 * @var bool $fuerDefekt Suche von „Defekt melden“: eine neue Suche behält das bei
 */
use Emc\Codes\Code;
use Emc\Codes\CodeAufloesung;
use Emc\Codes\CodeErgebnis;

include dirname(__DIR__) . '/fahrzeuge/_labels.php';
include dirname(__DIR__) . '/anlagen/_labels.php';
$zusatz = $zusatz ?? '';
$auswahl = $ergebnis->art === CodeErgebnis::AUSWAHL;
?>
<h1 class="h3 mb-3"><?= $auswahl ? 'Mehrere Fahrzeuge mit Adresse ' . (int) $adresse : 'Suche' ?></h1>
<?php if (!empty($fuerDefekt)): ?>
    <p class="lead">Defekt melden: Bitte wähle das Fahrzeug oder die Anlage.</p>
<?php endif; ?>

<form method="get" action="<?= e(url('/code')) ?>" role="search" class="card card-body mb-3">
    <?php if (!empty($fuerDefekt)): ?><input type="hidden" name="fuer" value="defekt"><?php endif; ?>
    <label for="code-seite-q" class="form-label small mb-1">Code, Digitaladresse oder Name</label>
    <div class="d-flex gap-2">
        <input type="search" class="form-control" id="code-seite-q" name="q" value="<?= e($q) ?>"
               autocapitalize="characters" autocomplete="off" placeholder="Code, z. B. F12">
        <button type="submit" class="btn btn-outline-primary">Öffnen</button>
    </div>
</form>

<?php if ($ergebnis->meldung !== null): ?>
    <div class="alert alert-info" role="status"><?= e($ergebnis->meldung) ?></div>
<?php endif; ?>

<?php if ($ergebnis->fahrzeuge !== []): ?>
    <?php if (!$auswahl): ?><h2 class="h5">Fahrzeuge</h2><?php endif; ?>
    <div class="list-group mb-4">
        <?php foreach ($ergebnis->fahrzeuge as $fz): ?>
            <?php $code = Code::fahrzeug((string) $fz['inventarnummer']); ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-center" href="<?= e(url('/fahrzeuge/' . (int) $fz['id'] . $zusatz)) ?>">
                <?= $titelbild($fz) ?>
                <div class="flex-grow-1">
                    <div class="fw-semibold">
                        <span class="fw-bold me-1"><?= e($code ?? (string) $fz['inventarnummer']) ?></span>
                        <?= e((string) $fz['bezeichnung']) ?>
                    </div>
                    <div class="small text-muted">
                        <?= $code !== null ? e((string) $fz['inventarnummer']) . ' · ' : '' ?><?= e((string) $fz['spurweite_name']) ?>
                        <?php if ($auswahl && ($fz['adress_protokolle'] ?? null) !== null): ?>
                            · <?= e(implode(', ', array_map(
                                fn(string $p): string => (\Emc\Digital\Protokoll::tryFrom($p)?->label() ?? $p) . ' ' . (int) $adresse,
                                explode(',', (string) $fz['adress_protokolle'])
                            ))) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div><?= $statusBadge($fz) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($ergebnis->weitereFahrzeuge): ?>
        <p class="small mb-4">Es werden nur die ersten <?= CodeAufloesung::MAX_TREFFER ?> Fahrzeuge gezeigt.
            <a href="<?= e(url('/fahrzeuge', ['q' => $q])) ?>">Alle in der Fahrzeugliste</a></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($ergebnis->anlagen !== []): ?>
    <h2 class="h5">Anlagen</h2>
    <div class="list-group mb-4">
        <?php foreach ($ergebnis->anlagen as $a): ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-center" href="<?= e(url('/anlagen/' . (int) $a['id'] . $zusatz)) ?>">
                <?= $anlageTitelbild($a) ?>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><span class="fw-bold me-1"><?= e((string) $a['code']) ?></span> <?= e((string) $a['name']) ?></div>
                    <div class="small text-muted"><?= e((string) $a['spurweite_name']) ?></div>
                </div>
                <div><?= $anlageStatusBadge($a) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($ergebnis->weitereAnlagen): ?>
        <p class="small mb-4">Es werden nur die ersten <?= CodeAufloesung::MAX_TREFFER ?> Anlagen gezeigt.
            <a href="<?= e(url('/anlagen', ['q' => $q])) ?>">Alle in der Anlagenliste</a></p>
    <?php endif; ?>
<?php endif; ?>

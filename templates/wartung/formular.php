<?php
/**
 * Eigene Seite für „Wartung eintragen“ bzw. „Wartungsplan anpassen“ nach Eingabefehlern.
 *
 * @var string $teil eintragen oder plan
 * @var string $objektName
 * @var string $objektUrl
 * @var array<string,mixed> $block
 */
?>
<h1 class="h3 mb-3"><?= e($teil === 'plan' ? 'Wartungsplan anpassen' : 'Wartung eintragen') ?>: <?= e($objektName) ?></h1>
<div class="alert alert-danger" role="alert">Bitte die markierten Angaben prüfen.</div>
<?php
if ($block !== []) {
    include __DIR__ . '/_daten.php';
    include __DIR__ . ($teil === 'plan' ? '/_plan.php' : '/_eintragen.php');
}
?>
<p class="mt-3"><a href="<?= e($objektUrl) ?>">&larr; Zurück</a></p>

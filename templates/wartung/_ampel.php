<?php
/**
 * Ampel als Badge mit Farbe und Text (nicht nur Farbe, für Barrierefreiheit).
 *
 * @var \Emc\Wartung\Faelligkeit $faelligkeit
 */
$ampelKlasse = 'text-bg-' . $faelligkeit->ampel->farbe();
?>
<span class="badge <?= e($ampelKlasse) ?> wartung-ampel wartung-ampel-<?= e($faelligkeit->ampel->value) ?>"><?= e($faelligkeit->text()) ?></span>

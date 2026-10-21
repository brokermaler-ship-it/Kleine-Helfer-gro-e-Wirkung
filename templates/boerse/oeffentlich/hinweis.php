<?php
/**
 * Schlichte Hinweisseite des öffentlichen Bereichs (Wartung vor dem Datenbank-Update, zu große Anfrage).
 *
 * @var string $ueberschrift
 * @var string $text
 * @var bool $zurueck Link zurück zu „Sammlung anbieten“
 */
?>
<h1 class="h3"><?= e($ueberschrift) ?></h1>
<p><?= e($text) ?></p>
<p><a class="btn btn-outline-primary" href="<?= e(url(!empty($zurueck) ? '/boerse/sammlung-anbieten' : '/boerse')) ?>"><?= !empty($zurueck) ? 'Zurück zum Formular' : 'Zu den Angeboten' ?></a></p>

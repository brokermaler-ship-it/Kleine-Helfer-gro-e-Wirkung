<?php
/** @var int $abgelehnt Dateien, die nicht als Foto übernommen werden konnten (nur direkt nach dem Senden) */
?>
<h1 class="h3">Danke!</h1>
<p class="lead">Danke! Deine Anfrage ist bei uns angekommen. Wir melden uns bei dir.</p>
<?php if ($abgelehnt > 0): ?>
<p class="alert alert-warning"><?= $abgelehnt === 1 ? '1 Datei' : (int) $abgelehnt . ' Dateien' ?> konnten wir nicht als Foto übernehmen (nur JPEG, PNG oder WebP). Deine Anfrage ist trotzdem angekommen.</p>
<?php endif; ?>
<p><a class="btn btn-outline-primary" href="<?= e(url('/boerse')) ?>">Zu den Angeboten</a></p>

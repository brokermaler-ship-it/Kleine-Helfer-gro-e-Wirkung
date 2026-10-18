<h1 class="h3">Kein Zugriff</h1>
<p>Dafür fehlt dir die Berechtigung.</p>
<?php if (!empty($detail)): ?><pre class="small text-muted"><?= e($detail) ?></pre><?php endif; ?>
<a class="btn btn-primary" href="<?= e(url('/')) ?>">Zur Startseite</a>

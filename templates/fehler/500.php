<h1 class="h3">Technischer Fehler</h1>
<p>Es ist ein technischer Fehler aufgetreten.</p>
<?php if (!empty($detail)): ?><pre class="small text-muted"><?= e($detail) ?></pre><?php endif; ?>
<a class="btn btn-primary" href="<?= e(url('/')) ?>">Zur Startseite</a>

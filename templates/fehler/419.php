<h1 class="h3">Sitzung abgelaufen</h1>
<p>Deine Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.</p>
<?php if (!empty($detail)): ?><pre class="small text-muted"><?= e($detail) ?></pre><?php endif; ?>
<a class="btn btn-primary" href="<?= e(url('/')) ?>">Zur Startseite</a>

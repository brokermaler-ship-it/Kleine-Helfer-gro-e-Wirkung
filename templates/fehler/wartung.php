<h1 class="h3">Wartung</h1>
<p>Die Anwendung wird gerade aktualisiert. Bitte versuche es in ein paar Minuten noch einmal.</p>
<form method="post" action="<?= e(url('/logout')) ?>">
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
    <button type="submit" class="btn btn-outline-secondary">Abmelden</button>
</form>

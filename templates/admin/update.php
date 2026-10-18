<?php /** @var list<string> $ausstehend */ ?>
<h1 class="h3 mb-3">Update</h1>
<?php if ($ausstehend === []): ?>
    <div class="alert alert-success">Die Datenbank ist auf dem neuesten Stand. Es ist nichts zu tun.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <p>Es stehen <?= count($ausstehend) ?> Datenbank-Änderungen an. Bis sie ausgeführt sind, können nur Administratoren die Anwendung nutzen.</p>
            <ul>
                <?php foreach ($ausstehend as $version): ?><li><code><?= e($version) ?></code></li><?php endforeach; ?>
            </ul>
            <p class="text-muted">Empfehlung: Mache vorher eine <a href="<?= e(url('/admin/sicherung')) ?>">Datensicherung</a>.</p>
            <form method="post" action="<?= e(url('/admin/update')) ?>">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-primary">Datenbank jetzt aktualisieren</button>
            </form>
        </div>
    </div>
<?php endif; ?>

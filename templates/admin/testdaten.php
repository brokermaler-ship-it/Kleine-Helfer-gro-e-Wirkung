<h1 class="h3 mb-3">Testdaten</h1>
<div class="card">
    <div class="card-body">
        <p>Dies ist die Testumgebung. Mit der Schaltfläche werden <strong>alle Daten gelöscht</strong> (Personen, Benutzer, Fahrzeuge, Decoder, Fotos, Protokoll) und durch frische Beispieldaten ersetzt:
            6 Personen, 12 Fahrzeuge (H0 und N) mit Decodern, Funktionstasten, CVs und Fotos. Die Spurweiten bleiben erhalten.</p>
        <p>Danach wirst du abgemeldet und kannst dich mit einem der Test-Zugänge neu anmelden: Mitglied und Bearbeiter mit dem Passwort <code>Test-EMC78!</code>, <code>admin@test.emc78.de</code> mit dem Passwort aus <code>testumgebung_admin_passwort</code> in der <code>config.php</code>.</p>
        <form method="post" action="<?= e(url('/admin/testdaten')) ?>" onsubmit="return confirm('Wirklich alle Daten durch Beispieldaten ersetzen?');">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <button type="submit" class="btn btn-warning">Beispieldaten neu laden</button>
        </form>
    </div>
</div>

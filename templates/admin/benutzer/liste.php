<?php /** @var list<array<string,mixed>> $benutzerListe */ /** @var ?int $eigeneId */
$rollen = ['mitglied' => 'Mitglied', 'bearbeiter' => 'Bearbeiter', 'admin' => 'Administrator']; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Benutzer</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/benutzer/neu')) ?>">Zugang anlegen</a>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead><tr><th>Person</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($benutzerListe as $b): ?>
            <tr>
                <td><?= e($b['nachname'] . ', ' . $b['vorname']) ?></td>
                <td><?= e($b['email']) ?></td>
                <td><?= e($rollen[$b['rolle']] ?? $b['rolle']) ?></td>
                <td>
                    <?= (int) $b['aktiv'] === 1 ? 'aktiv' : 'deaktiviert' ?>
                    <?php if ((int) $b['muss_passwort_aendern'] === 1): ?><span class="badge text-bg-warning">Passwort offen</span><?php endif; ?>
                </td>
                <td><?= $b['letzter_login'] ? e(date('d.m.Y H:i', (int) strtotime($b['letzter_login']))) : '–' ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/benutzer/' . $b['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
                    <form method="post" action="<?= e(url('/admin/benutzer/' . $b['id'] . '/loeschen')) ?>" class="d-inline"
                          onsubmit="return confirm('Zugang wirklich löschen?');">
                        <?php include dirname(__DIR__, 2) . '/partials/csrf.php'; ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

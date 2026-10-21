<?php /** @var list<array<string,mixed>> $benutzerListe */ /** @var ?int $eigeneId */
/** @var list<array{id:int, benutzer_id:int, email:string, name:string, erstellt_am:string}> $kennwortAnfragen */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Benutzer</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/benutzer/neu')) ?>">Zugang anlegen</a>
</div>
<?php if (($kennwortAnfragen ?? []) !== []): ?>
    <section class="card mb-4 border-info" aria-labelledby="kennwort-anfragen">
        <div class="card-body">
            <h2 class="h5" id="kennwort-anfragen">Anfragen auf neues Kennwort</h2>
            <p class="small text-muted mb-2">Setze unter „Bearbeiten“ ein Startpasswort und gib es der Person persönlich weiter; die Anfrage ist damit erledigt.</p>
            <ul class="list-group list-group-flush">
                <?php foreach ($kennwortAnfragen as $a): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2 px-0">
                        <span><strong><?= e($a['name']) ?></strong> · <?= e($a['email']) ?>
                            <span class="text-muted small">· angefragt am <?= e(date('d.m.Y H:i', (int) strtotime($a['erstellt_am']))) ?></span></span>
                        <span class="text-nowrap">
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/admin/benutzer/' . $a['benutzer_id'] . '/bearbeiten')) ?>">Startpasswort setzen</a>
                            <form method="post" action="<?= e(url('/admin/kennwort-anfragen/' . $a['id'] . '/verwerfen')) ?>" class="d-inline">
                                <?php include dirname(__DIR__, 2) . '/partials/csrf.php'; ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Verwerfen</button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
<?php endif; ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead><tr><th>Person</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($benutzerListe as $b): ?>
            <tr>
                <td><?= e($b['nachname'] . ', ' . $b['vorname']) ?></td>
                <td><?= e($b['email']) ?></td>
                <td><?= e(\Emc\Auth\Rolle::tryFrom((string) $b['rolle'])?->label() ?? (string) $b['rolle']) ?><?php if ((int) ($b['ist_boersenwart'] ?? 0) === 1): ?> <span class="badge text-bg-info">Börsenwart</span><?php endif; ?></td>
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

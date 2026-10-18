<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Betrieb\Migrator;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

final class InstallController
{
    public const MIN_PASSWORT = 10;

    public function __construct(
        private View $view,
        private \PDO $pdo,
        private Migrator $migrator,
        private Session $session,
    ) {
    }

    public function formular(Request $r): Response
    {
        $this->nurOhneBenutzer();
        return $this->seite([], []);
    }

    public function installiere(Request $r): Response
    {
        $this->nurOhneBenutzer();

        $werte = [
            'vorname' => trim((string) $r->input('vorname', '')),
            'nachname' => trim((string) $r->input('nachname', '')),
            'email' => mb_strtolower(trim((string) $r->input('email', ''))),
        ];
        $passwort = (string) $r->input('passwort', '');

        $fehler = [];
        if ($werte['vorname'] === '') {
            $fehler['vorname'] = 'Bitte gib einen Vornamen an.';
        }
        if ($werte['nachname'] === '') {
            $fehler['nachname'] = 'Bitte gib einen Nachnamen an.';
        }
        if (filter_var($werte['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($werte['email']) > 190) {
            $fehler['email'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
        }
        if (mb_strlen($passwort) < self::MIN_PASSWORT) {
            $fehler['passwort'] = 'Das Passwort muss mindestens ' . self::MIN_PASSWORT . ' Zeichen lang sein.';
        }
        if ($fehler !== []) {
            return $this->seite($werte, $fehler, 422);
        }

        // Gleichzeitige Installationen verhindern: nur ein Administrator wird angelegt.
        $this->pdo->query("SELECT GET_LOCK('emc_install', 10)")->fetchColumn();
        try {
            $this->migrator->ausfuehren();
            if ($this->benutzerVorhanden()) {
                throw new HttpFehler(404);
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('INSERT INTO person (vorname, nachname, email) VALUES (?, ?, ?)')
                    ->execute([$werte['vorname'], $werte['nachname'], $werte['email']]);
                $personId = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare(
                    "INSERT INTO benutzer (person_id, email, passwort_hash, rolle, aktiv, muss_passwort_aendern) VALUES (?, ?, ?, 'admin', 1, 0)"
                )->execute([$personId, $werte['email'], password_hash($passwort, PASSWORD_DEFAULT)]);
                $this->pdo->commit();
            } catch (\Throwable $t) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $t;
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('emc_install')")->fetchColumn();
        }

        $this->session->meldung('erfolg', 'Die Installation ist abgeschlossen. Du kannst dich jetzt anmelden.');
        return Response::redirect(url('/login'));
    }

    private function nurOhneBenutzer(): void
    {
        if ($this->migrator->istInstalliert() && $this->benutzerVorhanden()) {
            throw new HttpFehler(404);
        }
    }

    private function benutzerVorhanden(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM benutzer')->fetchColumn() > 0;
    }

    /**
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     */
    private function seite(array $werte, array $fehler, int $status = 200): Response
    {
        $html = $this->view->render('auth/install', [
            'titel' => 'Installation',
            'werte' => $werte,
            'fehler' => $fehler,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

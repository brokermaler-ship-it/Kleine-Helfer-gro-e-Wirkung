<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Auth\Auth;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Protokoll\Aenderungsprotokoll;

final class KontoController
{
    public function __construct(
        private View $view,
        private BenutzerRepository $benutzer,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function passwortForm(Request $r): Response
    {
        return $this->seite([]);
    }

    public function passwortAendern(Request $r): Response
    {
        $nutzer = $this->auth->benutzer();
        if ($nutzer === null) {
            throw new HttpFehler(404);
        }
        $alt = (string) $r->post('altes_passwort', '');
        $neu = (string) $r->post('neues_passwort', '');
        $wdh = (string) $r->post('wiederholung', '');

        $fehler = [];
        if (!$this->benutzer->passwortStimmt($nutzer->id, $alt)) {
            $fehler['altes_passwort'] = 'Das bisherige Passwort ist falsch.';
        }
        $regel = BenutzerRepository::pruefePasswort($neu);
        if ($regel !== null) {
            $fehler['neues_passwort'] = $regel;
        } elseif ($neu === $alt) {
            $fehler['neues_passwort'] = 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
        }
        if ($neu !== $wdh) {
            $fehler['wiederholung'] = 'Die beiden neuen Passwörter stimmen nicht überein.';
        }
        if ($fehler !== []) {
            return $this->seite($fehler, 422);
        }

        $this->benutzer->passwortSetzen($nutzer->id, $neu, false);
        // Andere Sitzungen des Kontos enden, diese bleibt angemeldet.
        $this->auth->uebernimmStand($nutzer->id);
        $this->protokoll->schreibe($nutzer->id, 'benutzer', $nutzer->id, 'geaendert', [
            'passwort' => ['alt' => null, 'neu' => 'geändert'],
        ]);
        $this->session->erneuere();
        $this->session->meldung('erfolg', 'Dein Passwort wurde geändert.');
        return Response::redirect(url('/'));
    }

    /** @param array<string,string> $fehler */
    private function seite(array $fehler, int $status = 200): Response
    {
        $html = $this->view->render('konto/passwort', [
            'titel' => 'Passwort ändern',
            'fehler' => $fehler,
            'erzwungen' => $this->auth->benutzer()?->mussPasswortAendern ?? false,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

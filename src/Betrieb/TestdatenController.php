<?php

declare(strict_types=1);

namespace Emc\Betrieb;

use Emc\Auth\Auth;
use Emc\Core\DomainFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

/** Beispieldaten per Browser neu laden (für Webspace ohne SSH); nur in der Testumgebung registriert. */
final class TestdatenController
{
    public function __construct(
        private View $view,
        private DemoDaten $demo,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function zeigen(Request $r): Response
    {
        return new Response($this->view->render('admin/testdaten', ['titel' => 'Testdaten']), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function neuLaden(Request $r): Response
    {
        try {
            $this->demo->erzeuge(true);
        } catch (DomainFehler $f) {
            $this->session->meldung('fehler', $f->getMessage());
            return Response::redirect(url('/admin/testdaten'));
        }
        // Die Benutzer wurden neu angelegt, die bisherige Anmeldung gilt nicht mehr.
        $this->auth->abmelden();
        $this->session->meldung('erfolg', 'Beispieldaten wurden neu geladen. Bitte mit einem Test-Zugang anmelden.');
        return Response::redirect(url('/login'));
    }
}

<?php

declare(strict_types=1);

namespace Emc\Betrieb;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

final class UpdateController
{
    public function __construct(private View $view, private Migrator $migrator, private Session $session)
    {
    }

    public function zeigen(Request $r): Response
    {
        return new Response($this->view->render('admin/update', [
            'titel' => 'Update',
            'ausstehend' => $this->migrator->ausstehend(),
        ]), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function ausfuehren(Request $r): Response
    {
        try {
            $schritte = count($this->migrator->ausfuehren());
        } catch (\Throwable $t) {
            error_log(sprintf('Update fehlgeschlagen: %s', $t->getMessage()));
            $this->session->meldung('fehler', 'Das Datenbank-Update ist fehlgeschlagen. Bitte mache zuerst eine Sicherung und wende dich an den Entwickler.');
            return Response::redirect(url('/admin/update'));
        }
        $this->session->meldung(
            'erfolg',
            $schritte === 0 ? 'Die Datenbank ist bereits aktuell.' : 'Datenbank wurde aktualisiert (' . $schritte . ' Schritte).'
        );
        return Response::redirect(url('/admin/update'));
    }
}

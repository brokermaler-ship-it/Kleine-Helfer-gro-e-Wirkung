<?php

declare(strict_types=1);

namespace Emc\Betrieb;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

final class SicherungController
{
    public function __construct(private View $view, private Sicherung $sicherung, private Session $session)
    {
    }

    public function zeigen(Request $r): Response
    {
        return new Response($this->view->render('admin/sicherung', [
            'titel' => 'Datensicherung',
            'letzte' => $this->sicherung->letzteSicherung(),
        ]), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function herunterladen(Request $r): Response
    {
        // Große Dateibestände brauchen länger als die übliche Laufzeitgrenze; ein Abbruch durch den Browser soll nichts halbfertig lassen.
        @set_time_limit(0);
        ignore_user_abort(true);
        $temp = tempnam(sys_get_temp_dir(), 'emc_zip');
        if ($temp === false) {
            $this->session->meldung('fehler', 'Die Sicherung konnte nicht erstellt werden.');
            return Response::redirect(url('/admin/sicherung'));
        }
        try {
            $this->sicherung->erstelle($temp);
        } catch (\Throwable $t) {
            @unlink($temp);
            error_log(sprintf('Sicherung fehlgeschlagen: %s', $t->getMessage()));
            $this->session->meldung('fehler', 'Die Sicherung konnte nicht erstellt werden.');
            return Response::redirect(url('/admin/sicherung'));
        }
        return Response::datei($temp, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="emc78-sicherung-' . date('Y-m-d') . '.zip"',
            'Cache-Control' => 'no-store',
        ], true);
    }
}

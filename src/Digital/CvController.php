<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Auth\Auth;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;

final class CvController
{
    public function __construct(
        private View $view,
        private DecoderRepository $decoder,
        private FahrzeugRepository $fahrzeuge,
        private CvRepository $cvs,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function zeigen(Request $r, array $p): Response
    {
        return $this->seite($this->ladeDecoder($p), [], []);
    }

    public function speichern(Request $r, array $p): Response
    {
        $dec = $this->ladeDecoder($p);
        $werte = ['cv' => $r->text('cv'), 'wert' => $r->text('wert'), 'kommentar' => $r->text('kommentar')];
        $fehler = [];
        $cv = $r->ganzzahl('cv', 1, 1024);
        $wert = $r->ganzzahl('wert', 0, 255);
        if ($cv === null) {
            $fehler['cv'] = CvRepository::pruefe(0, 0) ?? '';
        }
        if ($wert === null) {
            $fehler['wert'] = CvRepository::pruefe(1, 256) ?? '';
        }
        $kommentar = trim($r->text('kommentar'));
        if (mb_strlen($kommentar) > 200) {
            $fehler['kommentar'] = 'Der Kommentar darf höchstens 200 Zeichen lang sein.';
        }
        if ($fehler !== []) {
            return $this->seite($dec, $werte, $fehler, 422);
        }
        $vorher = null;
        foreach ($this->cvs->fuerDecoder((int) $dec['id']) as $z) {
            if ((int) $z['cv'] === $cv) {
                $vorher = (int) $z['wert'];
            }
        }
        $this->cvs->speichern((int) $dec['id'], $cv, $wert, $kommentar === '' ? null : $kommentar);
        if ($vorher !== $wert) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'decoder', (int) $dec['id'], 'geaendert', ['cv' . $cv => ['alt' => $vorher, 'neu' => $wert]]);
        }
        $this->session->meldung('erfolg', "CV $cv wurde gespeichert.");
        return Response::redirect(url('/decoder/' . $dec['id'] . '/cvs'));
    }

    public function loeschen(Request $r, array $p): Response
    {
        $dec = $this->ladeDecoder($p);
        $cv = (string) ($p['cv'] ?? '');
        if (preg_match('/^\d{1,4}$/', $cv) === 1) {
            $cv = (int) $cv;
            foreach ($this->cvs->fuerDecoder((int) $dec['id']) as $z) {
                if ((int) $z['cv'] === $cv) {
                    $this->cvs->loeschen((int) $dec['id'], $cv);
                    $this->protokoll->schreibe($this->auth->benutzer()?->id, 'decoder', (int) $dec['id'], 'geaendert', ['cv' . $cv => ['alt' => (int) $z['wert'], 'neu' => null]]);
                    $this->session->meldung('erfolg', "CV $cv wurde gelöscht.");
                    break;
                }
            }
        }
        return Response::redirect(url('/decoder/' . $dec['id'] . '/cvs'));
    }

    /**
     * @param array<string,mixed> $dec
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     */
    private function seite(array $dec, array $werte, array $fehler, int $status = 200): Response
    {
        $html = $this->view->render('digital/cvs', [
            'titel' => 'CVs: ' . $dec['bezeichnung'],
            'decoder' => $dec,
            'fahrzeug' => $this->fahrzeuge->finde((int) $dec['fahrzeug_id']) ?? throw new HttpFehler(404),
            'cvs' => $this->cvs->fuerDecoder((int) $dec['id']),
            'werte' => $werte,
            'fehler' => $fehler,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return array<string,mixed> */
    private function ladeDecoder(array $p): array
    {
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        return ($id > 0 ? $this->decoder->finde($id) : null) ?? throw new HttpFehler(404);
    }
}

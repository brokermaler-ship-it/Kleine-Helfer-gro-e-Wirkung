<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Auth\Auth;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Protokoll\Aenderungsprotokoll;

final class PersonController
{
    private const FELDER = ['vorname', 'nachname', 'email', 'telefon', 'notiz', 'aktiv'];

    public function __construct(
        private View $view,
        private PersonRepository $personen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function liste(Request $r): Response
    {
        $alle = $r->query('alle') === '1';
        return $this->html('personen/liste', [
            'titel' => 'Personen',
            'personen' => $this->personen->alle(!$alle),
            'alle' => $alle,
        ]);
    }

    public function neu(Request $r): Response
    {
        return $this->formular(['aktiv' => 1], [], null);
    }

    public function bearbeiten(Request $r, array $p): Response
    {
        $person = $this->lade($p);
        return $this->formular($person, [], $person);
    }

    public function anlegen(Request $r): Response
    {
        [$werte, $fehler] = $this->lies($r);
        if ($fehler !== []) {
            return $this->formular($werte, $fehler, null, 422);
        }
        $werte['aktiv'] = 1; // neue Personen sind aktiv
        $id = $this->personen->speichern($werte);
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'person', $id, 'angelegt', Aenderungsprotokoll::diff([], $werte));
        $this->session->meldung('erfolg', 'Die Person wurde angelegt.');
        return Response::redirect(url('/personen'));
    }

    public function aendern(Request $r, array $p): Response
    {
        $person = $this->lade($p);
        [$werte, $fehler] = $this->lies($r);
        if ($fehler !== []) {
            return $this->formular($werte + $person, $fehler, $person, 422);
        }
        $this->personen->speichern($werte, (int) $person['id']);
        $diff = Aenderungsprotokoll::diff($this->vergleichswerte($person), $werte);
        if ($diff !== []) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'person', (int) $person['id'], 'geaendert', $diff);
        }
        $this->session->meldung('erfolg', 'Die Person wurde gespeichert.');
        return Response::redirect(url('/personen'));
    }

    public function loeschen(Request $r, array $p): Response
    {
        $person = $this->lade($p);
        try {
            $this->personen->loeschen((int) $person['id']);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return Response::redirect(url('/personen'));
        }
        $alt = $this->vergleichswerte($person);
        $this->protokoll->schreibe(
            $this->auth->benutzer()?->id,
            'person',
            (int) $person['id'],
            'geloescht',
            Aenderungsprotokoll::diff($alt, array_fill_keys(array_keys($alt), null))
        );
        $this->session->meldung('erfolg', 'Die Person wurde gelöscht.');
        return Response::redirect(url('/personen'));
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $id = ctype_digit((string) ($p['id'] ?? '')) ? (int) $p['id'] : 0;
        $person = $id > 0 ? $this->personen->finde($id) : null;
        if ($person === null) {
            throw new HttpFehler(404);
        }
        return $person;
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function lies(Request $r): array
    {
        $werte = [
            'vorname' => trim((string) $r->post('vorname', '')),
            'nachname' => trim((string) $r->post('nachname', '')),
            'email' => trim((string) $r->post('email', '')),
            'telefon' => trim((string) $r->post('telefon', '')),
            'notiz' => trim((string) $r->post('notiz', '')),
            'aktiv' => $r->post('aktiv') !== null && $r->post('aktiv') !== '0' ? 1 : 0,
        ];
        $fehler = [];
        if ($werte['vorname'] === '') {
            $fehler['vorname'] = 'Bitte gib einen Vornamen an.';
        }
        if ($werte['nachname'] === '') {
            $fehler['nachname'] = 'Bitte gib einen Nachnamen an.';
        }
        if ($werte['email'] !== '' && (filter_var($werte['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($werte['email']) > 190)) {
            $fehler['email'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
        }
        foreach (['vorname' => 100, 'nachname' => 100, 'telefon' => 50] as $feld => $max) {
            if (mb_strlen($werte[$feld]) > $max) {
                $fehler[$feld] = 'Bitte höchstens ' . $max . ' Zeichen.';
            }
        }
        return [$werte, $fehler];
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function vergleichswerte(array $person): array
    {
        return array_intersect_key($person, array_flip(self::FELDER));
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     * @param ?array<string,mixed> $person
     */
    private function formular(array $werte, array $fehler, ?array $person, int $status = 200): Response
    {
        return $this->html('personen/formular', [
            'titel' => $person === null ? 'Person anlegen' : 'Person bearbeiten',
            'werte' => $werte,
            'fehler' => $fehler,
            'person' => $person,
            'verlauf' => $person === null ? [] : $this->protokoll->fuerObjekt('person', (int) $person['id']),
        ], $status);
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

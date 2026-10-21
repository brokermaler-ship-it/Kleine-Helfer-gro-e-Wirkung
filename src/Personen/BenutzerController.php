<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Auth\Auth;
use Emc\Auth\KennwortVergessen;
use Emc\Auth\Rolle;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Protokoll\Aenderungsprotokoll;

final class BenutzerController
{
    public function __construct(
        private View $view,
        private BenutzerRepository $benutzer,
        private PersonRepository $personen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private KennwortVergessen $kennwort,
    ) {
    }

    public function liste(Request $r): Response
    {
        return $this->html('admin/benutzer/liste', [
            'titel' => 'Benutzer',
            'benutzerListe' => $this->benutzer->alle(),
            'eigeneId' => $this->auth->benutzer()?->id,
            'kennwortAnfragen' => $this->kennwortAnfragen(),
        ]);
    }

    /** Offene „Kennwort vergessen“-Anfragen; vor dem Datenbank-Update (Migration 010) keine. */
    private function kennwortAnfragen(): array
    {
        try {
            return $this->kennwort->offeneAnfragen();
        } catch (\PDOException) {
            return [];
        }
    }

    public function anfrageVerwerfen(Request $r, array $p): Response
    {
        $id = ctype_digit((string) ($p['id'] ?? '')) ? (int) $p['id'] : 0;
        $admin = $this->auth->benutzer();
        $anfrage = null;
        foreach ($this->kennwort->offeneAnfragen() as $a) {
            if ($a['id'] === $id) {
                $anfrage = $a;
            }
        }
        if ($anfrage !== null && $admin !== null && $this->kennwort->verwerfe($id, $admin->id)) {
            $this->protokoll->schreibe($admin->id, 'benutzer', $anfrage['benutzer_id'], 'geaendert', [
                'passwort' => ['alt' => null, 'neu' => 'Anfrage auf neues Kennwort verworfen'],
            ]);
            $this->session->meldung('erfolg', 'Die Anfrage wurde verworfen.');
        } else {
            $this->session->meldung('warnung', 'Diese Anfrage ist schon erledigt.');
        }
        return Response::redirect(url('/admin/benutzer'));
    }

    public function neu(Request $r): Response
    {
        return $this->formularNeu(['person_id' => $r->query('person_id', ''), 'rolle' => 'mitglied', 'ist_boersenwart' => 0], []);
    }

    public function anlegen(Request $r): Response
    {
        $werte = [
            'person_id' => (string) $r->post('person_id', ''),
            'email' => trim((string) $r->post('email', '')),
            'rolle' => (string) $r->post('rolle', 'mitglied'),
            'ist_boersenwart' => self::haekchen($r) ? 1 : 0,
        ];
        $passwort = (string) $r->post('passwort', '');
        $fehler = [];
        $person = ctype_digit($werte['person_id']) ? $this->personen->finde((int) $werte['person_id']) : null;
        if ($person === null) {
            $fehler['person_id'] = 'Bitte wähle eine Person aus.';
        }
        $rolle = Rolle::tryFrom($werte['rolle']);
        if ($rolle === null) {
            $fehler['rolle'] = 'Bitte wähle eine Rolle aus.';
        }
        if ($fehler === []) {
            try {
                $id = $this->benutzer->anlegen((int) $person['id'], $werte['email'], $rolle, $passwort, $werte['ist_boersenwart'] === 1);
            } catch (DomainFehler $e) {
                $fehler[$this->feldFuer($e->getMessage())] = $e->getMessage();
            }
        }
        if ($fehler !== []) {
            return $this->formularNeu($werte, $fehler, 422);
        }
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'benutzer', $id, 'angelegt', [
            'email' => ['alt' => null, 'neu' => mb_strtolower($werte['email'])],
            'rolle' => ['alt' => null, 'neu' => $rolle->value],
            'aktiv' => ['alt' => null, 'neu' => 1],
            'ist_boersenwart' => ['alt' => null, 'neu' => $werte['ist_boersenwart']],
            'person_id' => ['alt' => null, 'neu' => (int) $person['id']],
        ]);
        $this->session->meldung('erfolg', 'Der Zugang wurde angelegt. Das Startpasswort muss beim ersten Anmelden geändert werden.');
        return Response::redirect(url('/admin/benutzer'));
    }

    public function bearbeiten(Request $r, array $p): Response
    {
        $b = $this->lade($p);
        return $this->formularBearbeiten($b, [], $b['rolle'], (int) $b['aktiv'], (int) $b['ist_boersenwart']);
    }

    public function aendern(Request $r, array $p): Response
    {
        $b = $this->lade($p);
        $rolle = Rolle::tryFrom((string) $r->post('rolle', ''));
        $aktiv = $r->post('aktiv') !== null && $r->post('aktiv') !== '0';
        $haekchen = self::haekchen($r);
        if ($rolle === null) {
            return $this->formularBearbeiten($b, ['rolle' => 'Bitte wähle eine Rolle aus.'], (string) $r->post('rolle', ''), (int) $aktiv, (int) $haekchen, 422);
        }
        try {
            $this->benutzer->aendern((int) $b['id'], $rolle, $aktiv, $haekchen);
        } catch (DomainFehler $e) {
            return $this->formularBearbeiten($b, ['allgemein' => $e->getMessage()], $rolle->value, (int) $aktiv, (int) $haekchen, 422);
        }
        $diff = Aenderungsprotokoll::diff(
            ['rolle' => $b['rolle'], 'aktiv' => (int) $b['aktiv'], 'ist_boersenwart' => (int) $b['ist_boersenwart']],
            ['rolle' => $rolle->value, 'aktiv' => (int) $aktiv, 'ist_boersenwart' => (int) $haekchen]
        );
        if ($diff !== []) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'benutzer', (int) $b['id'], 'geaendert', $diff);
        }
        $this->session->meldung('erfolg', 'Der Zugang wurde gespeichert.');
        return Response::redirect(url('/admin/benutzer'));
    }

    /** „Passwort zurücksetzen“: setzt ein neues Startpasswort, das beim nächsten Login geändert werden muss. */
    public function passwortZuruecksetzen(Request $r, array $p): Response
    {
        $b = $this->lade($p);
        $passwort = (string) $r->post('passwort', '');
        try {
            $this->benutzer->passwortSetzen((int) $b['id'], $passwort, true);
        } catch (DomainFehler $e) {
            return $this->formularBearbeiten($b, ['passwort' => $e->getMessage()], $b['rolle'], (int) $b['aktiv'], (int) $b['ist_boersenwart'], 422);
        }
        // Alle Sitzungen des Kontos enden; setzt ein Administrator sein eigenes Konto zurück, bleibt seine bestehen.
        $this->auth->uebernimmStand((int) $b['id']);
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'benutzer', (int) $b['id'], 'geaendert', [
            'passwort' => ['alt' => null, 'neu' => 'zurückgesetzt'],
        ]);
        $this->kennwort->erledigeFuer((int) $b['id'], $this->auth->benutzer()?->id);
        $this->session->meldung('erfolg', 'Das Startpasswort wurde gesetzt. Es muss beim nächsten Anmelden geändert werden.');
        return Response::redirect(url('/admin/benutzer'));
    }

    public function loeschen(Request $r, array $p): Response
    {
        $b = $this->lade($p);
        try {
            $this->benutzer->loeschen((int) $b['id']);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return Response::redirect(url('/admin/benutzer'));
        }
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'benutzer', (int) $b['id'], 'geloescht', [
            'email' => ['alt' => $b['email'], 'neu' => null],
            'rolle' => ['alt' => $b['rolle'], 'neu' => null],
        ]);
        $this->session->meldung('erfolg', 'Der Zugang wurde gelöscht.');
        return Response::redirect(url('/admin/benutzer'));
    }

    /** Checkbox „Börsenwart“: fehlt sie im Formular, ist das Häkchen entfernt. */
    private static function haekchen(Request $r): bool
    {
        return $r->post('ist_boersenwart') !== null && $r->post('ist_boersenwart') !== '0';
    }

    private function feldFuer(string $meldung): string
    {
        return match (true) {
            str_contains($meldung, 'E-Mail') => 'email',
            str_contains($meldung, 'Passwort') => 'passwort',
            default => 'person_id',
        };
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $id = ctype_digit((string) ($p['id'] ?? '')) ? (int) $p['id'] : 0;
        $b = $id > 0 ? $this->benutzer->finde($id) : null;
        if ($b === null) {
            throw new HttpFehler(404);
        }
        return $b;
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     */
    private function formularNeu(array $werte, array $fehler, int $status = 200): Response
    {
        $vergeben = array_column($this->benutzer->alle(), 'person_id');
        $frei = array_values(array_filter($this->personen->alle(), fn(array $p): bool => !in_array($p['id'], $vergeben, false)));
        return $this->html('admin/benutzer/formular', [
            'titel' => 'Zugang anlegen',
            'benutzerEintrag' => null,
            'werte' => $werte,
            'fehler' => $fehler,
            'personen' => $frei,
            'rollen' => Rolle::cases(),
            'verlauf' => [],
        ], $status);
    }

    /**
     * @param array<string,mixed> $b
     * @param array<string,string> $fehler
     */
    private function formularBearbeiten(array $b, array $fehler, mixed $rolle, int $aktiv, int $istBoersenwart, int $status = 200): Response
    {
        return $this->html('admin/benutzer/formular', [
            'titel' => 'Zugang bearbeiten',
            'benutzerEintrag' => $b,
            'werte' => ['rolle' => $rolle, 'aktiv' => $aktiv, 'ist_boersenwart' => $istBoersenwart],
            'fehler' => $fehler,
            'personen' => [],
            'rollen' => Rolle::cases(),
            'verlauf' => $this->protokoll->fuerObjekt('benutzer', (int) $b['id']),
        ], $status);
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

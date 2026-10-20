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
    public const DSGVO_BESTAETIGUNG = 'Bitte den Nachnamen zur Bestätigung eingeben.';
    public const DSGVO_AUSWAHL = 'Ungültige Fahrzeugauswahl.';
    public const DSGVO_ERNEUT = 'Die Löschung konnte gerade nicht ausgeführt werden. Bitte erneut versuchen.';

    /** @param \Closure(): DsgvoLoeschung $dsgvo erst bei Bedarf gebaut (braucht Dateiablage und OneDrive) */
    public function __construct(
        private View $view,
        private PersonRepository $personen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private \Closure $dsgvo,
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
            $id = (int) $person['id'];
            if ($this->personen->anzahlAbgestellteFahrzeuge($id) > 0) {
                // Ausgemusterte Fahrzeuge fehlen in der Fahrzeugliste: direkt aufs Abstellgleis dieser Person verweisen.
                $this->session->meldung('fehler', $e->getMessage(), url('/abstellgleis', ['person' => (string) $id]), 'Zum Abstellgleis');
            } else {
                $this->session->meldung('fehler', $e->getMessage());
            }
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

    public function dsgvoVorschau(Request $r, array $p): Response
    {
        return $this->dsgvoSeite($this->lade($p), [], null);
    }

    public function dsgvoLoeschen(Request $r, array $p): Response
    {
        $person = $this->lade($p);
        // Ungültige Auswahl nie still verwerfen: sonst würde ein zu übergebendes Fahrzeug gelöscht.
        $roh = $r->post('uebergeben', []);
        $uebergeben = [];
        $gueltig = is_array($roh);
        foreach (is_array($roh) ? $roh : [] as $w) {
            if (!is_string($w) || !ctype_digit($w) || strlen($w) > 18) {
                $gueltig = false;
                break;
            }
            $uebergeben[] = (int) $w;
        }
        if (!$gueltig) {
            return $this->dsgvoSeite($person, [], self::DSGVO_AUSWAHL, 422);
        }
        $eingabe = self::normiere($r->text('nachname'));
        if ($eingabe === '' || $eingabe !== self::normiere((string) $person['nachname'])) {
            return $this->dsgvoSeite($person, $uebergeben, self::DSGVO_BESTAETIGUNG, 422);
        }
        try {
            ($this->dsgvo)()->loesche((int) $person['id'], $uebergeben, (int) $this->auth->benutzer()?->id);
        } catch (DomainFehler $e) {
            if ($this->personen->finde((int) $person['id']) === null) {
                $this->session->meldung('warnung', 'Die Person wurde inzwischen bereits gelöscht.');
                return Response::redirect(url('/personen'));
            }
            return $this->dsgvoSeite($person, $uebergeben, $e->getMessage(), 422);
        } catch (\PDOException $e) {
            if (!self::istSperrkonflikt($e)) {
                throw $e;
            }
            // Deadlock oder Sperr-Timeout: die Transaktion wurde zurückgerollt, es ist nichts gelöscht.
            return $this->dsgvoSeite($person, $uebergeben, self::DSGVO_ERNEUT, 409);
        }
        $this->session->meldung('erfolg', 'Die Person wurde nach DSGVO gelöscht.');
        return Response::redirect(url('/personen'));
    }

    /**
     * Für den Nachnamen-Vergleich: Leerzeichen am Rand (auch geschützte) weg, NFC (falls intl vorhanden),
     * Groß-/Kleinschreibung per Case-Folding (damit „STRAUSS“ = „Strauß“).
     */
    private static function normiere(string $s): string
    {
        $s = (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s);
        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_C);
        }
        return mb_convert_case($s, MB_CASE_FOLD, 'UTF-8');
    }

    /** SQLSTATE 40001 (Serialisierung/Deadlock) bzw. MySQL/MariaDB-Fehler 1213 (Deadlock) und 1205 (Sperr-Timeout). */
    private static function istSperrkonflikt(\PDOException $e): bool
    {
        $info = $e->errorInfo ?? [];
        return $e->getCode() === '40001' || ($info[0] ?? null) === '40001' || in_array((int) ($info[1] ?? 0), [1205, 1213], true);
    }

    /**
     * @param array<string,mixed> $person
     * @param list<int> $uebergeben
     */
    private function dsgvoSeite(array $person, array $uebergeben, ?string $fehler, int $status = 200): Response
    {
        $vorschau = ($this->dsgvo)()->vorschau((int) $person['id'], (int) $this->auth->benutzer()?->id);
        return $this->html('personen/dsgvo', [
            'titel' => 'Person nach DSGVO löschen',
            'person' => $person,
            'vorschau' => $vorschau,
            'uebergeben' => $uebergeben,
            'fehler' => $fehler,
        ], $status);
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

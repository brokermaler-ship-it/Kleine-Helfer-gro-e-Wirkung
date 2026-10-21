<?php

declare(strict_types=1);

namespace Emc\Anlagen;

use Emc\Auth\Auth;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\OneDrive\Uebertragung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturDienst;
use Emc\Stammdaten\SpurweiteRepository;
use Emc\Wartung\WartungDienst;

/**
 * Einfache Anlagenliste: lesen für alle Angemeldeten, anlegen/ändern/löschen ab Bearbeiter
 * (die Routen verlangen dafür Berechtigung::ANLAGE_BEARBEITEN).
 */
final class AnlageController
{
    private const FORMULARFELDER = ['name', 'spurweite_id', 'kurzbeschreibung', 'standort', 'status'];

    public function __construct(
        private View $view,
        private AnlageRepository $anlagen,
        private SpurweiteRepository $spurweiten,
        private DokumentRepository $dokumente,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private bool $onedriveKonfiguriert = false,
        private ?Uebertragung $uebertragung = null,
        private ?WartungDienst $wartung = null,
        private ?ReparaturDienst $reparatur = null,
    ) {
    }

    public function liste(Request $r): Response
    {
        $spurweiteId = $r->ganzzahl('spurweite', 1, 4294967295);
        $text = mb_substr(trim($r->text('q')), 0, 100);
        return $this->html('anlagen/liste', [
            'titel' => 'Anlagen',
            'anlagen' => $this->anlagen->alle($spurweiteId, $text === '' ? null : $text),
            'spurweiten' => $this->spurweiten->alle(),
            'filterSpurweite' => $spurweiteId,
            'filterText' => $text,
        ]);
    }

    public function anzeigen(Request $r, array $p): Response
    {
        $anlage = $this->lade($p);
        return $this->html('anlagen/detail', [
            'titel' => $anlage['code'] . ' ' . $anlage['name'],
            'anlage' => $anlage,
            'verlauf' => $this->protokoll->fuerObjekt('anlage', (int) $anlage['id']),
            'dateien' => $this->dokumente->fuerObjekt('anlage', (int) $anlage['id']),
            'onedriveKonfiguriert' => $this->onedriveKonfiguriert,
            'wartung' => $this->wartung?->blockDaten('anlage', $anlage, $this->auth->benutzer(), heute: new \DateTimeImmutable('today')),
        ]);
    }

    public function neu(Request $r): Response
    {
        return $this->formular(['status' => 'in_ordnung'], [], null);
    }

    public function bearbeiten(Request $r, array $p): Response
    {
        $anlage = $this->lade($p);
        return $this->formular($anlage, [], $anlage);
    }

    public function anlegen(Request $r): Response
    {
        [$daten, $fehler] = $this->lies($r);
        if ($fehler !== []) {
            return $this->formular($this->anzeigeWerte($r), $fehler, null, 422);
        }
        $id = $this->anlagen->anlegen($daten);
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'anlage', $id, 'angelegt', Aenderungsprotokoll::diff([], $daten));
        $this->session->meldung('erfolg', 'Die Anlage wurde angelegt.');
        return Response::redirect(url('/anlagen/' . $id));
    }

    public function aendern(Request $r, array $p): Response
    {
        $anlage = $this->lade($p);
        $stand = $r->text('_stand'); // Stand des ursprünglich geladenen Formulars, nie der aktuelle DB-Stand
        [$daten, $fehler] = $this->lies($r);
        if ($fehler !== []) {
            return $this->formular($this->anzeigeWerte($r), $fehler, $anlage, 422, null, $stand);
        }
        try {
            $this->anlagen->aktualisieren((int) $anlage['id'], $daten, $stand);
        } catch (KonfliktFehler $e) {
            if ($this->anlagen->finde((int) $anlage['id']) === null) {
                throw new HttpFehler(404); // zwischenzeitlich gelöscht
            }
            return $this->formular($this->anzeigeWerte($r), [], $anlage, 409, $e->getMessage(), $stand);
        }
        $diff = Aenderungsprotokoll::diff($this->vergleichswerte($anlage), $daten);
        if ($diff !== []) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'anlage', (int) $anlage['id'], 'geaendert', $diff);
        }
        $this->session->meldung('erfolg', 'Die Anlage wurde gespeichert.');
        return Response::redirect(url('/anlagen/' . $anlage['id']));
    }

    public function loeschen(Request $r, array $p): Response
    {
        $anlage = $this->lade($p);
        $id = (int) $anlage['id'];
        $this->wartung?->entferneFuerObjekt('anlage', $id);
        $this->reparatur?->entferneFuerObjekt('anlage', $id);
        $this->dokumente->entferneVonObjekt('anlage', $id);
        $this->anlagen->loeschen($id);
        // Lösch-Aufträge für die OneDrive-Dateien gleich nebenbei ausführen (ohne Cronjob sonst erst später).
        $this->uebertragung?->nebenbei();
        $alt = $this->vergleichswerte($anlage);
        $this->protokoll->schreibe(
            $this->auth->benutzer()?->id,
            'anlage',
            $id,
            'geloescht',
            Aenderungsprotokoll::diff($alt, array_fill_keys(array_keys($alt), null))
        );
        $this->session->meldung('erfolg', 'Die Anlage wurde gelöscht.');
        return Response::redirect(url('/anlagen'));
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $id = preg_match('/^\d{1,10}$/', (string) ($p['id'] ?? '')) === 1 ? (int) $p['id'] : 0;
        $anlage = $id > 0 && $id <= 4294967295 ? $this->anlagen->finde($id) : null;
        if ($anlage === null) {
            throw new HttpFehler(404);
        }
        return $anlage;
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function lies(Request $r): array
    {
        $roh = [];
        foreach (self::FORMULARFELDER as $feld) {
            $roh[$feld] = $r->text($feld);
        }
        $spurweitenIds = array_map(fn(array $s): int => (int) $s['id'], $this->spurweiten->alle());
        return AnlageValidator::pruefe($roh, $spurweitenIds);
    }

    /**
     * Zurück ins Formular: Eingaben bleiben so erhalten, wie sie getippt wurden.
     *
     * @return array<string,string>
     */
    private function anzeigeWerte(Request $r): array
    {
        $w = [];
        foreach (self::FORMULARFELDER as $feld) {
            $w[$feld] = $r->text($feld);
        }
        return $w;
    }

    /**
     * @param array<string,mixed> $anlage
     * @return array<string,mixed>
     */
    private function vergleichswerte(array $anlage): array
    {
        $w = array_intersect_key($anlage, array_flip(self::FORMULARFELDER));
        $w['spurweite_id'] = (int) $w['spurweite_id'];
        return $w;
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     * @param ?array<string,mixed> $anlage
     */
    private function formular(array $werte, array $fehler, ?array $anlage, int $status = 200, ?string $konflikt = null, ?string $stand = null): Response
    {
        return $this->html('anlagen/formular', [
            'titel' => $anlage === null ? 'Anlage anlegen' : 'Anlage bearbeiten',
            'werte' => $werte,
            'fehler' => $fehler,
            'anlage' => $anlage,
            'konflikt' => $konflikt,
            'stand' => $stand ?? ($anlage['geaendert_am'] ?? ''),
            'spurweiten' => $this->spurweiten->alle(),
        ], $status);
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

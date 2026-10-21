<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
use Emc\Codes\Code;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Dateien\DateiController;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Personen\PersonRepository;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Seite „Reparaturen“ (Liste mit Filtern), Reparatur ansehen (alle, Recht „lesen“), bearbeiten und Fotos anhängen
 * (Berechtigung::darfReparaturBearbeiten am Objekt, sonst 403). Reparaturen werden nicht gelöscht, nur verworfen.
 */
final class ReparaturController
{
    private const TEIL_FELDER = ['bezeichnung', 'artikelnummer', 'bezugsquelle', 'kosten'];

    public function __construct(
        private View $view,
        private ReparaturRepository $reparaturen,
        private ReparaturDienst $dienst,
        private FahrzeugRepository $fahrzeuge,
        private AnlageRepository $anlagen,
        private PersonRepository $personen,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    /** GET /reparaturen */
    public function liste(Request $r): Response
    {
        return $this->vorUpdate(function () use ($r): Response {
            $filter = ReparaturFilter::ausRequest($r);
            return $this->seite('reparatur/liste', [
                'titel' => 'Reparaturen',
                'filter' => $filter,
                'reparaturen' => $this->reparaturen->suche($filter),
                'personen' => $this->personen->alle(false),
            ]);
        });
    }

    /** GET /reparaturen/{id} */
    public function anzeigen(Request $r, array $p): Response
    {
        return $this->vorUpdate(function () use ($p): Response {
            $rep = $this->lade($p);
            $typ = (string) $rep['objekt_typ'];
            $objekt = $this->objekt($typ, (int) $rep['objekt_id']);
            return $this->seite('reparatur/detail', [
                'titel' => 'Reparatur – ' . ($objekt === null ? 'Objekt gelöscht' : self::kennung($typ, $objekt) . ' ' . self::name($typ, $objekt)),
                'reparatur' => $rep,
                'objektTyp' => $typ,
                'objekt' => $objekt,
                'kennung' => $objekt === null ? null : self::kennung($typ, $objekt),
                'name' => $objekt === null ? null : self::name($typ, $objekt),
                'fotos' => $this->dokumente->fuerObjekt('reparatur', (int) $rep['id']),
                'darfBearbeiten' => $objekt !== null && Berechtigung::darfReparaturBearbeiten($this->auth->benutzer(), $typ, $objekt),
            ]);
        });
    }

    /** GET /reparaturen/{id}/bearbeiten */
    public function bearbeiten(Request $r, array $p): Response
    {
        return $this->vorUpdate(function () use ($p): Response {
            [$rep, $typ, $objekt] = $this->ladeBearbeitbar($p);
            $werte = [
                'beschreibung' => (string) $rep['beschreibung'],
                'wo_bauteil' => (string) ($rep['wo_bauteil'] ?? ''),
                'prioritaet' => (string) $rep['prioritaet'],
                'status' => (string) $rep['status'],
                'zustaendig' => (string) ($rep['zustaendig_person_id'] ?? ''),
                'termin' => (string) ($rep['termin'] ?? ''),
                'notizen' => (string) ($rep['notizen'] ?? ''),
                'was_gemacht' => (string) ($rep['was_gemacht'] ?? ''),
            ];
            $teile = array_map(fn(array $t): array => [
                'bezeichnung' => (string) $t['bezeichnung'],
                'artikelnummer' => (string) ($t['artikelnummer'] ?? ''),
                'bezugsquelle' => (string) ($t['bezugsquelle'] ?? ''),
                'kosten' => ReparaturValidator::betragEingabe($t['kosten'] === null ? null : (string) $t['kosten']),
                'bestellt' => (int) $t['bestellt'] === 1 ? '1' : '',
            ], $rep['ersatzteile']);
            $teile[] = self::leereZeile();
            return $this->formular($rep, $typ, $objekt, $werte, $teile, [], (string) $rep['geaendert_am']);
        });
    }

    /**
     * POST /reparaturen/{id}: speichert Felder und Ersatzteile unter der Sperre des Objekts (ReparaturDienst::inTransaktion)
     * samt automatischem Status. Stand-Prüfung wie bei Fahrzeugen: hat inzwischen jemand anderes gespeichert, 409 mit den
     * eigenen Eingaben, und nichts wird gespeichert.
     */
    public function aendern(Request $r, array $p): Response
    {
        return $this->vorUpdate(function () use ($r, $p): Response {
            [$rep, $typ, $objekt] = $this->ladeBearbeitbar($p);
            $id = (int) $rep['id'];
            $stand = is_string($r->post('stand')) ? (string) $r->post('stand') : ''; // Stand beim Laden des Formulars
            $eingabe = [];
            foreach (['beschreibung', 'wo_bauteil', 'prioritaet', 'status', 'zustaendig', 'termin', 'notizen', 'was_gemacht'] as $feld) {
                $eingabe[$feld] = $r->post($feld);
            }
            $eingabe['teile'] = $r->post('teile', []);
            [$d, $fehler, $teile] = ReparaturValidator::pruefe($eingabe, $this->personIds($rep), $typ);
            $werte = array_map(fn(mixed $w): string => is_string($w) ? $w : '', array_diff_key($eingabe, ['teile' => true]));
            if ($fehler !== []) {
                return $this->formular($rep, $typ, $objekt, $werte, self::anzeigeTeile($eingabe['teile']), $fehler, $stand, 422);
            }

            $benutzer = $this->auth->benutzer();
            try {
                $altStatus = $this->dienst->inTransaktion($typ, (int) $rep['objekt_id'], $benutzer?->id, function (array $gesperrt) use ($id, $typ, $d, $teile, $stand, $benutzer): string {
                    // Recht am gesperrten, aktuellen Stand des Objekts (z. B. Eigentümer inzwischen geändert).
                    if (!Berechtigung::darfReparaturBearbeiten($benutzer, $typ, $gesperrt)) {
                        throw new HttpFehler(403);
                    }
                    $alt = $this->reparaturen->finde($id) ?? throw new KonfliktFehler(ReparaturRepository::KONFLIKT);
                    $this->reparaturen->aktualisieren($id, $d, $teile, $stand);
                    $diff = Aenderungsprotokoll::diff(
                        array_intersect_key($alt, array_flip(ReparaturRepository::FELDER)) + ['ersatzteile' => self::teileText($alt['ersatzteile'])],
                        $d + ['ersatzteile' => self::teileText($teile)]
                    );
                    if ($diff !== []) {
                        $this->protokoll->schreibe($benutzer?->id, 'reparatur', $id, 'geaendert', $diff);
                    }
                    return (string) $alt['status'];
                });
            } catch (KonfliktFehler $e) {
                if ($this->reparaturen->finde($id) === null) {
                    throw new HttpFehler(404); // zwischenzeitlich gelöscht
                }
                return $this->formular($rep, $typ, $objekt, $werte, self::anzeigeTeile($eingabe['teile']), [], $stand, 409, $e->getMessage());
            }
            if ($altStatus === null) {
                throw new HttpFehler(404); // Objekt zwischenzeitlich gelöscht
            }
            $verworfen = $d['status'] === ReparaturStatus::Verworfen->value && $altStatus !== ReparaturStatus::Verworfen->value;
            $this->session->meldung('erfolg', $verworfen ? 'Die Reparatur wurde verworfen.' : 'Die Reparatur wurde gespeichert.');
            return Response::redirect(url('/reparaturen/' . $id));
        });
    }

    /** POST /reparaturen/{id}/fotos: Fotos anhängen (nur Bilder), Protokoll am Objekt wie beim Löschen eines Reparaturfotos. */
    public function fotos(Request $r, array $p): Response
    {
        return $this->vorUpdate(function () use ($r, $p): Response {
            [$rep, $typ, $objekt] = $this->ladeBearbeitbar($p);
            $id = (int) $rep['id'];
            $ziel = Response::redirect(url('/reparaturen/' . $id));
            if (DateiController::normalisiere($r->file('fotos')) === []) {
                $this->session->meldung('fehler', 'Es wurde kein Foto ausgewählt.');
                return $ziel;
            }
            $benutzerId = $this->auth->benutzer()?->id;
            $gespeichert = (new ReparaturFotos($this->upload, $this->dokumente, $this->session))->speichere($r->file('fotos'), $id, $benutzerId, 'eine Reparatur');
            $datum = date('d.m.Y', (int) strtotime((string) $rep['gemeldet_am']));
            foreach ($gespeichert as $foto) {
                $titel = (string) ($this->dokumente->finde($foto['id'])['titel'] ?? $foto['name']);
                $this->protokoll->schreibe($benutzerId, $typ, (int) $objekt['id'], 'geaendert', [
                    'datei' => ['alt' => null, 'neu' => $titel . ' (Reparatur vom ' . $datum . ')'],
                ]);
            }
            $n = count($gespeichert);
            if ($n > 0) {
                $this->session->meldung('erfolg', $n === 1 ? 'Das Foto wurde hinzugefügt.' : $n . ' Fotos wurden hinzugefügt.');
            }
            return $ziel;
        });
    }

    /**
     * Ersatzteile als Text fürs Änderungsprotokoll: „Haftreifen (4,90 €, bestellt); Schleifer“; null ohne Ersatzteile.
     *
     * @param list<array<string,mixed>> $teile
     */
    private static function teileText(array $teile): ?string
    {
        $texte = [];
        foreach ($teile as $t) {
            $zusatz = [];
            if (($t['kosten'] ?? null) !== null) {
                $zusatz[] = ReparaturValidator::betrag((string) $t['kosten']);
            }
            if (!empty($t['bestellt'])) {
                $zusatz[] = 'bestellt';
            }
            $texte[] = $t['bezeichnung'] . ($zusatz === [] ? '' : ' (' . implode(', ', $zusatz) . ')');
        }
        return $texte === [] ? null : implode('; ', $texte);
    }

    /**
     * Gesendete Ersatzteilzeilen fürs Formular (nach Fehlern oder Konflikt) – so, wie sie getippt wurden, in derselben
     * Reihenfolge wie die Fehlerschlüssel teile.<n>.<feld>.
     *
     * @return list<array<string,string>>
     */
    private static function anzeigeTeile(mixed $roh): array
    {
        $zeilen = [];
        foreach (is_array($roh) ? array_values($roh) : [] as $z) {
            $z = is_array($z) ? $z : [];
            $zeile = [];
            foreach (self::TEIL_FELDER as $feld) {
                $zeile[$feld] = is_string($z[$feld] ?? null) ? $z[$feld] : '';
            }
            $zeile['bestellt'] = ($z['bestellt'] ?? null) === '1' ? '1' : '';
            $zeilen[] = $zeile;
        }
        return $zeilen === [] ? [self::leereZeile()] : $zeilen;
    }

    /** @return array<string,string> */
    private static function leereZeile(): array
    {
        return array_fill_keys([...self::TEIL_FELDER, 'bestellt'], '');
    }

    /**
     * Wählbar für „zuständig“: aktive Personen und die bisher zuständige (auch wenn sie inzwischen inaktiv ist).
     *
     * @param array<string,mixed> $rep
     * @return list<int>
     */
    private function personIds(array $rep): array
    {
        $ids = array_map(fn(array $p): int => (int) $p['id'], $this->personen->alle(true));
        if ($rep['zustaendig_person_id'] !== null) {
            $ids[] = (int) $rep['zustaendig_person_id'];
        }
        return $ids;
    }

    /**
     * @param array<string,mixed> $rep
     * @param array<string,mixed> $objekt
     * @param array<string,string> $werte
     * @param list<array<string,string>> $teile
     * @param array<string,string> $fehler
     */
    private function formular(array $rep, string $typ, array $objekt, array $werte, array $teile, array $fehler, string $stand, int $status = 200, ?string $konflikt = null): Response
    {
        $personen = $this->personen->alle(true);
        $zustaendig = $rep['zustaendig_person_id'] === null ? null : (int) $rep['zustaendig_person_id'];
        if ($zustaendig !== null && !in_array($zustaendig, array_map(fn(array $p): int => (int) $p['id'], $personen), true)) {
            $bisher = $this->personen->finde($zustaendig);
            if ($bisher !== null) {
                $personen[] = $bisher;
            }
        }
        return $this->seite('reparatur/formular', [
            'titel' => 'Reparatur bearbeiten – ' . self::kennung($typ, $objekt) . ' ' . self::name($typ, $objekt),
            'reparatur' => $rep,
            'objektTyp' => $typ,
            'objekt' => $objekt,
            'kennung' => self::kennung($typ, $objekt),
            'name' => self::name($typ, $objekt),
            'werte' => $werte,
            'teile' => $teile,
            'fehler' => $fehler,
            'konflikt' => $konflikt,
            'stand' => $stand,
            'personen' => $personen,
        ], $status);
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Fehlen die Tabellen aus Migration 014 noch, führt die Seite zum Update statt zu einem Fehler 500. Im Normalfall
     * fängt das schon App (3a/3c) ab.
     *
     * @param callable():Response $aktion
     */
    private function vorUpdate(callable $aktion): Response
    {
        try {
            return $aktion();
        } catch (\PDOException $e) {
            if (!in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                throw $e;
            }
            $this->session->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
            return Response::redirect(url('/admin/update'));
        }
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        return ($id > 0 ? $this->reparaturen->finde($id) : null) ?? throw new HttpFehler(404);
    }

    /** @return array{0: array<string,mixed>, 1: string, 2: array<string,mixed>} Reparatur, Objekttyp, Objekt */
    private function ladeBearbeitbar(array $p): array
    {
        $rep = $this->lade($p);
        $typ = (string) $rep['objekt_typ'];
        $objekt = $this->objekt($typ, (int) $rep['objekt_id']) ?? throw new HttpFehler(404);
        if (!Berechtigung::darfReparaturBearbeiten($this->auth->benutzer(), $typ, $objekt)) {
            throw new HttpFehler(403);
        }
        return [$rep, $typ, $objekt];
    }

    /** @return ?array<string,mixed> */
    private function objekt(string $typ, int $id): ?array
    {
        return $typ === 'anlage' ? $this->anlagen->finde($id) : $this->fahrzeuge->finde($id);
    }

    /** @param array<string,mixed> $objekt */
    private static function kennung(string $typ, array $objekt): string
    {
        return $typ === 'anlage'
            ? (string) $objekt['code']
            : (Code::fahrzeug((string) $objekt['inventarnummer']) ?? (string) $objekt['inventarnummer']);
    }

    /** @param array<string,mixed> $objekt */
    private static function name(string $typ, array $objekt): string
    {
        return (string) ($typ === 'anlage' ? $objekt['name'] : $objekt['bezeichnung']);
    }
}

<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Auth;
use Emc\Codes\Code;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Dateien\DateiController;
use Emc\Dateien\DateiTyp;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadFehler;
use Emc\Dateien\UploadService;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * „Defekt melden“ (alle Mitglieder, Recht „lesen“) und „Meine Meldungen“. Eine Meldung ist eine Reparatur mit Status
 * „gemeldet“; bei „fährt nicht mehr“ setzt der automatische Status das Objekt auf „defekt“.
 */
final class MeldungController
{
    private const MAX_FOTOS = 20;
    /** Länge der Beschreibung im Änderungsprotokoll des Objekts. */
    private const PROTOKOLL_KURZ = 80;

    public function __construct(
        private View $view,
        private \PDO $pdo,
        private ReparaturRepository $reparaturen,
        private ReparaturDienst $dienst,
        private FahrzeugRepository $fahrzeuge,
        private AnlageRepository $anlagen,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    /** GET /defekt: Objekt wählen über Code, Digitaladresse oder Name (weiter über das Feld „Code“ mit fuer=defekt). */
    public function waehlen(Request $r): Response
    {
        return $this->seite('reparatur/objekt_waehlen', ['titel' => 'Defekt melden']);
    }

    public function formularFahrzeug(Request $r, array $p): Response
    {
        return $this->formular('fahrzeug', $this->objekt('fahrzeug', $p), [], []);
    }

    public function formularAnlage(Request $r, array $p): Response
    {
        return $this->formular('anlage', $this->objekt('anlage', $p), [], []);
    }

    public function meldenFahrzeug(Request $r, array $p): Response
    {
        return $this->vorUpdate(fn() => $this->melden($r, 'fahrzeug', $this->objekt('fahrzeug', $p)));
    }

    public function meldenAnlage(Request $r, array $p): Response
    {
        return $this->vorUpdate(fn() => $this->melden($r, 'anlage', $this->objekt('anlage', $p)));
    }

    /** GET /meine-meldungen */
    public function meine(Request $r): Response
    {
        return $this->vorUpdate(function (): Response {
            $benutzer = $this->auth->benutzer() ?? throw new HttpFehler(403);
            return $this->seite('reparatur/meine', [
                'titel' => 'Meine Meldungen',
                'meldungen' => $this->reparaturen->vonBenutzer($benutzer->id),
            ]);
        });
    }

    /** @param array<string,mixed> $objekt */
    private function melden(Request $r, string $typ, array $objekt): Response
    {
        $benutzerId = $this->auth->benutzer()?->id;
        $objektId = (int) $objekt['id'];
        $eingabe = [
            'beschreibung' => $r->post('beschreibung'),
            'wo_bauteil' => $r->post('wo_bauteil'),
            'einstufung' => $r->post('einstufung'),
        ];
        [$d, $fehler] = MeldungValidator::pruefe($eingabe, $typ);
        if ($fehler !== []) {
            $hatFotos = DateiController::normalisiere($r->file('fotos')) !== [];
            return $this->formular($typ, $objekt, $d + ['einstufung' => $d['prioritaet']], $fehler, $hatFotos, 422);
        }

        // Meldung und automatischer Status gemeinsam unter der Sperre des Objekts; Fotos erst danach (eine fehlerhafte
        // Datei verwirft die Meldung nicht). Inzwischen gelöschtes Objekt: 404 statt einer verwaisten Meldung.
        $reparaturId = $this->dienst->inTransaktion($typ, $objektId, $benutzerId, function () use ($typ, $objektId, $d, $benutzerId): int {
            $reparaturId = $this->reparaturen->anlegen($typ, $objektId, $d, $benutzerId);
            $this->protokoll->schreibe($benutzerId, 'reparatur', $reparaturId, 'angelegt', Aenderungsprotokoll::diff([], [
                'beschreibung' => $d['beschreibung'],
                'wo_bauteil' => $d['wo_bauteil'],
                'prioritaet' => $d['prioritaet'],
            ]));
            $this->protokoll->schreibe($benutzerId, $typ, $objektId, 'geaendert', [
                'reparatur' => ['alt' => null, 'neu' => 'Defekt gemeldet: ' . self::kurz($d['beschreibung'])],
            ]);
            return $reparaturId;
        }) ?? throw new HttpFehler(404);

        $this->speichereFotos($r, $reparaturId, $benutzerId);
        $this->session->meldung('erfolg', 'Danke! Dein Defekt wurde gemeldet.', url('/meine-meldungen'), 'Meine Meldungen');
        return Response::redirect(url(self::objektPfad($typ, $objektId)));
    }

    /** Nur Fotos; andere Dateien und Upload-Fehler ergeben eine Warnung, die Meldung bleibt gespeichert. */
    private function speichereFotos(Request $r, int $reparaturId, ?int $benutzerId): void
    {
        $dateien = DateiController::normalisiere($r->file('fotos'));
        foreach (array_slice($dateien, 0, self::MAX_FOTOS) as $datei) {
            $name = is_string($datei['name'] ?? null) ? basename(str_replace('\\', '/', $datei['name'])) : '';
            $punkt = strrpos($name, '.');
            $dateiTyp = $punkt === false ? null : DateiTyp::fuerEndung(substr($name, $punkt + 1));
            if ($dateiTyp === null || !$dateiTyp['bild']) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . 'Nur Fotos können an eine Meldung angehängt werden.');
                continue;
            }
            try {
                $id = $this->upload->speichere($datei, '', 'foto', $benutzerId, null, null, null, $reparaturId);
            } catch (UploadFehler $f) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . $f->getMessage());
                continue;
            }
            $this->dokumente->zuordnen($id, 'reparatur', $reparaturId);
        }
        if (count($dateien) > self::MAX_FOTOS) {
            $this->session->meldung('warnung', 'Es werden höchstens ' . self::MAX_FOTOS . ' Fotos auf einmal verarbeitet.');
        }
    }

    /**
     * @param array<string,mixed> $objekt
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     */
    private function formular(string $typ, array $objekt, array $werte, array $fehler, bool $fotosVerworfen = false, int $status = 200): Response
    {
        $kennung = $typ === 'anlage'
            ? (string) $objekt['code']
            : (Code::fahrzeug((string) $objekt['inventarnummer']) ?? (string) $objekt['inventarnummer']);
        $name = $typ === 'anlage' ? (string) $objekt['name'] : (string) $objekt['bezeichnung'];
        return $this->seite('reparatur/melden', [
            'titel' => 'Defekt melden – ' . $kennung . ' ' . $name,
            'objektTyp' => $typ,
            'objekt' => $objekt,
            'kennung' => $kennung,
            'name' => $name,
            'aktion' => url(self::objektPfad($typ, (int) $objekt['id']) . '/defekt'),
            'objektUrl' => url(self::objektPfad($typ, (int) $objekt['id'])),
            'werte' => $werte,
            'fehler' => $fehler,
            'fotosVerworfen' => $fotosVerworfen,
        ], $status);
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Fehlen die Tabellen aus Migration 014 noch (Programmdateien neu, Update nicht ausgeführt), führt die Seite zum
     * Update statt zu einem Fehler 500. Im Normalfall fängt das schon App (3a/3c) ab.
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

    /** Beschreibung für das Protokoll am Objekt: eine Zeile, höchstens PROTOKOLL_KURZ Zeichen. */
    private static function kurz(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > self::PROTOKOLL_KURZ ? mb_substr($text, 0, self::PROTOKOLL_KURZ - 1) . '…' : $text;
    }

    private static function objektPfad(string $typ, int $id): string
    {
        return ($typ === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . $id;
    }

    /** @return array<string,mixed> */
    private function objekt(string $typ, array $p): array
    {
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        $objekt = $id > 0 ? ($typ === 'anlage' ? $this->anlagen->finde($id) : $this->fahrzeuge->finde($id)) : null;
        if ($objekt === null) {
            throw new HttpFehler(404);
        }
        return $objekt;
    }
}

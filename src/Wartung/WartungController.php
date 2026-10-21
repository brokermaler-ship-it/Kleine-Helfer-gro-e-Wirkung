<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
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
use Emc\OneDrive\Uebertragung;
use Emc\Personen\PersonRepository;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Wartungen eintragen und löschen, Ausnahmen im Wartungsplan pflegen. Die Routen verlangen nur „lesen“;
 * das Recht am konkreten Objekt prüft dieser Controller (403).
 */
final class WartungController
{
    private const MAX_FOTOS = 20;
    private const INTERVALL_MAX = 999;

    public function __construct(
        private View $view,
        private \PDO $pdo,
        private WartungDienst $dienst,
        private WartungRepository $wartungen,
        private AusnahmeRepository $ausnahmen,
        private FahrzeugRepository $fahrzeuge,
        private AnlageRepository $anlagen,
        private PersonRepository $personen,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private ?Uebertragung $uebertragung = null,
    ) {
    }

    public function eintragenFahrzeug(Request $r, array $p): Response
    {
        return $this->eintragen($r, 'fahrzeug', $this->objekt('fahrzeug', self::id($p)));
    }

    public function eintragenAnlage(Request $r, array $p): Response
    {
        return $this->eintragen($r, 'anlage', $this->objekt('anlage', self::id($p)));
    }

    public function planFahrzeug(Request $r, array $p): Response
    {
        return $this->plan($r, 'fahrzeug', $this->objekt('fahrzeug', self::id($p)));
    }

    public function planAnlage(Request $r, array $p): Response
    {
        return $this->plan($r, 'anlage', $this->objekt('anlage', self::id($p)));
    }

    /** Löschen mit demselben Recht wie das Eintragen am Objekt; der Betriebsstundenstand des Fahrzeugs bleibt. */
    public function loeschen(Request $r, array $p): Response
    {
        $id = self::id($p);
        $w = $id > 0 ? $this->wartungen->finde($id) : null;
        if ($w === null) {
            throw new HttpFehler(404);
        }
        $typ = (string) $w['objekt_typ'];
        $objekt = $this->objekt($typ, (int) $w['objekt_id']);
        if (!Berechtigung::darfWartungEintragen($this->auth->benutzer(), $typ, $objekt)) {
            throw new HttpFehler(403);
        }
        $this->dokumente->entferneVonObjekt('wartung', $id);
        $this->wartungen->loeschen($id);
        $this->uebertragung?->nebenbei(); // Lösch-Aufträge für OneDrive-Fotos gleich ausführen
        $this->protokoll->schreibe($this->auth->benutzer()?->id, $typ, (int) $objekt['id'], 'geaendert', [
            'wartung' => ['alt' => WartungDienst::beschreibung((string) $w['datum'], $w['arten']), 'neu' => null],
        ]);
        $this->session->meldung('erfolg', 'Die Wartung wurde gelöscht.');
        return Response::redirect(self::objektUrl($typ, (int) $objekt['id']));
    }

    /** @param array<string,mixed> $objekt */
    private function eintragen(Request $r, string $typ, array $objekt): Response
    {
        $benutzer = $this->auth->benutzer();
        if (!Berechtigung::darfWartungEintragen($benutzer, $typ, $objekt)) {
            throw new HttpFehler(403);
        }
        $objektId = (int) $objekt['id'];
        $arten = $this->dienst->eintragbareArten($typ, $objekt);
        $eingabe = [
            'datum' => $r->text('datum'),
            'arten' => $r->post('arten', []),
            'betriebsstunden' => $r->text('betriebsstunden'),
            'ausgefuehrt_von' => $r->text('ausgefuehrt_von'),
            'notiz' => $r->text('notiz'),
        ];
        [$d, $fehler] = WartungValidator::pruefe(
            $eingabe,
            array_map(fn(array $a): int => (int) $a['id'], $arten),
            array_map(fn(array $p): int => (int) $p['id'], $this->personen->alle(true)),
            $typ === 'fahrzeug',
            (new \DateTimeImmutable('today'))->format('Y-m-d')
        );
        if ($fehler !== []) {
            $eingabe['arten'] = is_array($eingabe['arten']) ? array_values(array_filter($eingabe['arten'], 'is_string')) : [];
            return $this->formular($typ, $objekt, 'eintragen', $this->dienst->blockDaten($typ, $objekt, $benutzer, $eingabe, $fehler), 422);
        }
        $namen = array_values(array_map(
            fn(array $a): string => (string) $a['name'],
            array_filter($arten, fn(array $a): bool => in_array((int) $a['id'], $d['arten'], true))
        ));
        $aenderungen = ['wartung' => ['alt' => null, 'neu' => WartungDienst::beschreibung((string) $d['datum'], $namen)]];

        // Eintragen und Betriebsstunden-Aktualisierung gemeinsam; Fotos erst danach.
        $this->pdo->beginTransaction();
        try {
            $bezug = null;
            if ($typ === 'fahrzeug') {
                $st = $this->pdo->prepare('SELECT betriebsstunden FROM fahrzeug WHERE id = ? FOR UPDATE');
                $st->execute([$objektId]);
                $aktuell = $st->fetchColumn();
                if ($aktuell === false) {
                    throw new HttpFehler(404); // zwischenzeitlich gelöscht
                }
                $aktuell = (float) $aktuell;
                $bezug = $d['betriebsstunden'] ?? $aktuell;
                if ($d['betriebsstunden'] !== null && $d['betriebsstunden'] > $aktuell) {
                    // Nur ein höherer Stand zählt: ein Nachtrag mit kleinerem Stand senkt den Zähler nicht.
                    $this->pdo->prepare('UPDATE fahrzeug SET betriebsstunden = ? WHERE id = ?')->execute([$d['betriebsstunden'], $objektId]);
                    $aenderungen['betriebsstunden'] = ['alt' => $aktuell, 'neu' => $d['betriebsstunden']];
                }
            }
            $wartungId = $this->wartungen->eintragen($typ, $objektId, [
                'datum' => (string) $d['datum'],
                'betriebsstunden' => $d['betriebsstunden'],
                'stundenstand_bezug' => $bezug,
                'ausgefuehrt_von_person_id' => $d['ausgefuehrt_von_person_id'],
                'notiz' => $d['notiz'],
            ], $d['arten'], $benutzer?->id);
            $this->protokoll->schreibe($benutzer?->id, $typ, $objektId, 'geaendert', $aenderungen);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }

        $this->speichereFotos($r, $wartungId, $benutzer?->id);
        $this->session->meldung('erfolg', 'Die Wartung wurde eingetragen.');
        return Response::redirect(self::objektUrl($typ, $objektId));
    }

    /** Nur Fotos; andere Dateien und Upload-Fehler ergeben eine Warnung, die Wartung bleibt gespeichert. */
    private function speichereFotos(Request $r, int $wartungId, ?int $benutzerId): void
    {
        $dateien = DateiController::normalisiere($r->file('fotos'));
        foreach (array_slice($dateien, 0, self::MAX_FOTOS) as $datei) {
            $name = is_string($datei['name'] ?? null) ? basename(str_replace('\\', '/', $datei['name'])) : '';
            $punkt = strrpos($name, '.');
            $typ = $punkt === false ? null : DateiTyp::fuerEndung(substr($name, $punkt + 1));
            if ($typ === null || !$typ['bild']) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . 'Nur Fotos können an eine Wartung angehängt werden.');
                continue;
            }
            try {
                $id = $this->upload->speichere($datei, '', 'foto', $benutzerId, null, null, $wartungId);
            } catch (UploadFehler $f) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . $f->getMessage());
                continue;
            }
            $this->dokumente->zuordnen($id, 'wartung', $wartungId);
        }
        if (count($dateien) > self::MAX_FOTOS) {
            $this->session->meldung('warnung', 'Es werden höchstens ' . self::MAX_FOTOS . ' Fotos auf einmal verarbeitet.');
        }
    }

    /**
     * Wartungsplan anpassen: je Wartungsart modus[<id>] = standard | aus | eigen, bei „eigen“ monate[<id>] und
     * (nur Fahrzeuge) stunden[<id>]. Nicht gesendete Arten bleiben unverändert; bei einem Fehler wird nichts gespeichert.
     *
     * @param array<string,mixed> $objekt
     */
    private function plan(Request $r, string $typ, array $objekt): Response
    {
        $benutzer = $this->auth->benutzer();
        if (!Berechtigung::darfWartungsplanAendern($benutzer, $typ)) {
            throw new HttpFehler(403);
        }
        $objektId = (int) $objekt['id'];
        $modi = self::liste($r->post('modus', []));
        $monate = self::liste($r->post('monate', []));
        $stunden = self::liste($r->post('stunden', []));
        $vorher = $this->ausnahmen->fuerObjekt($typ, $objektId);

        $neu = [];
        $fehler = [];
        foreach ($this->dienst->passendeArten($typ, $objekt) as $art) {
            $artId = (int) $art['id'];
            if (!isset($modi[$artId])) {
                continue;
            }
            $modus = $modi[$artId];
            if (!in_array($modus, AusnahmeRepository::MODI, true)) {
                $fehler[$artId] = 'Bitte eine Einstellung aus der Liste wählen.';
                continue;
            }
            [$m, $h] = [null, null];
            if ($modus === 'eigen') {
                $m = self::intervall($monate[$artId] ?? '', $fehler, $artId);
                $h = $typ === 'fahrzeug' ? self::intervall($stunden[$artId] ?? '', $fehler, $artId) : null;
                if (!isset($fehler[$artId]) && $m === null && $h === null) {
                    $fehler[$artId] = AusnahmeRepository::OHNE_INTERVALL;
                }
            }
            $neu[$artId] = ['art' => $art, 'modus' => $modus, 'monate' => $m, 'stunden' => $h];
        }
        if ($fehler !== []) {
            $planWerte = ['modus' => $modi, 'monate' => $monate, 'stunden' => $stunden];
            return $this->formular($typ, $objekt, 'plan', $this->dienst->blockDaten($typ, $objekt, $benutzer, [], [], $planWerte, $fehler), 422);
        }

        $aenderungen = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($neu as $artId => $n) {
                $this->ausnahmen->speichere($typ, $objektId, $artId, $n['modus'], $n['monate'], $n['stunden']);
                $alt = self::planText($vorher[$artId] ?? null);
                $jetzt = self::planText($n['modus'] === 'standard' ? null : ['aus' => $n['modus'] === 'aus', 'intervall_monate' => $n['monate'], 'intervall_stunden' => $n['stunden']]);
                if ($alt !== $jetzt) {
                    $aenderungen['Wartungsplan ' . $n['art']['name']] = ['alt' => $alt, 'neu' => $jetzt];
                }
            }
            if ($aenderungen !== []) {
                $this->protokoll->schreibe($benutzer?->id, $typ, $objektId, 'geaendert', $aenderungen);
            }
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
        $this->session->meldung('erfolg', 'Der Wartungsplan wurde gespeichert.');
        return Response::redirect(self::objektUrl($typ, $objektId));
    }

    /** @param ?array{aus:bool, intervall_monate:?int, intervall_stunden:?int} $ausnahme */
    private static function planText(?array $ausnahme): string
    {
        if ($ausnahme === null) {
            return 'Standard';
        }
        if ($ausnahme['aus']) {
            return 'abgeschaltet';
        }
        return 'eigenes Intervall: ' . WartungsartValidator::intervallText($ausnahme['intervall_monate'], $ausnahme['intervall_stunden']);
    }

    /** @param array<int,string> $fehler */
    private static function intervall(string $roh, array &$fehler, int $artId): ?int
    {
        $roh = trim($roh);
        if ($roh === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}$/', $roh) !== 1 || (int) $roh < 1 || (int) $roh > self::INTERVALL_MAX) {
            $fehler[$artId] = 'Bitte eine ganze Zahl von 1 bis ' . self::INTERVALL_MAX . ' angeben.';
            return null;
        }
        return (int) $roh;
    }

    /** @return array<int,string> nur ganzzahlige Schlüssel und Textwerte */
    private static function liste(mixed $roh): array
    {
        $liste = [];
        foreach (is_array($roh) ? $roh : [] as $k => $v) {
            if (is_string($v) && preg_match('/^\d{1,10}$/', (string) $k) === 1) {
                $liste[(int) $k] = $v;
            }
        }
        return $liste;
    }

    /**
     * Seite mit dem Formular „Wartung eintragen“ bzw. „Wartungsplan anpassen“ nach Eingabefehlern.
     *
     * @param array<string,mixed> $objekt
     * @param ?array<string,mixed> $block
     */
    private function formular(string $typ, array $objekt, string $teil, ?array $block, int $status): Response
    {
        $name = $typ === 'anlage' ? $objekt['code'] . ' ' . $objekt['name'] : $objekt['inventarnummer'] . ' ' . $objekt['bezeichnung'];
        $html = $this->view->render('wartung/formular', [
            'titel' => ($teil === 'plan' ? 'Wartungsplan anpassen' : 'Wartung eintragen') . ' – ' . $name,
            'objektName' => $name,
            'objektUrl' => self::objektUrl($typ, (int) $objekt['id']),
            'teil' => $teil,
            'block' => $block ?? [],
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return array<string,mixed> */
    private function objekt(string $typ, int $id): array
    {
        $objekt = $id > 0 ? ($typ === 'anlage' ? $this->anlagen->finde($id) : $this->fahrzeuge->finde($id)) : null;
        if ($objekt === null) {
            throw new HttpFehler(404);
        }
        return $objekt;
    }

    private static function objektUrl(string $typ, int $id): string
    {
        return url(($typ === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . $id);
    }

    private static function id(array $p): int
    {
        $id = preg_match('/^\d{1,10}$/', (string) ($p['id'] ?? '')) === 1 ? (int) $p['id'] : 0;
        return $id <= 4294967295 ? $id : 0;
    }
}

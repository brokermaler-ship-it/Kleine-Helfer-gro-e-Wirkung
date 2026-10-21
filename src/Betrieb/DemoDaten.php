<?php

declare(strict_types=1);

namespace Emc\Betrieb;

use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Rolle;
use Emc\Core\App;
use Emc\Core\DomainFehler;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadFehler;
use Emc\Dateien\UploadService;
use Emc\Digital\CvRepository;
use Emc\Digital\DecoderRepository;
use Emc\Digital\FunktionRepository;
use Emc\Digital\FunktionsvorlageRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Personen\BenutzerRepository;
use Emc\Personen\PersonRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturDienst;
use Emc\Reparatur\ReparaturRepository;
use Emc\Wartung\Faelligkeitsrechner;
use Emc\Wartung\WartungRepository;

/**
 * Beispieldaten für die Testumgebung (erfundene Personen, plausible Modellbahn-Fahrzeuge).
 * Nutzt die echten Repositories und den UploadService, damit die Daten wie echte Eingaben gültig sind.
 */
final class DemoDaten
{
    public const PASSWORT = 'Test-EMC78!';
    private const BEHALTEN = ['schema_migration', 'spurweite', 'wartungsart', 'magazin_kategorie'];

    private \PDO $pdo;
    private string $speicherPfad;
    private string $fotoVerzeichnis;

    public function __construct(private App $app)
    {
        $c = $app->container();
        $this->pdo = $c->get('pdo');
        $this->speicherPfad = (string) ($app->config()['speicher_pfad'] ?? dirname(__DIR__, 2) . '/storage');
        $this->fotoVerzeichnis = dirname(__DIR__, 2) . '/tests/fixtures/demo';
    }

    public function erzeuge(bool $zuruecksetzen): void
    {
        if (!$this->app->istTestumgebung()) {
            throw new DomainFehler('Beispieldaten nur in der Testumgebung');
        }
        $adminPasswort = $this->app->config()['testumgebung_admin_passwort'] ?? null;
        if (!is_string($adminPasswort) || mb_strlen($adminPasswort) < BenutzerRepository::MIN_PASSWORT) {
            throw new DomainFehler(
                "Für die Beispieldaten fehlt in der config.php das Passwort des Test-Administrators: "
                . "'testumgebung_admin_passwort' setzen (mindestens " . BenutzerRepository::MIN_PASSWORT . ' Zeichen, nicht öffentlich).'
            );
        }
        if ($zuruecksetzen) {
            $this->leere();
        } elseif ((int) $this->pdo->query('SELECT COUNT(*) FROM person')->fetchColumn() > 0) {
            throw new DomainFehler('Es sind bereits Daten vorhanden. Mit --zuruecksetzen werden sie gelöscht und durch Beispieldaten ersetzt.');
        }

        $personen = $this->personen();
        $this->benutzer($personen, $adminPasswort);

        $c = $this->app->container();
        $fahrzeuge = $c->get(FahrzeugRepository::class);
        $decoder = $c->get(DecoderRepository::class);
        $spur = [];
        foreach ($this->pdo->query('SELECT id, name FROM spurweite')->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $spur[(string) $z['name']] = (int) $z['id'];
        }

        // Carlas eigene Fahrzeuge hat sie selbst angelegt (darf sie als Mitglied bearbeiten); alle anderen gelten als von Bernd (Bearbeiter) angelegt.
        $anleger = [];
        foreach (['carla' => 'mitglied@test.emc78.de', 'bernd' => 'bearbeiter@test.emc78.de'] as $schluessel => $email) {
            $anleger[$schluessel] = (int) $this->pdo->query('SELECT id FROM benutzer WHERE email = ' . $this->pdo->quote($email))->fetchColumn();
        }
        $ids = [];
        foreach ($this->fahrzeugDaten() as $schluessel => $d) {
            $eigner = $d['eigner'] === null ? null : $personen[$d['eigner']];
            $ids[$schluessel] = $fahrzeuge->anlegen([
                'bezeichnung' => $d['bezeichnung'],
                'baureihe' => $d['baureihe'],
                'bahnverwaltung' => $d['bahn'],
                'epoche' => $d['epoche'],
                'gattung' => $d['gattung'],
                'hersteller' => $d['hersteller'],
                'artikelnummer' => $d['artikel'],
                'spurweite_id' => $spur[$d['spur']],
                'stromsystem' => $d['strom'] ?? '2L',
                'eigentuemer_person_id' => $eigner,
                'status' => $d['status'] ?? 'einsatzbereit',
                'betriebsstunden' => $d['stunden'] ?? 0,
                'notiz' => $d['notiz'] ?? null,
            ], $anleger[$d['eigner'] === 'carla' ? 'carla' : 'bernd']);
            foreach ($d['decoder'] as $dec) {
                $dec['id'] = $decoder->anlegen($ids[$schluessel], $dec);
                $ids['decoder:' . $schluessel . ':' . $dec['bezeichnung']] = $dec['id'];
            }
        }

        $this->funktionenUndCvs($ids);
        $this->fotos($ids);
        $anlage = $this->anlageUndWartungen($ids, $personen, $spur['H0']);
        $this->reparaturen($ids, $anlage, $personen);
        $this->etiketten($ids);
    }

    /**
     * Drei Reparaturen relativ zu heute, angelegt wie echte Eingaben über ReparaturDienst::inTransaktion (Objekt gesperrt,
     * danach der automatische Status): eine dringende Meldung von Carla an der BR 01 (Lok dadurch automatisch „defekt“),
     * eine Anlagen-Meldung „W12 Antrieb brummt“ in Arbeit bei Bernd (Anlage automatisch „in Arbeit“) und eine erledigte
     * Reparatur an der V 200 mit zwei Ersatzteilen. Die von Hand „in Wartung“ gestellte BR 64 bleibt unberührt.
     *
     * @param array<string,int> $ids
     * @param array<string,int> $personen
     */
    private function reparaturen(array $ids, int $anlage, array $personen): void
    {
        $c = $this->app->container();
        $dienst = $c->get(ReparaturDienst::class);
        $repo = $c->get(ReparaturRepository::class);
        $protokoll = $c->get(Aenderungsprotokoll::class);
        $konto = fn(string $email): ?int => ((int) $this->pdo->query('SELECT id FROM benutzer WHERE email = ' . $this->pdo->quote($email))->fetchColumn()) ?: null;
        $heute = new \DateTimeImmutable('today');
        $vorTagen = fn(int $tage, string $uhrzeit): string => $heute->modify("-$tage days")->format('Y-m-d') . ' ' . $uhrzeit;

        /**
         * @param array{beschreibung:string, wo_bauteil?:?string, prioritaet:string} $meldung
         * @param ?array<string,mixed> $bearbeitung Felder für aktualisieren() (ohne die aus der Meldung)
         * @param list<array<string,mixed>> $teile
         */
        $lege = function (string $typ, int $objektId, string $email, array $meldung, string $gemeldetAm, ?array $bearbeitung = null, array $teile = [], ?string $abgeschlossenAm = null) use ($dienst, $repo, $protokoll, $konto): void {
            $benutzer = $konto($email);
            $dienst->inTransaktion($typ, $objektId, $benutzer, function () use ($typ, $objektId, $benutzer, $meldung, $gemeldetAm, $bearbeitung, $teile, $abgeschlossenAm, $repo, $protokoll): void {
                $id = $repo->anlegen($typ, $objektId, $meldung, $benutzer);
                $this->pdo->prepare('UPDATE reparatur SET gemeldet_am = ? WHERE id = ?')->execute([$gemeldetAm, $id]);
                $protokoll->schreibe($benutzer, 'reparatur', $id, 'angelegt', Aenderungsprotokoll::diff([], [
                    'beschreibung' => $meldung['beschreibung'],
                    'wo_bauteil' => $meldung['wo_bauteil'] ?? null,
                    'prioritaet' => $meldung['prioritaet'],
                ]));
                if ($bearbeitung !== null) {
                    $stand = (string) $this->pdo->query('SELECT geaendert_am FROM reparatur WHERE id = ' . $id)->fetchColumn();
                    $repo->aktualisieren($id, $bearbeitung + $meldung + ['wo_bauteil' => null, 'zustaendig_person_id' => null, 'termin' => null, 'notizen' => null, 'was_gemacht' => null], $teile, $stand);
                }
                if ($abgeschlossenAm !== null) {
                    $this->pdo->prepare('UPDATE reparatur SET abgeschlossen_am = ? WHERE id = ?')->execute([$abgeschlossenAm, $id]);
                }
            });
        };

        $lege('fahrzeug', $ids['v200'], 'fahrzeugwart@test.emc78.de', [
            'beschreibung' => 'Ruckelt in langsamer Fahrt, Haftreifen hinten abgefahren.', 'prioritaet' => 'normal',
        ], $vorTagen(21, '18:30:00'), [
            'status' => 'erledigt',
            'zustaendig_person_id' => $personen['wart'],
            'was_gemacht' => 'Beide Haftreifen und den Schleifer erneuert, Räder gereinigt. Läuft wieder ruhig.',
        ], [
            ['bezeichnung' => 'Haftreifen', 'artikelnummer' => '40070', 'bezugsquelle' => 'Modellbahnladen', 'kosten' => '4.90', 'bestellt' => 1],
            ['bezeichnung' => 'Schleifer', 'artikelnummer' => '40090', 'bezugsquelle' => 'Modellbahnladen', 'kosten' => '7.50', 'bestellt' => 1],
        ], $vorTagen(10, '20:15:00'));
        $lege('anlage', $anlage, 'fahrzeugwart@test.emc78.de', [
            'beschreibung' => 'Weiche schaltet nur noch zögerlich, Antrieb brummt laut.', 'wo_bauteil' => 'W12 Antrieb brummt', 'prioritaet' => 'normal',
        ], $vorTagen(5, '19:45:00'), [
            'status' => 'in_arbeit',
            'zustaendig_person_id' => $personen['bernd'],
            'termin' => $heute->modify('+7 days')->format('Y-m-d'),
            'notizen' => 'Antrieb ausgebaut, Ersatz wird beim nächsten Clubabend eingebaut.',
        ]);
        $lege('fahrzeug', $ids['br01'], 'mitglied@test.emc78.de', [
            'beschreibung' => 'Lok bleibt auf der Weiche stehen, Motor brummt', 'prioritaet' => 'dringend',
        ], $vorTagen(1, '20:05:00'));
    }

    /**
     * Einige Fahrzeuge gelten als schon beschriftet (vor einem Monat), damit die Auswahl „noch nicht gedruckt“ auf der
     * Seite Etiketten etwas zeigt; wie „Als gedruckt vermerken“, ohne den Bearbeitungsstand zu ändern.
     *
     * @param array<string,int> $ids
     */
    private function etiketten(array $ids): void
    {
        $am = (new \DateTimeImmutable('today'))->modify('-1 month')->format('Y-m-d') . ' 19:00:00';
        $st = $this->pdo->prepare('UPDATE fahrzeug SET etikett_gedruckt_am = ?, geaendert_am = geaendert_am WHERE id = ?');
        foreach (['v200', 'br01', 'br218', 'silberling'] as $schluessel) {
            $st->execute([$am, $ids[$schluessel]]);
        }
    }

    /**
     * Eine Anlage und einige Wartungen relativ zu heute: Gleise der Anlage überfällig, Räder der V 200 in Ordnung,
     * Getriebe der BR 01 bald fällig, alles andere „noch nie erfasst“. Liefert die ID der Anlage. Wartungsarten werden über den Namen gesucht;
     * fehlt eine (umbenannt oder gelöscht – die Stammliste bleibt beim Zurücksetzen erhalten), entfällt nur diese Wartung.
     *
     * @param array<string,int> $ids
     * @param array<string,int> $personen
     */
    private function anlageUndWartungen(array $ids, array $personen, int $h0): int
    {
        $c = $this->app->container();
        $anlage = $c->get(AnlageRepository::class)->anlegen([
            'name' => 'Clubanlage H0',
            'spurweite_id' => $h0,
            'kurzbeschreibung' => 'Zweigleisige Hauptstrecke mit Schattenbahnhof und kleinem Bahnbetriebswerk.',
            'standort' => 'Vereinsheim, Raum 2',
            'status' => 'in_ordnung',
        ]);
        $arten = [];
        foreach ($this->pdo->query('SELECT id, name FROM wartungsart')->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $arten[(string) $z['name']] = (int) $z['id'];
        }
        $konto = fn(string $email): ?int => ((int) $this->pdo->query('SELECT id FROM benutzer WHERE email = ' . $this->pdo->quote($email))->fetchColumn()) ?: null;
        $stand = fn(int $fahrzeugId): float => (float) $this->pdo->query('SELECT betriebsstunden FROM fahrzeug WHERE id = ' . $fahrzeugId)->fetchColumn();
        $heute = new \DateTimeImmutable('today');
        // Monate mit Monatsende-Begrenzung (31.10. − 4 Monate = 30.06., nicht 01.07.; − 11 Monate nicht im Dezember des Vorjahres).
        $wartungen = $c->get(WartungRepository::class);
        foreach ([
            ['anlage', $anlage, 'Gleise reinigen', Faelligkeitsrechner::plusMonate($heute, -4), null, 'bernd', 'bearbeiter@test.emc78.de', 'Mit Reinigungswagen und Isopropanol.'],
            ['fahrzeug', $ids['v200'], 'Räder und Radschleifer reinigen', $heute->modify('-14 days'), $stand($ids['v200']), 'wart', 'fahrzeugwart@test.emc78.de', null],
            ['fahrzeug', $ids['br01'], 'Getriebe und Lager schmieren', Faelligkeitsrechner::plusMonate($heute, -11)->modify('-15 days'), $stand($ids['br01']), 'wart', 'fahrzeugwart@test.emc78.de', 'Etwas Öl an den Achslagern.'],
        ] as [$typ, $objektId, $art, $datum, $stunden, $person, $email, $notiz]) {
            if (!isset($arten[$art])) {
                continue;
            }
            $wartungen->eintragen($typ, $objektId, [
                'datum' => $datum->format('Y-m-d'),
                'betriebsstunden' => $stunden,
                'stundenstand_bezug' => $stunden,
                'ausgefuehrt_von_person_id' => $personen[$person],
                'notiz' => $notiz,
            ], [$arten[$art]], $konto($email));
        }
        return $anlage;
    }

    /**
     * Leert alle Tabellen außer schema_migration und den Stammlisten spurweite und wartungsart (die Wartungsarten des
     * Vereins bleiben, auch wenn er sie geändert hat) sowie storage/dateien. Der Anlagen-Zähler steht in einstellung
     * und beginnt daher wieder bei A1.
     */
    private function leere(): void
    {
        $tabellen = array_map('strval', $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN));
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tabellen as $t) {
                if (!in_array($t, self::BEHALTEN, true)) {
                    $this->pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', $t) . '`');
                }
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $wurzel = rtrim($this->speicherPfad, '/') . '/dateien';
        if (!is_dir($wurzel)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } elseif ($f->getFilename() !== '.gitkeep') {
                @unlink($f->getPathname());
            }
        }
    }

    /** @return array<string,int> Schlüssel => Personen-ID */
    private function personen(): array
    {
        $repo = $this->app->container()->get(PersonRepository::class);
        $liste = [
            'anja' => ['Anja', 'Beispielmann', 'admin@test.emc78.de', 'Test-Administratorin'],
            'bernd' => ['Bernd', 'Probemeier', 'bearbeiter@test.emc78.de', 'Test-Bearbeiter'],
            'carla' => ['Carla', 'Mustermann', 'mitglied@test.emc78.de', 'Test-Mitglied'],
            'wart' => ['Greta', 'Wagner', 'fahrzeugwart@test.emc78.de', 'Test-Fahrzeugwart'],
            'dieter' => ['Dieter', 'Testfeld', null, 'Ohne Zugang'],
            'eva' => ['Eva', 'Musterfrau', null, 'Ohne Zugang'],
            'frank' => ['Frank', 'Demonstrant', null, 'Ohne Zugang'],
        ];
        $ids = [];
        foreach ($liste as $k => [$vor, $nach, $mail, $notiz]) {
            $ids[$k] = $repo->speichern(['vorname' => $vor, 'nachname' => $nach, 'email' => $mail, 'notiz' => $notiz]);
        }
        return $ids;
    }

    /**
     * Mitglied, Fahrzeugwart und Bearbeiter erhalten das öffentliche Testpasswort, der Administrator das aus der config.php.
     *
     * @param array<string,int> $personen
     */
    private function benutzer(array $personen, string $adminPasswort): void
    {
        $repo = $this->app->container()->get(BenutzerRepository::class);
        foreach ([
            ['anja', 'admin@test.emc78.de', Rolle::Admin, $adminPasswort],
            ['bernd', 'bearbeiter@test.emc78.de', Rolle::Bearbeiter, self::PASSWORT],
            ['wart', 'fahrzeugwart@test.emc78.de', Rolle::Fahrzeugwart, self::PASSWORT],
            ['carla', 'mitglied@test.emc78.de', Rolle::Mitglied, self::PASSWORT],
        ] as [$p, $mail, $rolle, $passwort]) {
            $id = $repo->anlegen($personen[$p], $mail, $rolle, $passwort);
            $repo->passwortSetzen($id, $passwort, false);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function fahrzeugDaten(): array
    {
        $esu = fn(string $bez, ?int $adr, string $typ = 'LokSound 5', bool $sound = true): array => [
            'bezeichnung' => $bez, 'hersteller' => 'ESU', 'typ' => $typ, 'firmware' => $sound ? '5.0.0' : null,
            'sound' => $sound, 'protokoll' => 'dcc', 'adresse' => $adr,
        ];
        return [
            'v200' => ['bezeichnung' => 'V 200 060', 'baureihe' => 'V 200', 'bahn' => 'DB', 'epoche' => 'III', 'gattung' => 'lok',
                'hersteller' => 'Roco', 'artikel' => '72900', 'spur' => 'H0', 'eigner' => null, 'stunden' => 42.5,
                'notiz' => 'Vereinslok für den Ausstellungsbetrieb.', 'decoder' => [$esu('Motor und Sound', 60)]],
            'br218' => ['bezeichnung' => 'BR 218 217-8', 'baureihe' => '218', 'bahn' => 'DB', 'epoche' => 'IV', 'gattung' => 'lok',
                'hersteller' => 'Piko', 'artikel' => '57901', 'spur' => 'H0', 'eigner' => 'anja', 'stunden' => 18.0,
                'decoder' => [$esu('Motor und Sound', 218)]],
            'br01' => ['bezeichnung' => 'BR 01 118', 'baureihe' => '01', 'bahn' => 'DB', 'epoche' => 'III', 'gattung' => 'lok',
                'hersteller' => 'Märklin', 'artikel' => '39011', 'spur' => 'H0', 'strom' => '3L', 'eigner' => null, 'stunden' => 120.0,
                'decoder' => [[
                    'bezeichnung' => 'Motor und Sound', 'hersteller' => 'Märklin', 'typ' => 'mSD3', 'firmware' => '3.1.0',
                    'sound' => true, 'protokoll' => 'mfx', 'adresse' => null,
                ]]],
            'vt98' => ['bezeichnung' => 'VT 98 9731', 'baureihe' => 'VT 98', 'bahn' => 'DB', 'epoche' => 'III', 'gattung' => 'triebwagen',
                'hersteller' => 'Fleischmann', 'artikel' => '4440', 'spur' => 'H0', 'eigner' => 'bernd', 'stunden' => 7.5,
                'decoder' => [$esu('Motor', 98, 'LokPilot 5', false)]],
            'e94' => ['bezeichnung' => 'E 94 279', 'baureihe' => 'E 94', 'bahn' => 'DB', 'epoche' => 'III', 'gattung' => 'lok',
                'hersteller' => 'Roco', 'artikel' => '73000', 'spur' => 'H0', 'eigner' => 'carla', 'stunden' => 31.0,
                'notiz' => 'Absichtlich dieselbe Adresse wie die Köf II (Beispiel für die Adresswarnung).',
                'decoder' => [$esu('Motor', 3, 'LokPilot 5', false)]],
            'koef' => ['bezeichnung' => 'Köf II', 'baureihe' => 'Köf II', 'bahn' => 'DB', 'epoche' => 'III', 'gattung' => 'lok',
                'hersteller' => 'Brawa', 'artikel' => '41500', 'spur' => 'H0', 'eigner' => 'dieter', 'stunden' => 55.0,
                'decoder' => [$esu('Motor', 3, 'LokPilot Micro', false)]],
            'silberling' => ['bezeichnung' => 'Silberling ABnrzb 704', 'baureihe' => 'ABnrzb 704', 'bahn' => 'DB', 'epoche' => 'IV', 'gattung' => 'wagen',
                'hersteller' => 'Roco', 'artikel' => '45050', 'spur' => 'H0', 'eigner' => null,
                'decoder' => [[
                    'bezeichnung' => 'Innenbeleuchtung', 'hersteller' => 'Viessmann', 'typ' => 'Licht-Funktionsdecoder', 'firmware' => null,
                    'sound' => false, 'protokoll' => 'dcc', 'adresse' => 2001,
                ], [
                    'bezeichnung' => 'Schlusslicht', 'hersteller' => 'Viessmann', 'typ' => 'Licht-Funktionsdecoder', 'firmware' => null,
                    'sound' => false, 'protokoll' => 'dcc', 'adresse' => 2002,
                ]]],
            'gueterwagen' => ['bezeichnung' => 'Hochbordwagen Eaos 106', 'baureihe' => 'Eaos 106', 'bahn' => 'DB', 'epoche' => 'IV', 'gattung' => 'wagen',
                'hersteller' => 'Roco', 'artikel' => '76020', 'spur' => 'H0', 'eigner' => 'eva', 'decoder' => []],
            'br103' => ['bezeichnung' => 'BR 103 113-7', 'baureihe' => '103', 'bahn' => 'DB', 'epoche' => 'IV', 'gattung' => 'lok',
                'hersteller' => 'Brawa', 'artikel' => '43000', 'spur' => 'H0', 'eigner' => 'dieter', 'status' => 'ausgemustert', 'stunden' => 210.0,
                'notiz' => 'Ausgemustert: Getriebeschaden, Ersatzteile nicht mehr lieferbar.', 'decoder' => [$esu('Motor und Sound', 103)]],
            'br64' => ['bezeichnung' => 'BR 64 419', 'baureihe' => '64', 'bahn' => 'DB', 'epoche' => 'III', 'gattung' => 'lok',
                'hersteller' => 'Minitrix', 'artikel' => '12532', 'spur' => 'N', 'eigner' => null, 'status' => 'in_wartung', 'stunden' => 9.0,
                'notiz' => 'Schleifer wird erneuert. Adresse 3 ist in N nicht doppelt, weil die Adressprüfung je Spurweite gilt.',
                'decoder' => [$esu('Motor', 3, 'LokPilot Nano', false)]],
            'br185' => ['bezeichnung' => 'BR 185 276-4', 'baureihe' => '185.2', 'bahn' => 'DB', 'epoche' => 'VI', 'gattung' => 'lok',
                'hersteller' => 'Minitrix', 'artikel' => '16852', 'spur' => 'N', 'eigner' => 'frank', 'status' => 'ausgeliehen', 'stunden' => 4.0,
                'decoder' => [$esu('Motor', 185, 'LokPilot 5 Nano', false)]],
            'bymb' => ['bezeichnung' => 'Eilzugwagen Bymb 421', 'baureihe' => 'Bymb 421', 'bahn' => 'DB', 'epoche' => 'IV', 'gattung' => 'wagen',
                'hersteller' => 'Fleischmann', 'artikel' => '814401', 'spur' => 'N', 'eigner' => 'carla', 'decoder' => []],
        ];
    }

    /** @param array<string,int> $ids */
    private function funktionenUndCvs(array $ids): void
    {
        $c = $this->app->container();
        $funktionen = $c->get(FunktionRepository::class);
        $vorlagen = $c->get(FunktionsvorlageRepository::class);
        $cvs = $c->get(CvRepository::class);

        $f = fn(int $t, string $b, string $s = 'dauer') => ['taste' => $t, 'beschreibung' => $b, 'schaltart' => $s];
        $v200 = $ids['decoder:v200:Motor und Sound'];
        $funktionen->ersetzen($v200, [
            $f(0, 'Spitzensignal und Schlusslicht'), $f(1, 'Motorgeräusch'), $f(2, 'Signalhorn', 'moment'),
            $f(3, 'Rangiergang'), $f(4, 'Anfahr- und Bremsverzögerung aus'), $f(5, 'Führerstandsbeleuchtung'),
            $f(6, 'Kompressor'), $f(8, 'Druckluft ablassen', 'moment'),
        ]);
        $vorlagen->ausDecoder($v200, 'ESU LokSound 5 – Diesellok', 'LokSound 5');

        $vorlagen->aufDecoderAnwenden((int) $this->pdo->query('SELECT id FROM funktionsvorlage LIMIT 1')->fetchColumn(), $ids['decoder:br218:Motor und Sound']);
        $funktionen->ersetzen($ids['decoder:br01:Motor und Sound'], [
            $f(0, 'Spitzenlicht'), $f(1, 'Dampfgeräusch'), $f(2, 'Pfeife', 'moment'), $f(3, 'Glocke', 'moment'),
            $f(4, 'Kohle schaufeln', 'moment'), $f(5, 'Wasser nachfüllen', 'moment'),
        ]);
        $funktionen->ersetzen($ids['decoder:silberling:Innenbeleuchtung'], [$f(0, 'Innenbeleuchtung')]);
        $funktionen->ersetzen($ids['decoder:vt98:Motor'], [$f(0, 'Spitzenlicht'), $f(1, 'Innenbeleuchtung')]);

        foreach ([
            [$v200, [[1, 60, 'Lokadresse (kurz)'], [2, 3, 'Anfahrspannung'], [3, 25, 'Anfahrverzögerung'], [4, 20, 'Bremsverzögerung'], [5, 180, 'Höchstgeschwindigkeit'], [29, 6, 'Konfiguration, 28/128 Fahrstufen']]],
            [$ids['decoder:br218:Motor und Sound'], [[1, 3, null], [3, 20, 'Anfahrverzögerung'], [4, 15, 'Bremsverzögerung'], [29, 14, 'Lange Adresse aktiv']]],
            [$ids['decoder:vt98:Motor'], [[2, 4, 'Anfahrspannung'], [5, 200, null]]],
        ] as [$decoder, $werte]) {
            foreach ($werte as [$cv, $wert, $kommentar]) {
                $cvs->speichern($decoder, $cv, $wert, $kommentar);
            }
        }
    }

    /** @param array<string,int> $ids */
    private function fotos(array $ids): void
    {
        if (!is_dir($this->fotoVerzeichnis)) {
            return; // z. B. in einem Paket ohne Beispielfotos: Daten ohne Bilder anlegen
        }
        $c = $this->app->container();
        $dokumente = $c->get(DokumentRepository::class);
        $upload = new UploadService(
            $this->pdo,
            $this->speicherPfad,
            max(1, (int) ($this->app->config()['upload_max_mb'] ?? 64)) * 1048576,
            new Bildbearbeitung(),
            static fn(string $tmp): bool => is_file($tmp),
            static fn(string $von, string $nach): bool => copy($von, $nach),
        );
        foreach ([
            ['v200', 'diesel.jpg', 'V 200 060 im Bahnhof'],
            ['br218', 'diesel.jpg', 'BR 218 vor dem Schnellzug'],
            ['br01', 'dampf.jpg', 'BR 01 118 Seitenansicht'],
            ['silberling', 'wagen.jpg', 'Silberling Bauart n'],
            ['vt98', 'wagen.jpg', 'Schienenbus VT 98'],
        ] as [$fahrzeug, $datei, $titel]) {
            $pfad = $this->fotoVerzeichnis . '/' . $datei;
            if (!is_file($pfad)) {
                continue;
            }
            try {
                $id = $upload->speichere(['name' => $datei, 'tmp_name' => $pfad, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pfad)], $titel, 'foto', null);
            } catch (UploadFehler) {
                continue;
            }
            $dokumente->zuordnen($id, 'fahrzeug', $ids[$fahrzeug]);
            $dokumente->setzeTitelbild($ids[$fahrzeug], $id);
        }
    }
}

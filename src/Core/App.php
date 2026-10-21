<?php

declare(strict_types=1);

namespace Emc\Core;

use Emc\Anlagen\AnlageController;
use Emc\Anlagen\AnlageRepository;
use Emc\Auth\Auth;
use Emc\Auth\AuthController;
use Emc\Auth\Berechtigung;
use Emc\Auth\InstallController;
use Emc\Auth\KennwortController;
use Emc\Auth\KennwortVergessen;
use Emc\Betrieb\DemoDaten;
use Emc\Boerse\AngebotController;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\AbrechnungRepository;
use Emc\Boerse\AnfrageController;
use Emc\Boerse\AnfrageDienst;
use Emc\Boerse\AnfrageRepository;
use Emc\Boerse\Angebotsnummer;
use Emc\Boerse\OeffentlichController;
use Emc\Boerse\OeffentlicherBereich;
use Emc\Boerse\Spamschutz;
use Emc\Boerse\BoerseController;
use Emc\Boerse\KasseController;
use Emc\Boerse\KasseDienst;
use Emc\Boerse\VerkaufRepository;
use Emc\Boerse\BoerseDienst;
use Emc\Boerse\BoerseRepository;
use Emc\Betrieb\Einstellungen;
use Emc\Betrieb\Migrator;
use Emc\Betrieb\Sicherung;
use Emc\Betrieb\SicherungController;
use Emc\Betrieb\TestdatenController;
use Emc\Betrieb\UpdateController;
use Emc\Codes\CodeAufloesung;
use Emc\Codes\CodeController;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DateiController;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Dateien\Uploadgrenzen;
use Emc\Digital\AdressenController;
use Emc\Digital\Adresspruefung;
use Emc\Digital\CvController;
use Emc\Digital\CvRepository;
use Emc\Digital\DecoderController;
use Emc\Digital\DecoderRepository;
use Emc\Digital\FunktionController;
use Emc\Digital\FunktionRepository;
use Emc\Digital\FunktionsvorlageRepository;
use Emc\Digital\PdfText;
use Emc\Etiketten\EtikettAuswahl;
use Emc\Etiketten\EtikettController;
use Emc\Fahrzeuge\FahrzeugController;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Magazin\KategorieController;
use Emc\Magazin\MagazinController;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Mail\Mailer;
use Emc\Mail\SmtpMailer;
use Emc\Mail\StreamVerbindung;
use Emc\OneDrive\Auftraege;
use Emc\OneDrive\CurlHttpClient;
use Emc\OneDrive\Graph;
use Emc\OneDrive\HttpClient;
use Emc\OneDrive\OAuth;
use Emc\OneDrive\OneDriveController;
use Emc\OneDrive\TokenSpeicher;
use Emc\OneDrive\Uebertragung;
use Emc\Personen\BenutzerController;
use Emc\Personen\BenutzerRepository;
use Emc\Personen\DsgvoLoeschung;
use Emc\Personen\KontoController;
use Emc\Personen\PersonController;
use Emc\Personen\PersonRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\MeldungController;
use Emc\Reparatur\ReparaturController;
use Emc\Reparatur\ReparaturDienst;
use Emc\Reparatur\ReparaturRepository;
use Emc\Stammdaten\SpurweiteController;
use Emc\Stammdaten\SpurweiteRepository;
use Emc\Start\StartController;
use Emc\Wartung\AusnahmeRepository;
use Emc\Wartung\UebersichtController;
use Emc\Wartung\WartungController;
use Emc\Wartung\WartungDienst;
use Emc\Wartung\WartungRepository;
use Emc\Wartung\WartungsartController;
use Emc\Wartung\WartungsartRepository;
use Emc\Wartung\Wartungsplan;

final class App
{
    private Container $container;
    private Router $router;
    private View $view;
    /**
     * Ausstehende Migrationen der laufenden Anfrage (glob + Abfrage nur einmal je Anfrage statt je Prüfung);
     * handle() setzt den Wert zu Beginn jeder Anfrage zurück. Das Update selbst leitet danach immer weiter.
     *
     * @var ?list<string>
     */
    private ?array $ausstehend = null;
    /** Anzahl neuer Defektmeldungen für den angemeldeten Benutzer, je Anfrage einmal ermittelt (false = noch nicht). */
    private int|false|null $neueDefekte = false;
    /** Anzahl offener Börsenanfragen für Börsenwarte, je Anfrage einmal ermittelt (false = noch nicht). */
    private int|false|null $neueAnfragen = false;
    /** Die laufende Anfrage gehört zum aktiven öffentlichen Bereich: Fehlerseiten im öffentlichen Layout. */
    private bool $oeffentlicheFehler = false;

    /** @param array<string,mixed> $config */
    private function __construct(private array $config, ?Session $session, private string $migrationsVerzeichnis)
    {
        self::setzeZeitzone($config);
        $GLOBALS['emc_basis_url'] = (string) ($config['basis_url'] ?? '');
        $this->container = new Container();
        $this->router = new Router();
        $this->view = new View(dirname(__DIR__, 2) . '/templates');
        $this->container->set('config', fn() => $this->config);
        $this->container->set('router', fn() => $this->router);
        $this->container->set('view', fn() => $this->view);
        $this->container->set('pdo', fn() => Database::verbinde(
            (string) ($this->config['db_dsn'] ?? ''),
            (string) ($this->config['db_benutzer'] ?? ''),
            (string) ($this->config['db_passwort'] ?? '')
        ));
        $this->container->set(Migrator::class, fn(Container $c) => new Migrator(
            $c->get('pdo'),
            $this->migrationsVerzeichnis
        ));
        $this->container->set('session', fn() => $session ?? new Session(
            cookieSecure: (bool) ($this->config['cookie_secure'] ?? true),
            speicherPfad: rtrim($this->speicherPfad(), '/') . '/sitzungen',
            name: Instanz::sitzungsName($this->config),
            cookiePfad: Instanz::cookiePfad($this->config),
            lebensdauer: Instanz::sitzungSekunden($this->config),
        ));
        // Die Sitzung startet erst, wenn ein Token gebraucht wird (öffentliche Fotos und Listen kommen ohne Sitzung aus).
        $this->container->set(Csrf::class, fn(Container $c) => new Csrf(fn() => $c->get('session')));
        $this->container->set(Auth::class, fn(Container $c) => new Auth($c->get('pdo'), $c->get('session'), Instanz::fingerabdruck($this->config)));
        $this->container->set(AuthController::class, fn(Container $c) => new AuthController(
            $this->view,
            $c->get(Auth::class),
            $c->get('session'),
            $c->get(Csrf::class)
        ));
        $this->container->set(InstallController::class, fn(Container $c) => new InstallController(
            $this->view,
            $c->get('pdo'),
            $c->get(Migrator::class),
            $c->get('session')
        ));
        $this->container->set(Einstellungen::class, fn(Container $c) => new Einstellungen($c->get('pdo')));
        $this->container->set(Tresor::class, fn() => new Tresor((string) ($this->config['app_schluessel'] ?? '')));
        $this->container->set(HttpClient::class, fn() => new CurlHttpClient());
        $this->container->set(TokenSpeicher::class, fn(Container $c) => new TokenSpeicher($c->get(Einstellungen::class), $c->get(Tresor::class)));
        $this->container->set(OAuth::class, fn(Container $c) => new OAuth(
            $c->get(HttpClient::class),
            (string) ($this->config['onedrive_client_id'] ?? ''),
            (string) ($this->config['onedrive_client_secret'] ?? ''),
            Uebertragung::rueckrufUrl($this->config) ?? '',
            max(1, (int) ($this->config['onedrive_zeitlimit_s'] ?? 20))
        ));
        $this->container->set(Graph::class, fn(Container $c) => new Graph(
            $c->get(HttpClient::class),
            $c->get(OAuth::class),
            $c->get(TokenSpeicher::class),
            max(1, (int) ($this->config['onedrive_zeitlimit_s'] ?? 20))
        ));
        $this->container->set(Auftraege::class, fn(Container $c) => new Auftraege($c->get('pdo')));
        // null, solange OneDrive nicht (gültig) konfiguriert ist: dann bleibt alles lokal und Tresor/TokenSpeicher werden nie gebaut.
        $this->container->set(Uebertragung::class, function (Container $c): ?Uebertragung {
            if (!Uebertragung::konfiguriert($this->config)) {
                return null;
            }
            // Stehen Migrationen aus, ruht OneDrive: sonst würde z. B. ein Upload gelingen, das Speichern der neuen
            // Spalten aber scheitern, und jeder weitere Versuch hinterließe eine Kopie im OneDrive.
            if ($this->ausstehend() !== []) {
                return null;
            }
            try {
                return new Uebertragung(
                    $c->get(Auftraege::class),
                    $c->get(Graph::class),
                    $c->get(TokenSpeicher::class),
                    $c->get(Einstellungen::class),
                    $c->get(DokumentRepository::class),
                    $c->get('pdo'),
                    $this->speicherPfad(),
                    ($this->config['onedrive_originale_lokal_behalten'] ?? true) !== false,
                    // Je Web-Anfrage nach ~25 s keine neuen Übertragungen mehr beginnen (der Cronjob hebt das auf).
                    max(1.0, (float) ($this->config['onedrive_zeitbudget_s'] ?? Uebertragung::ZEITBUDGET_WEB))
                );
            } catch (\Throwable $t) {
                error_log('OneDrive-Konfiguration unbrauchbar: ' . $t::class);
                return null;
            }
        });
        $this->container->set(Aenderungsprotokoll::class, fn(Container $c) => new Aenderungsprotokoll($c->get('pdo')));
        // null, solange kein Postfach eingerichtet ist: dann gehen Kennwort-Anfragen an die Administratoren.
        $this->container->set(Mailer::class, fn() => SmtpMailer::ausConfig($this->config, new StreamVerbindung()));
        $this->container->set(KennwortVergessen::class, fn(Container $c) => new KennwortVergessen(
            $c->get('pdo'),
            $c->get(Einstellungen::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Mailer::class),
            rtrim(trim((string) ($this->config['oeffentliche_url'] ?? '')), '/') . rtrim((string) ($this->config['basis_url'] ?? ''), '/'),
            $this->istTestumgebung(),
            (string) ($this->config['app_name'] ?? 'EMC 78 Lokschuppen')
        ));
        $this->container->set(KennwortController::class, fn(Container $c) => new KennwortController(
            $this->view,
            $c->get(KennwortVergessen::class),
            $c->get(Auth::class),
            $c->get('session'),
            // Mit echtem SMTP-Versand antwortet „Kennwort vergessen“ nie schneller als nach 3 Sekunden.
            SmtpMailer::konfiguriert($this->config) ? 3.0 : 0.0
        ));
        $this->container->set(PersonRepository::class, fn(Container $c) => new PersonRepository($c->get('pdo')));
        $this->container->set(BenutzerRepository::class, fn(Container $c) => new BenutzerRepository($c->get('pdo')));
        $this->container->set(PersonController::class, fn(Container $c) => new PersonController(
            $this->view,
            $c->get(PersonRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            fn(): DsgvoLoeschung => $c->get(DsgvoLoeschung::class)
        ));
        $this->container->set(BenutzerController::class, fn(Container $c) => new BenutzerController(
            $this->view,
            $c->get(BenutzerRepository::class),
            $c->get(PersonRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            $c->get(KennwortVergessen::class)
        ));
        $this->container->set(DsgvoLoeschung::class, fn(Container $c) => new DsgvoLoeschung(
            $c->get('pdo'),
            $c->get(DokumentRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(FahrzeugRepository::class),
            $c->get(Auftraege::class), // immer: Lösch-Aufträge auch, wenn OneDrive gerade nicht konfiguriert ist
            $c->get(Uebertragung::class),
            $c->get(WartungRepository::class),
            $c->get(AusnahmeRepository::class),
            $c->get(ReparaturRepository::class)
        ));
        $this->container->set(SpurweiteRepository::class, fn(Container $c) => new SpurweiteRepository($c->get('pdo')));
        $this->container->set(SpurweiteController::class, fn(Container $c) => new SpurweiteController(
            $this->view,
            $c->get(SpurweiteRepository::class),
            $c->get('session')
        ));
        $this->container->set(WartungsartRepository::class, fn(Container $c) => new WartungsartRepository($c->get('pdo')));
        $this->container->set(WartungsartController::class, fn(Container $c) => new WartungsartController(
            $this->view,
            $c->get(WartungsartRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(WartungRepository::class, fn(Container $c) => new WartungRepository($c->get('pdo')));
        $this->container->set(AusnahmeRepository::class, fn(Container $c) => new AusnahmeRepository($c->get('pdo')));
        $this->container->set(WartungDienst::class, fn(Container $c) => new WartungDienst(
            $c->get('pdo'),
            $c->get(WartungRepository::class),
            $c->get(AusnahmeRepository::class),
            $c->get(WartungsartRepository::class),
            $c->get(PersonRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(Wartungsplan::class)
        ));
        $this->container->set(Wartungsplan::class, fn(Container $c) => new Wartungsplan(
            $c->get('pdo'),
            $c->get(WartungsartRepository::class),
            $c->get(WartungRepository::class),
            $c->get(AusnahmeRepository::class)
        ));
        $this->container->set(UebersichtController::class, fn(Container $c) => new UebersichtController(
            $this->view,
            $c->get(Wartungsplan::class),
            $c->get(SpurweiteRepository::class)
        ));
        $this->container->set(StartController::class, fn(Container $c) => new StartController(
            $this->view,
            $c->get(Wartungsplan::class),
            $c->get(ReparaturRepository::class)
        ));
        $this->container->set(WartungController::class, fn(Container $c) => new WartungController(
            $this->view,
            $c->get('pdo'),
            $c->get(WartungDienst::class),
            $c->get(WartungRepository::class),
            $c->get(AusnahmeRepository::class),
            $c->get(FahrzeugRepository::class),
            $c->get(AnlageRepository::class),
            $c->get(PersonRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(UploadService::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            $c->get(Uebertragung::class)
        ));
        $this->container->set(FahrzeugRepository::class, fn(Container $c) => new FahrzeugRepository($c->get('pdo')));
        $this->container->set(FahrzeugController::class, fn(Container $c) => new FahrzeugController(
            $this->view,
            $c->get(FahrzeugRepository::class),
            $c->get(SpurweiteRepository::class),
            $c->get(PersonRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            $c->get(DecoderRepository::class),
            $c->get(Adresspruefung::class),
            $c->get(DokumentRepository::class),
            Uebertragung::konfiguriert($this->config),
            $c->get(Uebertragung::class),
            $c->get(WartungDienst::class),
            $c->get(ReparaturDienst::class),
            $c->get(MagazinDienst::class),
            $c->get(MagazinRepository::class)
        ));
        $this->container->set(AnlageRepository::class, fn(Container $c) => new AnlageRepository($c->get('pdo')));
        $this->container->set(ReparaturRepository::class, fn(Container $c) => new ReparaturRepository($c->get('pdo')));
        $this->container->set(ReparaturDienst::class, fn(Container $c) => new ReparaturDienst(
            $c->get('pdo'),
            $c->get(ReparaturRepository::class),
            $c->get(FahrzeugRepository::class),
            $c->get(AnlageRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(Aenderungsprotokoll::class)
        ));
        $this->container->set(MeldungController::class, fn(Container $c) => new MeldungController(
            $this->view,
            $c->get('pdo'),
            $c->get(ReparaturRepository::class),
            $c->get(ReparaturDienst::class),
            $c->get(FahrzeugRepository::class),
            $c->get(AnlageRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(UploadService::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(ReparaturController::class, fn(Container $c) => new ReparaturController(
            $this->view,
            $c->get(ReparaturRepository::class),
            $c->get(ReparaturDienst::class),
            $c->get(FahrzeugRepository::class),
            $c->get(AnlageRepository::class),
            $c->get(PersonRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(UploadService::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(CodeAufloesung::class, fn(Container $c) => new CodeAufloesung(
            $c->get(FahrzeugRepository::class),
            $c->get(AnlageRepository::class),
            $c->get(MagazinRepository::class),
            $c->get(AngebotRepository::class)
        ));
        $this->container->set(MagazinRepository::class, fn(Container $c) => new MagazinRepository($c->get('pdo')));
        $this->container->set(MagazinDienst::class, fn(Container $c) => new MagazinDienst(
            $c->get('pdo'),
            $c->get(MagazinRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(AngebotRepository::class)
        ));
        $this->container->set(AngebotRepository::class, fn(Container $c) => new AngebotRepository($c->get('pdo')));
        $this->container->set(BoerseRepository::class, fn(Container $c) => new BoerseRepository($c->get('pdo')));
        $this->container->set(AngebotDienst::class, fn(Container $c) => new AngebotDienst(
            $c->get('pdo'),
            $c->get(AngebotRepository::class),
            $c->get(MagazinRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(BoerseRepository::class)
        ));
        $this->container->set(BoerseDienst::class, fn(Container $c) => new BoerseDienst(
            $c->get('pdo'),
            $c->get(BoerseRepository::class),
            $c->get(AngebotRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(AbrechnungRepository::class)
        ));
        $this->container->set(AbrechnungRepository::class, fn(Container $c) => new AbrechnungRepository($c->get('pdo')));
        $this->container->set(VerkaufRepository::class, fn(Container $c) => new VerkaufRepository($c->get('pdo')));
        $this->container->set(KasseDienst::class, fn(Container $c) => new KasseDienst(
            $c->get('pdo'),
            $c->get(AngebotRepository::class),
            $c->get(MagazinDienst::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(BoerseRepository::class),
            $c->get(MagazinRepository::class),
            $c->get(VerkaufRepository::class)
        ));
        $this->container->set(KasseController::class, fn(Container $c) => new KasseController(
            $this->view,
            $c->get(BoerseRepository::class),
            $c->get(AngebotRepository::class),
            $c->get(KasseDienst::class),
            $c->get(VerkaufRepository::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(AngebotController::class, fn(Container $c) => new AngebotController(
            $this->view,
            $c->get(AngebotRepository::class),
            $c->get(AngebotDienst::class),
            $c->get(BoerseRepository::class),
            $c->get(MagazinRepository::class),
            $c->get(SpurweiteRepository::class),
            $c->get(PersonRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(UploadService::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(BoerseController::class, fn(Container $c) => new BoerseController(
            $this->view,
            $c->get(BoerseRepository::class),
            $c->get(BoerseDienst::class),
            $c->get(AngebotRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            $this->config,
            $c->get(AbrechnungRepository::class)
        ));
        $this->container->set(AnfrageRepository::class, fn(Container $c) => new AnfrageRepository($c->get('pdo')));
        $this->container->set(Spamschutz::class, fn(Container $c) => new Spamschutz($c->get('pdo'), $c->get(Einstellungen::class)));
        $this->container->set(AnfrageDienst::class, fn(Container $c) => new AnfrageDienst(
            $c->get('pdo'),
            $c->get(AnfrageRepository::class),
            $c->get(Spamschutz::class),
            $c->get(UploadService::class),
            $c->get(DokumentRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(MagazinDienst::class)
        ));
        $this->container->set(AnfrageController::class, fn(Container $c) => new AnfrageController(
            $this->view,
            $c->get(AnfrageRepository::class),
            $c->get(AnfrageDienst::class),
            $c->get(MagazinRepository::class),
            $c->get(SpurweiteRepository::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(OeffentlichController::class, fn(Container $c) => new OeffentlichController(
            $this->view,
            $this->config,
            $c->get(AngebotRepository::class),
            $c->get(BoerseRepository::class),
            $c->get(MagazinRepository::class),
            $c->get(SpurweiteRepository::class),
            $c->get(AnfrageDienst::class),
            $c->get(Spamschutz::class),
            fn() => $c->get('session'),
            $c->get(DokumentRepository::class),
            $c->get(Csrf::class)
        ));
        $this->container->set(MagazinController::class, fn(Container $c) => new MagazinController(
            $this->view,
            $c->get(MagazinRepository::class),
            $c->get(MagazinDienst::class),
            $c->get(SpurweiteRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(UploadService::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(KategorieController::class, fn(Container $c) => new KategorieController(
            $this->view,
            $c->get(MagazinRepository::class),
            $c->get('session')
        ));
        $this->container->set(CodeController::class, fn(Container $c) => new CodeController(
            $this->view,
            $c->get(CodeAufloesung::class)
        ));
        $this->container->set(EtikettController::class, fn(Container $c) => new EtikettController(
            $this->view,
            new EtikettAuswahl($c->get('pdo')),
            $c->get(SpurweiteRepository::class),
            $c->get('session'),
            $this->config
        ));
        $this->container->set(AnlageController::class, fn(Container $c) => new AnlageController(
            $this->view,
            $c->get(AnlageRepository::class),
            $c->get(SpurweiteRepository::class),
            $c->get(DokumentRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            Uebertragung::konfiguriert($this->config),
            $c->get(Uebertragung::class),
            $c->get(WartungDienst::class),
            $c->get(ReparaturDienst::class)
        ));
        $this->container->set(DecoderRepository::class, fn(Container $c) => new DecoderRepository($c->get('pdo')));
        $this->container->set(Adresspruefung::class, fn(Container $c) => new Adresspruefung($c->get('pdo')));
        $this->container->set(DecoderController::class, fn(Container $c) => new DecoderController(
            $this->view,
            $c->get(DecoderRepository::class),
            $c->get(FahrzeugRepository::class),
            $c->get(Adresspruefung::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(FunktionRepository::class, fn(Container $c) => new FunktionRepository($c->get('pdo')));
        $this->container->set(FunktionsvorlageRepository::class, fn(Container $c) => new FunktionsvorlageRepository($c->get('pdo')));
        $this->container->set(CvRepository::class, fn(Container $c) => new CvRepository($c->get('pdo')));
        $this->container->set(FunktionController::class, fn(Container $c) => new FunktionController(
            $this->view,
            $c->get(DecoderRepository::class),
            $c->get(FahrzeugRepository::class),
            $c->get(FunktionRepository::class),
            $c->get(FunktionsvorlageRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            new PdfText(min(PdfText::MAX_BYTES, Uploadgrenzen::ausConfig($this->config)->dateiBytes))
        ));
        $this->container->set(CvController::class, fn(Container $c) => new CvController(
            $this->view,
            $c->get(DecoderRepository::class),
            $c->get(FahrzeugRepository::class),
            $c->get(CvRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(AdressenController::class, fn(Container $c) => new AdressenController(
            $this->view,
            $c->get(Adresspruefung::class),
            $c->get(SpurweiteRepository::class)
        ));
        $this->container->set(DokumentRepository::class, fn(Container $c) => new DokumentRepository(
            $c->get('pdo'),
            $this->speicherPfad(),
            Uebertragung::konfiguriert($this->config) ? $c->get(Auftraege::class) : null
        ));
        $this->container->set(UploadService::class, fn(Container $c) => new UploadService(
            $c->get('pdo'),
            $this->speicherPfad(),
            max(1, (int) ($this->config['upload_max_mb'] ?? 64)) * 1048576,
            new Bildbearbeitung(),
            null,
            null,
            $c->get(Uebertragung::class)
        ));
        $this->container->set(DateiController::class, fn(Container $c) => new DateiController(
            $this->view,
            $c->get(DokumentRepository::class),
            $c->get(UploadService::class),
            $c->get(FahrzeugRepository::class),
            $c->get(AnlageRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            $c->get(Uebertragung::class),
            $c->get(Uebertragung::class) !== null ? $c->get(Graph::class) : null,
            $c->get(WartungRepository::class),
            $c->get(ReparaturRepository::class),
            $c->get(AngebotDienst::class)
        ));
        $this->container->set(Sicherung::class, fn(Container $c) => new Sicherung(
            $c->get('pdo'),
            $this->speicherPfad(),
            $c->get(Einstellungen::class)
        ));
        $this->container->set(SicherungController::class, fn(Container $c) => new SicherungController(
            $this->view,
            $c->get(Sicherung::class),
            $c->get('session')
        ));
        $this->container->set(UpdateController::class, fn(Container $c) => new UpdateController(
            $this->view,
            $c->get(Migrator::class),
            $c->get('session')
        ));
        $this->container->set(OneDriveController::class, fn(Container $c) => new OneDriveController(
            $this->view,
            $c->get('session'),
            $c->get(Einstellungen::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c,
            $this->config
        ));
        $this->container->set(DemoDaten::class, fn() => new DemoDaten($this));
        $this->container->set(TestdatenController::class, fn(Container $c) => new TestdatenController(
            $this->view,
            $c->get(DemoDaten::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(KontoController::class, fn(Container $c) => new KontoController(
            $this->view,
            $c->get(BenutzerRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));

        $routen = dirname(__DIR__) . '/routes.php';
        if (is_file($routen)) {
            (require $routen)($this->router, $this);
        }
    }

    /** Zeitzone aus `zeitzone` der config.php (Standard Europe/Berlin); unbekannte Werte fallen auf den Standard zurück. */
    public static function setzeZeitzone(array $config): void
    {
        $zone = is_string($config['zeitzone'] ?? null) ? trim($config['zeitzone']) : '';
        if ($zone === '' || !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $zone = 'Europe/Berlin';
        }
        date_default_timezone_set($zone);
    }

    /** @param array<string,mixed> $config */
    public static function erzeuge(array $config, ?Session $session = null, ?string $migrationsVerzeichnis = null): self
    {
        return new self($config, $session, $migrationsVerzeichnis ?? dirname(__DIR__, 2) . '/migrations');
    }

    public function handle(Request $request): Response
    {
        $this->ausstehend = null;
        $this->neueDefekte = false;
        $this->neueAnfragen = false;
        $this->oeffentlicheFehler = false;
        try {
            $this->richteViewEin();
            $antwort = $this->durchMiddleware($request);
        } catch (HttpFehler $f) {
            $header = $f->erlaubt !== [] ? ['Allow' => implode(', ', $f->erlaubt)] : [];
            $antwort = $this->fehlerSeite($f->status, null, $header);
        } catch (\Throwable $t) {
            error_log(sprintf('%s: %s in %s:%d', $t::class, $t->getMessage(), $t->getFile(), $t->getLine()));
            $antwort = $this->fehlerSeite(500, !empty($this->config['debug']) ? $t->getMessage() : null);
        }
        return self::gegenFrames($antwort);
    }

    /** Nur ein echtes `true` in der config.php schaltet die Testumgebung ein. */
    public function istTestumgebung(): bool
    {
        return Instanz::istTestumgebung($this->config);
    }

    /** Schutz vor Clickjacking für HTML-Seiten; Dateiauslieferung und eigene CSP bleiben unberührt. */
    private static function gegenFrames(Response $antwort): Response
    {
        if ($antwort->datei !== null || !str_starts_with(strtolower($antwort->headers['Content-Type'] ?? ''), 'text/html')) {
            return $antwort;
        }
        $header = $antwort->headers + [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ];
        return new Response($antwort->body, $antwort->status, $header, null, $antwort->loescheNachSenden);
    }

    /**
     * Schützt einen Handler: ohne die Berechtigung gibt es die 403-Seite.
     *
     * @param callable(Request,array<string,string>):Response $handler
     */
    public function erfordert(string $aktion, callable $handler): callable
    {
        return function (Request $request, array $params = []) use ($aktion, $handler): Response {
            if (!Berechtigung::darf($this->container->get(Auth::class)->benutzer(), $aktion)) {
                return $this->fehlerSeite(403);
            }
            return $handler($request, $params);
        };
    }

    private function richteViewEin(): void
    {
        $c = $this->container;
        $auth = fn() => $c->get(Auth::class);
        $this->view->teile('app_name', $this->config['app_name'] ?? 'EMC 78 Lokschuppen');
        $this->view->teile('testumgebung', $this->istTestumgebung());
        $this->view->teile('uploadGrenzen', Uploadgrenzen::ausConfig($this->config));
        $this->view->teile('csrf', $c->get(Csrf::class));
        $this->view->teile('darf', fn(string $aktion): bool => Berechtigung::darf($auth()->benutzer(), $aktion));
        $this->view->teile('darfFz', fn(array $fahrzeug): bool => Berechtigung::darfFahrzeugBearbeiten($auth()->benutzer(), $fahrzeug));
        // Nur aufgerufen, wenn das Menü für angemeldete Benutzer gezeichnet wird (ein COUNT pro Seite).
        $this->view->teile('abstellgleisAnzahl', fn(): int => $c->get(FahrzeugRepository::class)->anzahlAbgestellt());
        // Badge am Menüpunkt „Reparaturen“; dieselbe Zahl wie in der Hinweisleiste (ein COUNT pro Seite).
        $this->view->teile('neueDefekte', fn(): ?int => $this->neueDefektmeldungen());
        // Badge am Menüpunkt „Börse“ (und „Anfragen“); dieselbe Zahl wie in der Hinweisleiste.
        $this->view->teile('neueAnfragen', fn(): ?int => $this->neueBoersenanfragen());
        $this->view->teileSpaet('benutzer', fn() => $auth()->benutzer());
        $this->view->teileSpaet('betriebshinweise', fn() => $this->betriebshinweise());
        $this->view->teileSpaet('meldungen', fn() => $c->get('session')->meldungenAbholen());
    }

    private function durchMiddleware(Request $request): Response
    {
        $pfad = '/' . trim($request->path(), '/');

        // 1. Kein Schema: zuerst installieren.
        if ($pfad !== '/install' && !$this->container->get(Migrator::class)->istInstalliert()) {
            return Response::redirect(url('/install'));
        }

        // 1b. Öffentlicher Bereich /boerse (ohne Anmeldung, nicht /boersen…): ohne Schalter boerse_oeffentlich und
        //     Datenschutzerklärung antwortet jeder Pfad dort mit 404 – auch POST ohne Token, vor jeder Datenbankarbeit.
        $boerse = OeffentlicherBereich::istPfad($pfad);
        if ($boerse && !OeffentlicherBereich::aktiv($this->config)) {
            return $this->fehlerSeite(404);
        }
        // Ab hier (aktiv): alle Fehlerseiten (404, 405, 419, 500 …) ohne Benutzerdaten, Menü und Meldungen.
        $this->oeffentlicheFehler = $boerse;

        // 2a. Zu große Uploads: PHP verwirft dann $_POST, was sonst wie ein CSRF-Fehler aussähe.
        if ($request->isPost() && $boerse) {
            $zuGross = $this->pruefePostGroesseOeffentlich($request);
            if ($zuGross !== null) {
                return $zuGross;
            }
        } elseif ($request->isPost()) {
            $zuGross = $this->pruefePostGroesse($request);
            if ($zuGross !== null) {
                return $zuGross;
            }
        }

        // 2. CSRF-Schutz für alle POST-Anfragen.
        if ($request->isPost()) {
            $token = $request->post(Csrf::FELD);
            if (!$this->container->get(Csrf::class)->pruefe(is_string($token) ? $token : null)) {
                return $this->fehlerSeite(419);
            }
        }

        // 2c. Öffentlicher Bereich: keine Anmeldung, vor dem Datenbank-Update (Migration 019) die Wartungsseite.
        if ($boerse) {
            if ($this->ausstehend() !== []) {
                return OeffentlichController::oeffentlicheSeite($this->view, $this->config, 'hinweis', [
                    'titel' => 'Wartung',
                    'ueberschrift' => 'Wartung',
                    'text' => 'Die Modellbahnbörse wird gerade aktualisiert. Bitte versuche es in ein paar Minuten noch einmal.',
                ], 503, true, ['Retry-After' => '300']);
            }
            return $this->router->dispatchOderWirf($request);
        }

        $oeffentlich = in_array($pfad, ['/login', '/install', '/kennwort/vergessen', '/kennwort/neu'], true);
        // 2b. „Kennwort vergessen“ braucht die Tabellen aus Migration 010.
        if (str_starts_with($pfad, '/kennwort/') && $this->ausstehend() !== []) {
            return $this->wartungsSeite();
        }
        if (!$oeffentlich) {
            // 3. Anmeldung erforderlich.
            $benutzer = $this->container->get(Auth::class)->benutzer();
            if ($benutzer === null) {
                // Kurzlink aus dem QR-Code eines Preisschilds: ohne Anmeldung zur öffentlichen Angebotsseite, wenn das
                // Angebot dort sichtbar ist; sonst wie bisher zur Anmeldung.
                $angebotsseite = $this->oeffentlicherKurzlink($request, $pfad);
                if ($angebotsseite !== null) {
                    return Response::redirect($angebotsseite);
                }
                $ziel = $request->method() === 'GET' && $pfad !== '/' ? ['ziel' => $pfad] : [];
                return Response::redirect(url('/login', $ziel));
            }
            // 3a. Stehen Migrationen aus, dürfen nur Administratoren weiterarbeiten (Update ausführen).
            if (!in_array($pfad, ['/logout', '/admin/update'], true)
                && !Berechtigung::darf($benutzer, Berechtigung::BETRIEB)
                && $this->ausstehend() !== []
            ) {
                return $this->wartungsSeite();
            }
            // 3a'. Administratoren dürfen vor dem Datenbank-Update lesen, aber nur Update, Sicherung und Abmelden absenden
            //      (sonst scheitern Speichervorgänge an noch fehlenden Spalten mit einem Fehler 500).
            if ($request->isPost()
                && !in_array($pfad, ['/logout', '/admin/update', '/admin/sicherung'], true)
                && Berechtigung::darf($benutzer, Berechtigung::BETRIEB)
                && $this->ausstehend() !== []
            ) {
                $this->container->get('session')->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
                return Response::redirect(url('/admin/update'));
            }
            // 3b. Die OneDrive-Verwaltung erst nach dem Datenbank-Update öffnen.
            if (str_starts_with($pfad, '/admin/onedrive')
                && Berechtigung::darf($benutzer, Berechtigung::BETRIEB)
                && $this->ausstehend() !== []
            ) {
                $this->container->get('session')->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen; bis dahin ruht die OneDrive-Übertragung.');
                return Response::redirect(url('/admin/update'));
            }
            // 3c. Anlagen, Wartungsarten, die Übersicht „Wartung“, das Feld „Code“ (sucht auch Anlagen) sowie Defekt
            //     melden, Reparaturen, Etiketten, das Magazin und die Börse brauchen die Tabellen aus Migration 011–017.
            //     Geprüft wird bewusst „irgendeine Migration steht aus“ (nicht einzeln 011 … 015): Administratoren
            //     sollen ohnehin zuerst das Update ausführen. Nicht-Administratoren sehen schon die Wartungsseite (3a).
            if (self::brauchtNeuesSchema($pfad) && $this->ausstehend() !== []) {
                $this->container->get('session')->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
                return Response::redirect(url('/admin/update'));
            }
            // 4. Erzwungene Passwortänderung.
            if ($benutzer->mussPasswortAendern && $pfad !== '/konto/passwort' && $pfad !== '/logout') {
                return Response::redirect(url('/konto/passwort'));
            }
        }

        return $this->router->dispatchOderWirf($request);
    }

    /**
     * Ziel für /c/<Angebotsnummer> ohne Anmeldung: die öffentliche Angebotsseite, nur wenn der öffentliche Bereich aktiv,
     * die Datenbank aktuell und das Angebot dort sichtbar ist; sonst null (weiter zur Anmeldung).
     */
    private function oeffentlicherKurzlink(Request $request, string $pfad): ?string
    {
        if ($request->method() !== 'GET' || !str_starts_with($pfad, '/c/') || !OeffentlicherBereich::aktiv($this->config)) {
            return null;
        }
        $nr = Angebotsnummer::zerlege(substr($pfad, 3));
        if ($nr === null) {
            return null;
        }
        try {
            if ($this->ausstehend() !== []) {
                return null;
            }
            $angebot = $this->container->get(AngebotRepository::class)->oeffentlichNachNummer($nr['jahr'], $nr['lauf']);
        } catch (\Throwable) {
            return null;
        }
        return $angebot === null ? null : url('/boerse/angebot/' . $angebot['nummer']);
    }

    /** Zu große Anfrage an den öffentlichen Bereich (Fotos): Hinweisseite im öffentlichen Layout statt Weiterleitung. */
    private function pruefePostGroesseOeffentlich(Request $request): ?Response
    {
        $laenge = $request->server('CONTENT_LENGTH');
        if (!is_string($laenge) && !is_int($laenge)) {
            return null;
        }
        $max = isset($this->config['post_max_bytes'])
            ? (int) $this->config['post_max_bytes']
            : UploadService::iniBytes((string) ini_get('post_max_size'));
        if ($max <= 0 || (int) $laenge <= $max) {
            return null;
        }
        return OeffentlichController::oeffentlicheSeite($this->view, $this->config, 'hinweis', [
            'titel' => 'Zu groß',
            'ueberschrift' => 'Die Fotos sind zu groß',
            'text' => 'Die Fotos sind zusammen zu groß (höchstens ' . Uploadgrenzen::mb($max) . ' MB). Bitte weniger oder kleinere Fotos auswählen und das Formular noch einmal ausfüllen.',
            'zurueck' => true,
        ], 413, true);
    }

    /**
     * Fehlerseite im öffentlichen Layout (kein Name, kein Menü, keine Meldungen verbraucht, no-store, noindex, ohne
     * technische Details – auch nicht mit debug).
     *
     * @param array<string,string> $header
     */
    private function oeffentlicheFehlerSeite(int $status, array $header): Response
    {
        try {
            if ($status === 404) {
                return OeffentlichController::oeffentlicheSeite($this->view, $this->config, 'nicht_gefunden', ['titel' => 'Nicht gefunden'], 404, true, $header);
            }
            [$ueberschrift, $text] = match ($status) {
                419 => ['Bitte neu laden', 'Die Seite war zu lange offen. Bitte lade sie neu und sende das Formular noch einmal.'],
                405 => ['Nicht möglich', 'Diese Aktion gibt es hier nicht.'],
                503 => ['Wartung', 'Die Modellbahnbörse ist gerade nicht erreichbar. Bitte versuche es in ein paar Minuten noch einmal.'],
                default => ['Fehler', 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später noch einmal.'],
            };
            return OeffentlichController::oeffentlicheSeite($this->view, $this->config, 'hinweis', [
                'titel' => $ueberschrift, 'ueberschrift' => $ueberschrift, 'text' => $text,
            ], $status, true, $header);
        } catch (\Throwable) {
            return new Response('Es ist ein technischer Fehler aufgetreten.', $status, $header + ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
    }

    /** Pfade, die vor dem Datenbank-Update zu /admin/update führen (siehe 3c in handle()). */
    private static function brauchtNeuesSchema(string $pfad): bool
    {
        foreach (['/anlagen', '/wartung', '/admin/wartungsarten', '/code', '/defekt', '/meine-meldungen', '/reparaturen', '/etiketten', '/magazin', '/admin/magazin-kategorien', '/angebote', '/boersen', '/kasse', '/verkaeufe', '/anfragen', '/c'] as $p) {
            if ($pfad === $p || str_starts_with($pfad, $p . '/')) {
                return true;
            }
        }
        // Kurzlink /c/<Code>, „Defekt melden“ an Fahrzeug oder Anlage (…/defekt) und die Übergabe ans Magazin.
        return str_starts_with($pfad, '/c/') || str_ends_with($pfad, '/defekt') || (str_starts_with($pfad, '/fahrzeuge/') && str_ends_with($pfad, '/an-magazin'));
    }

    /** @return list<string> ausstehende Migrationen, je Anfrage einmal ermittelt */
    private function ausstehend(): array
    {
        return $this->ausstehend ??= $this->container->get(Migrator::class)->ausstehend();
    }

    private function wartungsSeite(): Response
    {
        $html = $this->view->render('fehler/wartung', ['titel' => 'Wartung']);
        return new Response($html, 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '300']);
    }

    /**
     * Hinweisleisten: neue Defektmeldungen (ab Fahrzeugwart), neue Börsenanfragen (Börsenwart), für Administratoren Kennwort-Anfragen, Update ausstehend,
     * Sicherung überfällig und OneDrive.
     *
     * @return list<array{typ:string,text:string,link:string,linktext:string}>
     */
    private function betriebshinweise(): array
    {
        $hinweise = [];
        try {
            $benutzer = $this->container->get(Auth::class)->benutzer();
            if (Berechtigung::darf($benutzer, Berechtigung::BENUTZER_VERWALTEN) && $this->ausstehend() === []) {
                $n = $this->container->get(KennwortVergessen::class)->anzahlOffen();
                if ($n > 0) {
                    $hinweise[] = ['typ' => 'info', 'text' => ($n === 1 ? '1 Anfrage' : $n . ' Anfragen') . ' auf neues Kennwort', 'link' => '/admin/benutzer', 'linktext' => 'Anfragen ansehen'];
                }
            }
        } catch (\Throwable) {
            // Hinweise sind nur Beiwerk.
        }
        $n = $this->neueDefektmeldungen();
        if ($n !== null && $n > 0) {
            $hinweise[] = ['typ' => 'warning', 'text' => $n === 1 ? '1 neue Defektmeldung' : $n . ' neue Defektmeldungen', 'link' => $this->linkNeueDefekte(), 'linktext' => 'Ansehen'];
        }
        $n = $this->neueBoersenanfragen();
        if ($n !== null && $n > 0) {
            $hinweise[] = ['typ' => 'info', 'text' => $n === 1 ? '1 neue Börsenanfrage' : $n . ' neue Börsenanfragen', 'link' => '/anfragen?status=offen', 'linktext' => 'Ansehen'];
        }
        try {
            if (!Berechtigung::darf($this->container->get(Auth::class)->benutzer(), Berechtigung::BETRIEB)) {
                return $hinweise;
            }
            if ($this->ausstehend() !== []) {
                $hinweise[] = ['typ' => 'warning', 'text' => 'Es steht ein Datenbank-Update an.', 'link' => '/admin/update', 'linktext' => 'Jetzt aktualisieren'];
            }
            if ($this->container->get(Sicherung::class)->istUeberfaellig(new \DateTimeImmutable())) {
                $hinweise[] = ['typ' => 'warning', 'text' => 'Die letzte Datensicherung ist älter als 30 Tage.', 'link' => '/admin/sicherung', 'linktext' => 'Sicherung erstellen'];
            }
            if (Uebertragung::konfiguriert($this->config) && $this->container->get(Uebertragung::class) !== null) {
                $odStatus = $this->container->get(TokenSpeicher::class)->status();
                if ($odStatus === TokenSpeicher::STATUS_ABGELAUFEN) {
                    $hinweise[] = ['typ' => 'warning', 'text' => 'Die OneDrive-Verbindung ist abgelaufen. Neue Dateien werden lokal gesammelt – bitte unter Verwaltung › OneDrive neu verbinden.', 'link' => '/admin/onedrive', 'linktext' => 'OneDrive öffnen'];
                } else {
                    $n = $this->container->get(Auftraege::class)->wartenLaengerAls(60);
                    if ($n > 0) {
                        $hinweise[] = ['typ' => 'warning', 'text' => ($n === 1 ? '1 OneDrive-Auftrag wartet' : $n . ' OneDrive-Aufträge warten') . ' seit über einer Stunde. ' . ($odStatus === TokenSpeicher::STATUS_VERBUNDEN ? 'Ohne Cronjob bitte unter Verwaltung › OneDrive „Jetzt übertragen“ klicken.' : 'OneDrive ist getrennt – bitte unter Verwaltung › OneDrive verbinden.'), 'link' => '/admin/onedrive', 'linktext' => 'OneDrive öffnen'];
                    }
                }
            }
        } catch (\Throwable) {
            // Hinweise sind nur Beiwerk; bei Datenbankproblemen nicht die ganze Seite verhindern.
        }
        return $hinweise;
    }

    /**
     * Neue Defektmeldungen (Status „gemeldet“, von niemandem mit Recht geöffnet, nicht selbst gemeldet) für Benutzer, die
     * die Defekthinweise für Fahrzeuge bzw. Anlagen sehen; null für alle anderen, vor dem Datenbank-Update und bei
     * Datenbankfehlern. Je Anfrage einmal abgefragt (Hinweisleiste und Badge im Menü teilen sich das Ergebnis).
     */
    private function neueDefektmeldungen(): ?int
    {
        if ($this->neueDefekte !== false) {
            return $this->neueDefekte;
        }
        $this->neueDefekte = null;
        try {
            $benutzer = $this->container->get(Auth::class)->benutzer();
            $fahrzeuge = Berechtigung::siehtDefekthinweise($benutzer, 'fahrzeug');
            $anlagen = Berechtigung::siehtDefekthinweise($benutzer, 'anlage');
            if ($benutzer !== null && ($fahrzeuge || $anlagen) && $this->ausstehend() === []) {
                $this->neueDefekte = $this->container->get(ReparaturRepository::class)->anzahlNeu($fahrzeuge, $anlagen, $benutzer->id);
            }
        } catch (\Throwable) {
            // Hinweise sind nur Beiwerk (z. B. Tabellen aus Migration 014 fehlen trotz eingetragener Version).
        }
        return $this->neueDefekte;
    }

    /**
     * Offene Börsenanfragen (Status „offen“) für Börsenwarte; null für alle anderen, vor dem Datenbank-Update und bei
     * Datenbankfehlern. Je Anfrage einmal abgefragt (Hinweisleiste und Badge im Menü teilen sich das Ergebnis).
     */
    private function neueBoersenanfragen(): ?int
    {
        if ($this->neueAnfragen !== false) {
            return $this->neueAnfragen;
        }
        $this->neueAnfragen = null;
        try {
            if (Berechtigung::istBoersenwart($this->container->get(Auth::class)->benutzer()) && $this->ausstehend() === []) {
                $this->neueAnfragen = $this->container->get(AnfrageRepository::class)->anzahlOffen();
            }
        } catch (\Throwable) {
            // Hinweise sind nur Beiwerk (z. B. Tabelle aus Migration 019 fehlt trotz eingetragener Version).
        }
        return $this->neueAnfragen;
    }

    /** Liste der gemeldeten Reparaturen; wer nur einen Objekttyp sieht, bekommt den Filter dazu (Zahl und Liste passen). */
    private function linkNeueDefekte(): string
    {
        $link = '/reparaturen?status=gemeldet';
        try {
            $benutzer = $this->container->get(Auth::class)->benutzer();
            $fahrzeuge = Berechtigung::siehtDefekthinweise($benutzer, 'fahrzeug');
            $anlagen = Berechtigung::siehtDefekthinweise($benutzer, 'anlage');
            if ($fahrzeuge !== $anlagen) {
                $link .= '&typ=' . ($fahrzeuge ? 'fahrzeug' : 'anlage');
            }
        } catch (\Throwable) {
            // ohne Filter
        }
        return $link;
    }

    private function speicherPfad(): string
    {
        return (string) ($this->config['speicher_pfad'] ?? dirname(__DIR__, 2) . '/storage');
    }

    /** Liefert die Weiterleitung mit Fehlermeldung, wenn der Request größer als post_max_size ist. */
    private function pruefePostGroesse(Request $request): ?Response
    {
        $laenge = $request->server('CONTENT_LENGTH');
        if (!is_string($laenge) && !is_int($laenge)) {
            return null;
        }
        $laenge = (int) $laenge;
        $max = isset($this->config['post_max_bytes'])
            ? (int) $this->config['post_max_bytes']
            : UploadService::iniBytes((string) ini_get('post_max_size'));
        if ($max <= 0 || $laenge <= $max) {
            return null;
        }
        // Fotoformulare (Defekt melden, Fotos an einer Reparatur): PHP hat alle Felder verworfen, also zurück zum
        // Formular mit einem Hinweis, was zu tun ist. Ziel nur aus den Ziffern der ID gebaut.
        if (preg_match('~^/(fahrzeuge|anlagen)/(\d{1,10})/defekt$~', $request->path(), $m) === 1
            || preg_match('~^/(reparaturen|magazin|angebote)/(\d{1,10})/fotos$~', $request->path(), $m) === 1
        ) {
            $this->container->get('session')->meldung('fehler', 'Die Fotos sind zusammen zu groß (höchstens ' . Uploadgrenzen::mb($max)
                . ' MB). Bitte weniger oder kleinere Fotos auswählen und den Text noch einmal eingeben.');
            return Response::redirect(url('/' . $m[1] . '/' . $m[2] . match ($m[1]) {
                'reparaturen' => '',
                'magazin', 'angebote' => '#fotos',
                default => '/defekt',
            }));
        }
        $mb = min(max(1, (int) ($this->config['upload_max_mb'] ?? 64)), max(1, intdiv($max, 1048576)));
        $this->container->get('session')->meldung('fehler', 'Die Datei ist zu groß (höchstens ' . $mb . ' MB).');
        return Response::redirect($this->gleicheHerkunft($request) ?? url('/'));
    }

    /** Relativer Pfad des Referers, nur wenn er vom selben Host stammt. */
    private function gleicheHerkunft(Request $request): ?string
    {
        $referer = $request->server('HTTP_REFERER');
        $host = $request->server('HTTP_HOST');
        if (!is_string($referer) || !is_string($host) || $host === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $referer) === 1) {
            return null;
        }
        $teile = parse_url($referer);
        if ($teile === false || !isset($teile['host']) || !in_array($teile['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        $refHost = strtolower($teile['host']) . (isset($teile['port']) ? ':' . $teile['port'] : '');
        if ($refHost !== strtolower($host)) {
            return null;
        }
        $pfad = $teile['path'] ?? '/';
        if ($pfad === '' || $pfad[0] !== '/' || str_starts_with($pfad, '//')) {
            return null;
        }
        return $pfad . (isset($teile['query']) ? '?' . $teile['query'] : '');
    }

    public function container(): Container
    {
        return $this->container;
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function view(): View
    {
        return $this->view;
    }

    /** @param array<string,string> $header */
    private function fehlerSeite(int $status, ?string $detail = null, array $header = []): Response
    {
        if ($this->oeffentlicheFehler) {
            return $this->oeffentlicheFehlerSeite($status, $header);
        }
        try {
            $html = $this->view->render('fehler/' . $status, [
                'titel' => 'Fehler',
                'app_name' => $this->config['app_name'] ?? 'EMC 78 Lokschuppen',
                'detail' => $detail,
            ]);
        } catch (\Throwable $t) {
            $html = 'Es ist ein technischer Fehler aufgetreten.';
        }
        return new Response($html, $status, $header + ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

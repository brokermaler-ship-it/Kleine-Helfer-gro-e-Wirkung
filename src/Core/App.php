<?php

declare(strict_types=1);

namespace Emc\Core;

use Emc\Auth\Auth;
use Emc\Auth\AuthController;
use Emc\Auth\Berechtigung;
use Emc\Auth\InstallController;
use Emc\Betrieb\DemoDaten;
use Emc\Betrieb\Einstellungen;
use Emc\Betrieb\Migrator;
use Emc\Betrieb\Sicherung;
use Emc\Betrieb\SicherungController;
use Emc\Betrieb\TestdatenController;
use Emc\Betrieb\UpdateController;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DateiController;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Digital\AdressenController;
use Emc\Digital\Adresspruefung;
use Emc\Digital\CvController;
use Emc\Digital\CvRepository;
use Emc\Digital\DecoderController;
use Emc\Digital\DecoderRepository;
use Emc\Digital\FunktionController;
use Emc\Digital\FunktionRepository;
use Emc\Digital\FunktionsvorlageRepository;
use Emc\Fahrzeuge\FahrzeugController;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\CurlHttpClient;
use Emc\OneDrive\Graph;
use Emc\OneDrive\HttpClient;
use Emc\OneDrive\Auftraege;
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
use Emc\Stammdaten\SpurweiteController;
use Emc\Stammdaten\SpurweiteRepository;
use Emc\Protokoll\Aenderungsprotokoll;

final class App
{
    private Container $container;
    private Router $router;
    private View $view;

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
        $this->container->set(Csrf::class, fn(Container $c) => new Csrf($c->get('session')));
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
            if ($c->get(Migrator::class)->ausstehend() !== []) {
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
        $this->container->set(PersonRepository::class, fn(Container $c) => new PersonRepository($c->get('pdo')));
        $this->container->set(BenutzerRepository::class, fn(Container $c) => new BenutzerRepository($c->get('pdo')));
        $this->container->set(PersonController::class, fn(Container $c) => new PersonController(
            $this->view,
            $c->get(PersonRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(BenutzerController::class, fn(Container $c) => new BenutzerController(
            $this->view,
            $c->get(BenutzerRepository::class),
            $c->get(PersonRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session')
        ));
        $this->container->set(DsgvoLoeschung::class, fn(Container $c) => new DsgvoLoeschung(
            $c->get('pdo'),
            $c->get(DokumentRepository::class),
            $c->get(Aenderungsprotokoll::class),
            $c->get(FahrzeugRepository::class),
            $c->get(Uebertragung::class)
        ));
        $this->container->set(SpurweiteRepository::class, fn(Container $c) => new SpurweiteRepository($c->get('pdo')));
        $this->container->set(SpurweiteController::class, fn(Container $c) => new SpurweiteController(
            $this->view,
            $c->get(SpurweiteRepository::class),
            $c->get('session')
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
            $c->get(Uebertragung::class)
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
            $c->get('session')
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
            $c->get(Aenderungsprotokoll::class),
            $c->get(Auth::class),
            $c->get('session'),
            $c->get(Uebertragung::class),
            $c->get(Uebertragung::class) !== null ? $c->get(Graph::class) : null
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
        $this->view->teile('csrf', $c->get(Csrf::class));
        $this->view->teile('darf', fn(string $aktion): bool => Berechtigung::darf($auth()->benutzer(), $aktion));
        $this->view->teile('darfFz', fn(array $fahrzeug): bool => Berechtigung::darfFahrzeugBearbeiten($auth()->benutzer(), $fahrzeug));
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

        // 2a. Zu große Uploads: PHP verwirft dann $_POST, was sonst wie ein CSRF-Fehler aussähe.
        if ($request->isPost()) {
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

        $oeffentlich = $pfad === '/login' || $pfad === '/install';
        if (!$oeffentlich) {
            // 3. Anmeldung erforderlich.
            $benutzer = $this->container->get(Auth::class)->benutzer();
            if ($benutzer === null) {
                $ziel = $request->method() === 'GET' && $pfad !== '/' ? ['ziel' => $pfad] : [];
                return Response::redirect(url('/login', $ziel));
            }
            // 3a. Stehen Migrationen aus, dürfen nur Administratoren weiterarbeiten (Update ausführen).
            if (!in_array($pfad, ['/logout', '/admin/update'], true)
                && !Berechtigung::darf($benutzer, Berechtigung::BETRIEB)
                && $this->container->get(Migrator::class)->ausstehend() !== []
            ) {
                return $this->wartungsSeite();
            }
            // 3b. Die OneDrive-Verwaltung erst nach dem Datenbank-Update öffnen.
            if (str_starts_with($pfad, '/admin/onedrive')
                && Berechtigung::darf($benutzer, Berechtigung::BETRIEB)
                && $this->container->get(Migrator::class)->ausstehend() !== []
            ) {
                $this->container->get('session')->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen; bis dahin ruht die OneDrive-Übertragung.');
                return Response::redirect(url('/admin/update'));
            }
            // 4. Erzwungene Passwortänderung.
            if ($benutzer->mussPasswortAendern && $pfad !== '/konto/passwort' && $pfad !== '/logout') {
                return Response::redirect(url('/konto/passwort'));
            }
        }

        return $this->router->dispatchOderWirf($request);
    }

    private function wartungsSeite(): Response
    {
        $html = $this->view->render('fehler/wartung', ['titel' => 'Wartung']);
        return new Response($html, 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '300']);
    }

    /**
     * Hinweisleisten für Administratoren (Update ausstehend, Sicherung überfällig).
     *
     * @return list<array{typ:string,text:string,link:string,linktext:string}>
     */
    private function betriebshinweise(): array
    {
        $hinweise = [];
        try {
            if (!Berechtigung::darf($this->container->get(Auth::class)->benutzer(), Berechtigung::BETRIEB)) {
                return [];
            }
            if ($this->container->get(Migrator::class)->ausstehend() !== []) {
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

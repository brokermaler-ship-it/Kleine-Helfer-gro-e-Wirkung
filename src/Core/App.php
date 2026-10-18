<?php

declare(strict_types=1);

namespace Emc\Core;

use Emc\Auth\Auth;
use Emc\Auth\AuthController;
use Emc\Auth\Berechtigung;
use Emc\Auth\InstallController;
use Emc\Betrieb\Einstellungen;
use Emc\Betrieb\Migrator;
use Emc\Digital\AdressenController;
use Emc\Digital\Adresspruefung;
use Emc\Digital\DecoderController;
use Emc\Digital\DecoderRepository;
use Emc\Fahrzeuge\FahrzeugController;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Personen\BenutzerController;
use Emc\Personen\BenutzerRepository;
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
        $this->container->set('session', fn() => $session ?? new Session(cookieSecure: (bool) ($this->config['cookie_secure'] ?? true)));
        $this->container->set(Csrf::class, fn(Container $c) => new Csrf($c->get('session')));
        $this->container->set(Auth::class, fn(Container $c) => new Auth($c->get('pdo'), $c->get('session')));
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
            $c->get(Adresspruefung::class)
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
        $this->container->set(AdressenController::class, fn(Container $c) => new AdressenController(
            $this->view,
            $c->get(Adresspruefung::class),
            $c->get(SpurweiteRepository::class)
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

    /** @param array<string,mixed> $config */
    public static function erzeuge(array $config, ?Session $session = null, ?string $migrationsVerzeichnis = null): self
    {
        return new self($config, $session, $migrationsVerzeichnis ?? dirname(__DIR__, 2) . '/migrations');
    }

    public function handle(Request $request): Response
    {
        try {
            $this->richteViewEin();
            return $this->durchMiddleware($request);
        } catch (HttpFehler $f) {
            $header = $f->erlaubt !== [] ? ['Allow' => implode(', ', $f->erlaubt)] : [];
            return $this->fehlerSeite($f->status, null, $header);
        } catch (\Throwable $t) {
            error_log(sprintf('%s: %s in %s:%d', $t::class, $t->getMessage(), $t->getFile(), $t->getLine()));
            return $this->fehlerSeite(500, !empty($this->config['debug']) ? $t->getMessage() : null);
        }
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
        $this->view->teile('app_name', $this->config['app_name'] ?? '');
        $this->view->teile('csrf', $c->get(Csrf::class));
        $this->view->teile('darf', fn(string $aktion): bool => Berechtigung::darf($auth()->benutzer(), $aktion));
        $this->view->teileSpaet('benutzer', fn() => $auth()->benutzer());
        $this->view->teileSpaet('meldungen', fn() => $c->get('session')->meldungenAbholen());
    }

    private function durchMiddleware(Request $request): Response
    {
        $pfad = '/' . trim($request->path(), '/');

        // 1. Kein Schema: zuerst installieren.
        if ($pfad !== '/install' && !$this->container->get(Migrator::class)->istInstalliert()) {
            return Response::redirect(url('/install'));
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
            // 4. Erzwungene Passwortänderung.
            if ($benutzer->mussPasswortAendern && $pfad !== '/konto/passwort' && $pfad !== '/logout') {
                return Response::redirect(url('/konto/passwort'));
            }
        }

        return $this->router->dispatchOderWirf($request);
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
                'app_name' => $this->config['app_name'] ?? '',
                'detail' => $detail,
            ]);
        } catch (\Throwable $t) {
            $html = 'Es ist ein technischer Fehler aufgetreten.';
        }
        return new Response($html, $status, $header + ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

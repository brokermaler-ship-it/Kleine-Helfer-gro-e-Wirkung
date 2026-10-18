<?php

declare(strict_types=1);

use Emc\Auth\AuthController;
use Emc\Auth\InstallController;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Router;
use Emc\Personen\BenutzerController;
use Emc\Personen\KontoController;
use Emc\Personen\PersonController;

/** Routendefinitionen; spätere Tasks ergänzen hier. */
return function (Router $router, App $app): void {
    $c = $app->container();

    $router->add('GET', '/', fn() => Response::redirect(url('/fahrzeuge')));

    $router->add('GET', '/login', fn(Request $r) => $c->get(AuthController::class)->loginForm($r));
    $router->add('POST', '/login', fn(Request $r) => $c->get(AuthController::class)->login($r));
    $router->add('POST', '/logout', fn(Request $r) => $c->get(AuthController::class)->logout($r));

    $router->add('GET', '/install', fn(Request $r) => $c->get(InstallController::class)->formular($r));
    $router->add('POST', '/install', fn(Request $r) => $c->get(InstallController::class)->installiere($r));

    $person = fn(string $m) => fn(Request $r, array $p = []) => $c->get(PersonController::class)->$m($r, $p);
    $router->add('GET', '/personen', $app->erfordert('person_bearbeiten', $person('liste')));
    $router->add('GET', '/personen/neu', $app->erfordert('person_bearbeiten', $person('neu')));
    $router->add('POST', '/personen', $app->erfordert('person_bearbeiten', $person('anlegen')));
    $router->add('GET', '/personen/{id}/bearbeiten', $app->erfordert('person_bearbeiten', $person('bearbeiten')));
    $router->add('POST', '/personen/{id}', $app->erfordert('person_bearbeiten', $person('aendern')));
    $router->add('POST', '/personen/{id}/loeschen', $app->erfordert('person_bearbeiten', $person('loeschen')));

    $benutzer = fn(string $m) => fn(Request $r, array $p = []) => $c->get(BenutzerController::class)->$m($r, $p);
    $router->add('GET', '/admin/benutzer', $app->erfordert('benutzer_verwalten', $benutzer('liste')));
    $router->add('GET', '/admin/benutzer/neu', $app->erfordert('benutzer_verwalten', $benutzer('neu')));
    $router->add('POST', '/admin/benutzer', $app->erfordert('benutzer_verwalten', $benutzer('anlegen')));
    $router->add('GET', '/admin/benutzer/{id}/bearbeiten', $app->erfordert('benutzer_verwalten', $benutzer('bearbeiten')));
    $router->add('POST', '/admin/benutzer/{id}', $app->erfordert('benutzer_verwalten', $benutzer('aendern')));
    $router->add('POST', '/admin/benutzer/{id}/passwort', $app->erfordert('benutzer_verwalten', $benutzer('passwortZuruecksetzen')));
    $router->add('POST', '/admin/benutzer/{id}/loeschen', $app->erfordert('benutzer_verwalten', $benutzer('loeschen')));

    $router->add('GET', '/konto/passwort', fn(Request $r) => $c->get(KontoController::class)->passwortForm($r));
    $router->add('POST', '/konto/passwort', fn(Request $r) => $c->get(KontoController::class)->passwortAendern($r));
};

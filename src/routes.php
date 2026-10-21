<?php

declare(strict_types=1);

use Emc\Anlagen\AnlageController;
use Emc\Auth\AuthController;
use Emc\Auth\InstallController;
use Emc\Auth\KennwortController;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Router;
use Emc\Betrieb\SicherungController;
use Emc\Betrieb\TestdatenController;
use Emc\Betrieb\UpdateController;
use Emc\Dateien\DateiController;
use Emc\Digital\AdressenController;
use Emc\Digital\CvController;
use Emc\Digital\DecoderController;
use Emc\Digital\FunktionController;
use Emc\Fahrzeuge\FahrzeugController;
use Emc\OneDrive\OneDriveController;
use Emc\Personen\BenutzerController;
use Emc\Personen\KontoController;
use Emc\Personen\PersonController;
use Emc\Stammdaten\SpurweiteController;

/**
 * Routendefinitionen.
 *
 * Fahrzeugbezogene Schreibrouten verlangen hier nur 'lesen' bzw. 'fahrzeug_anlegen'; ob der Benutzer das
 * konkrete Fahrzeug bearbeiten darf (Berechtigung::darfFahrzeugBearbeiten), prüfen die Controller je Objekt (403).
 * Nur das Löschen eines Fahrzeugs verlangt generell 'fahrzeug_bearbeiten'.
 */
return function (Router $router, App $app): void {
    $c = $app->container();

    $router->add('GET', '/', fn() => Response::redirect(url('/fahrzeuge')));

    $router->add('GET', '/login', fn(Request $r) => $c->get(AuthController::class)->loginForm($r));
    $router->add('POST', '/login', fn(Request $r) => $c->get(AuthController::class)->login($r));
    $router->add('POST', '/logout', fn(Request $r) => $c->get(AuthController::class)->logout($r));

    $kw = fn(string $m) => fn(Request $r, array $p = []) => $c->get(KennwortController::class)->$m($r);
    $router->add('GET', '/kennwort/vergessen', $kw('vergessenForm'));
    $router->add('POST', '/kennwort/vergessen', $kw('vergessen'));
    $router->add('GET', '/kennwort/neu', $kw('neuForm'));
    $router->add('POST', '/kennwort/neu', $kw('neu'));

    $router->add('GET', '/install', fn(Request $r) => $c->get(InstallController::class)->formular($r));
    $router->add('POST', '/install', fn(Request $r) => $c->get(InstallController::class)->installiere($r));

    $fz = fn(string $m) => fn(Request $r, array $p = []) => $c->get(FahrzeugController::class)->$m($r, $p);
    $router->add('GET', '/fahrzeuge', $app->erfordert('lesen', $fz('liste')));
    $router->add('GET', '/fahrzeuge/neu', $app->erfordert('fahrzeug_anlegen', $fz('neu')));
    $router->add('POST', '/fahrzeuge', $app->erfordert('fahrzeug_anlegen', $fz('anlegen')));
    $router->add('GET', '/fahrzeuge/{id}', $app->erfordert('lesen', $fz('anzeigen')));
    $router->add('GET', '/fahrzeuge/{id}/bearbeiten', $app->erfordert('lesen', $fz('bearbeiten')));
    $router->add('POST', '/fahrzeuge/{id}', $app->erfordert('lesen', $fz('aendern')));
    $router->add('POST', '/fahrzeuge/{id}/loeschen', $app->erfordert('fahrzeug_bearbeiten', $fz('loeschen')));
    $router->add('POST', '/fahrzeuge/{id}/abstellen', $app->erfordert('lesen', $fz('abstellen')));
    $router->add('POST', '/fahrzeuge/{id}/zurueckholen', $app->erfordert('lesen', $fz('zurueckholen')));
    $router->add('GET', '/abstellgleis', $app->erfordert('lesen', $fz('abstellgleis')));

    $anl = fn(string $m) => fn(Request $r, array $p = []) => $c->get(AnlageController::class)->$m($r, $p);
    $router->add('GET', '/anlagen', $app->erfordert('lesen', $anl('liste')));
    $router->add('GET', '/anlagen/neu', $app->erfordert('anlage_bearbeiten', $anl('neu')));
    $router->add('POST', '/anlagen', $app->erfordert('anlage_bearbeiten', $anl('anlegen')));
    $router->add('GET', '/anlagen/{id}', $app->erfordert('lesen', $anl('anzeigen')));
    $router->add('GET', '/anlagen/{id}/bearbeiten', $app->erfordert('anlage_bearbeiten', $anl('bearbeiten')));
    $router->add('POST', '/anlagen/{id}', $app->erfordert('anlage_bearbeiten', $anl('aendern')));
    $router->add('POST', '/anlagen/{id}/loeschen', $app->erfordert('anlage_bearbeiten', $anl('loeschen')));

    $dec = fn(string $m) => fn(Request $r, array $p = []) => $c->get(DecoderController::class)->$m($r, $p);
    $router->add('POST', '/fahrzeuge/{id}/decoder', $app->erfordert('lesen', $dec('anlegen')));
    $router->add('GET', '/decoder/{id}/bearbeiten', $app->erfordert('lesen', $dec('bearbeiten')));
    $router->add('POST', '/decoder/{id}', $app->erfordert('lesen', $dec('aendern')));
    $router->add('POST', '/decoder/{id}/loeschen', $app->erfordert('lesen', $dec('loeschen')));

    $fkt = fn(string $m) => fn(Request $r, array $p = []) => $c->get(FunktionController::class)->$m($r, $p);
    $router->add('GET', '/decoder/{id}/funktionen', $app->erfordert('lesen', $fkt('zeigen')));
    $router->add('POST', '/decoder/{id}/funktionen', $app->erfordert('lesen', $fkt('speichern')));
    $router->add('POST', '/decoder/{id}/funktionen/csv', $app->erfordert('lesen', $fkt('csvHochladen')));
    $router->add('POST', '/decoder/{id}/funktionen/csv/uebernehmen', $app->erfordert('lesen', $fkt('csvUebernehmen')));
    $router->add('GET', '/funktionen/vorlage.csv', $app->erfordert('lesen', $fkt('csvVorlage')));
    $router->add('POST', '/decoder/{id}/vorlage', $app->erfordert('lesen', $fkt('alsVorlage')));
    $router->add('POST', '/decoder/{id}/vorlage-anwenden', $app->erfordert('lesen', $fkt('vorlageAnwenden')));

    $cv = fn(string $m) => fn(Request $r, array $p = []) => $c->get(CvController::class)->$m($r, $p);
    $router->add('GET', '/decoder/{id}/cvs', $app->erfordert('lesen', $cv('zeigen')));
    $router->add('POST', '/decoder/{id}/cvs', $app->erfordert('lesen', $cv('speichern')));
    $router->add('POST', '/decoder/{id}/cvs/{cv}/loeschen', $app->erfordert('lesen', $cv('loeschen')));

    $adr = fn(string $m) => fn(Request $r, array $p = []) => $c->get(AdressenController::class)->$m($r);
    $router->add('GET', '/adressen', $app->erfordert('lesen', $adr('uebersicht')));
    $router->add('GET', '/api/adresspruefung', $app->erfordert('lesen', $adr('api')));

    $datei = fn(string $m) => fn(Request $r, array $p = []) => $c->get(DateiController::class)->$m($r, $p);
    $router->add('POST', '/fahrzeuge/{id}/dateien', $app->erfordert('lesen', $datei('hochladen')));
    $router->add('POST', '/fahrzeuge/{id}/titelbild', $app->erfordert('lesen', $datei('titelbild')));
    $router->add('POST', '/anlagen/{id}/dateien', $app->erfordert('anlage_bearbeiten', $datei('hochladenAnlage')));
    $router->add('POST', '/anlagen/{id}/titelbild', $app->erfordert('anlage_bearbeiten', $datei('titelbildAnlage')));
    $router->add('POST', '/dateien/{id}/loeschen', $app->erfordert('lesen', $datei('loeschen')));
    $router->add('GET', '/dateien/{id}', $app->erfordert('lesen', $datei('ausliefern')));
    $router->add('GET', '/dateien/{id}/{variante}', $app->erfordert('lesen', $datei('ausliefern')));

    $person = fn(string $m) => fn(Request $r, array $p = []) => $c->get(PersonController::class)->$m($r, $p);
    $router->add('GET', '/personen', $app->erfordert('person_bearbeiten', $person('liste')));
    $router->add('GET', '/personen/neu', $app->erfordert('person_bearbeiten', $person('neu')));
    $router->add('POST', '/personen', $app->erfordert('person_bearbeiten', $person('anlegen')));
    $router->add('GET', '/personen/{id}/bearbeiten', $app->erfordert('person_bearbeiten', $person('bearbeiten')));
    $router->add('POST', '/personen/{id}', $app->erfordert('person_bearbeiten', $person('aendern')));
    $router->add('POST', '/personen/{id}/loeschen', $app->erfordert('person_bearbeiten', $person('loeschen')));
    $router->add('GET', '/personen/{id}/dsgvo', $app->erfordert('person_dsgvo_loeschen', $person('dsgvoVorschau')));
    $router->add('POST', '/personen/{id}/dsgvo', $app->erfordert('person_dsgvo_loeschen', $person('dsgvoLoeschen')));

    $benutzer = fn(string $m) => fn(Request $r, array $p = []) => $c->get(BenutzerController::class)->$m($r, $p);
    $router->add('GET', '/admin/benutzer', $app->erfordert('benutzer_verwalten', $benutzer('liste')));
    $router->add('GET', '/admin/benutzer/neu', $app->erfordert('benutzer_verwalten', $benutzer('neu')));
    $router->add('POST', '/admin/benutzer', $app->erfordert('benutzer_verwalten', $benutzer('anlegen')));
    $router->add('GET', '/admin/benutzer/{id}/bearbeiten', $app->erfordert('benutzer_verwalten', $benutzer('bearbeiten')));
    $router->add('POST', '/admin/benutzer/{id}', $app->erfordert('benutzer_verwalten', $benutzer('aendern')));
    $router->add('POST', '/admin/benutzer/{id}/passwort', $app->erfordert('benutzer_verwalten', $benutzer('passwortZuruecksetzen')));
    $router->add('POST', '/admin/benutzer/{id}/loeschen', $app->erfordert('benutzer_verwalten', $benutzer('loeschen')));
    $router->add('POST', '/admin/kennwort-anfragen/{id}/verwerfen', $app->erfordert('benutzer_verwalten', $benutzer('anfrageVerwerfen')));

    $spur = fn(string $m) => fn(Request $r, array $p = []) => $c->get(SpurweiteController::class)->$m($r, $p);
    $router->add('GET', '/admin/spurweiten', $app->erfordert('stammdaten_verwalten', $spur('liste')));
    $router->add('POST', '/admin/spurweiten', $app->erfordert('stammdaten_verwalten', $spur('speichern')));
    $router->add('POST', '/admin/spurweiten/{id}/loeschen', $app->erfordert('stammdaten_verwalten', $spur('loeschen')));

    $sich = fn(string $m) => fn(Request $r, array $p = []) => $c->get(SicherungController::class)->$m($r);
    $router->add('GET', '/admin/sicherung', $app->erfordert('betrieb', $sich('zeigen')));
    $router->add('POST', '/admin/sicherung', $app->erfordert('betrieb', $sich('herunterladen')));

    $upd = fn(string $m) => fn(Request $r, array $p = []) => $c->get(UpdateController::class)->$m($r);
    $router->add('GET', '/admin/update', $app->erfordert('betrieb', $upd('zeigen')));
    $router->add('POST', '/admin/update', $app->erfordert('betrieb', $upd('ausfuehren')));

    $od = fn(string $m) => fn(Request $r, array $p = []) => $c->get(OneDriveController::class)->$m($r);
    $router->add('GET', '/admin/onedrive', $app->erfordert('betrieb', $od('zeigen')));
    $router->add('POST', '/admin/onedrive/verbinden', $app->erfordert('betrieb', $od('verbinden')));
    $router->add('GET', '/admin/onedrive/rueckruf', $app->erfordert('betrieb', $od('rueckruf')));
    $router->add('GET', '/admin/onedrive/ordner', $app->erfordert('betrieb', $od('ordnerZeigen')));
    $router->add('POST', '/admin/onedrive/ordner', $app->erfordert('betrieb', $od('ordnerSpeichern')));
    $router->add('POST', '/admin/onedrive/uebertragen', $app->erfordert('betrieb', $od('uebertragen')));
    $router->add('POST', '/admin/onedrive/umzug', $app->erfordert('betrieb', $od('umzug')));
    $router->add('POST', '/admin/onedrive/trennen', $app->erfordert('betrieb', $od('trennen')));

    if ($app->istTestumgebung()) {
        $test = fn(string $m) => fn(Request $r, array $p = []) => $c->get(TestdatenController::class)->$m($r);
        $router->add('GET', '/admin/testdaten', $app->erfordert('betrieb', $test('zeigen')));
        $router->add('POST', '/admin/testdaten', $app->erfordert('betrieb', $test('neuLaden')));
    }

    $router->add('GET', '/konto/passwort', fn(Request $r) => $c->get(KontoController::class)->passwortForm($r));
    $router->add('POST', '/konto/passwort', fn(Request $r) => $c->get(KontoController::class)->passwortAendern($r));
};

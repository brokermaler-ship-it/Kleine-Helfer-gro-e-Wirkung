<?php

declare(strict_types=1);

use Emc\Auth\AuthController;
use Emc\Auth\InstallController;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Router;

/** Routendefinitionen; spätere Tasks ergänzen hier. */
return function (Router $router, App $app): void {
    $c = $app->container();

    $router->add('GET', '/', fn() => Response::redirect(url('/fahrzeuge')));

    $router->add('GET', '/login', fn(Request $r) => $c->get(AuthController::class)->loginForm($r));
    $router->add('POST', '/login', fn(Request $r) => $c->get(AuthController::class)->login($r));
    $router->add('POST', '/logout', fn(Request $r) => $c->get(AuthController::class)->logout($r));

    $router->add('GET', '/install', fn(Request $r) => $c->get(InstallController::class)->formular($r));
    $router->add('POST', '/install', fn(Request $r) => $c->get(InstallController::class)->installiere($r));
};

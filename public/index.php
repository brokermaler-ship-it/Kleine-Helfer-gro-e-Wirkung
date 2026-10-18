<?php

declare(strict_types=1);

use Emc\Core\App;
use Emc\Core\Request;

$wurzel = dirname(__DIR__);
require $wurzel . '/vendor/autoload.php';

if (!is_file($wurzel . '/config.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Einrichtung</title>'
        . '<p>Bitte config.example.php nach config.php kopieren und ausfüllen.</p></html>';
    exit;
}

$config = require $wurzel . '/config.php';

ini_set('display_errors', empty($config['debug']) ? '0' : '1');

App::erzeuge($config)->handle(Request::fromGlobals($config))->send();

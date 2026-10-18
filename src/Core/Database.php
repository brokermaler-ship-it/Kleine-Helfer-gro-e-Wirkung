<?php

declare(strict_types=1);

namespace Emc\Core;

final class Database
{
    public static function verbinde(string $dsn, string $benutzer, string $passwort): \PDO
    {
        $pdo = new \PDO($dsn, $benutzer, $passwort, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('SET NAMES utf8mb4');
        return $pdo;
    }
}

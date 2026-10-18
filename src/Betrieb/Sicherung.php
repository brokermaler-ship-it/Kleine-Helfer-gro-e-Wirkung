<?php

declare(strict_types=1);

namespace Emc\Betrieb;

final class Sicherung
{
    private const BLOCKGROESSE = 100;
    private const MAX_ALTER_TAGE = 30;

    public function __construct(private \PDO $pdo, private string $speicherPfad, private Einstellungen $einstellungen)
    {
    }

    /** Schreibt ein ZIP mit datenbank.sql, dateien/ und info.txt nach $zielZip. */
    public function erstelle(string $zielZip): void
    {
        $sqlDatei = tempnam(sys_get_temp_dir(), 'emc_sql');
        if ($sqlDatei === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }
        try {
            $this->schreibeDump($sqlDatei);

            $zip = new \ZipArchive();
            if ($zip->open($zielZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
            }
            $zip->addFile($sqlDatei, 'datenbank.sql');
            $zip->addFromString('info.txt', $this->info());
            $this->fuegeDateienHinzu($zip);
            if (!$zip->close()) {
                throw new \RuntimeException('ZIP-Datei konnte nicht geschrieben werden.');
            }
        } finally {
            @unlink($sqlDatei);
        }
        $this->einstellungen->setze('letzte_sicherung', (new \DateTimeImmutable())->format(\DATE_ATOM));
    }

    public function istUeberfaellig(\DateTimeImmutable $jetzt): bool
    {
        $wert = $this->einstellungen->hole('letzte_sicherung');
        if ($wert === null || $wert === '') {
            return true;
        }
        try {
            $letzte = new \DateTimeImmutable($wert);
        } catch (\Exception) {
            return true;
        }
        return $letzte < $jetzt->modify('-' . self::MAX_ALTER_TAGE . ' days');
    }

    public function letzteSicherung(): ?\DateTimeImmutable
    {
        $wert = $this->einstellungen->hole('letzte_sicherung');
        try {
            return $wert === null || $wert === '' ? null : new \DateTimeImmutable($wert);
        } catch (\Exception) {
            return null;
        }
    }

    private function info(): string
    {
        $versionDatei = dirname(__DIR__, 2) . '/VERSION';
        $version = is_file($versionDatei) ? trim((string) file_get_contents($versionDatei)) : 'unbekannt';
        $migration = $this->pdo->query('SELECT MAX(version) FROM schema_migration')->fetchColumn();
        return "EMC 78 Halle – Datensicherung\n"
            . 'Erstellt am: ' . (new \DateTimeImmutable())->format('d.m.Y H:i:s') . "\n"
            . 'App-Version: ' . $version . "\n"
            . 'Letzte Migration: ' . ($migration === false || $migration === null ? '-' : (string) $migration) . "\n";
    }

    private function schreibeDump(string $datei): void
    {
        $f = fopen($datei, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Sicherungsdatei konnte nicht geschrieben werden.');
        }
        $puffer = (bool) $this->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try {
            fwrite($f, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tabellen = array_map(
                'strval',
                $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN)
            );
            foreach ($tabellen as $tabelle) {
                $q = '`' . str_replace('`', '``', $tabelle) . '`';
                $create = $this->pdo->query('SHOW CREATE TABLE ' . $q)->fetch(\PDO::FETCH_NUM);
                fwrite($f, "DROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n");
                $this->schreibeZeilen($f, $q);
            }
            fwrite($f, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $puffer);
            fclose($f);
        }
    }

    /** @param resource $f */
    private function schreibeZeilen($f, string $q): void
    {
        // Ungepuffert lesen, damit auch große Tabellen nicht komplett im Speicher landen.
        $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $this->pdo->query('SELECT * FROM ' . $q, \PDO::FETCH_NUM);
        $block = [];
        try {
            while (($zeile = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                $werte = [];
                foreach ($zeile as $wert) {
                    $werte[] = $wert === null ? 'NULL' : $this->literal((string) $wert);
                }
                $block[] = '(' . implode(',', $werte) . ')';
                if (count($block) >= self::BLOCKGROESSE) {
                    fwrite($f, "INSERT INTO $q VALUES\n" . implode(",\n", $block) . ";\n");
                    $block = [];
                }
            }
        } finally {
            $st->closeCursor();
            $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        if ($block !== []) {
            fwrite($f, "INSERT INTO $q VALUES\n" . implode(",\n", $block) . ";\n");
        }
        fwrite($f, "\n");
    }

    /** Zeichenkettenliteral ohne rohe Zeilenumbrüche, damit der Import an ";\n" zerlegen kann. */
    private function literal(string $wert): string
    {
        return strtr($this->pdo->quote($wert), ["\r" => '\\r', "\n" => '\\n']);
    }

    private function fuegeDateienHinzu(\ZipArchive $zip): void
    {
        $basis = rtrim($this->speicherPfad, '/') . '/dateien';
        if (!is_dir($basis)) {
            return;
        }
        $zip->addEmptyDir('dateien');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $eintrag) {
            if ($eintrag->isLink()) {
                continue;
            }
            $relativ = 'dateien/' . substr($eintrag->getPathname(), strlen($basis) + 1);
            if ($eintrag->isDir()) {
                $zip->addEmptyDir($relativ);
            } elseif ($eintrag->isFile() && $eintrag->getFilename() !== '.gitkeep') {
                $zip->addFile($eintrag->getPathname(), $relativ);
            }
        }
    }
}

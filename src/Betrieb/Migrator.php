<?php

declare(strict_types=1);

namespace Emc\Betrieb;

final class Migrator
{
    public function __construct(private \PDO $pdo, private string $verzeichnis)
    {
    }

    public function istInstalliert(): bool
    {
        $st = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migration'"
        );
        return (int) $st->fetchColumn() > 0;
    }

    /** @return list<string> Versionen (Dateinamen ohne .sql), sortiert */
    public function ausstehend(): array
    {
        $alle = [];
        foreach (glob(rtrim($this->verzeichnis, '/') . '/*.sql') ?: [] as $datei) {
            $alle[] = basename($datei, '.sql');
        }
        sort($alle, SORT_STRING);
        if (!$this->istInstalliert()) {
            return $alle;
        }
        $erledigt = $this->pdo->query('SELECT version FROM schema_migration')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_diff($alle, $erledigt));
    }

    /**
     * Führt ausstehende Migrationen aus. Eine Version wird erst nach vollständigem Erfolg
     * eingetragen (DDL ist in MySQL nicht transaktional).
     *
     * @return list<string> ausgeführte Versionen
     */
    public function ausfuehren(): array
    {
        $ausgefuehrt = [];
        foreach ($this->ausstehend() as $version) {
            $sql = file_get_contents(rtrim($this->verzeichnis, '/') . '/' . $version . '.sql');
            foreach ($this->anweisungen((string) $sql) as $anweisung) {
                $this->pdo->exec($anweisung);
            }
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migration (version VARCHAR(100) NOT NULL PRIMARY KEY, '
                . 'ausgefuehrt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $st = $this->pdo->prepare('INSERT INTO schema_migration (version) VALUES (?)');
            $st->execute([$version]);
            $ausgefuehrt[] = $version;
        }
        return $ausgefuehrt;
    }

    /** @return list<string> */
    private function anweisungen(string $sql): array
    {
        $ergebnis = [];
        $aktuell = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $zeile) {
            if (str_starts_with(ltrim($zeile), '--')) {
                continue;
            }
            $aktuell .= $zeile . "\n";
            if (str_ends_with(rtrim($zeile), ';')) {
                $ergebnis[] = trim($aktuell);
                $aktuell = '';
            }
        }
        if (trim($aktuell) !== '') {
            $ergebnis[] = trim($aktuell);
        }
        return $ergebnis;
    }
}

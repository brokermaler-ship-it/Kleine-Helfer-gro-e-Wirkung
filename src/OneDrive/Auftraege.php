<?php

declare(strict_types=1);

namespace Emc\OneDrive;

/** Warteschlange der OneDrive-Übertragungen (Tabelle onedrive_auftrag). */
final class Auftraege
{
    private const SPERRE_SEKUNDEN = 600;
    private const MAX_VERSUCHE = 20;
    private const BACKOFF = [60, 300, 1800, 7200];
    private const BACKOFF_SPAETER = 3600;
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function neu(string $art, ?int $dokumentId, ?string $itemId, ?\DateTimeImmutable $jetzt = null): int
    {
        $jetzt ??= new \DateTimeImmutable();
        $this->pdo->prepare('INSERT INTO onedrive_auftrag (art, dokument_id, item_id, naechster_versuch) VALUES (?, ?, ?, ?)')
            ->execute([$art, $dokumentId, $itemId, $jetzt->format(self::FORMAT)]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Beansprucht höchstens $max fällige Aufträge atomar (ein UPDATE mit eigenem Token), sodass
     * parallele Läufe (Cron, Admin-Knopf, Upload) nie denselben Auftrag erhalten. Sperre: 10 Minuten.
     * Optional nur ein bestimmter Auftrag ($nurId) oder nur eine Art ('hochladen'/'loeschen').
     *
     * @return list<array<string,mixed>> jede Zeile enthält den Besitz-Token in `sperr_token`
     */
    public function beanspruche(int $max, \DateTimeImmutable $jetzt, ?int $nurId = null, ?string $art = null): array
    {
        $max = max(1, $max);
        $token = bin2hex(random_bytes(16));
        $jetztText = $jetzt->format(self::FORMAT);
        $bis = $jetzt->modify('+' . self::SPERRE_SEKUNDEN . ' seconds')->format(self::FORMAT);
        $bedingung = '';
        $param = [$bis, $token, $jetztText, $jetztText];
        if ($nurId !== null) {
            $bedingung .= ' AND id = ?';
            $param[] = $nurId;
        }
        if ($art !== null) {
            $bedingung .= ' AND art = ?';
            $param[] = $art;
        }
        $this->pdo->prepare(
            "UPDATE onedrive_auftrag SET gesperrt_bis = ?, sperr_token = ? WHERE status = 'wartet' AND naechster_versuch <= ? "
            . 'AND (gesperrt_bis IS NULL OR gesperrt_bis < ?)' . $bedingung . ' ORDER BY id LIMIT ' . $max
        )->execute($param);
        $st = $this->pdo->prepare('SELECT * FROM onedrive_auftrag WHERE sperr_token = ? ORDER BY id');
        $st->execute([$token]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Verlängert die Sperre eines beanspruchten Auftrags. Gibt false zurück, wenn der Auftrag inzwischen
     * einem anderen Lauf gehört (Sperre war abgelaufen) – dann darf er nicht mehr bearbeitet werden.
     */
    public function verlaengere(int $id, string $token, \DateTimeImmutable $jetzt): bool
    {
        $bis = $jetzt->modify('+' . self::SPERRE_SEKUNDEN . ' seconds')->format(self::FORMAT);
        $this->pdo->prepare("UPDATE onedrive_auftrag SET gesperrt_bis = ? WHERE id = ? AND sperr_token = ? AND status = 'wartet'")
            ->execute([$bis, $id, $token]);
        $st = $this->pdo->prepare("SELECT 1 FROM onedrive_auftrag WHERE id = ? AND sperr_token = ? AND status = 'wartet'");
        $st->execute([$id, $token]);
        return $st->fetchColumn() !== false;
    }

    public function erledigt(int $id, ?string $token = null): void
    {
        $this->aendere($id, $token, "status = 'erledigt', gesperrt_bis = NULL, sperr_token = NULL", []);
    }

    /** Zählt einen Fehlversuch; Wartezeit steigt (1, 5, 30, 120 min, dann stündlich); nach 20 Versuchen Status „fehler“. */
    public function fehlgeschlagen(int $id, string $fehler, ?int $warteSekunden, \DateTimeImmutable $jetzt, ?string $token = null): void
    {
        $st = $this->pdo->prepare('SELECT versuche FROM onedrive_auftrag WHERE id = ?');
        $st->execute([$id]);
        $versuche = ((int) $st->fetchColumn()) + 1;
        $warte = self::BACKOFF[$versuche - 1] ?? self::BACKOFF_SPAETER;
        if ($warteSekunden !== null) {
            $warte = max($warte, min($warteSekunden, 86400));
        }
        $this->aendere(
            $id,
            $token,
            'versuche = ?, status = ?, letzter_fehler = ?, naechster_versuch = ?, gesperrt_bis = NULL, sperr_token = NULL',
            [
                $versuche,
                $versuche >= self::MAX_VERSUCHE ? 'fehler' : 'wartet',
                mb_substr($fehler, 0, 500),
                $jetzt->modify('+' . $warte . ' seconds')->format(self::FORMAT),
            ]
        );
    }

    /** Dauerhafter Fehler (z. B. Ordner gesperrt, Datei zu groß): sofort Status „fehler“, kein weiterer Versuch. */
    public function dauerhaftFehlgeschlagen(int $id, string $fehler, ?string $token = null): void
    {
        $this->aendere(
            $id,
            $token,
            "versuche = versuche + 1, status = 'fehler', letzter_fehler = ?, gesperrt_bis = NULL, sperr_token = NULL",
            [mb_substr($fehler, 0, 500)]
        );
    }

    /** Gibt einen Auftrag ohne Fehlversuch zurück (z. B. Verbindung abgelaufen). */
    public function freigeben(int $id, string $grund, int $warteSekunden, \DateTimeImmutable $jetzt, ?string $token = null): void
    {
        $this->aendere(
            $id,
            $token,
            "status = 'wartet', letzter_fehler = ?, naechster_versuch = ?, gesperrt_bis = NULL, sperr_token = NULL",
            [mb_substr($grund, 0, 500), $jetzt->modify('+' . $warteSekunden . ' seconds')->format(self::FORMAT)]
        );
    }

    /** Entfernt erledigte Aufträge, die älter als $tage Tage sind. */
    public function raeumeAuf(\DateTimeImmutable $jetzt, int $tage = 30): int
    {
        $st = $this->pdo->prepare("DELETE FROM onedrive_auftrag WHERE status = 'erledigt' AND geaendert_am < ?");
        $st->execute([$jetzt->modify('-' . $tage . ' days')->format(self::FORMAT)]);
        return $st->rowCount();
    }

    /** @param list<mixed> $werte */
    private function aendere(int $id, ?string $token, string $setze, array $werte): void
    {
        $sql = 'UPDATE onedrive_auftrag SET ' . $setze . ' WHERE id = ?';
        $werte[] = $id;
        if ($token !== null) {
            $sql .= ' AND sperr_token = ?';
            $werte[] = $token;
        }
        $this->pdo->prepare($sql)->execute($werte);
    }

    /** @return array{wartet:int,fehler:int,erledigt:int} */
    public function zaehle(): array
    {
        $z = ['wartet' => 0, 'fehler' => 0, 'erledigt' => 0];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM onedrive_auftrag GROUP BY status')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $z[(string) $r['status']] = (int) $r['n'];
        }
        return $z;
    }
}

<?php

declare(strict_types=1);

namespace Emc\OneDrive;

use Emc\Betrieb\Einstellungen;
use Emc\Dateien\DokumentRepository;

/** Arbeitet die OneDrive-Warteschlange ab: Hochladen, lokales Original entfernen, Löschen. */
final class Uebertragung
{
    public function __construct(
        private Auftraege $auftraege,
        private Graph $graph,
        private TokenSpeicher $tokens,
        private Einstellungen $einstellungen,
        private DokumentRepository $dokumente,
        private \PDO $pdo,
        private string $speicherPfad,
        private bool $originaleBehalten = false,
    ) {
    }

    /** Sind Client-ID, Client-Secret und Schlüssel gesetzt? Erst dann dürfen Tresor/TokenSpeicher gebaut werden. */
    public static function konfiguriert(array $config): bool
    {
        foreach (['onedrive_client_id', 'onedrive_client_secret', 'app_schluessel'] as $k) {
            if (!is_string($config[$k] ?? null) || trim($config[$k]) === '') {
                return false;
            }
        }
        return true;
    }

    /** Verbunden und Zielordner gewählt. */
    public function aktiv(): bool
    {
        return $this->tokens->status() === TokenSpeicher::STATUS_VERBUNDEN && $this->ziel() !== null;
    }

    /**
     * @return array{erledigt:int,fehler:int}
     */
    public function verarbeite(int $max = 5, ?\DateTimeImmutable $jetzt = null): array
    {
        $jetzt ??= new \DateTimeImmutable();
        $ergebnis = ['erledigt' => 0, 'fehler' => 0];
        if (!$this->aktiv()) {
            return $ergebnis;
        }
        $ziel = $this->ziel();
        $auftraege = $this->auftraege->beanspruche($max, $jetzt);
        foreach ($auftraege as $i => $a) {
            $id = (int) $a['id'];
            try {
                if ($a['art'] === 'loeschen') {
                    if (is_string($a['item_id'] ?? null) && $a['item_id'] !== '') {
                        $this->graph->loeschen($ziel['drive_id'], $a['item_id']);
                    }
                } else {
                    $this->hochladen((int) $a['dokument_id'], $ziel);
                }
                $this->auftraege->erledigt($id);
                $ergebnis['erledigt']++;
            } catch (GraphFehler $e) {
                if ($e->istAbgelaufen()) {
                    $this->tokens->setzeStatus(TokenSpeicher::STATUS_ABGELAUFEN);
                    // Nichts geht verloren: alle noch gesperrten Aufträge dieses Laufs bleiben in der Warteschlange.
                    foreach (array_slice($auftraege, $i) as $rest) {
                        $this->auftraege->freigeben((int) $rest['id'], 'Verbindung abgelaufen', 600, $jetzt);
                    }
                    return $ergebnis;
                }
                $this->auftraege->fehlgeschlagen($id, $e->getMessage(), $e->warteSekunden(), $jetzt);
                $ergebnis['fehler']++;
            } catch (\Throwable $t) {
                $this->auftraege->fehlgeschlagen($id, $t::class . ': ' . $t->getMessage(), null, $jetzt);
                $ergebnis['fehler']++;
            }
        }
        return $ergebnis;
    }

    /** Reiht ein frisch gespeichertes Dokument ein und versucht die Übertragung sofort. */
    public function einreihen(int $dokumentId): void
    {
        $this->auftraege->neu('hochladen', $dokumentId, null);
        $this->verarbeite(1);
    }

    /** Legt für alle lokalen Dokumente ohne offenen Auftrag einen Hochlade-Auftrag an. */
    public function umzug(?\DateTimeImmutable $jetzt = null): int
    {
        if (!$this->aktiv()) {
            return 0;
        }
        $jetzt ??= new \DateTimeImmutable();
        $st = $this->pdo->prepare(
            "INSERT INTO onedrive_auftrag (art, dokument_id, naechster_versuch) SELECT 'hochladen', d.id, ? FROM dokument d "
            . "WHERE d.ablage = 'lokal' AND NOT EXISTS (SELECT 1 FROM onedrive_auftrag a WHERE a.dokument_id = d.id AND a.art = 'hochladen' AND a.status = 'wartet')"
        );
        $st->execute([$jetzt->format('Y-m-d H:i:s')]);
        return $st->rowCount();
    }

    /** @param array{drive_id:string,item_id:string,name:string} $ziel */
    private function hochladen(int $dokumentId, array $ziel): void
    {
        $dok = $this->dokumente->finde($dokumentId);
        if ($dok === null || $dok['ablage'] === 'onedrive') {
            return;
        }
        $basis = $this->dokumente->absoluterPfad((string) $dok['pfad']);
        if ($basis === null || !is_file($basis)) {
            throw new \RuntimeException('Die lokale Datei fehlt.');
        }
        $fahrzeug = null;
        foreach ($this->dokumente->objekte($dokumentId, 'fahrzeug') as $fzId) {
            $st = $this->pdo->prepare('SELECT inventarnummer, bezeichnung FROM fahrzeug WHERE id = ?');
            $st->execute([$fzId]);
            $fahrzeug = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            break;
        }
        $e = $this->graph->hochladen($ziel['drive_id'], $ziel['item_id'], Pfadbildner::fuerDokument($dok, $fahrzeug), $basis);

        // Erst vermerken, dann das Original entfernen: bricht etwas dazwischen ab, bleibt die Datei erreichbar.
        $st = $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = ?, onedrive_pfad = ?, original_lokal = 1 WHERE id = ? AND ablage = 'lokal'");
        $st->execute([$e['item_id'], mb_substr($e['pfad'], 0, 500), $dokumentId]);
        if ($st->rowCount() === 0) {
            // Dokument wurde inzwischen gelöscht (oder parallel übertragen): Duplikat in OneDrive wieder entfernen.
            if ($this->dokumente->finde($dokumentId) === null) {
                $this->graph->loeschen($ziel['drive_id'], $e['item_id']);
            }
            return;
        }
        if (!$this->originaleBehalten && @unlink($basis)) {
            $this->pdo->prepare('UPDATE dokument SET original_lokal = 0 WHERE id = ?')->execute([$dokumentId]);
        }
    }

    /** @return ?array{drive_id:string,item_id:string,name:string} */
    public function ziel(): ?array
    {
        $roh = $this->einstellungen->hole('onedrive_ziel');
        $d = $roh === null || $roh === '' ? null : json_decode($roh, true);
        if (!is_array($d) || !is_string($d['drive_id'] ?? null) || !is_string($d['item_id'] ?? null) || $d['drive_id'] === '' || $d['item_id'] === '') {
            return null;
        }
        return ['drive_id' => $d['drive_id'], 'item_id' => $d['item_id'], 'name' => (string) ($d['name'] ?? '')];
    }
}

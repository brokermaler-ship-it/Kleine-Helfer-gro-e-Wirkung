<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Betrieb\Einstellungen;
use Emc\Core\Transaktion;
use Emc\Dateien\DateiController;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadFehler;
use Emc\Dateien\UploadService;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Anfragen aus dem öffentlichen Bereich: entgegennehmen (Begrenzung je IP, Fotos ohne Metadaten) und nach 12 Monaten
 * samt Fotos löschen. Protokolleinträge enthalten nie Name, E-Mail, Telefon oder Nachricht.
 */
final class AnfrageDienst
{
    public const OBJEKT = 'boerse_anfrage';
    public const AUFBEWAHRUNG_MONATE = 12;
    /** Einstellung mit dem Zeitpunkt des letzten Aufräumens beim Seitenaufruf. */
    public const AUFGERAEUMT_AM = 'boerse_aufgeraeumt_am';

    private int $abgelehnt = 0;

    public function __construct(
        private \PDO $pdo,
        private AnfrageRepository $rep,
        private Spamschutz $spam,
        private UploadService $upload,
        private DokumentRepository $dok,
        private Aenderungsprotokoll $protokoll,
    ) {
    }

    /**
     * Speichert die Anfrage, wenn die IP die Begrenzung nicht überschreitet. Die Sperre auf die Zeile des Salzes
     * serialisiert alle öffentlichen Anfragen (Zählen und Speichern in einer Transaktion). Fotos werden erst nach dem
     * Commit neu kodiert gespeichert; ein unbrauchbares Foto wird verworfen, die Anfrage bleibt (abgelehnteFotos()).
     *
     * @param array{name:string, email:?string, telefon:?string, nachricht:string, wunsch:?string} $daten vom AnfrageValidator
     * @param list<array<string,mixed>> $fotos normalisierte $_FILES-Einträge
     * @return string 'gespeichert' oder 'begrenzt'
     * @throws \Emc\Core\DomainFehler bei Sperrkonflikt (nichts gespeichert)
     */
    public function nimmEntgegen(string $art, ?int $angebotId, array $daten, array $fotos, string $ip, \DateTimeImmutable $jetzt): string
    {
        if (!in_array($art, AnfrageValidator::ARTEN, true)) {
            throw new \InvalidArgumentException('Unbekannte Art der Anfrage.');
        }
        $this->abgelehnt = 0;
        $ipHash = $this->spam->ipHash($ip);
        $id = Transaktion::readCommitted($this->pdo, function () use ($art, $angebotId, $daten, $ipHash, $jetzt): ?int {
            $this->spam->sperre();
            if ($this->spam->begrenzt($ipHash, $jetzt)) {
                return null;
            }
            $id = $this->rep->anlegen($art, $angebotId, $daten, $jetzt);
            $this->spam->zaehle($ipHash, $jetzt);
            $protokoll = ['art' => ['alt' => null, 'neu' => $art]];
            if ($angebotId !== null) {
                $protokoll['angebot_id'] = ['alt' => null, 'neu' => $angebotId];
            }
            $this->protokoll->schreibe(null, self::OBJEKT, $id, 'angelegt', $protokoll);
            return $id;
        });
        if ($id === null) {
            return 'begrenzt';
        }
        foreach (array_slice($fotos, 0, AnfrageValidator::MAX_FOTOS) as $foto) {
            try {
                $this->upload->speichereOeffentlich($foto, $id);
            } catch (UploadFehler) {
                $this->abgelehnt++;
            } catch (\Throwable $t) {
                $this->abgelehnt++;
                error_log('Börsenanfrage: Foto nicht gespeichert: ' . $t::class);
            }
        }
        return 'gespeichert';
    }

    /** Anzahl der Dateien der letzten Anfrage, die nicht als Foto übernommen werden konnten. */
    public function abgelehnteFotos(): int
    {
        return $this->abgelehnt;
    }

    /**
     * Löscht Anfragen, die vor mehr als 12 Monaten eingegangen sind, samt nur ihnen zugeordneten Fotos (Dateien vom
     * Webspace) und Protokolleinträgen; dazu Sperrzeilen älter als 24 Stunden.
     *
     * @return int Anzahl gelöschter Anfragen
     */
    public function raeumeAuf(\DateTimeImmutable $jetzt): int
    {
        $anzahl = 0;
        foreach ($this->rep->eingegangenVor($jetzt->modify('-' . self::AUFBEWAHRUNG_MONATE . ' months')) as $id) {
            if ($this->entferne($id, null, false)) {
                $anzahl++;
            }
        }
        $this->spam->raeumeAuf($jetzt);
        return $anzahl;
    }

    /** Wie raeumeAuf(), aber höchstens einmal je Stunde (für Seitenaufrufe); merkt sich den Zeitpunkt in `einstellung`. */
    public function raeumeAufHoechstensStuendlich(\DateTimeImmutable $jetzt): void
    {
        $einstellungen = new Einstellungen($this->pdo);
        $zuletzt = $einstellungen->hole(self::AUFGERAEUMT_AM);
        if ($zuletzt !== null && preg_match('/^\d+$/', $zuletzt) === 1 && (int) $zuletzt > $jetzt->getTimestamp() - 3600 && (int) $zuletzt <= $jetzt->getTimestamp()) {
            return;
        }
        $einstellungen->setze(self::AUFGERAEUMT_AM, (string) $jetzt->getTimestamp());
        $this->raeumeAuf($jetzt);
    }

    /** Löscht eine Anfrage von Hand samt nur ihr zugeordneten Fotos (geteilte, z. B. ins Magazin übernommene, bleiben). */
    public function loesche(int $anfrageId, ?int $benutzerId): void
    {
        $this->entferne($anfrageId, $benutzerId, true);
    }

    /** @return bool false, wenn es die Anfrage nicht (mehr) gibt */
    private function entferne(int $id, ?int $benutzerId, bool $vonHand): bool
    {
        $pfade = Transaktion::readCommitted($this->pdo, function () use ($id, $benutzerId, $vonHand): ?array {
            if ($this->rep->sperre($id) === null) {
                return null;
            }
            $pfade = $this->dok->loeseVonObjekt(self::OBJEKT, $id);
            $this->rep->loesche($id);
            if ($vonHand) {
                // Nur wer gelöscht hat, ohne Angaben aus der Anfrage.
                $this->protokoll->schreibe($benutzerId, self::OBJEKT, $id, 'geloescht');
            }
            return $pfade;
        });
        if ($pfade === null) {
            return false;
        }
        foreach ($pfade as $pfad) {
            $this->dok->entferneDateien($pfad);
        }
        return true;
    }

    /**
     * Normalisierte Dateien eines Formularfelds (einzeln oder mehrfach, ohne leere Felder).
     *
     * @return list<array<string,mixed>>
     */
    public static function fotos(mixed $dateien): array
    {
        return DateiController::normalisiere($dateien);
    }
}

<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Core\Session;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\ObjektFotos;
use Emc\Dateien\UploadService;

/** Fotos an einer Reparatur ablegen (beim Melden und auf der Reparaturseite); die Logik steckt in ObjektFotos. */
final class ReparaturFotos
{
    public const MAX_FOTOS = ObjektFotos::MAX_FOTOS;

    public function __construct(
        private UploadService $upload,
        private DokumentRepository $dokumente,
        private Session $session,
    ) {
    }

    /**
     * Nur Fotos; andere Dateien und Upload-Fehler ergeben eine Warnung, der Rest wird trotzdem gespeichert.
     *
     * @param mixed $dateien $_FILES-Eintrag (einzeln oder mehrfach)
     * @param string $wofuer „eine Meldung“ bzw. „eine Reparatur“ (für die Warnung)
     * @return list<array{id:int, name:string}> gespeicherte Fotos
     */
    public function speichere(mixed $dateien, int $reparaturId, ?int $benutzerId, string $wofuer): array
    {
        return (new ObjektFotos($this->upload, $this->dokumente, $this->session))->speichere($dateien, 'reparatur', $reparaturId, $benutzerId, $wofuer);
    }
}

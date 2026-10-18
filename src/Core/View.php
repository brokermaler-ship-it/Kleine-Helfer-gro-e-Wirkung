<?php

declare(strict_types=1);

namespace Emc\Core;

final class View
{
    public function __construct(private string $templateDir)
    {
    }

    /** @param array<string,mixed> $daten */
    public function render(string $template, array $daten = [], ?string $layout = 'layout'): string
    {
        $inhalt = $this->include($template, $daten);
        if ($layout === null) {
            return $inhalt;
        }
        return $this->include($layout, $daten + ['inhalt' => $inhalt], ['inhalt' => $inhalt]);
    }

    /**
     * @param array<string,mixed> $daten
     * @param array<string,mixed> $ueberschreibe
     */
    private function include(string $template, array $daten, array $ueberschreibe = []): string
    {
        $datei = rtrim($this->templateDir, '/') . '/' . $template . '.php';
        if (!is_file($datei)) {
            throw new \RuntimeException('Template nicht gefunden: ' . $template);
        }
        $daten = $ueberschreibe + $daten;
        unset($daten['datei'], $daten['template']);
        extract($daten, EXTR_SKIP);
        ob_start();
        try {
            include $datei;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}

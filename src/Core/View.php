<?php

declare(strict_types=1);

namespace Emc\Core;

final class View
{
    /** @var array<string,mixed> */
    private array $globale = [];
    /** @var array<string,callable> */
    private array $spaet = [];

    public function __construct(private string $templateDir)
    {
    }

    /** Wert, der in jedem Template verfügbar ist (Aufrufdaten haben Vorrang). */
    public function teile(string $k, mixed $v): void
    {
        $this->globale[$k] = $v;
    }

    /** Wie teile(), der Wert wird aber erst beim Rendern ermittelt (z. B. Meldungen, die dabei verbraucht werden). */
    public function teileSpaet(string $k, callable $quelle): void
    {
        $this->spaet[$k] = $quelle;
    }

    /** @param array<string,mixed> $daten */
    public function render(string $template, array $daten = [], ?string $layout = 'layout'): string
    {
        foreach ($this->spaet as $k => $quelle) {
            if (!array_key_exists($k, $daten)) {
                $daten[$k] = $quelle();
            }
        }
        $daten += $this->globale;
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

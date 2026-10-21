<?php

declare(strict_types=1);

namespace Emc\Digital\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\RawData\FilterHelper;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * Parser der Bibliothek mit zählendem Entpacken, einer Höchstzahl an Objekten (auch aus Objektströmen) und begrenzten
 * ToUnicode-Zeichentabellen.
 */
final class BegrenzterParser extends Parser
{
    /** Höchstens so viele Einträge legt eine Zeichentabelle an (zwei Byte je Code: mehr gibt es nicht). */
    public const MAX_ZEICHEN_JE_TABELLE = 65536;

    public readonly ZaehlenderFilterHelper $filter;

    /** Anzahl übersprungener Zeichentabellen (für das Protokoll). */
    public int $uebersprungeneTabellen = 0;

    private bool $tabellenGeprueft = false;

    /**
     * @param int $maxZeichen höchstens so viele Einträge in allen Zeichentabellen zusammen (je Schrift gezählt, die
     *                        Bibliothek lädt eine gemeinsame Tabelle für jede Schrift neu)
     */
    public function __construct(Config $config, int $entpackBudget, private int $maxObjekte, private int $maxZeichen = 8 * self::MAX_ZEICHEN_JE_TABELLE)
    {
        parent::__construct([], $config);
        $this->filter = new ZaehlenderFilterHelper($entpackBudget);
        $this->rawDataParser = new class ([], $config, $this->filter) extends RawDataParser {
            public function __construct(array $cfg, Config $config, FilterHelper $filter)
            {
                parent::__construct($cfg, $config);
                $this->filterHelper = $filter;
            }
        };
    }

    protected function parseObject(string $id, array $structure, ?Document $document)
    {
        parent::parseObject($id, $structure, $document);
        if (count($this->objects) > $this->maxObjekte) {
            throw new GrenzeUeberschritten('Zu viele Objekte');
        }
    }

    /**
     * Letzter Haken vor Document::setObjects(): dort lädt die Bibliothek für jede Schrift sofort die ToUnicode-Tabelle
     * (Font::init → loadTranslateTable). Ein einziger bfrange-Eintrag „<0000> <FFFFFFFF> <0041>“ in wenigen Bytes legte
     * dabei Milliarden Einträge an (gemessen: 1 Mio. ≈ 90 MB, 16 Mio. → Fatal Error bei 512 MB). Zu große Tabellen
     * werden deshalb vorher durch einen leeren Strom ersetzt; die Schrift liest sich dann wie ohne Tabelle.
     */
    protected function parseTrailer(array $structure, ?Document $document)
    {
        if ($document !== null && !$this->tabellenGeprueft) {
            $this->tabellenGeprueft = true;
            $this->begrenzeZeichentabellen($document);
        }
        return parent::parseTrailer($structure, $document);
    }

    private function begrenzeZeichentabellen(Document $document): void
    {
        $roh = fn(): array => $this->elements; // Header ohne Auflösen der Verweise (das Dokument kennt die Objekte noch nicht)
        /** @var array<string,int> $verweise Objekt-ID der Tabelle => Anzahl Schriften */
        $verweise = [];
        foreach ($this->objects as $objekt) {
            $kopf = $objekt instanceof Font ? $objekt->getHeader() : null;
            $tabelle = $kopf instanceof Header ? ($roh->call($kopf)['ToUnicode'] ?? null) : null;
            if ($tabelle instanceof ElementXRef) {
                $verweise[$tabelle->getId()] = ($verweise[$tabelle->getId()] ?? 0) + 1;
            }
        }
        /** @var array<string,float> $kosten */
        $kosten = [];
        foreach ($verweise as $id => $schriften) {
            $strom = $this->objects[$id] ?? null;
            if (!$strom instanceof PDFObject) {
                continue;
            }
            $eintraege = self::bereichsEintraege((string) $strom->getContent());
            if ($eintraege > self::MAX_ZEICHEN_JE_TABELLE) {
                $this->leere($id, $document);
                continue;
            }
            $kosten[$id] = $eintraege * $schriften;
        }
        asort($kosten);
        $summe = 0.0;
        foreach ($kosten as $id => $k) {
            $summe += $k;
            if ($summe > $this->maxZeichen) {
                $this->leere((string) $id, $document);
            }
        }
    }

    /**
     * Einträge, die loadTranslateTable() für die bfrange-Einträge mit Startwert („<von> <bis> <ziel>“) anlegt – mit
     * denselben regulären Ausdrücken wie die Bibliothek. Einträge mit Liste („[…]“) und bfchar sind durch die Länge des
     * Stroms begrenzt und zählen nicht.
     */
    public static function bereichsEintraege(string $inhalt): float
    {
        if (!str_contains($inhalt, 'beginbfrange')
            || !preg_match_all('/beginbfrange(?P<sections>.*?)endbfrange/s', $inhalt, $abschnitte)) {
            return 0.0;
        }
        $summe = 0.0;
        foreach ($abschnitte['sections'] as $abschnitt) {
            preg_match_all('/<(?P<from>[0-9A-F]+)> *<(?P<to>[0-9A-F]+)> *(?P<dest><[0-9A-F]+>|\[[\r\n<>0-9A-F ]+\])[ \r\n]+/is', $abschnitt, $t);
            foreach ($t['from'] as $i => $von) {
                if (1 === preg_match('/^<[0-9A-F]+>$/i', $t['dest'][$i])) {
                    $summe += max(0.0, (float) hexdec($t['to'][$i]) - (float) hexdec($von) + 1);
                }
            }
        }
        return $summe;
    }

    private function leere(string $id, Document $document): void
    {
        $this->objects[$id] = new PDFObject($document, new Header([], $document), '', $this->getConfig());
        $this->uebersprungeneTabellen++;
    }
}

<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

/**
 * Baut und parst XMP-Metadaten-Strings für die JPEG-Einbettung durch ExifService.
 *
 * Das erzeugte XMP ist Lightroom Classic / Adobe Camera Raw kompatibel.
 */
class XmpService
{
    // Lightroom-kompatible Label-Namen (kanonisch englisch)
    public const LABEL_MAP = [
        'Red'    => 'Red',
        'Yellow' => 'Yellow',
        'Green'  => 'Green',
        'Blue'   => 'Blue',
        'Purple' => 'Purple',
    ];

    // Lokalisierte Label-Namen → kanonisch englisch
    // photoshop:LabelColor (immer Kleinbuchstaben EN) wird per strcasecmp aufgelöst,
    // daher hier nur Varianten die NICHT bereits über LABEL_MAP greifen.
    private const LABEL_ALIASES = [
        // Deutsch (LR exportiert xmp:Label in der UI-Sprache)
        'Rot'  => 'Red',
        'Gelb' => 'Yellow',
        'Grün' => 'Green',   // UTF-8: ü = 0xC3 0xBC
        'Blau' => 'Blue',
        'Lila' => 'Purple',
    ];

    // Kanonisches EN → deutsches LR-Standard-Label-Set
    // Beim Schreiben im Modus "Lightroom (deutsche Lokalisierung)" verwendet, damit
    // DE-LRC den xmp:Label-String matchen kann (sonst zeigt LR "Custom" / weiße Fahne).
    // photoshop:LabelColor bleibt unabhängig immer lowercase EN — das treibt den
    // Farbstreifen in LR und ist universell.
    private const LABEL_MAP_DE = [
        'Red'    => 'Rot',
        'Yellow' => 'Gelb',
        'Green'  => 'Grün',
        'Blue'   => 'Blau',
        'Purple' => 'Lila',
    ];

    public const LABEL_LANG_EN = 'en';
    public const LABEL_LANG_DE = 'de';
    public const VALID_LABEL_LANGS = [self::LABEL_LANG_EN, self::LABEL_LANG_DE];

    // digiKam:ColorLabel → kanonisch englisch (numerische Werte 0–10)
    // Werte ohne StarRate-Äquivalent (2=Orange, 7=Grey, 8=Black, 9=White, 10=Darkred) → null
    private const DIGIKAM_COLOR_MAP = [
        1 => 'Red',
        3 => 'Yellow',
        4 => 'Green',
        5 => 'Blue',
        6 => 'Purple',
    ];

    // Pick-Werte → xmpDM-Attribut-Paare (LRC/Bridge-kompatibel)
    // xmpDM:pick: 1=Pick, -1=Reject, 0=none (none entspricht: Attribute komplett weglassen)
    // xmpDM:good: redundantes Boolean (true=Pick, false=Reject), wird parallel geschrieben
    public const VALID_PICKS = ['pick', 'reject', 'none'];
    private const PICK_MAP = [
        'pick'   => ['pick' => '1',  'good' => 'true'],
        'reject' => ['pick' => '-1', 'good' => 'false'],
    ];

    private const XMP_TEMPLATE = <<<'XMP'
<?xpacket begin='﻿' id='W5M0MpCehiHzreSzNTczkc9d'?>
<x:xmpmeta xmlns:x='adobe:ns:meta/' x:xmptk='StarRate 1.0'>
  <rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>
    <rdf:Description rdf:about=''
      xmlns:xmp='http://ns.adobe.com/xap/1.0/'%PHOTOSHOP_NS%%XMPDM_NS%
      %RATING_ATTR%%LABEL_ATTR%%PICK_ATTR%>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end='w'?>
XMP;

    // ─── XMP aufbauen / parsen ────────────────────────────────────────────────

    /**
     * Baut den XMP-Sidecar-Inhalt als String.
     *
     * Schreibt sowohl xmp:Label (sprachneutral kanonisch EN) als auch
     * photoshop:LabelColor (lowercase EN) — analog zu Lightroom Classic.
     * photoshop:LabelColor ist beim Re-Import durch LR persistenter, da LR
     * dieses Feld mit Priorität 1 liest.
     *
     * Pick/Reject als xmpDM:pick (1/-1) + xmpDM:good (true/false) — Bridge-/LRC-kompatibel.
     * Bei pick='none' oder pick=null werden die Attribute komplett weggelassen.
     *
     * @param string $lang 'en' = Bridge/digiKam-kompatibel (Red/Yellow/…),
     *                     'de' = Lightroom DE-Lokalisierung (Rot/Gelb/…).
     *                     Beeinflusst nur xmp:Label, nicht photoshop:LabelColor.
     */
    public function buildXmpContent(int $rating, ?string $label, ?string $pick = null, string $lang = self::LABEL_LANG_EN): string
    {
        $ratingAttr = "xmp:Rating=\"{$rating}\"";

        if ($label) {
            $labelLower    = strtolower($label);
            $localized     = self::localizeLabel($label, $lang);
            $labelAttr     = "\n      xmp:Label=\"{$localized}\""
                           . "\n      photoshop:LabelColor=\"{$labelLower}\"";
            $photoshopNs   = "\n      xmlns:photoshop='http://ns.adobe.com/photoshop/1.0/'";
        } else {
            $labelAttr   = '';
            $photoshopNs = '';
        }

        // PHP 8.5: null als Array-Offset ist deprecated → vor dem isset auf null prüfen.
        if ($pick !== null && isset(self::PICK_MAP[$pick])) {
            $pickValues = self::PICK_MAP[$pick];
            $pickAttr   = "\n      xmpDM:pick=\"{$pickValues['pick']}\""
                        . "\n      xmpDM:good=\"{$pickValues['good']}\"";
            $xmpDmNs    = "\n      xmlns:xmpDM='http://ns.adobe.com/xmp/1.0/DynamicMedia/'";
        } else {
            $pickAttr = '';
            $xmpDmNs  = '';
        }

        $xmp = str_replace(
            ['%RATING_ATTR%', '%LABEL_ATTR%', '%PICK_ATTR%', '%PHOTOSHOP_NS%', '%XMPDM_NS%'],
            [$ratingAttr, $labelAttr, $pickAttr, $photoshopNs, $xmpDmNs],
            self::XMP_TEMPLATE
        );

        return $xmp;
    }

    /**
     * Felder, die im XMP stehen, aber von parseXmpContent() nicht verstanden werden —
     * z.B. xmp:Rating="-1" (alter Lightroom-Reject), ein eigenes Label-Set
     * („To Print“) oder ein unbekannter xmpDM:pick-Wert. parseXmpContent() liefert
     * dafür „leer“; beim Patchen würden sie gelöscht. Wer nicht löschen darf
     * (z.B. „In Dateien schreiben“), muss solche Dateien auslassen.
     *
     * @return list<'rating'|'color'|'pick'>
     */
    public function unreadableFields(string $xmp): array
    {
        if (trim($xmp) === '') {
            return [];
        }
        $parsed = $this->parseXmpContent($xmp);
        $out    = [];

        foreach (['/(?:xmp|xap):Rating\s*=\s*[\'"]([^\'"]*)[\'"]/', '/<(?:xmp|xap):Rating>([^<]*)<\//'] as $re) {
            if (preg_match_all($re, $xmp, $m)) {
                foreach ($m[1] as $v) {
                    if (!preg_match('/^[0-5]$/', $v)) { $out[] = 'rating'; break 2; }
                }
            }
        }

        // Label: alles unlesbar, wenn parseXmpContent() nichts erkannt hat; sonst trotzdem
        // jeden xmp:Label/photoshop:LabelColor-Wert prüfen — ein eigenes Label wie
        // „To Print" neben einer bekannten Farbe würde beim Schreiben sonst überschrieben.
        $labelRes = [
            '/(?:xmp|xap):Label\s*=\s*[\'"]([^\'"]*)[\'"]/', '/<(?:xmp|xap):Label>([^<]*)<\//',
            '/photoshop:LabelColor\s*=\s*[\'"]([^\'"]*)[\'"]/', '/<photoshop:LabelColor>([^<]*)<\//',
            '/digiKam:ColorLabel\s*=\s*[\'"]([^\'"]*)[\'"]/', '/<digiKam:ColorLabel>([^<]*)<\//',
        ];
        foreach ($labelRes as $re) {
            if (!preg_match($re, $xmp, $m) || trim($m[1]) === '' || trim($m[1]) === '0') {
                continue;
            }
            if ($parsed['label'] === null
                || (!str_contains($re, 'digiKam') && $this->resolveLabel(trim($m[1])) === null)) {
                $out[] = 'color';
                break;
            }
        }

        foreach (['/xmpDM:pick\s*=\s*[\'"]([^\'"]*)[\'"]/', '/<xmpDM:pick>([^<]*)<\//'] as $re) {
            if (preg_match($re, $xmp, $m) && !preg_match('/^(-1|0|1)$/', $m[1])) {
                $out[] = 'pick';
                break;
            }
        }
        return $out;
    }

    /**
     * Parst einen XMP-String und gibt Rating + Label + Pick zurück.
     *
     * Rating-Auflösung:
     *  xmp:Rating / xap:Rating (xap: ist der alte exiftool/IDimager Alias, gleiche Namespace-URI)
     *
     * Label-Auflösung (Priorität hoch → niedrig):
     *  1. photoshop:LabelColor  — LR-proprietär, immer lowercase EN (red/yellow/…)
     *  2. xmp:Label             — Standard, sprachabhängig (Red/Rot/Rouge/…)
     *  3. digiKam:ColorLabel    — numerisch (1=Red, 3=Yellow, 4=Green, 5=Blue, 6=Purple)
     *
     * Pick-Auflösung (Priorität hoch → niedrig):
     *  1. xmpDM:pick   — 1=Pick, -1=Reject, 0=none (Bridge/LRC-Standard)
     *  2. flashView:IsPicked / flashView:IsRejected — alter FlashView-Namespace (rückwärtskompatibel)
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    public function parseXmpContent(string $xmp): array
    {
        $rating = 0;
        $label  = null;
        $pick   = 'none';

        // xmp:Rating / xap:Rating als Attribut oder Element
        if (preg_match('/(?:xmp|xap):Rating\s*=\s*[\'"](\d+)[\'"]/', $xmp, $m)) {
            $val = (int) $m[1];
            $rating = ($val >= 0 && $val <= 5) ? $val : 0;
        } elseif (preg_match('/<(?:xmp|xap):Rating>(\d+)<\/(?:xmp|xap):Rating>/', $xmp, $m)) {
            $val = (int) $m[1];
            $rating = ($val >= 0 && $val <= 5) ? $val : 0;
        }

        // photoshop:LabelColor (Prio 1) — immer lowercase EN, z.B. "red", "green"
        if (preg_match('/photoshop:LabelColor\s*=\s*[\'"]([^\'"]+)[\'"]/', $xmp, $m)
            || preg_match('/<photoshop:LabelColor>([^<]+)<\/photoshop:LabelColor>/', $xmp, $m)) {
            $label = $this->resolveLabel(trim($m[1]));
        }

        // xmp:Label / xap:Label (Prio 2, nur wenn photoshop:LabelColor nicht aufgelöst)
        // Sprachabhängig: akzeptiert EN (Red/red/RED) und DE (Rot/Gelb/Grün/…)
        if ($label === null
            && (preg_match('/(?:xmp|xap):Label\s*=\s*[\'"]([^\'"]+)[\'"]/', $xmp, $m)
                || preg_match('/<(?:xmp|xap):Label>([^<]+)<\/(?:xmp|xap):Label>/', $xmp, $m))) {
            $label = $this->resolveLabel(trim($m[1]));
        }

        // digiKam:ColorLabel (Prio 3) — numerisch, nur wenn noch kein Label gefunden
        if ($label === null
            && (preg_match('/digiKam:ColorLabel\s*=\s*[\'"](\d+)[\'"]/', $xmp, $m)
                || preg_match('/<digiKam:ColorLabel>(\d+)<\/digiKam:ColorLabel>/', $xmp, $m))) {
            $label = self::DIGIKAM_COLOR_MAP[(int) $m[1]] ?? null;
        }

        // xmpDM:pick (Prio 1) — 1=Pick, -1=Reject, 0/sonst=none
        if (preg_match('/xmpDM:pick\s*=\s*[\'"](-?\d+)[\'"]/', $xmp, $m)
            || preg_match('/<xmpDM:pick>(-?\d+)<\/xmpDM:pick>/', $xmp, $m)) {
            $pickVal = (int) $m[1];
            if ($pickVal === 1) {
                $pick = 'pick';
            } elseif ($pickVal === -1) {
                $pick = 'reject';
            }
        }

        // flashView:IsPicked / IsRejected (Prio 2, nur wenn xmpDM:pick nicht aufgelöst)
        // Rückwärtskompatibel mit altem FlashView-Schema (vor xmpDM-Migration)
        if ($pick === 'none') {
            if (preg_match('/flashView:IsPicked\s*=\s*[\'"](True|true|1)[\'"]/', $xmp)) {
                $pick = 'pick';
            } elseif (preg_match('/flashView:IsRejected\s*=\s*[\'"](True|true|1)[\'"]/', $xmp)) {
                $pick = 'reject';
            }
        }

        return ['rating' => $rating, 'label' => $label, 'pick' => $pick];
    }

    /**
     * Übersetzt einen kanonisch englischen Label-Namen in die Ziel-Sprache.
     * Unbekannte Sprachen oder Labels → unverändert (EN).
     */
    public static function localizeLabel(string $canonicalEn, string $lang): string
    {
        if ($lang === self::LABEL_LANG_DE && isset(self::LABEL_MAP_DE[$canonicalEn])) {
            return self::LABEL_MAP_DE[$canonicalEn];
        }
        return $canonicalEn;
    }

    /**
     * Normalisiert einen Label-String auf den kanonischen englischen Namen.
     * Akzeptiert:
     *  - Englisch, case-insensitiv: red/Red/RED → Red
     *  - Deutsch (LR UI-Sprache): Rot/Gelb/Grün/Blau/Lila → Red/Yellow/Green/Blue/Purple
     * Unbekannte Werte → null.
     */
    private function resolveLabel(string $val): ?string
    {
        // Exakter Treffer (kanonisch englisch)
        if (isset(self::LABEL_MAP[$val])) {
            return $val;
        }

        // Case-insensitiver englischer Fallback (z.B. "red" → "Red", "GREEN" → "Green")
        foreach (array_keys(self::LABEL_MAP) as $canonical) {
            if (strcasecmp($val, $canonical) === 0) {
                return $canonical;
            }
        }

        // Alias-Map (z.B. deutsch: "Rot" → "Red", "Grün" → "Green")
        if (isset(self::LABEL_ALIASES[$val])) {
            return self::LABEL_ALIASES[$val];
        }

        return null;
    }
}

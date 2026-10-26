<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Liest und schreibt XMP/EXIF-Metadaten direkt in JPEG-Dateien.
 *
 * Verwendet einen eigenständigen XMP-Ansatz (kein pel/pel für XMP,
 * da PEL nur EXIF-IFD verwaltet). XMP wird als separates APP1-Segment
 * in den JPEG-Datenstrom eingebettet.
 *
 * Unterstützte Formate: JPEG / JPG
 * Schreibt: xmp:Rating (0–5), xmp:Label + photoshop:LabelColor,
 *           xmpDM:pick + xmpDM:good (Pick/Reject, LRC/Bridge-kompatibel).
 */
class ExifService implements XmpStore
{
    // XMP-Namespace-Marker im JPEG
    private const XMP_MARKER     = "\xFF\xE1";
    private const XMP_NAMESPACE  = 'http://ns.adobe.com/xap/1.0/';
    private const XMP_MAGIC      = "http://ns.adobe.com/xap/1.0/\x00";

    // Lightroom-kompatible Label-Namen (kanonische Quelle: XmpService::LABEL_MAP)
    public const LABEL_MAP = XmpService::LABEL_MAP;

    // Maximale Header-Bytes die zum Lesen von XMP/EXIF nötig sind (256 KB).
    // XMP sitzt in APP1-Segmenten vor dem SOS-Marker, typisch unter 100 KB.
    private const READ_HEADER_SIZE = 262_144;

    public function __construct(
        private readonly XmpService      $xmpService,
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Öffentliche API ──────────────────────────────────────────────────────

    /**
     * Schreibt Rating und/oder Label und/oder Pick in die JPEG-Datei.
     * Alle anderen XMP-Felder (Lightroom-Metadaten etc.) bleiben erhalten.
     *
     * @param  File         $file   Nextcloud File-Objekt
     * @param  int|null     $rating 0–5 (null = nicht ändern)
     * @param  string|null  $label  Red/Yellow/Green/Blue/Purple/'' (null = nicht ändern, '' = entfernen)
     * @param  string|null  $pick   pick/reject/none (null = nicht ändern, none = entfernen)
     * @param  string       $lang   'en' (Bridge/digiKam) oder 'de' (Lightroom DE) — beeinflusst nur xmp:Label
     * @throws \RuntimeException bei Fehler
     */
    public function writeMetadata(
        File $file,
        ?int $rating = null,
        ?string $label = null,
        ?string $pick = null,
        string $lang = XmpService::LABEL_LANG_EN,
    ): void {
        $this->validateInputs($rating, $label, $pick);

        $content = $file->getContent();
        if (!$this->isJpeg($content)) {
            throw new \RuntimeException("Not a valid JPEG file: " . $file->getName());
        }

        $updated = $this->applyMetadataToContent($content, $rating, $label, $pick, $lang);
        $file->putContent($updated);

        $this->logger->info(sprintf(
            'StarRate: XMP written to %s — rating: %s, label: %s, pick: %s, lang: %s',
            $file->getName(),
            $rating ?? 'unchanged',
            $label  ?? 'unchanged',
            $pick   ?? 'unchanged',
            $lang
        ));
    }

    /**
     * Liest Rating und Label aus einer JPEG-Datei.
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    public function readMetadata(File $file): array
    {
        try {
            // Read only the JPEG header — XMP/EXIF sits before the SOS marker,
            // typically well within 256 KB.  Avoids loading 30 MB+ RAW JPEGs.
            $handle = $file->fopen('rb');
            if ($handle === false) {
                return self::emptyResult();
            }
            // stream_get_contents schleift intern bis Limit oder EOF erreicht ist.
            // Direktes fread() liefert in NC's Storage-Stack (z.B. Files_Trashbin
            // Wrapper) oft nur einen 8 KB-Block zurück — das XMP-APP1-Segment liegt
            // typischerweise bei Offset 15–25 KB (nach EXIF), wäre damit unsichtbar.
            $header = stream_get_contents($handle, self::READ_HEADER_SIZE);
            fclose($handle);

            if ($header === false || !$this->isJpeg($header)) {
                return self::emptyResult();
            }
            $xmp = $this->readXmpFromContent($header);
            // EXIF-Fallback nur wenn das XMP komplett leer war — sonst überschreiben
            // wir z.B. einen vorhandenen Pick-Wert mit dem leeren EXIF-Default.
            if ($xmp['rating'] === 0 && $xmp['label'] === null && $xmp['pick'] === 'none') {
                return $this->readExifRatingFromContent($header);
            }
            return $xmp;
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: failed to read metadata from {$file->getName()}: " . $e->getMessage());
            return self::emptyResult();
        }
    }

    /**
     * Liest Metadaten aus rohem JPEG-Inhalt (für Tests und interne Nutzung).
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    public function readMetadataFromContent(string $content): array
    {
        if (!$this->isJpeg($content)) {
            return self::emptyResult();
        }
        $xmp = $this->readXmpFromContent($content);
        if ($xmp['rating'] === 0 && $xmp['label'] === null && $xmp['pick'] === 'none') {
            return $this->readExifRatingFromContent($content);
        }
        return $xmp;
    }

    /** @return array{rating: int, label: string|null, pick: string} */
    private static function emptyResult(): array
    {
        return ['rating' => 0, 'label' => null, 'pick' => 'none'];
    }

    /**
     * Schreibt Metadaten in rohen JPEG-Inhalt und gibt den aktualisierten Inhalt zurück.
     * Alle anderen XMP-Felder bleiben erhalten. Hauptsächlich für Tests.
     */
    public function writeMetadataToContent(
        string $content,
        ?int $rating = null,
        ?string $label = null,
        ?string $pick = null,
        string $lang = XmpService::LABEL_LANG_EN,
    ): string {
        $this->validateInputs($rating, $label, $pick);

        if (!$this->isJpeg($content)) {
            throw new \RuntimeException('Content is not a valid JPEG.');
        }

        return $this->applyMetadataToContent($content, $rating, $label, $pick, $lang);
    }

    /**
     * Liest Metadaten aus einem eigenständigen XMP-Paket (z.B. RAW-Sidecar).
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    public function readMetadataFromXmp(string $xmp): array
    {
        return trim($xmp) === '' ? self::emptyResult() : $this->parseXmp($xmp);
    }

    /**
     * Schreibt Metadaten in ein eigenständiges XMP-Paket (z.B. RAW-Sidecar) und gibt
     * es zurück. Gleiche Patch-Regeln wie beim JPEG; $xmp === null baut ein neues Paket.
     *
     * @throws \RuntimeException wenn das vorhandene XMP nicht gepatcht werden kann —
     *         es wird nie ersetzt, es könnte eine Lightroom-Entwicklung enthalten.
     */
    public function writeMetadataToXmp(
        ?string $xmp,
        ?int $rating = null,
        ?string $label = null,
        ?string $pick = null,
        string $lang = XmpService::LABEL_LANG_EN,
    ): string {
        $this->validateInputs($rating, $label, $pick);

        if ($xmp !== null && !str_contains($xmp, '<rdf:Description')) {
            throw new \RuntimeException('XMP packet has no rdf:Description block.');
        }
        return $this->applyMetadataToXmp($xmp, $rating, $label, $pick, $lang, strict: true);
    }

    /**
     * Prüft ob eine Datei ein JPEG ist (anhand Magic Bytes, nicht Dateiendung).
     */
    public function isJpegFile(File $file): bool
    {
        try {
            $handle = $file->fopen('rb');
            if ($handle === false) {
                return false;
            }
            $header = fread($handle, 2);
            fclose($handle);
            return $header !== false && $this->isJpeg($header);
        } catch (\Exception) {
            return false;
        }
    }

    // ─── Kernlogik: Metadaten anwenden ───────────────────────────────────────

    /**
     * Wendet Rating, Label und Pick auf JPEG-Inhalt an.
     * Vorhandene XMP-Felder (Lightroom-Metadaten etc.) bleiben erhalten.
     */
    private function applyMetadataToContent(string $content, ?int $rating, ?string $label, ?string $pick, string $lang): string
    {
        $newXmp = $this->applyMetadataToXmp($this->extractXmpBlock($content), $rating, $label, $pick, $lang);
        return $this->embedXmpInJpeg($content, $newXmp);
    }

    /**
     * Wendet Rating, Label und Pick auf einen XMP-Block an (null = neues Paket bauen).
     * $strict: ein vorhandener Block wird nie durch ein frisches Paket ersetzt.
     */
    private function applyMetadataToXmp(?string $xmpBlock, ?int $rating, ?string $label, ?string $pick, string $lang, bool $strict = false): string
    {
        $existing = $xmpBlock !== null ? $this->parseXmp($xmpBlock) : self::emptyResult();
        $merged   = $this->mergeXmpData($existing, $rating, $label, $pick);

        return $xmpBlock !== null
            ? $this->patchXmpString($xmpBlock, $merged['rating'], $merged['label'], $merged['pick'], $lang, $strict)
            : $this->buildXmpPacket($merged['rating'], $merged['label'], $merged['pick'], $lang);
    }

    /**
     * Aktualisiert xmp:Rating und xmp:Label in einem bestehenden XMP-String,
     * ohne andere Felder zu verändern (Issue #16: keine Datenverluste mehr).
     *
     * Unterstützt xmp: und xap: Namespace-Alias, Attribut- und Element-Form.
     * Schreibt immer in Attribut-Form zurück, konsistent mit dem Prefix der Datei.
     * Bei Multi-Block-XMP wird der Block mit der xmlns:xmp/xap-Deklaration gezielt befüllt.
     */
    private function patchXmpString(
        string $existingXmp,
        int $rating,
        ?string $label,
        ?string $pick = 'none',
        string $lang = XmpService::LABEL_LANG_EN,
        bool $strict = false,
    ): string {
        $patched = $existingXmp;

        // Vorhandene Rating-, Label- und Pick-Felder entfernen.
        // photoshop:LabelColor und xmpDM:pick/good werden ebenfalls weggeräumt, sonst bliebe
        // ein veralteter Wert stehen und LR/Bridge würde beim Re-Import unsere Änderung ignorieren.
        // Altes flashView:IsPicked/IsRejected (inkl. xmlns:flashView) wird aktiv aufgeräumt
        // — neues Schema ist xmpDM:pick (Bridge/LRC-Standard).
        $stripPatterns = [
            '/[ \t]*(?:xmp|xap):Rating\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
            '/<(?:xmp|xap):Rating>[^<]*<\/(?:xmp|xap):Rating>\s*/',
            '/[ \t]*(?:xmp|xap):Label\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
            '/<(?:xmp|xap):Label>[^<]*<\/(?:xmp|xap):Label>\s*/',
            '/[ \t]*photoshop:LabelColor\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
            '/<photoshop:LabelColor>[^<]*<\/photoshop:LabelColor>\s*/',
            '/[ \t]*xmpDM:pick\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
            '/<xmpDM:pick>[^<]*<\/xmpDM:pick>\s*/',
            '/[ \t]*xmpDM:good\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
            '/<xmpDM:good>[^<]*<\/xmpDM:good>\s*/',
            '/[ \t]*flashView:Is(?:Picked|Rejected)\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
            '/<flashView:Is(?:Picked|Rejected)>[^<]*<\/flashView:Is(?:Picked|Rejected)>\s*/',
            // Verwaiste flashView-Namespace-Deklaration entfernen (Whitespace-Linie + Attribut)
            '/[ \t]*xmlns:flashView\s*=\s*(?:"[^"]*"|\'[^\']*\')/',
        ];
        $pcreFailed = false;
        foreach ($stripPatterns as $pattern) {
            $result = preg_replace($pattern, '', $patched);
            if ($result === null) {
                $pcreFailed = true;
                continue;
            }
            $patched = $result;
        }

        // Richtigen Block finden: der mit xmlns:xmp= oder xmlns:xap= Deklaration.
        // Bei Multi-Block-XMP (exiftool, FujiFilm etc.) sitzt xmp: oft nicht in Block 1.
        [$targetBlock, $needsNsDecl, $needsPhotoshopNs, $needsXmpDmNs, $prefix] = $this->findXmpBlock($patched);

        $hasLabel        = ($label !== null && $label !== '');
        $hasPick         = ($pick !== null && $pick !== '' && $pick !== 'none');

        $ratingAttr      = "\n      {$prefix}:Rating=\"{$rating}\"";
        // xmp:Label wird ggf. lokalisiert (Bridge-/digiKam-EN vs LR-DE).
        // photoshop:LabelColor bleibt unabhängig immer lowercase EN.
        $localizedLabel  = $hasLabel ? XmpService::localizeLabel($label, $lang) : $label;
        $labelAttr       = $hasLabel ? "\n      {$prefix}:Label=\"{$localizedLabel}\"" : '';
        $photoshopAttr   = $hasLabel ? "\n      photoshop:LabelColor=\"" . strtolower($label) . "\"" : '';

        // pick → xmpDM:pick="1" + xmpDM:good="true"
        // reject → xmpDM:pick="-1" + xmpDM:good="false"
        $pickAttr = '';
        if ($hasPick) {
            $pickNum  = $pick === 'pick' ? '1'    : '-1';
            $goodVal  = $pick === 'pick' ? 'true' : 'false';
            $pickAttr = "\n      xmpDM:pick=\"{$pickNum}\"\n      xmpDM:good=\"{$goodVal}\"";
        }

        $nsDecl          = $needsNsDecl ? "\n      xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\"" : '';
        $photoshopNsDecl = ($hasLabel && $needsPhotoshopNs)
            ? "\n      xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\""
            : '';
        $xmpDmNsDecl     = ($hasPick && $needsXmpDmNs)
            ? "\n      xmlns:xmpDM=\"http://ns.adobe.com/xmp/1.0/DynamicMedia/\""
            : '';

        // In den gefundenen Block injizieren (limit=-1 damit der Callback alle Blöcke sieht,
        // aber nur den Zielblock modifiziert).
        $seen     = 0;
        $count    = 0;
        $injected = preg_replace_callback(
            '/(<rdf:Description\b[^>]*?)\s*(\/?>)/s',
            static function (array $m) use ($ratingAttr, $labelAttr, $photoshopAttr, $pickAttr, $nsDecl, $photoshopNsDecl, $xmpDmNsDecl, $targetBlock, &$seen): string {
                $seen++;
                if ($seen !== $targetBlock) {
                    return $m[0];
                }
                return $m[1]
                    . $nsDecl . $photoshopNsDecl . $xmpDmNsDecl
                    . $ratingAttr . $labelAttr . $photoshopAttr . $pickAttr
                    . "\n    " . $m[2];
            },
            $patched,
            -1,
            $count
        );

        // Strikt (RAW-Sidecar): nie ersetzen — das Paket kann eine Lightroom-Entwicklung
        // enthalten. Lieber gar nicht schreiben als Entwicklungseinstellungen verlieren.
        if ($strict && ($injected === null || $count === 0 || $pcreFailed)) {
            throw new \RuntimeException('XMP packet could not be patched safely; left untouched.');
        }

        // Fallback: kein rdf:Description gefunden oder Regex-Fehler → frisches XMP
        if ($injected === null || $count === 0) {
            return $this->xmpService->buildXmpContent($rating, $label, $pick, $lang);
        }

        return $injected;
    }

    // ─── XMP lesen ────────────────────────────────────────────────────────────

    /**
     * Extrahiert Rating, Label und Pick aus dem JPEG-Inhalt.
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    private function readXmpFromContent(string $content): array
    {
        $xmpBlock = $this->extractXmpBlock($content);
        if ($xmpBlock === null) {
            return self::emptyResult();
        }

        return $this->parseXmp($xmpBlock);
    }

    /**
     * Liest EXIF-Rating als Fallback wenn kein XMP vorhanden (z.B. digiKam-Bilder).
     * EXIF kennt kein Farb-Label und kein Pick-Flag — beide bleiben Default.
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    private function readExifRatingFromContent(string $content): array
    {
        if (!function_exists('exif_read_data')) {
            return self::emptyResult();
        }

        // EXIF steht immer in den ersten Segmenten — 64 KB reichen aus.
        // php://memory vermeidet Tempfile auf der Festplatte.
        $tmp = fopen('php://memory', 'r+b');
        if ($tmp === false) {
            return self::emptyResult();
        }

        try {
            fwrite($tmp, substr($content, 0, 65536));
            rewind($tmp);
            $exif = @exif_read_data($tmp, 'IFD0');
        } finally {
            fclose($tmp);
        }

        if (!is_array($exif) || !isset($exif['Rating'])) {
            return self::emptyResult();
        }

        $rating = (int) $exif['Rating'];
        if ($rating < 0 || $rating > 5) {
            return self::emptyResult();
        }

        return ['rating' => $rating, 'label' => null, 'pick' => 'none'];
    }

    /**
     * Sucht das XMP-APP1-Segment im JPEG-Datenstrom.
     */
    private function extractXmpBlock(string $content): ?string
    {
        $len    = strlen($content);
        $offset = 2; // SOI überspringen

        while ($offset < $len - 4) {
            if ($content[$offset] !== "\xFF") {
                break;
            }

            $marker     = $content[$offset + 1];
            $segmentLen = (ord($content[$offset + 2]) << 8) | ord($content[$offset + 3]);

            // APP1 = 0xE1
            if ($marker === "\xE1" && $segmentLen > strlen(self::XMP_MAGIC)) {
                $payload = substr($content, $offset + 4, $segmentLen - 2);
                if (str_starts_with($payload, self::XMP_MAGIC)) {
                    return substr($payload, strlen(self::XMP_MAGIC));
                }
            }

            // SOS (Start of Scan) → Bilddaten beginnen, kein weiteres Segment
            if ($marker === "\xDA") {
                break;
            }

            $offset += 2 + $segmentLen;
        }

        return null;
    }

    /**
     * Parst einen XMP-String und extrahiert Rating und Label.
     * Delegiert an XmpService::parseXmpContent().
     *
     * @return array{rating: int, label: string|null}
     */
    private function parseXmp(string $xmp): array
    {
        return $this->xmpService->parseXmpContent($xmp);
    }

    // ─── XMP schreiben ────────────────────────────────────────────────────────

    /**
     * Merged vorhandene Daten mit neuen Werten (null = bestehenden Wert beibehalten).
     * Für pick: 'none' und '' gelten als "entfernen", null als "unverändert".
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    private function mergeXmpData(array $existing, ?int $rating, ?string $label, ?string $pick): array
    {
        return [
            'rating' => $rating !== null ? $rating : $existing['rating'],
            'label'  => $label  !== null ? ($label === '' ? null : $label) : ($existing['label'] ?? null),
            'pick'   => $pick   !== null ? ($pick === '' ? 'none' : $pick) : ($existing['pick'] ?? 'none'),
        ];
    }

    /**
     * Baut ein vollständiges XMP-Paket als String.
     * Delegiert an XmpService::buildXmpContent().
     */
    private function buildXmpPacket(int $rating, ?string $label, ?string $pick = null, string $lang = XmpService::LABEL_LANG_EN): string
    {
        return $this->xmpService->buildXmpContent($rating, $label, $pick, $lang);
    }

    /**
     * Bettet das XMP-Paket in den JPEG-Datenstrom ein.
     * Ersetzt ein vorhandenes XMP-Segment oder fügt eines nach SOI ein.
     */
    private function embedXmpInJpeg(string $content, string $xmpPacket): string
    {
        $xmpPayload   = self::XMP_MAGIC . $xmpPacket;
        $segmentLen   = strlen($xmpPayload) + 2; // +2 für Längenfeld selbst

        if ($segmentLen > 0xFFFF) {
            throw new \RuntimeException('XMP packet too large for a single JPEG segment (max 65533 bytes).');
        }

        $newSegment = self::XMP_MARKER
            . chr(($segmentLen >> 8) & 0xFF)
            . chr($segmentLen & 0xFF)
            . $xmpPayload;

        // Vorhandenes XMP-Segment suchen und ersetzen
        $result = $this->replaceExistingXmpSegment($content, $newSegment);
        if ($result !== null) {
            return $result;
        }

        // Kein vorhandenes XMP → nach SOI (2 Bytes) einfügen
        return substr($content, 0, 2) . $newSegment . substr($content, 2);
    }

    /**
     * Sucht und ersetzt ein vorhandenes XMP-APP1-Segment.
     * Gibt null zurück wenn kein XMP-Segment gefunden.
     */
    private function replaceExistingXmpSegment(string $content, string $newSegment): ?string
    {
        $len    = strlen($content);
        $offset = 2;

        while ($offset < $len - 4) {
            if ($content[$offset] !== "\xFF") {
                break;
            }

            $marker     = $content[$offset + 1];
            $segmentLen = (ord($content[$offset + 2]) << 8) | ord($content[$offset + 3]);

            if ($marker === "\xE1" && $segmentLen > strlen(self::XMP_MAGIC)) {
                $payload = substr($content, $offset + 4, $segmentLen - 2);
                if (str_starts_with($payload, self::XMP_MAGIC)) {
                    // Dieses Segment ersetzen
                    return substr($content, 0, $offset)
                        . $newSegment
                        . substr($content, $offset + 2 + $segmentLen);
                }
            }

            if ($marker === "\xDA") {
                break;
            }

            $offset += 2 + $segmentLen;
        }

        return null;
    }

    // ─── Hilfsmethoden ───────────────────────────────────────────────────────

    /**
     * Findet den rdf:Description-Block mit xmp:- oder xap:-Namespace-Deklaration.
     *
     * Gibt [blockIndex (1-basiert), needsNsDecl, needsPhotoshopNs, needsXmpDmNs, prefix] zurück:
     *  - blockIndex:        welcher Block (1 = erster)
     *  - needsNsDecl:       true wenn kein Block xmlns:xmp deklariert → in Block 1 ergänzen
     *  - needsPhotoshopNs:  true wenn der Zielblock xmlns:photoshop nicht deklariert
     *  - needsXmpDmNs:      true wenn der Zielblock xmlns:xmpDM nicht deklariert
     *  - prefix:            'xmp' oder 'xap' (konsistent mit dem was die Datei benutzt)
     *
     * @return array{int, bool, bool, bool, string}
     */
    private function findXmpBlock(string $xmp): array
    {
        // Gleicher Regex wie im Injection-Callback, aber mit non-capturing Gruppe
        // fuer den schliessenden Tag-Delimiter, da wir hier nur Gruppe 1 brauchen.
        preg_match_all('/(<rdf:Description\b[^>]*?)\s*(?:\/?>)/s', $xmp, $matches);

        foreach ($matches[1] as $i => $openingTag) {
            if (str_contains($openingTag, 'xmlns:xmp=')) {
                $needsPhotoshop = !str_contains($openingTag, 'xmlns:photoshop=');
                $needsXmpDm     = !str_contains($openingTag, 'xmlns:xmpDM=');
                return [$i + 1, false, $needsPhotoshop, $needsXmpDm, 'xmp'];
            }
            if (str_contains($openingTag, 'xmlns:xap=')) {
                $needsPhotoshop = !str_contains($openingTag, 'xmlns:photoshop=');
                $needsXmpDm     = !str_contains($openingTag, 'xmlns:xmpDM=');
                return [$i + 1, false, $needsPhotoshop, $needsXmpDm, 'xap'];
            }
        }

        // Kein Block mit xmp/xap-Namespace → Block 1, alle Namespaces ergänzen
        return [1, true, true, true, 'xmp'];
    }

    private function isJpeg(string $content): bool
    {
        return strlen($content) >= 2
            && $content[0] === "\xFF"
            && $content[1] === "\xD8";
    }

    private function validateInputs(?int $rating, ?string $label, ?string $pick = null): void
    {
        if ($rating !== null && ($rating < 0 || $rating > 5)) {
            throw new \InvalidArgumentException("Rating must be between 0 and 5, got: {$rating}");
        }

        if ($label !== null && $label !== '' && !isset(self::LABEL_MAP[$label])) {
            throw new \InvalidArgumentException(
                "Invalid label: {$label}. Allowed: " . implode(', ', array_keys(self::LABEL_MAP))
            );
        }

        if ($pick !== null && $pick !== '' && !in_array($pick, XmpService::VALID_PICKS, true)) {
            throw new \InvalidArgumentException(
                "Invalid pick: {$pick}. Allowed: " . implode(', ', XmpService::VALID_PICKS)
            );
        }
    }
}

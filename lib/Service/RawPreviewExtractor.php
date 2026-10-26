<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Holt die von der Kamera eingebettete JPEG-Vorschau aus einer RAW-Datei
 * (Nikon NEF und andere TIFF-basierte RAWs wie CR2, ARW, DNG, PEF, dazu Panasonic
 * RW2 (JpgFromRaw-Tag), Olympus ORF (MakerNote), Fuji RAF und Canon CR3
 * (ISO-BMFF-Container wie MP4).
 *
 * Es wird nichts entwickelt/demosaiced: Kameras betten ein oder mehrere JPEGs
 * ein (Thumbnail, mittlere Vorschau, meist ein volles "JpgFromRaw"). Wir laufen
 * den TIFF-IFD-Baum ab (IFD0 → Folge-IFDs → SubIFDs), sammeln alle Baseline-/
 * Progressive-JPEGs, nehmen das kleinste, das die angefragte Größe abdeckt,
 * wenden die EXIF-Orientierung der RAW an und skalieren. Gelesen werden nur die
 * IFD-Header und das gewählte JPEG — nie die Sensordaten.
 *
 * Hinweis: Das ist die Kamera-Darstellung (Picture Control / Weißabgleich wie
 * aufgenommen), keine Lightroom-Entwicklung.
 */
class RawPreviewExtractor
{
    // Nextcloud mappt nef, cr2, arw, dng, raf, orf, pef, rw2 … auf diesen Typ.
    public const MIME = 'image/x-dcraw';

    // Endungen, deren Vorschau wir finden (geprüft mit Beispieldateien von
    // raw.pixls.us). Andere image/x-dcraw-Formate (MRW, X3F, IIQ, …) werden gar
    // nicht erst gelistet — sonst stünden leere Kacheln im Grid.
    public const EXTENSIONS = ['nef', 'cr2', 'cr3', 'arw', 'dng', 'pef', 'orf', 'rw2', 'raf'];

    // Endungen, die Nextcloud selbst nicht auf MIME abbildet (siehe Application)
    public const EXTRA_MAPPINGS = ['cr3' => [self::MIME]];

    // TIFF-Tags
    private const TAG_COMPRESSION   = 0x0103;
    private const TAG_STRIP_OFFSETS = 0x0111;
    private const TAG_ORIENTATION   = 0x0112;
    private const TAG_STRIP_COUNTS  = 0x0117;
    private const TAG_SUB_IFDS      = 0x014A;
    private const TAG_JPEG_OFFSET   = 0x0201;
    private const TAG_JPEG_LENGTH   = 0x0202;
    private const TAG_RW2_JPEG      = 0x002E;   // Panasonic JpgFromRaw (UNDEFINED-Blob in IFD0)
    private const TAG_EXIF_IFD      = 0x8769;
    private const TAG_MAKERNOTE     = 0x927C;
    private const WANTED_TAGS = [
        self::TAG_COMPRESSION, self::TAG_STRIP_OFFSETS, self::TAG_ORIENTATION,
        self::TAG_STRIP_COUNTS, self::TAG_SUB_IFDS, self::TAG_JPEG_OFFSET, self::TAG_JPEG_LENGTH,
        self::TAG_RW2_JPEG, self::TAG_EXIF_IFD, self::TAG_MAKERNOTE,
    ];

    // Olympus-MakerNote: Thumbnail im Haupt-IFD, Vorschau im CameraSettings-Sub-IFD
    private const OLY_THUMBNAIL       = 0x0100;
    private const OLY_CAMERA_SETTINGS = 0x2020;
    private const OLY_PREVIEW_START   = 0x0101;
    private const OLY_PREVIEW_LENGTH  = 0x0102;

    // Canon CR3: uuid-Box in moov (THMB + CMT1-EXIF) bzw. auf Top-Level (PRVW)
    private const CR3_CANON_UUID   = "\x85\xc0\xb6\x87\x82\x0f\x11\xe0\x81\x11\xf4\xce\x46\x2b\x6a\x48";
    private const CR3_PREVIEW_UUID = "\xea\xf4\x2b\x5e\x1c\x98\x4b\x88\xb9\xfb\xb7\xdc\x40\x6e\x4d\x16";

    // Schutzgrenzen gegen kaputte/bösartige Dateien
    private const MAX_IFDS        = 32;
    private const MAX_IFD_ENTRIES = 1024;
    private const MAX_BMFF_BOXES  = 512;
    private const JPEG_HEAD_BYTES = 262_144;
    private const MAX_JPEG_BYTES  = 80 * 1024 * 1024;
    private const MIN_JPEG_BYTES  = 128;
    private const OUTPUT_QUALITY  = 90;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Öffentliche API ──────────────────────────────────────────────────────

    /**
     * Ob wir für diese Datei (nach Endung) eine Vorschau liefern können.
     */
    public static function supports(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Listen-Filter für Grid und Gast-Galerien: Nicht-RAWs immer, RAWs nur in
     * unterstützten Formaten und nur, wenn der User sie sehen will (show_raw).
     */
    public static function isListable(string $mime, string $filename, bool $showRaw = true): bool
    {
        return $mime !== self::MIME || ($showRaw && self::supports($filename));
    }

    /**
     * Liefert ein JPEG (Bytes) von höchstens $maxX × $maxY, korrekt orientiert,
     * oder null, wenn die Datei keine brauchbare Vorschau enthält.
     */
    public function extractPreview(File $file, int $maxX, int $maxY): ?string
    {
        $stream = $this->openSeekable($file);
        if ($stream === null) {
            return null;
        }
        try {
            return $this->extractPreviewFromStream($stream, $maxX, $maxY);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Wie extractPreview(), aber auf einem bereits geöffneten, seekbaren Stream.
     *
     * @param resource $stream
     */
    public function extractPreviewFromStream($stream, int $maxX, int $maxY): ?string
    {
        $info = $this->inspectStream($stream);

        foreach ($this->orderCandidates($info['candidates'], max($maxX, $maxY)) as $candidate) {
            $data = $this->readRange($stream, $candidate['offset'], $candidate['length']);
            if ($data === null) {
                continue;
            }
            $orientation = $info['orientation'] ?: $this->jpegExifOrientation($data);
            $out = $this->render($data, $orientation, $maxX, $maxY);
            if ($out !== null) {
                return $out;
            }
        }
        return null;
    }

    /**
     * Listet alle eingebetteten JPEGs plus die Orientierung der RAW (0 = nicht gesetzt).
     *
     * @param  resource $stream
     * @return array{orientation: int, candidates: list<array{offset: int, length: int, width: int, height: int}>}
     */
    public function inspectStream($stream): array
    {
        $result = ['orientation' => 0, 'candidates' => []];

        $header = $this->readRange($stream, 0, 16);
        if ($header === null || strlen($header) < 16) {
            return $result;
        }

        // Fuji RAF: fester Header mit Zeiger auf das JPEG (Offset/Länge bei Byte 84/88,
        // Big-Endian). Die Orientierung steckt im EXIF des JPEGs selbst.
        if (str_starts_with($header, 'FUJIFILMCCD-RAW')) {
            $ptr = $this->readRange($stream, 84, 8);
            if ($ptr !== null && strlen($ptr) === 8) {
                $result['candidates'] = $this->validateCandidates($stream, [
                    [$this->u32(substr($ptr, 0, 4), false), $this->u32(substr($ptr, 4, 4), false)],
                ]);
            }
            return $result;
        }

        // Canon CR3: ISO-BMFF statt TIFF
        if (substr($header, 4, 8) === 'ftypcrx ') {
            return $this->inspectCr3($stream);
        }

        $order = substr($header, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return $result;
        }
        $le = $order === 'II';
        // 42 = TIFF (NEF, CR2, ARW, DNG, PEF), 0x4F52/0x5352 = Olympus ORF, 0x55 = Panasonic RW2
        if (!in_array($this->u16(substr($header, 2, 2), $le), [42, 0x4F52, 0x5352, 0x55], true)) {
            return $result;
        }

        $queue   = [$this->u32(substr($header, 4, 4), $le)];
        $visited = [];
        $ranges  = [];

        while ($queue !== [] && count($visited) < self::MAX_IFDS) {
            $offset = array_shift($queue);
            if ($offset < 8 || isset($visited[$offset])) {
                continue;
            }
            $isIfd0           = $visited === [];
            $visited[$offset] = true;

            $ifd = $this->readIfd($stream, $offset, $le);
            if ($ifd === null) {
                continue;
            }
            $tags = $ifd['tags'];

            if ($isIfd0) {
                $result['orientation'] = $this->validOrientation($tags[self::TAG_ORIENTATION][0] ?? 0);
            }
            if (isset($tags[self::TAG_JPEG_OFFSET], $tags[self::TAG_JPEG_LENGTH])) {
                $ranges[] = [$tags[self::TAG_JPEG_OFFSET][0], $tags[self::TAG_JPEG_LENGTH][0]];
            }
            // Panasonic RW2: volles JPEG als UNDEFINED-Blob direkt in IFD0
            if (isset($tags[self::TAG_RW2_JPEG])) {
                $ranges[] = $tags[self::TAG_RW2_JPEG];
            }
            // Olympus ORF: Vorschau steckt in der MakerNote des EXIF-IFDs
            if (isset($tags[self::TAG_EXIF_IFD])) {
                $queue[] = $tags[self::TAG_EXIF_IFD][0];
            }
            if (isset($tags[self::TAG_MAKERNOTE])) {
                array_push($ranges, ...$this->olympusPreviews($stream, ...$tags[self::TAG_MAKERNOTE]));
            }
            // DNG / manche ARWs speichern die Vorschau als einzelnen JPEG-komprimierten Strip.
            if (in_array($tags[self::TAG_COMPRESSION][0] ?? 0, [6, 7], true)
                && count($tags[self::TAG_STRIP_OFFSETS] ?? []) === 1
                && isset($tags[self::TAG_STRIP_COUNTS])
            ) {
                $ranges[] = [$tags[self::TAG_STRIP_OFFSETS][0], $tags[self::TAG_STRIP_COUNTS][0]];
            }

            array_push($queue, ...($tags[self::TAG_SUB_IFDS] ?? []));
            if ($ifd['next'] > 0) {
                $queue[] = $ifd['next'];
            }
        }

        $result['candidates'] = $this->validateCandidates($stream, $ranges);
        return $result;
    }

    /**
     * Reihenfolge: zuerst die kleinste Vorschau, deren lange Kante $target abdeckt,
     * dann die größeren, zuletzt die kleineren (größte zuerst). $target <= 0 = größte zuerst.
     *
     * @param  list<array{width: int, height: int}> $candidates
     * @return list<array{width: int, height: int}>
     */
    public function orderCandidates(array $candidates, int $target): array
    {
        usort($candidates, static fn(array $a, array $b): int => ($a['width'] * $a['height']) <=> ($b['width'] * $b['height']));

        if ($target <= 0) {
            return array_reverse($candidates);
        }

        $big   = array_filter($candidates, static fn(array $c): bool => max($c['width'], $c['height']) >= $target);
        $small = array_diff_key($candidates, $big);
        return array_merge(array_values($big), array_reverse($small));
    }

    /**
     * Orientiert und skaliert ein JPEG auf $maxX × $maxY. Gibt das Original zurück,
     * wenn nichts zu tun ist, null wenn es nicht (im Speicherbudget) dekodierbar ist.
     */
    public function render(string $jpeg, int $orientation, int $maxX, int $maxY): ?string
    {
        $dims = self::jpegDimensions($jpeg);
        if ($dims === null) {
            return null;
        }
        [$w, $h]     = $dims;
        $orientation = $this->validOrientation($orientation) ?: 1;
        // Box im (unrotierten) Koordinatensystem des JPEGs
        $swap = $orientation >= 5;
        $boxW = ($swap ? $maxY : $maxX) ?: $w;
        $boxH = ($swap ? $maxX : $maxY) ?: $h;

        if ($orientation === 1 && $w <= $boxW && $h <= $boxH) {
            return $jpeg;
        }

        $scale = min(1.0, $boxW / $w, $boxH / $h);
        $outW  = max(1, (int) round($w * $scale));
        $outH  = max(1, (int) round($h * $scale));

        if (extension_loaded('imagick')) {
            $out = $this->renderImagick($jpeg, $orientation, $outW, $outH);
            if ($out !== null) {
                return $out;
            }
        }
        return function_exists('imagecreatefromstring')
            ? $this->renderGd($jpeg, $w, $h, $orientation, $outW, $outH)
            : null;
    }

    /**
     * Breite/Höhe aus dem ersten SOF-Marker — nur für Formate, die GD/Imagick
     * dekodieren können (Baseline, Extended, Progressive). Lossless-JPEG (SOF3,
     * DNG-Sensordaten) liefert null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function jpegDimensions(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 4 || $data[0] !== "\xFF" || $data[1] !== "\xD8") {
            return null;
        }
        $pos = 2;
        while ($pos + 9 < $len) {
            if ($data[$pos] !== "\xFF") {
                return null;
            }
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $pos += 2;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                return null;
            }
            $segLen = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if ($segLen < 2) {
                return null;
            }
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                $h = (ord($data[$pos + 5]) << 8) | ord($data[$pos + 6]);
                $w = (ord($data[$pos + 7]) << 8) | ord($data[$pos + 8]);
                return ($w > 0 && $h > 0) ? [$w, $h] : null;
            }
            // Andere SOF-Typen (Lossless, Arithmetic, …) — nicht dekodierbar
            if ($marker >= 0xC3 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                return null;
            }
            $pos += 2 + $segLen;
        }
        return null;
    }

    // ─── TIFF-Parsing ─────────────────────────────────────────────────────────

    /**
     * Liest ein IFD — nur die Tags aus $wanted (SHORT/LONG/IFD, max. 64 Werte).
     * UNDEFINED-Blobs (Typ 7) liefern [Offset, Länge] statt ihres Inhalts.
     * $base: Bezugspunkt für Offsets (0 = Dateianfang, MakerNotes: ihr Anfang);
     * zurückgegebene Offsets bleiben relativ zu $base.
     *
     * @param  resource  $stream
     * @param  list<int> $wanted
     * @return array{tags: array<int, list<int>>, next: int}|null
     */
    private function readIfd($stream, int $offset, bool $le, array $wanted = self::WANTED_TAGS, int $base = 0): ?array
    {
        $countRaw = $this->readRange($stream, $offset, 2);
        if ($countRaw === null || strlen($countRaw) < 2) {
            return null;
        }
        $count = $this->u16($countRaw, $le);
        if ($count === 0 || $count > self::MAX_IFD_ENTRIES) {
            return null;
        }

        $block = $this->readRange($stream, $offset + 2, $count * 12 + 4);
        if ($block === null || strlen($block) < $count * 12) {
            return null;
        }

        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($block, $i * 12, 12);
            $tag   = $this->u16(substr($entry, 0, 2), $le);
            $type  = $this->u16(substr($entry, 2, 2), $le);
            $n     = $this->u32(substr($entry, 4, 4), $le);
            if (!in_array($tag, $wanted, true)) {
                continue;
            }
            // 7 = UNDEFINED: nur die Position merken, nicht lesen (kann ein ganzes JPEG sein)
            if ($type === 7) {
                if ($n > 4) {
                    $tags[$tag] = [$this->u32(substr($entry, 8, 4), $le), $n];
                }
                continue;
            }
            // 3 = SHORT, 4 = LONG, 13 = IFD
            if (!in_array($type, [3, 4, 13], true) || $n === 0 || $n > 64) {
                continue;
            }
            $size  = $type === 3 ? 2 : 4;
            $bytes = $n * $size;
            $raw   = $bytes <= 4
                ? substr($entry, 8, $bytes)
                : $this->readRange($stream, $base + $this->u32(substr($entry, 8, 4), $le), $bytes);
            if ($raw === null || strlen($raw) < $bytes) {
                continue;
            }
            $values = [];
            for ($j = 0; $j < $n; $j++) {
                $chunk    = substr($raw, $j * $size, $size);
                $values[] = $size === 2 ? $this->u16($chunk, $le) : $this->u32($chunk, $le);
            }
            $tags[$tag] = $values;
        }

        $next = strlen($block) >= $count * 12 + 4
            ? $this->u32(substr($block, $count * 12, 4), $le)
            : 0;

        return ['tags' => $tags, 'next' => $next];
    }

    /**
     * Vorschau-Bereiche aus einer Olympus/OM-System-MakerNote (ORF). Andere
     * MakerNotes (Nikon, Canon, …) → leer; deren Vorschauen liegen in normalen IFDs.
     *
     * @param  resource $stream
     * @return list<array{0: int, 1: int}> [offset, length]
     */
    private function olympusPreviews($stream, int $offset, int $length): array
    {
        $head = $this->readRange($stream, $offset, 16);
        if ($head === null || strlen($head) < 16 || $length < 16) {
            return [];
        }
        // Offsets in der MakerNote sind relativ zu ihrem Anfang
        if (str_starts_with($head, "OLYMPUS\x00")) {
            [$ifdAt, $order] = [12, substr($head, 8, 2)];
        } elseif (str_starts_with($head, "OM SYSTEM\x00")) {
            [$ifdAt, $order] = [16, substr($head, 12, 2)];
        } else {
            return [];
        }
        if ($order !== 'II' && $order !== 'MM') {
            return [];
        }
        $le = $order === 'II';

        $main = $this->readIfd($stream, $offset + $ifdAt, $le, [self::OLY_THUMBNAIL, self::OLY_CAMERA_SETTINGS], $offset);
        if ($main === null) {
            return [];
        }
        $ranges = [];
        if (isset($main['tags'][self::OLY_THUMBNAIL])) {
            [$thumbAt, $thumbLen] = $main['tags'][self::OLY_THUMBNAIL];
            $ranges[] = [$offset + $thumbAt, $thumbLen];
        }
        if (isset($main['tags'][self::OLY_CAMERA_SETTINGS])) {
            $cs = $this->readIfd(
                $stream, $offset + $main['tags'][self::OLY_CAMERA_SETTINGS][0], $le,
                [self::OLY_PREVIEW_START, self::OLY_PREVIEW_LENGTH], $offset,
            );
            if (isset($cs['tags'][self::OLY_PREVIEW_START], $cs['tags'][self::OLY_PREVIEW_LENGTH])) {
                $ranges[] = [$offset + $cs['tags'][self::OLY_PREVIEW_START][0], $cs['tags'][self::OLY_PREVIEW_LENGTH][0]];
            }
        }
        return $ranges;
    }

    // ─── CR3 (ISO-BMFF) ───────────────────────────────────────────────────────

    /**
     * Canon CR3: Thumbnail (THMB im Canon-uuid von moov, 160×120), Vorschau (PRVW
     * im Preview-uuid, ~1620×1080) und volles JPEG (erste Spur, Sample in mdat).
     * Orientierung aus dem EXIF-TIFF in CMT1.
     *
     * @param  resource $stream
     * @return array{orientation: int, candidates: list<array{offset: int, length: int, width: int, height: int}>}
     */
    private function inspectCr3($stream): array
    {
        $orientation = 0;
        $ranges      = [];
        $fileSize    = (int) (fstat($stream)['size'] ?? 0);

        foreach ($this->bmffBoxes($stream, 0, $fileSize > 0 ? $fileSize : PHP_INT_MAX) as [$type, $start, $end]) {
            if ($type === 'moov') {
                $firstTrak = true;
                foreach ($this->bmffBoxes($stream, $start, $end) as [$t, $s, $e]) {
                    if ($t === 'uuid' && $this->readRange($stream, $s, 16) === self::CR3_CANON_UUID) {
                        foreach ($this->bmffBoxes($stream, $s + 16, $e) as [$tt, $ss]) {
                            if ($tt === 'CMT1') {
                                $orientation = $this->tiffOrientation($stream, $ss);
                            } elseif ($tt === 'THMB') {
                                // Version/Flags, Breite, Höhe, JPEG-Länge, 4 Bytes ?, JPEG
                                $ranges[] = $this->sizedBlob($stream, $ss + 8, $ss + 16);
                            }
                        }
                    } elseif ($t === 'trak' && $firstTrak) {
                        $firstTrak = false;
                        $full      = $this->cr3TrackJpeg($stream, $s, $e);
                        if ($full !== null) {
                            $ranges[] = $full;
                        }
                    }
                }
            } elseif ($type === 'uuid' && $this->readRange($stream, $start, 16) === self::CR3_PREVIEW_UUID) {
                // Nach der UUID 8 Bytes Kopf, dann die PRVW-Box:
                // 4 Bytes ?, 2 Bytes ?, Breite, Höhe, 2 Bytes ?, JPEG-Länge, JPEG
                foreach ($this->bmffBoxes($stream, $start + 24, $end) as [$t, $s]) {
                    if ($t === 'PRVW') {
                        $ranges[] = $this->sizedBlob($stream, $s + 12, $s + 16);
                    }
                }
            }
        }

        return ['orientation' => $orientation, 'candidates' => $this->validateCandidates($stream, $ranges)];
    }

    /**
     * Volles JPEG der ersten CR3-Spur: Länge aus stsz, Position aus co64/stco.
     *
     * @param  resource $stream
     * @return array{0: int, 1: int}|null
     */
    private function cr3TrackJpeg($stream, int $start, int $end): ?array
    {
        foreach (['mdia', 'minf', 'stbl'] as $name) {
            $box = null;
            foreach ($this->bmffBoxes($stream, $start, $end) as [$t, $s, $e]) {
                if ($t === $name) {
                    $box = [$s, $e];
                    break;
                }
            }
            if ($box === null) {
                return null;
            }
            [$start, $end] = $box;
        }

        $offset = 0;
        $length = 0;
        foreach ($this->bmffBoxes($stream, $start, $end) as [$t, $s]) {
            $d = $this->readRange($stream, $s, 16);
            if ($d === null || strlen($d) < 12) {
                continue;
            }
            if ($t === 'stsz') {
                // Version/Flags, Sample-Größe (0 = Tabelle folgt), Anzahl, [Größe …]
                $length = $this->u32(substr($d, 4, 4), false)
                    ?: (strlen($d) >= 16 ? $this->u32(substr($d, 12, 4), false) : 0);
            } elseif ($t === 'co64' && strlen($d) >= 16) {
                $offset = unpack('J', substr($d, 8, 8))[1];
            } elseif ($t === 'stco') {
                $offset = $this->u32(substr($d, 8, 4), false);
            }
        }
        return $offset > 0 && $length > 0 ? [$offset, $length] : null;
    }

    /**
     * ISO-BMFF-Boxen zwischen $start und $end als [Typ, Payload-Start, Ende].
     *
     * @param  resource $stream
     * @return \Generator<int, array{0: string, 1: int, 2: int}>
     */
    private function bmffBoxes($stream, int $start, int $end): \Generator
    {
        $pos = $start;
        for ($n = 0; $n < self::MAX_BMFF_BOXES && $pos + 8 <= $end; $n++) {
            $h = $this->readRange($stream, $pos, 16);
            if ($h === null || strlen($h) < 8) {
                return;
            }
            $size = $this->u32(substr($h, 0, 4), false);
            $head = 8;
            if ($size === 1) {
                if (strlen($h) < 16) {
                    return;
                }
                $size = unpack('J', substr($h, 8, 8))[1];
                $head = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;   // bis zum Ende des Containers
            }
            if ($size < $head || $size > $end - $pos) {
                return;
            }
            yield [substr($h, 4, 4), $pos + $head, $pos + $size];
            $pos += $size;
        }
    }

    /** [Offset, Länge] eines JPEGs, dessen Länge als Big-Endian-u32 bei $lengthAt steht. */
    private function sizedBlob($stream, int $lengthAt, int $dataAt): array
    {
        $len = $this->readRange($stream, $lengthAt, 4);
        return [$dataAt, $len !== null && strlen($len) === 4 ? $this->u32($len, false) : 0];
    }

    /** Orientierung aus IFD0 eines eingebetteten TIFF-Blocks (Offsets relativ zu $tiffStart). */
    private function tiffOrientation($stream, int $tiffStart): int
    {
        $h = $this->readRange($stream, $tiffStart, 8);
        if ($h === null || strlen($h) < 8 || !in_array(substr($h, 0, 2), ['II', 'MM'], true)) {
            return 0;
        }
        $le  = substr($h, 0, 2) === 'II';
        $ifd = $this->readIfd($stream, $tiffStart + $this->u32(substr($h, 4, 4), $le), $le, [self::TAG_ORIENTATION], $tiffStart);
        return $this->validOrientation($ifd['tags'][self::TAG_ORIENTATION][0] ?? 0);
    }

    /**
     * Behält nur Bereiche, an denen tatsächlich ein dekodierbares JPEG liegt.
     *
     * @param  resource $stream
     * @param  list<array{0: int, 1: int}> $ranges [offset, length]
     * @return list<array{offset: int, length: int, width: int, height: int}>
     */
    private function validateCandidates($stream, array $ranges): array
    {
        $out = [];
        foreach ($ranges as [$offset, $length]) {
            if ($offset <= 0 || $length < self::MIN_JPEG_BYTES || $length > self::MAX_JPEG_BYTES || isset($out[$offset])) {
                continue;
            }
            $head = $this->readRange($stream, $offset, min($length, self::JPEG_HEAD_BYTES));
            $dims = $head === null ? null : self::jpegDimensions($head);
            if ($dims !== null) {
                $out[$offset] = ['offset' => $offset, 'length' => $length, 'width' => $dims[0], 'height' => $dims[1]];
            }
        }
        return array_values($out);
    }

    /**
     * Orientierung aus dem EXIF-APP1-Segment eines eingebetteten JPEGs (0 = keine).
     */
    private function jpegExifOrientation(string $jpeg): int
    {
        $len = min(strlen($jpeg), self::JPEG_HEAD_BYTES);
        $pos = 2;
        while ($pos + 4 < $len && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            $segLen = (ord($jpeg[$pos + 2]) << 8) | ord($jpeg[$pos + 3]);
            if ($marker === 0xDA || $segLen < 2) {
                break;
            }
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\x00\x00") {
                $tiff = substr($jpeg, $pos + 10, $segLen - 8);
                if (strlen($tiff) < 8) {
                    return 0;
                }
                $le  = substr($tiff, 0, 2) === 'II';
                $mem = fopen('php://memory', 'r+b');
                fwrite($mem, $tiff);
                $ifd = $this->readIfd($mem, $this->u32(substr($tiff, 4, 4), $le), $le);
                fclose($mem);
                return $this->validOrientation($ifd['tags'][self::TAG_ORIENTATION][0] ?? 0);
            }
            $pos += 2 + $segLen;
        }
        return 0;
    }

    private function validOrientation(int $o): int
    {
        return ($o >= 1 && $o <= 8) ? $o : 0;
    }

    // ─── Rendering ────────────────────────────────────────────────────────────

    private function renderImagick(string $jpeg, int $orientation, int $outW, int $outH): ?string
    {
        try {
            $im = new \Imagick();
            // libjpeg-DCT-Scaling: direkt in Zielgröße dekodieren — eine 45-MP-Vorschau
            // muss nie vollständig in den Speicher.
            $im->setOption('jpeg:size', $outW . 'x' . $outH);
            $im->readImageBlob($jpeg);
            $im->thumbnailImage($outW, $outH, true);
            match ($orientation) {
                2 => $im->flopImage(),
                3 => $im->rotateImage('#000', 180),
                4 => $im->flipImage(),
                5 => $im->rotateImage('#000', 90) && $im->flopImage(),
                6 => $im->rotateImage('#000', 90),
                7 => $im->rotateImage('#000', 270) && $im->flopImage(),
                8 => $im->rotateImage('#000', 270),
                default => true,
            };
            $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            $im->stripImage();
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::OUTPUT_QUALITY);
            $blob = $im->getImageBlob();
            $im->clear();
            return $blob !== '' ? $blob : null;
        } catch (\Throwable $e) {
            $this->logger->debug('StarRate RAW preview: Imagick render failed: ' . $e->getMessage());
            return null;
        }
    }

    private function renderGd(string $jpeg, int $w, int $h, int $orientation, int $outW, int $outH): ?string
    {
        // GD dekodiert immer in voller Größe (~5 Bytes/Pixel inkl. Overhead).
        if (!$this->fitsInMemory(($w * $h + $outW * $outH * 2) * 5)) {
            $this->logger->debug("StarRate RAW preview: {$w}x{$h} preview exceeds memory_limit for GD decode");
            return null;
        }

        $img = @imagecreatefromstring($jpeg);
        if ($img === false) {
            return null;
        }
        if ($outW !== $w || $outH !== $h) {
            // imagecopyresampled statt imagescale(): IMG_BICUBIC fehlt in manchen
            // libgd-Builds und liefert dort stillschweigend false.
            $scaled = imagecreatetruecolor($outW, $outH);
            if (!imagecopyresampled($scaled, $img, 0, 0, 0, 0, $outW, $outH, $w, $h)) {
                return null;
            }
            $img = $scaled;
        }

        // GD dreht gegen den Uhrzeigersinn
        $angle = match ($orientation) {
            3, 4    => 180,
            5, 6    => -90,
            7, 8    => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $img = imagerotate($img, $angle, 0);
            if ($img === false) {
                return null;
            }
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }

        ob_start();
        imagejpeg($img, null, self::OUTPUT_QUALITY);
        $out = (string) ob_get_clean();
        return $out !== '' ? $out : null;
    }

    private function fitsInMemory(int $bytes): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $value = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };
        return memory_get_usage() + $bytes < $value;
    }

    // ─── Stream-Helfer ────────────────────────────────────────────────────────

    /** @return resource|null */
    private function openSeekable(File $file)
    {
        try {
            $stream = $file->fopen('rb');
        } catch (\Throwable $e) {
            $this->logger->debug('StarRate RAW preview: cannot open ' . $file->getName() . ': ' . $e->getMessage());
            return null;
        }
        if (!is_resource($stream)) {
            return null;
        }
        if (stream_get_meta_data($stream)['seekable'] ?? false) {
            return $stream;
        }
        // Nicht seekbarer Storage (z.B. manche External Mounts): in einen Temp-Stream
        // kopieren (geht ab 2 MB auf die Platte).
        $tmp = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($tmp === false) {
            fclose($stream);
            return null;
        }
        stream_copy_to_stream($stream, $tmp);
        fclose($stream);
        rewind($tmp);
        return $tmp;
    }

    /** @param resource $stream */
    private function readRange($stream, int $offset, int $length): ?string
    {
        if ($offset < 0 || $length <= 0 || fseek($stream, $offset) !== 0) {
            return null;
        }
        $data = stream_get_contents($stream, $length);
        return ($data === false || $data === '') ? null : $data;
    }

    private function u16(string $b, bool $le): int
    {
        return unpack($le ? 'v' : 'n', str_pad($b, 2, "\0"))[1];
    }

    private function u32(string $b, bool $le): int
    {
        return unpack($le ? 'V' : 'N', str_pad($b, 4, "\0"))[1];
    }
}

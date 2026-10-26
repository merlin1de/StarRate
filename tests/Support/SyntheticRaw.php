<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Support;

/**
 * Baut kleine, strukturell realistische RAW-Dateien für Tests.
 *
 * Aufbau wie ein Nikon-NEF: IFD0 trägt Orientierung, ein kleines Thumbnail-JPEG
 * und den SubIFDs-Zeiger; SubIFD0 enthält die große "JpgFromRaw"-Vorschau,
 * SubIFD1 steht für die Sensordaten. Keine echte Kameradatei nötig (und keine committet).
 */
final class SyntheticRaw
{
    /**
     * JPEG mit roter linker und blauer rechter Hälfte — damit Tests prüfen
     * können, ob die Orientierung angewendet wurde.
     */
    public static function jpeg(int $w, int $h, int $quality = 85): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($img, 220, 20, 20));
        imagefilledrectangle($img, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($img, 20, 20, 220));
        ob_start();
        imagejpeg($img, null, $quality);
        return (string) ob_get_clean();
    }

    /** Minimaler Lossless-JPEG-Header (SOF3) — so sehen DNG-Sensorkacheln aus. */
    public static function losslessJpegStub(): string
    {
        return "\xFF\xD8\xFF\xC3\x00\x0B\x10\x00\x10\x00\x10\x01\x01\x11\x00" . str_repeat("\x00", 256) . "\xFF\xD9";
    }

    /**
     * @param list<string> $subPreviews JPEGs in SubIFD0..n (JPEGInterchangeFormat)
     */
    public static function nef(
        int $orientation,
        ?string $thumb,
        array $subPreviews,
        bool $littleEndian = true,
        bool $withLosslessSensor = true,
    ): string {
        $ifds = [];
        $ifd0 = [0x0112 => [3, [$orientation]]];
        $blobs = [];

        if ($thumb !== null) {
            $blobs['thumb'] = $thumb;
            $ifd0[0x0201] = [4, ['@thumb']];
            $ifd0[0x0202] = [4, [strlen($thumb)]];
        }

        $subs = [];
        foreach ($subPreviews as $i => $jpeg) {
            $blobs["sub{$i}"] = $jpeg;
            $subs[] = [0x0201 => [4, ["@sub{$i}"]], 0x0202 => [4, [strlen($jpeg)]]];
        }
        if ($withLosslessSensor) {
            $sensor = self::losslessJpegStub();
            $blobs['sensor'] = $sensor;
            $subs[] = [0x0103 => [3, [7]], 0x0111 => [4, ['@sensor']], 0x0117 => [4, [strlen($sensor)]]];
        }

        $ifd0[0x014A] = [4, array_map(static fn(int $i): string => "@ifd{$i}", array_keys($subs))];
        $ifds[] = $ifd0;
        foreach ($subs as $s) {
            $ifds[] = $s;
        }

        return self::tiff($ifds, $blobs, $littleEndian);
    }

    /** Panasonic RW2: eigene TIFF-Magic 0x55, JpgFromRaw als UNDEFINED-Blob (Tag 0x2E) in IFD0. */
    public static function rw2(string $jpeg): string
    {
        return self::tiff([[0x0112 => [3, [1]], 0x002E => [7, ['@jpeg']]]], ['jpeg' => $jpeg], true, 0x55);
    }

    /**
     * Olympus ORF: Vorschau in der MakerNote (IFD0 → EXIF-IFD → MakerNote →
     * CameraSettings-Sub-IFD); Offsets darin relativ zum MakerNote-Anfang.
     */
    public static function orf(string $preview, int $orientation = 1): string
    {
        // MakerNote: Header (12) | Haupt-IFD @12 (18) | CameraSettings-IFD @30 (30) | JPEG @60
        $makerNote = "OLYMPUS\x00II\x03\x00"
            . pack('v', 1) . pack('vvVV', 0x2020, 13, 1, 30) . pack('V', 0)
            . pack('v', 2) . pack('vvVV', 0x0101, 4, 1, 60) . pack('vvVV', 0x0102, 4, 1, strlen($preview)) . pack('V', 0)
            . $preview;

        return self::tiff(
            [[0x0112 => [3, [$orientation]], 0x8769 => [4, ['@ifd0']]], [0x927C => [7, ['@mn']]]],
            ['mn' => $makerNote],
            true,
            0x4F52,
        );
    }

    /** Fuji RAF: fester Header mit JPEG-Zeiger bei Byte 84. */
    public static function raf(string $jpeg): string
    {
        $header = str_pad('FUJIFILMCCD-RAW 0201FF383501', 84, "\0");
        $offset = 160;
        $header .= pack('N', $offset) . pack('N', strlen($jpeg));
        return str_pad($header, $offset, "\0") . $jpeg;
    }

    /**
     * Kleiner generischer TIFF-Writer. Werte dürfen "@name"-Referenzen sein, die
     * auf den Offset von Blob `name` bzw. IFD `ifdN` (N = Index - 1 nach IFD0) auflösen.
     * Typ 7 (UNDEFINED) mit ['@blob'] verweist auf einen ganzen Blob (Anzahl = Länge).
     *
     * @param list<array<int, array{0: int, 1: list<int|string>}>> $ifds
     * @param array<string, string> $blobs
     */
    private static function tiff(array $ifds, array $blobs, bool $le, int $magic = 42): string
    {
        $u16 = static fn(int $v): string => pack($le ? 'v' : 'n', $v);
        $u32 = static fn(int $v): string => pack($le ? 'V' : 'N', $v);

        // Durchlauf 1: Offsets. Layout: Header | IFDs (jeweils gefolgt von ihren
        // Out-of-line-Arrays) | Blobs. "ifdN" = SubIFD N, IFD0 liegt fest bei 8.
        $ifd0Offset = 8;
        $subOffsets = [];
        $pos = 8;
        foreach ($ifds as $i => $tags) {
            if ($i > 0) {
                $subOffsets['ifd' . ($i - 1)] = $pos;
            }
            $pos += 2 + 12 * count($tags) + 4;
            foreach ($tags as [$type, $values]) {
                $size = $type === 7 ? 0 : ($type === 3 ? 2 : 4) * count($values);
                if ($size > 4) {
                    $pos += $size;
                }
            }
        }
        foreach ($blobs as $name => $data) {
            $subOffsets[$name] = $pos;
            $pos += strlen($data);
        }

        $resolve = static function (int|string $v) use ($subOffsets): int {
            return is_string($v) ? $subOffsets[substr($v, 1)] : $v;
        };

        // Durchlauf 2: schreiben
        $out = ($le ? 'II' : 'MM') . $u16($magic) . $u32($ifd0Offset);
        foreach ($ifds as $tags) {
            ksort($tags);
            $ifdStart  = strlen($out);
            $extraPos  = $ifdStart + 2 + 12 * count($tags) + 4;
            $entries   = '';
            $extra     = '';
            foreach ($tags as $tag => [$type, $values]) {
                if ($type === 7) {
                    $entries .= $u16($tag) . $u16(7) . $u32(strlen($blobs[substr($values[0], 1)])) . $u32($resolve($values[0]));
                    continue;
                }
                $packed = '';
                foreach ($values as $v) {
                    $packed .= $type === 3 ? $u16($resolve($v)) : $u32($resolve($v));
                }
                $entries .= $u16($tag) . $u16($type) . $u32(count($values));
                if (strlen($packed) <= 4) {
                    $entries .= str_pad($packed, 4, "\0");
                } else {
                    $entries .= $u32($extraPos + strlen($extra));
                    $extra   .= $packed;
                }
            }
            $out .= $u16(count($tags)) . $entries . $u32(0) . $extra;
        }
        foreach ($blobs as $data) {
            $out .= $data;
        }
        return $out;
    }
}

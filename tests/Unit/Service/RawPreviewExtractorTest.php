<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\RawPreviewExtractor;
use OCA\StarRate\Tests\Support\SyntheticRaw;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RawPreviewExtractorTest extends TestCase
{
    private RawPreviewExtractor $extractor;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required');
        }
        $this->extractor = new RawPreviewExtractor($this->createMock(LoggerInterface::class));
    }

    /** @return resource */
    private function stream(string $data)
    {
        $s = fopen('php://memory', 'r+b');
        fwrite($s, $data);
        rewind($s);
        return $s;
    }

    /** @return array{0: int, 1: int} */
    private function dims(string $jpeg): array
    {
        $d = RawPreviewExtractor::jpegDimensions($jpeg);
        $this->assertNotNull($d, 'output is not a decodable JPEG');
        return $d;
    }

    private function pixel(string $jpeg, int $x, int $y): array
    {
        $img = imagecreatefromstring($jpeg);
        $rgb = imagecolorat($img, $x, $y);
        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private function assertRedish(array $rgb): void
    {
        $this->assertGreaterThan(150, $rgb[0]);
        $this->assertLessThan(100, $rgb[2]);
    }

    private function assertBlueish(array $rgb): void
    {
        $this->assertGreaterThan(150, $rgb[2]);
        $this->assertLessThan(100, $rgb[0]);
    }

    // ─── Inspektion ───────────────────────────────────────────────────────────

    public function testInspectFindsThumbAndPreviewButSkipsLosslessSensorData(): void
    {
        $nef  = SyntheticRaw::nef(1, SyntheticRaw::jpeg(160, 120), [SyntheticRaw::jpeg(640, 426)]);
        $info = $this->extractor->inspectStream($this->stream($nef));

        $this->assertSame(1, $info['orientation']);
        $this->assertCount(2, $info['candidates']);
        $sizes = array_map(fn($c) => $c['width'] . 'x' . $c['height'], $info['candidates']);
        sort($sizes);
        $this->assertSame(['160x120', '640x426'], $sizes);
    }

    public function testInspectBigEndian(): void
    {
        $nef  = SyntheticRaw::nef(8, null, [SyntheticRaw::jpeg(300, 200)], false);
        $info = $this->extractor->inspectStream($this->stream($nef));

        $this->assertSame(8, $info['orientation']);
        $this->assertCount(1, $info['candidates']);
        $this->assertSame(300, $info['candidates'][0]['width']);
    }

    public function testInspectRejectsNonRawData(): void
    {
        $this->assertSame([], $this->extractor->inspectStream($this->stream(SyntheticRaw::jpeg(10, 10)))['candidates']);
        $this->assertSame([], $this->extractor->inspectStream($this->stream(str_repeat("\x00", 64)))['candidates']);
        $this->assertSame([], $this->extractor->inspectStream($this->stream('II'))['candidates']);
    }

    public function testInspectSurvivesIfdLoop(): void
    {
        // IFD0, dessen "next IFD"-Zeiger auf sich selbst zeigt.
        $nef = SyntheticRaw::nef(1, SyntheticRaw::jpeg(64, 48), [], true, false);
        $entries = unpack('v', substr($nef, 8, 2))[1];
        $nextPos = 8 + 2 + 12 * $entries;
        $looped  = substr_replace($nef, pack('V', 8), $nextPos, 4);

        $info = $this->extractor->inspectStream($this->stream($looped));
        $this->assertCount(1, $info['candidates']);
    }

    public function testInspectRaf(): void
    {
        $info = $this->extractor->inspectStream($this->stream(SyntheticRaw::raf(SyntheticRaw::jpeg(320, 240))));
        $this->assertCount(1, $info['candidates']);
    }

    // ─── Kandidatenwahl ───────────────────────────────────────────────────────

    public function testOrderPrefersSmallestCoveringCandidate(): void
    {
        $c = [
            ['width' => 6000, 'height' => 4000],
            ['width' => 160,  'height' => 120],
            ['width' => 1620, 'height' => 1080],
        ];
        $this->assertSame(1620, $this->extractor->orderCandidates($c, 1024)[0]['width']);
        $this->assertSame(160,  $this->extractor->orderCandidates($c, 100)[0]['width']);
        $this->assertSame(6000, $this->extractor->orderCandidates($c, 4096)[0]['width']);
        $this->assertSame(6000, $this->extractor->orderCandidates($c, 9000)[0]['width']);
        $this->assertSame(6000, $this->extractor->orderCandidates($c, 0)[0]['width']);
    }

    // ─── Extraktion & Rendering ───────────────────────────────────────────────

    public function testFastPathReturnsEmbeddedJpegUnchanged(): void
    {
        $preview = SyntheticRaw::jpeg(640, 426);
        $nef     = SyntheticRaw::nef(1, SyntheticRaw::jpeg(160, 120), [$preview]);

        $out = $this->extractor->extractPreviewFromStream($this->stream($nef), 4096, 4096);
        $this->assertSame($preview, $out);
    }

    public function testPicksThumbnailForSmallRequests(): void
    {
        $thumb = SyntheticRaw::jpeg(160, 120);
        $nef   = SyntheticRaw::nef(1, $thumb, [SyntheticRaw::jpeg(640, 426)]);

        $this->assertSame($thumb, $this->extractor->extractPreviewFromStream($this->stream($nef), 160, 160));
    }

    public function testScalesDownToFit(): void
    {
        $nef = SyntheticRaw::nef(1, null, [SyntheticRaw::jpeg(640, 426)]);
        $out = $this->extractor->extractPreviewFromStream($this->stream($nef), 320, 320);

        [$w, $h] = $this->dims($out);
        $this->assertSame(320, $w);
        $this->assertSame(213, $h);
    }

    public function testOrientation6RotatesClockwise(): void
    {
        // Hochformat: Sensor-Querformat-JPEG, Orientierung 6 = 90° im Uhrzeigersinn drehen.
        $nef = SyntheticRaw::nef(6, null, [SyntheticRaw::jpeg(600, 400)]);
        $out = $this->extractor->extractPreviewFromStream($this->stream($nef), 4096, 4096);

        $this->assertSame([400, 600], $this->dims($out));
        // Linke (rote) Hälfte landet oben, rechte (blaue) unten.
        $this->assertRedish($this->pixel($out, 200, 50));
        $this->assertBlueish($this->pixel($out, 200, 550));
    }

    public function testOrientation8RotatesCounterClockwise(): void
    {
        $nef = SyntheticRaw::nef(8, null, [SyntheticRaw::jpeg(600, 400)]);
        $out = $this->extractor->extractPreviewFromStream($this->stream($nef), 4096, 4096);

        $this->assertSame([400, 600], $this->dims($out));
        $this->assertBlueish($this->pixel($out, 200, 50));
        $this->assertRedish($this->pixel($out, 200, 550));
    }

    public function testOrientation3Rotates180(): void
    {
        $nef = SyntheticRaw::nef(3, null, [SyntheticRaw::jpeg(600, 400)]);
        $out = $this->extractor->extractPreviewFromStream($this->stream($nef), 4096, 4096);

        $this->assertSame([600, 400], $this->dims($out));
        $this->assertBlueish($this->pixel($out, 50, 200));
        $this->assertRedish($this->pixel($out, 550, 200));
    }

    public function testRotatedBoundingBoxRespectsMaxSize(): void
    {
        // Orientierung 6 + 300×300-Box → lange Kante 300 im angezeigten (Hochformat-)Bild.
        $nef = SyntheticRaw::nef(6, null, [SyntheticRaw::jpeg(600, 400)]);
        $out = $this->extractor->extractPreviewFromStream($this->stream($nef), 300, 300);

        $this->assertSame([200, 300], $this->dims($out));
    }

    public function testRafUsesEmbeddedJpeg(): void
    {
        $jpeg = SyntheticRaw::jpeg(320, 240);
        $out  = $this->extractor->extractPreviewFromStream($this->stream(SyntheticRaw::raf($jpeg)), 1024, 1024);
        $this->assertSame($jpeg, $out);
    }

    public function testRw2UsesJpgFromRawTag(): void
    {
        $jpeg = SyntheticRaw::jpeg(320, 240);
        $info = $this->extractor->inspectStream($this->stream(SyntheticRaw::rw2($jpeg)));
        $this->assertSame([[320, 240]], array_map(fn($c) => [$c['width'], $c['height']], $info['candidates']));

        $out = $this->extractor->extractPreviewFromStream($this->stream(SyntheticRaw::rw2($jpeg)), 1024, 1024);
        $this->assertSame($jpeg, $out);
    }

    public function testOrfUsesOlympusMakerNotePreview(): void
    {
        $jpeg = SyntheticRaw::jpeg(400, 300);
        $out  = $this->extractor->extractPreviewFromStream($this->stream(SyntheticRaw::orf($jpeg)), 1024, 1024);
        $this->assertSame($jpeg, $out);
    }

    public function testOrfAppliesOrientationFromIfd0(): void
    {
        $out = $this->extractor->extractPreviewFromStream($this->stream(SyntheticRaw::orf(SyntheticRaw::jpeg(400, 300), 6)), 1024, 1024);
        $this->assertSame([300, 400], $this->dims($out));
    }

    public function testSupportsOnlyFormatsWithKnownPreviewLocation(): void
    {
        foreach (['DSC_0001.NEF', 'a.cr2', 'a.ARW', 'a.dng', 'a.pef', 'a.orf', 'a.RW2', 'a.raf'] as $name) {
            $this->assertTrue(RawPreviewExtractor::supports($name), $name);
        }
        foreach (['a.mrw', 'a.x3f', 'a.iiq', 'a.jpg', 'noext'] as $name) {
            $this->assertFalse(RawPreviewExtractor::supports($name), $name);
        }
        $this->assertTrue(RawPreviewExtractor::isListable('image/jpeg', 'a.jpg'));
        $this->assertTrue(RawPreviewExtractor::isListable('image/x-dcraw', 'a.NEF'));
        $this->assertFalse(RawPreviewExtractor::isListable('image/x-dcraw', 'a.MRW'));
    }

    public function testNoCandidatesReturnsNull(): void
    {
        $nef = SyntheticRaw::nef(1, null, [], true, true);
        $this->assertNull($this->extractor->extractPreviewFromStream($this->stream($nef), 1024, 1024));
    }

    public function testExtractPreviewFromNextcloudFile(): void
    {
        $preview = SyntheticRaw::jpeg(640, 426);
        $nef     = SyntheticRaw::nef(1, null, [$preview]);

        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('DSC_0001.NEF');
        $file->method('fopen')->willReturnCallback(fn() => $this->stream($nef));

        $this->assertSame($preview, $this->extractor->extractPreview($file, 2048, 2048));
    }

    // ─── JPEG-Header-Parsing ──────────────────────────────────────────────────

    public function testJpegDimensionsRejectsLosslessJpeg(): void
    {
        $this->assertNull(RawPreviewExtractor::jpegDimensions(SyntheticRaw::losslessJpegStub()));
        $this->assertNull(RawPreviewExtractor::jpegDimensions('not a jpeg'));
        $this->assertSame([33, 17], RawPreviewExtractor::jpegDimensions(SyntheticRaw::jpeg(33, 17)));
    }

    /**
     * Optional: STARRATE_RAW_SAMPLE auf eine echte Kameradatei zeigen lassen (End-to-End-Check).
     *   STARRATE_RAW_SAMPLE=~/Pictures/DSC_0001.NEF vendor/bin/phpunit --filter RealSample
     */
    public function testRealSampleFromEnvironment(): void
    {
        $path = getenv('STARRATE_RAW_SAMPLE') ?: '';
        if ($path === '' || !is_file($path)) {
            $this->markTestSkipped('STARRATE_RAW_SAMPLE auf eine RAW-Datei setzen, um diesen Test auszuführen.');
        }
        $stream = fopen($path, 'rb');
        $info   = $this->extractor->inspectStream($stream);
        fwrite(STDERR, "\n" . basename($path) . ': ' . json_encode($info) . "\n");
        $this->assertNotEmpty($info['candidates']);

        $out = $this->extractor->extractPreviewFromStream($stream, 2048, 2048);
        fclose($stream);
        $this->assertNotNull($out);
        [$w, $h] = $this->dims($out);
        $this->assertLessThanOrEqual(2048, max($w, $h));
    }
}

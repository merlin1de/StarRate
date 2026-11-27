<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Service\XmpService;
use OCA\StarRate\Service\XmpStoreResolver;
use OCA\StarRate\Service\XmpSyncService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class XmpSyncServiceTest extends TestCase
{
    /** @var SidecarService&MockObject */
    private SidecarService $sidecar;
    /** @var ExifService&MockObject */
    private ExifService $exif;
    private XmpSyncService $sync;

    private const SETTINGS = [
        'write_xmp' => true, 'show_raw' => true, 'write_raw_sidecar' => true, 'xmp_label_language' => 'en',
    ];

    protected function setUp(): void
    {
        $this->sidecar = $this->createMock(SidecarService::class);
        $this->exif    = $this->createMock(ExifService::class);
        $this->sync    = new XmpSyncService(
            new XmpStoreResolver($this->exif, $this->sidecar),
            new XmpService(),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function raw(): File&MockObject
    {
        $f = $this->createMock(File::class);
        $f->method('getMimeType')->willReturn('image/x-dcraw');
        $f->method('getName')->willReturn('DSC_2195.NEF');
        $f->method('getId')->willReturn(42);
        return $f;
    }

    private static function db(int $rating = 0, ?string $color = null, string $pick = 'none'): array
    {
        return ['rating' => $rating, 'color' => $color, 'pick' => $pick];
    }

    private static function xmp(int $rating = 0, ?string $label = null, string $pick = 'none'): array
    {
        return ['rating' => $rating, 'label' => $label, 'pick' => $pick];
    }

    // ─── diff ────────────────────────────────────────────────────────────────

    public function testDiffClassifiesMissingDifferentAndExtra(): void
    {
        $d = XmpSyncService::diff(self::db(5, 'Blue', 'reject'), self::xmp(0, 'Green', 'none'));
        $this->assertSame('missing',   $d['rating']['kind']);
        $this->assertSame('different', $d['color']['kind']);
        $this->assertSame('missing',   $d['pick']['kind']);

        $e = XmpSyncService::diff(self::db(), self::xmp(3, 'Red', 'pick'));
        $this->assertSame(['extra', 'extra', 'extra'], array_column($e, 'kind'));
    }

    public function testDiffIsEmptyWhenEqual(): void
    {
        $this->assertSame([], XmpSyncService::diff(self::db(5, 'Blue', 'pick'), self::xmp(5, 'Blue', 'pick')));
    }

    public function testFieldsToWriteRespectsSelectionAndOverwrite(): void
    {
        $d = XmpSyncService::diff(self::db(5, 'Blue', 'reject'), self::xmp(0, 'Green', 'pick'));
        $this->assertSame(['rating'], XmpSyncService::fieldsToWrite($d, ['rating', 'color'], false));
        $this->assertSame(['rating', 'color'], XmpSyncService::fieldsToWrite($d, ['rating', 'color'], true));
        $this->assertSame([], XmpSyncService::fieldsToWrite($d, ['pick'], false));
        $this->assertSame(['pick'], XmpSyncService::fieldsToWrite($d, ['pick'], true));
    }

    // ─── apply ───────────────────────────────────────────────────────────────

    public function testApplyFillsMissingColourOnly(): void
    {
        // DSC_2195: StarRate Blue, Lightroom-Sidecar ohne Farbe, mit Pick
        $this->sidecar->method('readMetadata')->willReturn(self::xmp(0, null, 'pick'));
        $this->sidecar->expects($this->once())->method('writeMetadata')
            ->with($this->anything(), 0, 'Blue', 'pick', 'en');

        $r = $this->sync->apply($this->raw(), self::db(0, 'Blue', 'none'), self::SETTINGS, ['rating', 'color'], false);
        $this->assertSame('written', $r);
    }

    public function testApplyLeavesUnselectedJpegFieldsToTheWriter(): void
    {
        // JPEG: nicht gewählte Felder als null (= unverändert), der Writer liest das volle XMP
        $jpeg = $this->createMock(File::class);
        $jpeg->method('getMimeType')->willReturn('image/jpeg');
        $jpeg->method('getId')->willReturn(43);
        $this->exif->method('readMetadata')->willReturn(self::xmp(0, null, 'none'));
        $this->exif->method('readXmpPacket')->willReturn('');
        $this->exif->expects($this->once())->method('writeMetadata')
            ->with($jpeg, 4, null, null, 'en');

        $r = $this->sync->apply($jpeg, self::db(4, 'Blue', 'pick'), self::SETTINGS, ['rating'], false);
        $this->assertSame('written', $r);
    }

    public function testApplyLeavesDifferentValueWithoutOverwrite(): void
    {
        // DSC_2183: StarRate Yellow, in Lightroom auf Green gesetzt → ohne Overwrite unangetastet
        $this->sidecar->method('readMetadata')->willReturn(self::xmp(5, 'Green', 'pick'));
        $this->sidecar->expects($this->never())->method('writeMetadata');

        $r = $this->sync->apply($this->raw(), self::db(5, 'Yellow', 'pick'), self::SETTINGS, ['rating', 'color'], false);
        $this->assertSame('unchanged', $r);
    }

    public function testApplyReplacesDifferentValueWithOverwrite(): void
    {
        $this->sidecar->method('readMetadata')->willReturn(self::xmp(5, 'Green', 'pick'));
        $this->sidecar->expects($this->once())->method('writeMetadata')
            ->with($this->anything(), 5, 'Yellow', 'pick', 'en');

        $this->sync->apply($this->raw(), self::db(5, 'Yellow', 'pick'), self::SETTINGS, ['rating', 'color'], true);
    }

    public function testApplyNeverClearsValuesOnlyInFile(): void
    {
        // Datei hat 3★/Red, StarRate nichts → auch mit Overwrite nichts schreiben
        $this->sidecar->method('readMetadata')->willReturn(self::xmp(3, 'Red', 'pick'));
        $this->sidecar->expects($this->never())->method('writeMetadata');

        $r = $this->sync->apply($this->raw(), self::db(), self::SETTINGS, ['rating', 'color', 'pick'], true);
        $this->assertSame('unchanged', $r);
    }

    public function testApplyReportsNoStoreWhenSidecarsOff(): void
    {
        $this->sidecar->expects($this->never())->method('readMetadata');
        $settings = ['write_raw_sidecar' => false] + self::SETTINGS;

        $this->assertSame('no_store', $this->sync->apply($this->raw(), self::db(5), $settings, ['rating'], false));
    }

    public function testApplyReportsFailureWithoutThrowing(): void
    {
        $this->sidecar->method('readMetadata')->willReturn(self::xmp());
        $this->sidecar->method('writeMetadata')->willThrowException(new \RuntimeException('locked'));

        $this->assertSame('failed', $this->sync->apply($this->raw(), self::db(5), self::SETTINGS, ['rating'], false));
    }

    public function testCompareOnJpegUsesEmbeddedXmp(): void
    {
        $f = $this->createMock(File::class);
        $f->method('getMimeType')->willReturn('image/jpeg');
        $this->exif->method('readMetadata')->willReturn(self::xmp(0, null, 'none'));
        $this->sidecar->expects($this->never())->method('readMetadata');

        $c = $this->sync->compare($f, self::db(4), self::SETTINGS);
        $this->assertSame('ok', $c['status']);
        $this->assertSame('missing', $c['diffs']['rating']['kind']);
    }

    public function testCompareReportsUnreadable(): void
    {
        $this->sidecar->method('readMetadata')->willThrowException(new \RuntimeException('offline'));
        $this->assertSame('unreadable', $this->sync->compare($this->raw(), self::db(5), self::SETTINGS)['status']);
    }
}

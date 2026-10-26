<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Service\XmpStoreResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class XmpStoreResolverTest extends TestCase
{
    private ExifService $exif;
    private SidecarService $sidecar;
    private XmpStoreResolver $resolver;

    private const ON = ['show_raw' => true, 'write_xmp' => true, 'write_raw_sidecar' => true];

    protected function setUp(): void
    {
        $this->exif     = $this->createMock(ExifService::class);
        $this->sidecar  = $this->createMock(SidecarService::class);
        $this->resolver = new XmpStoreResolver($this->exif, $this->sidecar);
    }

    private function file(string $name, string $mime): File
    {
        $f = $this->createMock(File::class);
        $f->method('getName')->willReturn($name);
        $f->method('getMimeType')->willReturn($mime);
        return $f;
    }

    public function testJpegUsesEmbeddedXmp(): void
    {
        $this->assertSame($this->exif, $this->resolver->forFile($this->file('a.jpg', 'image/jpeg'), self::ON));
    }

    public function testRawUsesSidecar(): void
    {
        $this->assertSame($this->sidecar, $this->resolver->forFile($this->file('a.NEF', 'image/x-dcraw'), self::ON));
    }

    public function testNothingForDngPngOrDisabledSettings(): void
    {
        $this->assertNull($this->resolver->forFile($this->file('a.DNG', 'image/x-dcraw'), self::ON));
        $this->assertNull($this->resolver->forFile($this->file('a.png', 'image/png'), self::ON));
        $this->assertNull($this->resolver->forFile($this->file('a.jpg', 'image/jpeg'), ['write_xmp' => false]));
        $this->assertNull($this->resolver->forFile(
            $this->file('a.NEF', 'image/x-dcraw'),
            ['show_raw' => true, 'write_xmp' => true, 'write_raw_sidecar' => false],
        ));
    }
}

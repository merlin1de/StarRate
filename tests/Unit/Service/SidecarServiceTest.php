<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Service\XmpService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SidecarServiceTest extends TestCase
{
    /** Gekürztes Lightroom-Classic-Sidecar mit Entwicklungseinstellungen. */
    private const LR_SIDECAR = <<<'XMP'
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Adobe XMP Core 7.0-c000">
 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about=""
    xmlns:xmp="http://ns.adobe.com/xap/1.0/"
    xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/"
    xmp:Rating="2"
    crs:Version="16.0"
    crs:WhiteBalance="As Shot"
    crs:Exposure2012="+0.35"
    crs:Contrast2012="+12">
   <crs:ToneCurvePV2012>
    <rdf:Seq>
     <rdf:li>0, 0</rdf:li>
     <rdf:li>255, 255</rdf:li>
    </rdf:Seq>
   </crs:ToneCurvePV2012>
  </rdf:Description>
 </rdf:RDF>
</x:xmpmeta>
XMP;

    private const EMPTY = ['rating' => 0, 'label' => null, 'pick' => 'none'];

    private SidecarService $service;
    private ExifService $exif;

    protected function setUp(): void
    {
        $logger        = $this->createMock(LoggerInterface::class);
        $this->exif    = new ExifService(new XmpService(), $logger);
        $this->service = new SidecarService($this->exif, $logger);
    }

    /**
     * In-Memory-Ordner: $files ist name => content und wird beim Schreiben aktualisiert.
     */
    private function rawInFolder(string $rawName, array &$files, string $mime = 'image/x-dcraw'): File
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturnCallback(function (string $name) use (&$files) {
            return array_key_exists($name, $files);
        });
        $folder->method('get')->willReturnCallback(function (string $name) use (&$files) {
            $f = $this->createMock(File::class);
            $f->method('getName')->willReturn($name);
            $f->method('getContent')->willReturnCallback(fn() => $files[$name]);
            $f->method('putContent')->willReturnCallback(function (string $c) use (&$files, $name) { $files[$name] = $c; });
            return $f;
        });
        $folder->method('newFile')->willReturnCallback(function (string $name, $content) use (&$files) {
            $files[$name] = $content;
            return $this->createMock(File::class);
        });

        $raw = $this->createMock(File::class);
        $raw->method('getName')->willReturn($rawName);
        $raw->method('getMimeType')->willReturn($mime);
        $raw->method('getParent')->willReturn($folder);
        return $raw;
    }

    public function testSidecarNames(): void
    {
        $this->assertSame(
            ['DSC_0001.xmp', 'DSC_0001.XMP', 'DSC_0001.NEF.xmp', 'DSC_0001.NEF.XMP'],
            SidecarService::sidecarNames('DSC_0001.NEF'),
        );
    }

    public function testAppliesToOnlyRawWithBothSettingsOn(): void
    {
        $files = [];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);
        $jpeg  = $this->rawInFolder('DSC_0001.JPG', $files, 'image/jpeg');
        $on    = ['write_xmp' => true, 'write_raw_sidecar' => true];

        $this->assertTrue(SidecarService::appliesTo($raw, $on));
        $this->assertFalse(SidecarService::appliesTo($jpeg, $on));
        $this->assertFalse(SidecarService::appliesTo($raw, ['write_xmp' => false, 'write_raw_sidecar' => true]));
        $this->assertFalse(SidecarService::appliesTo($raw, ['write_xmp' => true, 'write_raw_sidecar' => false]));
    }

    public function testCreatesLightroomStyleSidecarWhenMissing(): void
    {
        $files = [];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);

        $this->assertSame(self::EMPTY, $this->service->readMetadata($raw));
        $this->service->writeMetadata($raw, 4, 'Green', 'pick');

        $this->assertArrayHasKey('DSC_0001.xmp', $files);
        $this->assertSame(['rating' => 4, 'label' => 'Green', 'pick' => 'pick'], $this->service->readMetadata($raw));
    }

    public function testDoesNotCreateEmptySidecar(): void
    {
        $files = [];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);

        $this->service->writeMetadata($raw, 0, '', 'none');
        $this->assertSame([], $files);
    }

    public function testPatchesExistingLightroomSidecarAndKeepsDevelopSettings(): void
    {
        $files = ['DSC_0002.xmp' => self::LR_SIDECAR];
        $raw   = $this->rawInFolder('DSC_0002.NEF', $files);

        $this->assertSame(2, $this->service->readMetadata($raw)['rating']);
        $this->service->writeMetadata($raw, 5, 'Red', null);

        $xmp = $files['DSC_0002.xmp'];
        $this->assertStringContainsString('crs:Exposure2012="+0.35"', $xmp);
        $this->assertStringContainsString('<crs:ToneCurvePV2012>', $xmp);
        $this->assertStringContainsString('<rdf:li>255, 255</rdf:li>', $xmp);
        $this->assertSame(1, substr_count($xmp, 'xmp:Rating='));
        $this->assertSame(['rating' => 5, 'label' => 'Red', 'pick' => 'none'], $this->service->readMetadata($raw));

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xmp), 'gepatchtes Sidecar muss wohlgeformtes XML bleiben');
    }

    public function testNullKeepsFieldsAndEmptyClears(): void
    {
        $files = [];
        $raw   = $this->rawInFolder('DSC_0003.NEF', $files);
        $this->service->writeMetadata($raw, 3, 'Blue', 'pick');

        $this->service->writeMetadata($raw, null, '', null);
        $this->assertSame(['rating' => 3, 'label' => null, 'pick' => 'pick'], $this->service->readMetadata($raw));

        $this->service->writeMetadata($raw, 0, null, 'none');
        $this->assertSame(self::EMPTY, $this->service->readMetadata($raw));
    }

    public function testUpdatesDarktableStyleSidecarWhenNoLightroomOne(): void
    {
        $files = ['DSC_0004.NEF.xmp' => self::LR_SIDECAR];
        $raw   = $this->rawInFolder('DSC_0004.NEF', $files);

        $this->service->writeMetadata($raw, 1, null, null);
        $this->assertArrayNotHasKey('DSC_0004.xmp', $files);
        $this->assertSame(1, $this->service->readMetadata($raw)['rating']);
    }

    public function testRefusesToOverwriteUnpatchableXmp(): void
    {
        $unpatchable = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><something/></x:xmpmeta>';
        $files       = ['DSC_0005.xmp' => $unpatchable];
        $raw         = $this->rawInFolder('DSC_0005.NEF', $files);

        try {
            $this->service->writeMetadata($raw, 5, null, null);
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException) {
            $this->assertSame($unpatchable, $files['DSC_0005.xmp']);
        }
    }

    public function testWriteMetadataToXmpValidatesInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exif->writeMetadataToXmp(null, 9, null, null);
    }
}

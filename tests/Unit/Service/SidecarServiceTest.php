<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Service\XmpService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
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
    private TagService $tags;

    /** DB-Stand (TagService::getMetadata) je fileId */
    private array $db = [];
    /** Anzahl getDirectoryListing()-Aufrufe */
    private int $listings = 0;
    /** @var list<string> gelöschte Dateinamen */
    private array $deleted = [];
    /** @var list<string> Ziel-Pfade von move() */
    private array $moved = [];

    protected function setUp(): void
    {
        $logger     = $this->createMock(LoggerInterface::class);
        $this->exif = new ExifService(new XmpService(), $logger);
        $this->tags = $this->createMock(TagService::class);
        $this->tags->method('getMetadata')->willReturnCallback(
            fn(string $id) => $this->db[$id] ?? ['rating' => 0, 'color' => null, 'pick' => 'none']
        );
        $this->service = new SidecarService($this->exif, $this->tags, $logger);
    }

    /**
     * In-Memory-Ordner /u/files/Shoot: $files ist name => content und wird beim
     * Schreiben, Löschen und Verschieben aktualisiert.
     */
    private function folder(array &$files): Folder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(7);
        $folder->method('getPath')->willReturn('/u/files/Shoot');
        $folder->method('nodeExists')->willReturnCallback(fn(string $name) => array_key_exists($name, $files));
        $folder->method('get')->willReturnCallback(function (string $name) use (&$files, $folder) {
            if (!array_key_exists($name, $files)) {
                throw new NotFoundException($name);
            }
            return $this->fileNode($name, $files, $folder);
        });
        $folder->method('getDirectoryListing')->willReturnCallback(function () use (&$files, $folder) {
            $this->listings++;
            $nodes = [];
            foreach (array_keys($files) as $n) {
                $nodes[] = $this->fileNode($n, $files, $folder);   // kein fn(): das kopiert $files
            }
            return $nodes;
        });
        $folder->method('newFile')->willReturnCallback(function (string $name, $content) use (&$files, $folder) {
            $files[$name] = $content;
            return $this->fileNode($name, $files, $folder);
        });
        return $folder;
    }

    private function fileNode(string $name, array &$files, Folder $folder): File
    {
        $f = $this->createMock(File::class);
        $f->method('getName')->willReturn($name);
        $f->method('getPath')->willReturn('/u/files/Shoot/' . $name);
        $f->method('getParent')->willReturn($folder);
        $f->method('getContent')->willReturnCallback(function () use (&$files, $name) { return $files[$name]; });
        $f->method('putContent')->willReturnCallback(function (string $c) use (&$files, $name) { $files[$name] = $c; });
        $f->method('delete')->willReturnCallback(function () use (&$files, $name) {
            unset($files[$name]);
            $this->deleted[] = $name;
        });
        $f->method('move')->willReturnCallback(function (string $target) use (&$files, $name, $f) {
            $files[basename($target)] = $files[$name];
            unset($files[$name]);
            $this->moved[] = $target;
            return $f;
        });
        return $f;
    }

    private function rawInFolder(string $rawName, array &$files, string $mime = 'image/x-dcraw', int $id = 42): File
    {
        $raw = $this->createMock(File::class);
        $raw->method('getId')->willReturn($id);
        $raw->method('getName')->willReturn($rawName);
        $raw->method('getMimeType')->willReturn($mime);
        $raw->method('getParent')->willReturn($this->folder($files));
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

    public function testDngGetsNoSidecar(): void
    {
        // Lightroom liest bei DNG das eingebettete XMP und ignoriert IMG_0001.xmp
        $files = [];
        $dng   = $this->rawInFolder('IMG_0001.DNG', $files);
        $this->assertFalse(SidecarService::hasSidecar($dng));
        $this->assertFalse(SidecarService::appliesTo($dng, ['write_xmp' => true, 'write_raw_sidecar' => true]));
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

    public function testNewSidecarStartsFromDbStateNotEmpty(): void
    {
        // 4★ in der DB, noch kein Sidecar, nur die Farbe ändert sich → das Rating
        // muss im neuen Sidecar landen, sonst kippt das Self-Healing die DB auf 0.
        $this->db['42'] = ['rating' => 4, 'color' => 'Red', 'pick' => 'pick'];
        $files = [];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);

        $this->service->writeMetadata($raw, null, 'Red', null);
        $this->assertSame(['rating' => 4, 'label' => 'Red', 'pick' => 'pick'], $this->service->readMetadata($raw));
    }

    public function testDoesNotCreateEmptySidecar(): void
    {
        $files = [];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);

        $this->service->writeMetadata($raw, 0, '', 'none');
        $this->assertSame([], $files);
    }

    public function testEmptySidecarFileIsFilledInsteadOfFailing(): void
    {
        $files = ['DSC_0001.xmp' => ''];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);

        $this->service->writeMetadata($raw, 3, null, null);
        $this->assertSame(3, $this->service->readMetadata($raw)['rating']);
        $this->assertSame(['DSC_0001.xmp'], array_keys($files));
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

    public function testRefusesToReplaceTruncatedSidecar(): void
    {
        // rdf:Description ist da, aber abgeschnitten — der Patch findet keinen Block.
        // Früher wurde dann ein frisches Paket geschrieben und die Entwicklung war weg.
        $truncated = substr(self::LR_SIDECAR, 0, strpos(self::LR_SIDECAR, 'xmp:Rating'));
        $files     = ['DSC_0006.xmp' => $truncated];
        $raw       = $this->rawInFolder('DSC_0006.NEF', $files);

        try {
            $this->service->writeMetadata($raw, 5, null, null);
            $this->fail('RuntimeException erwartet');
        } catch (\RuntimeException) {
            $this->assertSame($truncated, $files['DSC_0006.xmp']);
        }
    }

    public function testBatchLookupsSwitchToOneFolderListing(): void
    {
        $files = ['DSC_0001.xmp' => self::LR_SIDECAR];
        $folder = $this->folder($files);
        $raws = [];
        foreach (range(1, 6) as $i) {
            $raw = $this->createMock(File::class);
            $raw->method('getId')->willReturn($i);
            $raw->method('getName')->willReturn(sprintf('DSC_%04d.NEF', $i));
            $raw->method('getParent')->willReturn($folder);
            $raws[] = $raw;
        }

        foreach ($raws as $raw) {
            $this->service->writeMetadata($raw, 2, null, null);
        }
        $this->assertSame(1, $this->listings, 'Ordner-Listing nur einmal laden');
        $this->assertCount(6, $files);
        // Nach dem Listing neu angelegte Sidecars werden auch wiedergefunden (kein Duplikat)
        $this->service->writeMetadata($raws[5], 4, null, null);
        $this->assertCount(6, $files);
        $this->assertSame(4, $this->service->readMetadata($raws[5])['rating']);
    }

    public function testDeleteSidecarOfRemovesMatchingSidecarOnly(): void
    {
        $files = ['DSC_0001.xmp' => self::LR_SIDECAR, 'DSC_0002.xmp' => self::LR_SIDECAR];
        $raw   = $this->rawInFolder('DSC_0001.NEF', $files);

        $this->service->deleteSidecarOf($raw);
        $this->assertSame(['DSC_0001.xmp'], $this->deleted);
        $this->assertSame(['DSC_0002.xmp'], array_keys($files));
    }

    public function testMoveSidecarWithKeepsNamingConvention(): void
    {
        $files  = ['DSC_0001.NEF.xmp' => self::LR_SIDECAR];
        $source = $this->rawInFolder('DSC_0001.NEF', $files);
        $target = $this->rawInFolder('Paris_001.NEF', $files);

        $this->service->moveSidecarWith($source, $target);
        $this->assertSame(['/u/files/Shoot/Paris_001.NEF.xmp'], $this->moved);
    }

    public function testMoveSidecarNeverOverwritesExistingTarget(): void
    {
        $files  = ['DSC_0001.xmp' => self::LR_SIDECAR, 'Paris_001.xmp' => 'other'];
        $source = $this->rawInFolder('DSC_0001.NEF', $files);
        $target = $this->rawInFolder('Paris_001.NEF', $files);

        $this->service->moveSidecarWith($source, $target);
        $this->assertSame([], $this->moved);
        $this->assertSame('other', $files['Paris_001.xmp']);
    }

    public function testWriteMetadataToXmpValidatesInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exif->writeMetadataToXmp(null, 9, null, null);
    }
}

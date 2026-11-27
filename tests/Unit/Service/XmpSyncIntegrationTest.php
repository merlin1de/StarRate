<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Service\XmpService;
use OCA\StarRate\Service\XmpStoreResolver;
use OCA\StarRate\Service\XmpSyncService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * „In Dateien schreiben“ mit echtem ExifService/SidecarService/XmpService gegen
 * echte Sidecar-Inhalte — die Stellen, an denen Patch und Backfill sitzen.
 */
class XmpSyncIntegrationTest extends TestCase
{
    private const SETTINGS = [
        'write_xmp' => true, 'show_raw' => true, 'write_raw_sidecar' => true, 'xmp_label_language' => 'en',
    ];

    private XmpSyncService $sync;
    /** DB-Stand für den Sidecar-Backfill */
    private array $db = [];

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $xmp    = new XmpService();
        $exif   = new ExifService($xmp, $logger);
        $tags   = $this->createMock(TagService::class);
        $tags->method('getMetadata')->willReturnCallback(
            fn() => $this->db ?: ['rating' => 0, 'color' => null, 'pick' => 'none']
        );
        $this->sync = new XmpSyncService(
            new XmpStoreResolver($exif, new SidecarService($exif, $tags, $logger)),
            $xmp,
            $logger,
        );
    }

    private static function sidecar(string $attrs): string
    {
        return <<<XMP
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Adobe XMP Core 7.0-c000">
 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about=""
    xmlns:xmp="http://ns.adobe.com/xap/1.0/"
    xmlns:xmpDM="http://ns.adobe.com/xmp/1.0/DynamicMedia/"
    xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"
    xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/"
    xmp:MetadataDate="2026-11-24T00:23:19Z"
    crs:Exposure2012="+0.35"
    {$attrs}>
  </rdf:Description>
 </rdf:RDF>
</x:xmpmeta>
XMP;
    }

    /** RAW in einem In-Memory-Ordner; $files[name] wird beim Schreiben aktualisiert. */
    private function raw(array &$files): File
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(7);
        $node = function (string $name) use (&$files): File {
            $f = $this->createMock(File::class);
            $f->method('getName')->willReturn($name);
            $f->method('getContent')->willReturnCallback(function () use (&$files, $name) { return $files[$name]; });
            $f->method('putContent')->willReturnCallback(function (string $c) use (&$files, $name) { $files[$name] = $c; });
            return $f;
        };
        $folder->method('get')->willReturnCallback(function (string $name) use (&$files, $node) {
            if (!array_key_exists($name, $files)) {
                throw new NotFoundException($name);
            }
            return $node($name);
        });
        // SidecarService wechselt ab dem 3. Lookup im Ordner auf das Listing
        $folder->method('getDirectoryListing')->willReturnCallback(function () use (&$files, $node) {
            return array_map($node, array_keys($files));
        });
        $raw = $this->createMock(File::class);
        $raw->method('getId')->willReturn(42);
        $raw->method('getName')->willReturn('DSC_2195.NEF');
        $raw->method('getMimeType')->willReturn('image/x-dcraw');
        $raw->method('getParent')->willReturn($folder);
        return $raw;
    }

    public function testFillsMissingColourAndKeepsFlagAndDevelopSettings(): void
    {
        // DSC_2195: Lightroom-Sidecar mit Pick, ohne Farbe; StarRate hat Blue
        $files = ['DSC_2195.xmp' => self::sidecar('xmpDM:pick="1" xmpDM:good="true"')];
        $raw   = $this->raw($files);
        $db    = ['rating' => 0, 'color' => 'Blue', 'pick' => 'none'];

        $this->assertSame('missing', $this->sync->compare($raw, $db, self::SETTINGS)['diffs']['color']['kind']);
        $this->assertSame('written', $this->sync->apply($raw, $db, self::SETTINGS, ['rating', 'color'], false));

        $out = $files['DSC_2195.xmp'];
        $this->assertStringContainsString('xmp:Label="Blue"', $out);
        $this->assertStringContainsString('xmpDM:pick="1"', $out);          // Flag aus der Datei bleibt
        $this->assertStringContainsString('crs:Exposure2012="+0.35"', $out); // Entwicklung bleibt
    }

    public function testUnselectedPickIsNotBackfilledFromDb(): void
    {
        // Sidecar ohne Pick; DB hat Pick — Markierungen abgewählt → kein xmpDM:pick
        $this->db = ['rating' => 3, 'color' => 'Red', 'pick' => 'pick'];
        $files = ['DSC_2195.xmp' => self::sidecar('')];
        $raw   = $this->raw($files);

        $this->sync->apply($raw, $this->db, self::SETTINGS, ['rating', 'color'], false);

        $out = $files['DSC_2195.xmp'];
        $this->assertStringContainsString('xmp:Rating="3"', $out);
        $this->assertStringContainsString('xmp:Label="Red"', $out);
        $this->assertStringNotContainsString('xmpDM:pick', $out);
    }

    public function testFileWithUnknownValuesIsNeverTouched(): void
    {
        // Alter Lightroom-Reject als Rating -1 + eigenes Label-Set
        $original = self::sidecar('xmp:Rating="-1" xmp:Label="To Print"');
        $files    = ['DSC_2195.xmp' => $original];
        $raw      = $this->raw($files);
        $db       = ['rating' => 3, 'color' => 'Red', 'pick' => 'none'];

        $cmp = $this->sync->compare($raw, $db, self::SETTINGS);
        $this->assertSame('skipped', $cmp['status']);
        $this->assertSame(['rating', 'color'], $cmp['unreadable']);

        $this->assertSame('skipped', $this->sync->apply($raw, $db, self::SETTINGS, ['rating', 'color', 'pick'], true));
        $this->assertSame($original, $files['DSC_2195.xmp']);
    }

    public function testDifferentValueOnlyReplacedWithOverwrite(): void
    {
        // DSC_2183: StarRate Yellow, in Lightroom Green
        $files = ['DSC_2195.xmp' => self::sidecar('xmp:Rating="5" xmp:Label="Green" photoshop:LabelColor="green" xmpDM:pick="1"')];
        $raw   = $this->raw($files);
        $db    = ['rating' => 5, 'color' => 'Yellow', 'pick' => 'pick'];

        $this->assertSame('unchanged', $this->sync->apply($raw, $db, self::SETTINGS, ['rating', 'color'], false));
        $this->assertStringContainsString('xmp:Label="Green"', $files['DSC_2195.xmp']);

        $this->assertSame('written', $this->sync->apply($raw, $db, self::SETTINGS, ['rating', 'color'], true));
        $this->assertStringContainsString('xmp:Label="Yellow"', $files['DSC_2195.xmp']);
        $this->assertStringContainsString('xmp:Rating="5"', $files['DSC_2195.xmp']);
    }

    public function testCreatesSidecarWithOnlySelectedValues(): void
    {
        $this->db = ['rating' => 4, 'color' => null, 'pick' => 'reject'];
        $files = [];
        $raw   = $this->raw($files);
        $folder = $raw->getParent();
        $folder->method('newFile')->willReturnCallback(function (string $name, $c) use (&$files) { $files[$name] = $c; return null; });

        $this->sync->apply($raw, $this->db, self::SETTINGS, ['rating'], false);

        $this->assertStringContainsString('xmp:Rating="4"', $files['DSC_2195.xmp']);
        $this->assertStringNotContainsString('xmpDM:pick', $files['DSC_2195.xmp']);
    }

    public function testUnreadableFieldsDetection(): void
    {
        $x = new XmpService();
        $this->assertSame([], $x->unreadableFields(self::sidecar('xmp:Rating="5" xmp:Label="Blau" xmpDM:pick="-1"')));
        $this->assertSame([], $x->unreadableFields(self::sidecar('xmp:Label=""')));
        $this->assertSame(['rating'], $x->unreadableFields(self::sidecar('xmp:Rating="-1"')));
        $this->assertSame(['color'], $x->unreadableFields(self::sidecar('xmp:Label="Good to Use"')));
        $this->assertSame(['pick'], $x->unreadableFields(self::sidecar('xmpDM:pick="2"')));
    }
}

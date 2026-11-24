<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Command;

use OCA\StarRate\Command\ImportXmpCommand;
use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\TagService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ starrate:import-xmp — vor allem Pick/Reject (#128): Der Import las xmpDM:pick,
 * schrieb es aber nie in die Tag-Datenbank.
 */
class ImportXmpCommandTest extends TestCase
{
    private const EMPTY_DB = ['rating' => 0, 'color' => null, 'pick' => 'none'];

    private ExifService&MockObject $exif;
    private TagService&MockObject $tags;
    private Folder&MockObject $folder;
    private IRootFolder&MockObject $root;

    /** @var array<string, array{rating: int, label: string|null, pick: string}> XMP pro Dateiname */
    private array $xmp = [];
    /** @var array<string, array{rating: int, color: string|null, pick: string}> DB-Stand pro fileId */
    private array $db = [];
    /** @var list<array{0: string, 1: array}> setMetadata-Aufrufe */
    private array $written = [];

    protected function setUp(): void
    {
        $this->exif = $this->createMock(ExifService::class);
        $this->exif->method('readMetadata')->willReturnCallback(
            fn(File $f) => $this->xmp[$f->getName()] ?? ['rating' => 0, 'label' => null, 'pick' => 'none']
        );

        $this->tags = $this->createMock(TagService::class);
        $this->tags->method('getMetadata')->willReturnCallback(
            fn(string $id) => $this->db[$id] ?? self::EMPTY_DB
        );
        $this->tags->method('setMetadata')->willReturnCallback(
            function (string $id, array $data): void { $this->written[] = [$id, $data]; }
        );

        $this->folder = $this->createMock(Folder::class);
        $this->folder->method('getPath')->willReturn('/admin/files/Photos');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getPath')->willReturn('/admin/files');
        $userFolder->method('get')->with('Photos')->willReturn($this->folder);

        $this->root = $this->createMock(IRootFolder::class);
        $this->root->method('getUserFolder')->with('admin')->willReturn($userFolder);
    }

    /** Legt die Dateien des Testordners an: [Dateiname => fileId]. */
    private function files(array $names): void
    {
        $files = [];
        foreach ($names as $name => $id) {
            $f = $this->createMock(File::class);
            $f->method('getName')->willReturn($name);
            $f->method('getId')->willReturn($id);
            $f->method('getMimeType')->willReturn('image/jpeg');
            $files[] = $f;
        }
        $this->folder->method('getDirectoryListing')->willReturn($files);
    }

    private function import(array $options = []): string
    {
        $tester = new CommandTester(new ImportXmpCommand($this->root, $this->exif, $this->tags));
        $tester->execute(
            ['nc-path' => '/Photos', '--user' => 'admin'] + $options,
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]
        );
        $this->assertSame(0, $tester->getStatusCode());
        return $tester->getDisplay();
    }

    public function testImportsFileThatOnlyHasAPick(): void
    {
        $this->files(['pick_only.jpg' => 11]);
        $this->xmp['pick_only.jpg'] = ['rating' => 0, 'label' => null, 'pick' => 'pick'];

        $out = $this->import();

        $this->assertSame([['11', ['rating' => 0, 'color' => null, 'pick' => 'pick']]], $this->written);
        $this->assertStringContainsString('pick=pick', $out);
        $this->assertStringContainsString('1 imported, 0 skipped (already rated), 0 without XMP', $out);
    }

    public function testImportsRejectTogetherWithRatingAndLabel(): void
    {
        $this->files(['reject.jpg' => 12]);
        $this->xmp['reject.jpg'] = ['rating' => 3, 'label' => 'Red', 'pick' => 'reject'];

        $this->import();

        $this->assertSame([['12', ['rating' => 3, 'color' => 'Red', 'pick' => 'reject']]], $this->written);
    }

    public function testExistingPickCountsAsAlreadyRated(): void
    {
        $this->files(['db_pick.jpg' => 13]);
        $this->xmp['db_pick.jpg'] = ['rating' => 5, 'label' => null, 'pick' => 'none'];
        $this->db['13']           = ['rating' => 0, 'color' => null, 'pick' => 'pick'];

        $out = $this->import();

        $this->assertSame([], $this->written, 'Pick aus der DB darf ohne --overwrite nicht verloren gehen');
        $this->assertStringContainsString('skip  db_pick.jpg (already rated: 0★ — pick=pick)', $out);
        $this->assertStringContainsString('0 imported, 1 skipped (already rated)', $out);
    }

    public function testOverwriteLetsXmpWinIncludingAMissingPick(): void
    {
        $this->files(['db_pick.jpg' => 13]);
        $this->xmp['db_pick.jpg'] = ['rating' => 5, 'label' => null, 'pick' => 'none'];
        $this->db['13']           = ['rating' => 0, 'color' => null, 'pick' => 'pick'];

        $this->import(['--overwrite' => true]);

        // Wie bei Sternen und Farbe: mit --overwrite ist das XMP maßgeblich, 'none' entfernt den Pick.
        $this->assertSame([['13', ['rating' => 5, 'color' => null, 'pick' => 'none']]], $this->written);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->files(['pick_only.jpg' => 11]);
        $this->xmp['pick_only.jpg'] = ['rating' => 0, 'label' => null, 'pick' => 'pick'];

        $out = $this->import(['--dry-run' => true]);

        $this->assertSame([], $this->written);
        $this->assertStringContainsString('import pick_only.jpg  rating=0  label=—  pick=pick', $out);
        $this->assertStringContainsString('[dry-run] Done: 1 imported', $out);
    }

    public function testFileWithoutRatingLabelOrPickIsSkippedAsNoXmp(): void
    {
        $this->files(['plain.jpg' => 14]);
        $this->tags->expects($this->never())->method('getMetadata');

        $out = $this->import();

        $this->assertSame([], $this->written);
        $this->assertStringContainsString('0 imported, 0 skipped (already rated), 1 without XMP', $out);
    }
}

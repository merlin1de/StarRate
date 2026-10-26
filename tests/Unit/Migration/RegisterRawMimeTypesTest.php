<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Migration;

use OCA\StarRate\Migration\RegisterRawMimeTypes;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RegisterRawMimeTypesTest extends TestCase
{
    public function testRetagsExistingCr3FilesAsCameraRaw(): void
    {
        $loader = $this->createMock(IMimeTypeLoader::class);
        $loader->method('getId')->with('image/x-dcraw')->willReturn(77);
        $loader->expects($this->once())->method('updateFilecache')->with('cr3', 77)->willReturn(3);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info')->with($this->stringContains('3 .cr3'));

        (new RegisterRawMimeTypes($loader))->run($output);
    }
}

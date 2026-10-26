<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Migration;

use OCA\StarRate\Migration\RegisterRawMimeTypes;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RegisterRawMimeTypesTest extends TestCase
{
    private function appConfig(string $stored, ?string &$written): IAppConfig
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturn($stored);
        $config->method('setValueString')->willReturnCallback(function ($app, $key, $value) use (&$written) {
            $written = $value;
            return true;
        });
        return $config;
    }

    public function testRetagsExistingCr3FilesAsCameraRawOnce(): void
    {
        $loader = $this->createMock(IMimeTypeLoader::class);
        $loader->method('getId')->with('image/x-dcraw')->willReturn(77);
        $loader->expects($this->once())->method('updateFilecache')->with('cr3', 77)->willReturn(3);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info')->with($this->stringContains('3 .cr3'));

        $written = null;
        (new RegisterRawMimeTypes($loader, $this->appConfig('', $written)))->run($output);
        $this->assertSame('cr3', $written);
    }

    public function testSkipsFullTableUpdateWhenAlreadyDone(): void
    {
        // Das UPDATE läuft per LIKE über den ganzen Filecache — nicht bei jedem App-Update
        $loader = $this->createMock(IMimeTypeLoader::class);
        $loader->expects($this->never())->method('updateFilecache');

        $written = null;
        (new RegisterRawMimeTypes($loader, $this->appConfig('cr3', $written)))->run($this->createMock(IOutput::class));
        $this->assertNull($written);
    }
}

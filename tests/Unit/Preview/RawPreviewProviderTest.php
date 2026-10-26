<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Preview;

use OCA\StarRate\Preview\RawPreviewProvider;
use OCA\StarRate\Service\RawPreviewExtractor;
use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RawPreviewProviderTest extends TestCase
{
    private function provider(bool $cameraRawPreviewsEnabled): RawPreviewProvider
    {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isInstalled')->with('camerarawpreviews')->willReturn($cameraRawPreviewsEnabled);
        if (method_exists(IAppManager::class, 'isEnabledForAnyone')) {
            $apps->method('isEnabledForAnyone')->with('camerarawpreviews')->willReturn($cameraRawPreviewsEnabled);
        }
        return new RawPreviewProvider(
            new RawPreviewExtractor($this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class),
            $apps,
        );
    }

    private function info(string $name, int $size = 1000): FileInfo
    {
        $f = $this->createMock(FileInfo::class);
        $f->method('getName')->willReturn($name);
        $f->method('getSize')->willReturn($size);
        return $f;
    }

    public function testAvailableForSupportedRawFormats(): void
    {
        $provider = $this->provider(false);
        $this->assertTrue($provider->isAvailable($this->info('IMG_0001.CR3')));
        $this->assertFalse($provider->isAvailable($this->info('IMG_0001.MRW')));
        $this->assertFalse($provider->isAvailable($this->info('IMG_0001.NEF', 0)));
    }

    public function testStepsAsideForCameraRawPreviewsApp(): void
    {
        // Die spezialisierte App kennt mehr Formate → NC soll deren Provider nehmen
        $this->assertFalse($this->provider(true)->isAvailable($this->info('DSC_0001.NEF')));
    }
}

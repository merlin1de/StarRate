<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Listener;

use OCA\StarRate\Listener\RawSidecarListener;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Settings\UserSettings;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RawSidecarListenerTest extends TestCase
{
    /** @var SidecarService&MockObject */
    private SidecarService $sidecars;
    private array $settings = ['write_xmp' => true, 'write_raw_sidecar' => true];

    private function listener(): RawSidecarListener
    {
        $this->sidecars ??= $this->createMock(SidecarService::class);
        $userSettings = $this->createMock(UserSettings::class);
        $userSettings->method('getSettings')->willReturnCallback(fn() => $this->settings);
        return new RawSidecarListener($this->sidecars, $userSettings, $this->createMock(LoggerInterface::class));
    }

    private function file(string $path, string $mime = 'image/x-dcraw'): File
    {
        $f = $this->createMock(File::class);
        $f->method('getPath')->willReturn($path);
        $f->method('getName')->willReturn(basename($path));
        $f->method('getMimeType')->willReturn($mime);
        return $f;
    }

    protected function setUp(): void
    {
        $this->sidecars = $this->createMock(SidecarService::class);
    }

    public function testDeletingRawDeletesSidecar(): void
    {
        $raw = $this->file('/anna/files/Shoot/DSC_0001.NEF');
        $this->sidecars->expects($this->once())->method('deleteSidecarOf')->with($raw);

        $this->listener()->handle(new NodeDeletedEvent($raw));
    }

    public function testIgnoresJpegDngTrashAndDisabledSidecars(): void
    {
        $this->sidecars->expects($this->never())->method('deleteSidecarOf');
        $listener = $this->listener();

        $listener->handle(new NodeDeletedEvent($this->file('/anna/files/Shoot/DSC_0001.JPG', 'image/jpeg')));
        $listener->handle(new NodeDeletedEvent($this->file('/anna/files/Shoot/IMG_0001.DNG')));
        $listener->handle(new NodeDeletedEvent($this->file('/anna/files_trashbin/files/DSC_0001.NEF.d1700000000')));

        $this->settings['write_raw_sidecar'] = false;
        $listener->handle(new NodeDeletedEvent($this->file('/anna/files/Shoot/DSC_0001.NEF')));
    }

    public function testRenamingRawMovesSidecar(): void
    {
        $source = $this->file('/anna/files/Shoot/DSC_0001.NEF');
        $target = $this->file('/anna/files/Paris/Paris_001.NEF');
        $this->sidecars->expects($this->once())->method('moveSidecarWith')->with($source, $target);

        $this->listener()->handle(new NodeRenamedEvent($source, $target));
    }

    public function testRenamingRawToNonRawLeavesSidecar(): void
    {
        $this->sidecars->expects($this->never())->method('moveSidecarWith');
        $this->listener()->handle(new NodeRenamedEvent(
            $this->file('/anna/files/Shoot/DSC_0001.NEF'),
            $this->file('/anna/files/Shoot/DSC_0001.bak', 'application/octet-stream'),
        ));
    }

    public function testFailuresNeverBreakTheFileOperation(): void
    {
        $this->sidecars->method('deleteSidecarOf')->willThrowException(new \RuntimeException('locked'));
        $this->listener()->handle(new NodeDeletedEvent($this->file('/anna/files/Shoot/DSC_0001.NEF')));
        $this->addToAssertionCount(1);
    }
}

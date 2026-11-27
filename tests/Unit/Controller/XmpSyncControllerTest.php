<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Controller;

use OCA\StarRate\Controller\XmpSyncController;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Service\XmpSyncService;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class XmpSyncControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private IRequest $request;
    /** @var XmpSyncService&MockObject */
    private XmpSyncService $sync;
    /** @var TagService&MockObject */
    private TagService $tags;
    private array $settings = ['write_xmp' => true, 'show_raw' => true, 'write_raw_sidecar' => true, 'xmp_label_language' => 'en'];

    private function controller(bool $loggedIn = true): XmpSyncController
    {
        $session = $this->createMock(IUserSession::class);
        if ($loggedIn) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('rayne');
            $session->method('getUser')->willReturn($user);
        }

        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->willReturnCallback(function (int $id) {
            if ($id === 404) return [];
            $f = $this->createMock(File::class);
            $f->method('getName')->willReturn("DSC_{$id}.NEF");
            return [$f];
        });
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($folder);

        $userSettings = $this->createMock(UserSettings::class);
        $userSettings->method('getSettings')->willReturn($this->settings);

        return new XmpSyncController(
            'starrate', $this->request, $root, $session, $this->tags, $this->sync,
            $userSettings, $this->createMock(LoggerInterface::class),
        );
    }

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->sync    = $this->createMock(XmpSyncService::class);
        $this->tags    = $this->createMock(TagService::class);
        $this->tags->method('getMetadataBatch')->willReturn([]);
    }

    private function body(array $data): void
    {
        $this->request->method('getParams')->willReturn($data);
    }

    public function testPreviewListsOnlyFilesWithSomethingToWrite(): void
    {
        $this->body(['fileIds' => [1, 2, 3, 404]]);
        $this->sync->method('compare')->willReturnOnConsecutiveCalls(
            ['status' => 'ok', 'diffs' => ['color' => ['db' => 'Blue', 'file' => null, 'kind' => 'missing']]],
            ['status' => 'ok', 'diffs' => ['rating' => ['db' => 0, 'file' => 3, 'kind' => 'extra']]],
            ['status' => 'no_store', 'diffs' => []],
        );

        $data = $this->controller()->preview()->getData();

        $this->assertSame([1], array_column($data['items'], 'id'));
        $this->assertSame(1, $data['counts']['missing']['color']);
        $this->assertSame(1, $data['counts']['extra']['rating']);
        $this->assertSame([2, 1, 1], [$data['checked'], $data['noStore'], $data['notFound']]);
    }

    public function testApplyPassesFieldsAndOverwriteAndCountsResults(): void
    {
        $this->body(['fileIds' => [1, 2], 'fields' => ['color', 'bogus'], 'overwrite' => true]);
        $this->sync->expects($this->exactly(2))->method('apply')
            ->with($this->anything(), $this->anything(), $this->anything(), ['color'], true)
            ->willReturnOnConsecutiveCalls('written', 'failed');

        $data = $this->controller()->apply()->getData();
        $this->assertSame(1, $data['written']);
        $this->assertSame(1, $data['failed']);
    }

    public function testApplyRequiresFields(): void
    {
        $this->body(['fileIds' => [1], 'fields' => []]);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->apply()->getStatus());
    }

    public function testApplyRejectsTooManyIds(): void
    {
        $this->body(['fileIds' => range(1, XmpSyncController::MAX_APPLY_IDS + 1), 'fields' => ['rating']]);
        $this->sync->expects($this->never())->method('apply');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->apply()->getStatus());
    }

    public function testRefusesWhenXmpWritingIsOff(): void
    {
        $this->settings['write_xmp'] = false;
        $this->body(['fileIds' => [1], 'fields' => ['rating']]);
        $res = $this->controller()->apply();
        $this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
        $this->assertSame('xmp_disabled', $res->getData()['error']);
    }

    public function testRequiresLogin(): void
    {
        $this->body(['fileIds' => [1]]);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(false)->preview()->getStatus());
    }
}

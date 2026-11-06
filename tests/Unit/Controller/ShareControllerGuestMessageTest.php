<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Controller;

use OCA\StarRate\Controller\ShareController;
use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\TagService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareControllerGuestMessageTest extends TestCase
{
    private const TOKEN   = 'tok123';
    private const MESSAGE = 'For culling only.';
    private const PW_HASH = 'stored-password-hash';

    /** @var IRequest&MockObject */
    private IRequest $request;
    /** @var ShareService&MockObject */
    private ShareService $shareService;
    /** @var array<string, mixed> */
    private array $sessionData = [];
    private ShareController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->shareService = $this->createMock(ShareService::class);
        $this->shareService->method('isShareValid')->willReturn(true);

        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(fn(string $k) => $this->sessionData[$k] ?? null);
        $session->method('set')->willReturnCallback(function (string $k, $v): void {
            $this->sessionData[$k] = $v;
        });

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('owner');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $this->controller = new ShareController(
            'starrate',
            $this->request,
            $this->shareService,
            $this->createMock(TagService::class),
            $userSession,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IConfig::class),
            $session,
        );
    }

    private function share(array $overrides = []): array
    {
        return array_merge([
            'token'         => self::TOKEN,
            'owner_id'      => 'owner',
            'nc_path'       => '/Fotos',
            'permissions'   => 'view',
            'guest_message' => self::MESSAGE,
        ], $overrides);
    }

    private function givenShare(array $overrides = []): void
    {
        $this->shareService->method('getShare')->willReturn($this->share($overrides));
    }

    private function givenBody(array $body): void
    {
        $this->request->method('getParams')->willReturn($body);
    }

    // ─── Auslieferung nur hinter der Passwortprüfung ──────────────────────────

    public function testGuestImagesWithoutPasswordProofDoesNotLeakMessage(): void
    {
        $this->givenShare(['password_hash' => self::PW_HASH]);

        $response = $this->controller->guestImages(self::TOKEN);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertArrayNotHasKey('guest_message', $response->getData());
    }

    public function testGuestImagesWithWrongPwTokenDoesNotLeakMessage(): void
    {
        $this->givenShare(['password_hash' => self::PW_HASH]);
        $this->request->method('getHeader')->willReturn('wrong-token');

        $response = $this->controller->guestImages(self::TOKEN);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertArrayNotHasKey('guest_message', $response->getData());
    }

    public function testGuestImagesDeliversMessageAfterPasswordProof(): void
    {
        $this->givenShare(['password_hash' => self::PW_HASH]);
        $this->request->method('getHeader')->willReturn(hash_hmac('sha256', self::TOKEN, self::PW_HASH));
        $this->shareService->method('getImagesForShare')->willReturn(['images' => [], 'folders' => []]);

        $response = $this->controller->guestImages(self::TOKEN);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(self::MESSAGE, $response->getData()['guest_message']);
    }

    public function testGuestImagesReturnsNullForLegacyShareWithoutMessage(): void
    {
        $share = $this->share();
        unset($share['guest_message']);
        $this->shareService->method('getShare')->willReturn($share);
        $this->shareService->method('getImagesForShare')->willReturn(['images' => [], 'folders' => []]);

        $this->assertNull($this->controller->guestImages(self::TOKEN)->getData()['guest_message']);
    }

    public function testGuestPageTemplateNeverContainsMessage(): void
    {
        $this->givenShare();

        $params = $this->controller->guestView(self::TOKEN)->getParams();

        $this->assertArrayNotHasKey('guest_message', $params);
        $this->assertStringNotContainsString(self::MESSAGE, (string) json_encode($params));
    }

    // ─── Bestätigung ──────────────────────────────────────────────────────────

    public function testAcknowledgeWritesOneLogEntryPerSession(): void
    {
        $this->givenShare();
        $this->shareService->expects($this->once())->method('appendMessageAckToLog');

        $this->assertSame(Http::STATUS_OK, $this->controller->guestAcknowledgeMessage(self::TOKEN)->getStatus());
        $this->assertSame(Http::STATUS_OK, $this->controller->guestAcknowledgeMessage(self::TOKEN)->getStatus());
    }

    public function testAcknowledgeLogsAgainWhenTextChanged(): void
    {
        $this->shareService->method('getShare')->willReturnOnConsecutiveCalls(
            $this->share(),
            $this->share(['guest_message' => 'Pick your top 10.']),
        );
        $this->shareService->expects($this->exactly(2))->method('appendMessageAckToLog');

        $this->controller->guestAcknowledgeMessage(self::TOKEN);
        $this->controller->guestAcknowledgeMessage(self::TOKEN);
    }

    public function testAcknowledgeRequiresPassword(): void
    {
        $this->givenShare(['password_hash' => self::PW_HASH]);
        $this->shareService->expects($this->never())->method('appendMessageAckToLog');

        $response = $this->controller->guestAcknowledgeMessage(self::TOKEN);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testAcknowledgeWithoutMessageIsNotFound(): void
    {
        $this->givenShare(['guest_message' => null]);
        $this->shareService->expects($this->never())->method('appendMessageAckToLog');

        $response = $this->controller->guestAcknowledgeMessage(self::TOKEN);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testAcknowledgeOnInvalidLinkIsForbidden(): void
    {
        $this->shareService->method('getShare')->willReturn(null);

        $response = $this->controller->guestAcknowledgeMessage(self::TOKEN);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    // ─── Validierung ──────────────────────────────────────────────────────────

    public function testCreateRejectsTooLongMessage(): void
    {
        $this->givenBody([
            'nc_path'       => '/Fotos',
            'guest_message' => str_repeat('x', ShareService::GUEST_MESSAGE_MAX_LENGTH + 1),
        ]);
        $this->shareService->expects($this->never())->method('createShare');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->create()->getStatus());
    }

    public function testCreateCountsMultibyteCharactersNotBytes(): void
    {
        $this->givenBody([
            'nc_path'       => '/Fotos',
            'guest_message' => str_repeat('ü', ShareService::GUEST_MESSAGE_MAX_LENGTH),
        ]);
        $this->shareService->method('createShare')->willReturn($this->share());

        $this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
    }

    public function testUpdateRejectsNonStringMessage(): void
    {
        $this->givenShare();
        $this->givenBody(['guest_message' => ['<b>nope</b>']]);
        $this->shareService->expects($this->never())->method('updateShare');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->update(self::TOKEN)->getStatus());
    }

    public function testUpdateAcceptsNullToRemoveMessage(): void
    {
        $this->givenShare();
        $this->givenBody(['guest_message' => null]);
        $this->shareService->expects($this->once())
            ->method('updateShare')
            ->with(self::TOKEN, ['guest_message' => null])
            ->willReturn($this->share(['guest_message' => null]));

        $this->assertSame(Http::STATUS_OK, $this->controller->update(self::TOKEN)->getStatus());
    }
}

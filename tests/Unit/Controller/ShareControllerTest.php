<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Controller;

use OCA\StarRate\Controller\ShareController;
use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\TagService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareControllerTest extends TestCase
{
    private const TOKEN   = 'AbCdEfGhIjKlMnOp';
    private const FILE_ID = 42;

    private IRequest&MockObject $request;
    private ShareService&MockObject $shareService;
    private ShareController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->shareService = $this->createMock(ShareService::class);

        $this->controller = new ShareController(
            'starrate',
            $this->request,
            $this->shareService,
            $this->createMock(TagService::class),
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IConfig::class),
        );
    }

    /**
     * Gast-Share mit Bewertungsrecht; $flags überschreibt allow_rating/allow_color/allow_pick.
     * Werte wie nach ShareService::getShare() (dort werden fehlende Schalter normalisiert).
     */
    private function rateShare(array $flags = []): array
    {
        return array_merge([
            'token'        => self::TOKEN,
            'owner_id'     => 'photographer',
            'nc_path'      => '/Fotos',
            'permissions'  => ShareService::PERM_RATE,
            'allow_rating' => true,
            'allow_color'  => true,
            'allow_pick'   => false,
        ], $flags);
    }

    /**
     * Ruft guestRate() auf und liefert die an saveGuestRating() übergebenen
     * Werte als [rating, color, pick].
     */
    private function guestRate(array $share, array $body): array
    {
        $this->shareService->method('getShare')->willReturn($share);
        $this->shareService->method('isShareValid')->willReturn(true);
        $this->shareService->method('fileExistsInShare')->willReturn(true);
        $this->request->method('getParams')->willReturn(['file_id' => self::FILE_ID] + $body);

        $saved = null;
        $this->shareService->method('saveGuestRating')
            ->willReturnCallback(function ($s, $id, $rating, $color, $pick) use (&$saved) {
                $saved = [$rating, $color, $pick];
                return [];
            });

        $this->controller->guestRate(self::TOKEN);
        $this->assertNotNull($saved, 'saveGuestRating wurde nicht aufgerufen');
        return $saved;
    }

    // ─── Bewertungsarten pro Share ────────────────────────────────────────────

    public function testGuestRateAcceptsStarsAndColorByDefault(): void
    {
        $this->assertSame([4, 'Green', null], $this->guestRate($this->rateShare(), ['rating' => 4, 'color' => 'green']));
    }

    public function testGuestRateIgnoresStarsWhenShareDisallowsThem(): void
    {
        $share = $this->rateShare(['allow_rating' => false]);
        $this->assertSame([null, 'Red', null], $this->guestRate($share, ['rating' => 5, 'color' => 'Red']));
    }

    public function testGuestRateIgnoresColorWhenShareDisallowsIt(): void
    {
        $share = $this->rateShare(['allow_color' => false, 'allow_pick' => true]);
        $this->assertSame([null, null, 'pick'], $this->guestRate($share, ['color' => null, 'pick' => 'pick']));
    }
}

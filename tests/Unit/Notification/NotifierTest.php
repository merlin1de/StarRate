<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Notification;

use OCA\StarRate\Notification\Notifier;
use OCA\StarRate\Service\GuestActivityNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
    private Notifier $notifier;
    /** @var array<string,string> */
    private array $parsed = [];

    protected function setUp(): void
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn($text, $params = []) => vsprintf($text, (array) $params));
        $l->method('n')->willReturnCallback(
            fn($singular, $plural, $count, $params = []) =>
                vsprintf(str_replace('%n', (string) $count, $count === 1 ? $singular : $plural), $params)
        );
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);

        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturnCallback(
            fn($route, $params = []) => $route . ($params ? '?' . http_build_query($params) : '')
        );
        $url->method('imagePath')->willReturn('/apps/starrate/img/app.svg');
        $url->method('getAbsoluteURL')->willReturnArgument(0);

        $this->notifier = new Notifier($factory, $url);
    }

    private function notification(array $params, string $app = 'starrate', string $subject = GuestActivityNotifier::SUBJECT): INotification
    {
        $n = $this->createMock(INotification::class);
        $n->method('getApp')->willReturn($app);
        $n->method('getSubject')->willReturn($subject);
        $n->method('getSubjectParameters')->willReturn($params);
        foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link', 'setIcon' => 'icon'] as $m => $k) {
            $n->method($m)->willReturnCallback(function ($v) use ($n, $k) {
                $this->parsed[$k] = $v;
                return $n;
            });
        }
        return $n;
    }

    public function testPluralSubjectAndFolderLink(): void
    {
        $this->notifier->prepare(
            $this->notification(['guest' => 'Jane', 'count' => 50, 'folder' => 'Shooting-2024', 'path' => '/Fotos/Shooting-2024']),
            'de'
        );

        $this->assertSame('Jane hat 50 Bilder in Shooting-2024 bewertet', $this->parsed['subject']);
        $this->assertSame('Zeit, die Metadaten zu synchronisieren.', $this->parsed['message']);
        $this->assertSame('starrate.gallery.folder?path=' . urlencode('Fotos/Shooting-2024'), $this->parsed['link']);
    }

    public function testSingularAndUnknownGuestAndRootLink(): void
    {
        $this->notifier->prepare(
            $this->notification(['guest' => '', 'count' => 1, 'folder' => '/', 'path' => '']),
            'de'
        );

        $this->assertSame('Ein Gast hat 1 Bild in / bewertet', $this->parsed['subject']);
        $this->assertSame('starrate.gallery.index', $this->parsed['link']);
    }

    public function testForeignNotificationIsRejected(): void
    {
        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($this->notification([], 'files'), 'de');
    }
}

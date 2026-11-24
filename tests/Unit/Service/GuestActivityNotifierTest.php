<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\GuestActivityNotifier;
use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GuestActivityNotifierTest extends TestCase
{
    private const OWNER = 'photographer1';
    private const TOKEN = 'AbCdEfGh12345678AbCdEfGh';
    private const T0    = 1_800_000_000;

    /** @var array<string,string> */
    private array $userValues = [];
    /** @var array<string,string> */
    private array $appValues = [];
    private array $log = [];
    private int $now = self::T0;
    private bool $enabled = true;
    /** @var array<int,array> */
    private array $sent = [];

    private GuestActivityNotifier $notifier;

    protected function setUp(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            fn($u, $a, $k, $d = '') => $this->userValues[$k] ?? $d
        );
        $config->method('setUserValue')->willReturnCallback(
            function ($u, $a, $k, $v): void { $this->userValues[$k] = $v; }
        );
        $config->method('getAppValue')->willReturnCallback(
            fn($a, $k, $d = '') => $this->appValues[$k] ?? $d
        );
        $config->method('setAppValue')->willReturnCallback(
            function ($a, $k, $v): void { $this->appValues[$k] = $v; }
        );

        /** @var ShareService&MockObject $shares */
        $shares = $this->createMock(ShareService::class);
        $shares->method('getAllShareOwners')->willReturn([self::OWNER]);
        $shares->method('getSharesByOwner')->willReturnCallback(fn() => [[
            'token'      => self::TOKEN,
            'nc_path'    => '/Fotos/Shooting-2024',
            'guest_name' => 'Jane',
            'created_at' => self::T0 - 86400,
        ]]);
        $shares->method('getRawGuestLog')->willReturnCallback(fn() => $this->log);

        $settings = $this->createMock(UserSettings::class);
        $settings->method('getSettings')->willReturnCallback(
            fn() => ['notify_guest_activity' => $this->enabled]
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn() => $this->now);

        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturnCallback(function () {
            $n = $this->createMock(INotification::class);
            $params = [];
            foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $m) {
                $n->method($m)->willReturnSelf();
            }
            $n->method('setSubject')->willReturnCallback(function ($s, $p) use ($n, &$params) {
                $params = $p;
                return $n;
            });
            $n->method('getSubjectParameters')->willReturnCallback(function () use (&$params) {
                return $params;
            });
            return $n;
        });
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->sent[] = $n->getSubjectParameters();
        });

        $this->notifier = new GuestActivityNotifier(
            $config, $shares, $settings, $manager, $time, $this->createMock(LoggerInterface::class)
        );
    }

    private function rate(int $fileId, int $ts, string $guest = 'Jane', array $fields = ['rating' => 3]): void
    {
        $this->log[] = array_merge(
            ['file_id' => $fileId, 'rating' => null, 'color' => null, 'pick' => null],
            $fields,
            ['guest_name' => $guest, 'timestamp' => $ts]
        );
    }

    public function testFirstRunSkipsHistory(): void
    {
        $this->rate(1, self::T0 - 3600);
        $this->notifier->run();
        $this->now += 3600;
        $this->notifier->run();
        $this->assertSame([], $this->sent);
    }

    public function testGroupsDistinctFilesIntoOneNotificationAfterQuietPeriod(): void
    {
        $this->notifier->run(); // initialise watermark

        for ($i = 1; $i <= 50; $i++) {
            $this->rate($i, self::T0 + $i);
        }
        $this->rate(7, self::T0 + 60, 'Jane', ['color' => 'Red']); // same file again
        $this->log[] = ['file_id' => 3, 'comment' => 'nice', 'guest_name' => 'Jane', 'timestamp' => self::T0 + 61];

        $this->now = self::T0 + 120;
        $this->notifier->run();
        $this->assertSame([], $this->sent, 'still within quiet period');

        $this->now = self::T0 + 61 + GuestActivityNotifier::QUIET_SECONDS + 1;
        $this->notifier->run();
        $this->assertCount(1, $this->sent);
        $this->assertSame('Jane', $this->sent[0]['guest']);
        $this->assertSame(50, $this->sent[0]['count']);
        $this->assertSame('Shooting-2024', $this->sent[0]['folder']);

        // Nothing new → no repeat
        $this->now += 3600;
        $this->notifier->run();
        $this->assertCount(1, $this->sent);
    }

    public function testSeparateGuestsAndPlaceholderName(): void
    {
        $this->notifier->run();
        $this->rate(1, self::T0 + 1, 'Gast');   // placeholder → share guest_name
        $this->rate(2, self::T0 + 2, 'Bob');
        $this->rate(3, self::T0 + 3, 'Bob');

        $this->now = self::T0 + 10 + GuestActivityNotifier::QUIET_SECONDS;
        $this->notifier->run();

        $byGuest = array_column($this->sent, 'count', 'guest');
        $this->assertSame(['Jane' => 1, 'Bob' => 2], $byGuest);
    }

    public function testDisabledSettingDropsPending(): void
    {
        $this->notifier->run();
        $this->rate(1, self::T0 + 1);
        $this->enabled = false;
        $this->now = self::T0 + 1 + GuestActivityNotifier::QUIET_SECONDS;
        $this->notifier->run();
        $this->enabled = true;
        $this->now += 3600;
        $this->notifier->run();
        $this->assertSame([], $this->sent);
    }

    public function testCountSurvivesLogTrimming(): void
    {
        $this->notifier->run();
        for ($i = 1; $i <= 30; $i++) {
            $this->rate($i, self::T0 + $i);
        }
        $this->now = self::T0 + 40;
        $this->notifier->run();

        // Log gets trimmed, guest keeps going
        $this->log = [];
        for ($i = 31; $i <= 40; $i++) {
            $this->rate($i, self::T0 + 40 + $i);
        }
        $this->now = self::T0 + 200 + GuestActivityNotifier::QUIET_SECONDS;
        $this->notifier->run();

        $this->assertCount(1, $this->sent);
        $this->assertSame(40, $this->sent[0]['count']);
    }
}

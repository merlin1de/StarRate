<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Settings;

use OCA\StarRate\Settings\UserSettings;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserSettingsTest extends TestCase
{
    private UserSettings $settings;

    /** @var IConfig&MockObject */
    private IConfig $config;
    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    private const USER_ID = 'testuser';

    protected function setUp(): void
    {
        $this->config      = $this->createMock(IConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn(self::USER_ID);
        $this->userSession->method('getUser')->willReturn($user);

        $this->settings = new UserSettings($this->config, $this->userSession);
    }

    // ─── getSettings ─────────────────────────────────────────────────────────

    public function testGetSettingsReturnsDefaults(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);

        $this->assertSame('name', $result['default_sort']);
        $this->assertSame('asc', $result['default_sort_order']);
        $this->assertTrue($result['show_filename']);
        $this->assertTrue($result['show_rating_overlay']);
        $this->assertTrue($result['show_color_overlay']);
        $this->assertSame('auto', $result['grid_columns']);
        $this->assertTrue($result['enable_rating_ui']);
        $this->assertTrue($result['enable_color_ui']);
        $this->assertFalse($result['enable_pick_ui']);
        // RAWs sind opt-in (RAW+JPEG-Ordner zeigten sonst jedes Bild doppelt)
        $this->assertFalse($result['show_raw']);
        $this->assertTrue($result['write_raw_sidecar']);
        // TIFF bleibt standardmäßig sichtbar (Bestandsverhalten)
        $this->assertTrue($result['show_tiff']);
    }

    public function testGetSettingsReturnsStoredValues(): void
    {
        $stored = [
            'default_sort'       => 'mtime',
            'default_sort_order' => 'desc',
            'show_filename'      => '0',
            'show_rating_overlay' => '1',
            'show_color_overlay' => 'yes',
            'grid_columns'       => '4',
            'enable_pick_ui'     => '1',
        ];

        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) use ($stored) {
                return $stored[$key] ?? $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);

        $this->assertSame('mtime', $result['default_sort']);
        $this->assertSame('desc', $result['default_sort_order']);
        $this->assertFalse($result['show_filename']);
        $this->assertTrue($result['show_rating_overlay']);
        $this->assertTrue($result['show_color_overlay']);
        $this->assertSame('4', $result['grid_columns']);
        $this->assertTrue($result['enable_pick_ui']);
    }

    /** @dataProvider booleanParsingProvider */
    public function testGetSettingsBooleanParsing(string $stored, bool $expected): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) use ($stored) {
                return $key === 'show_filename' ? $stored : $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);
        $this->assertSame($expected, $result['show_filename']);
    }

    public static function booleanParsingProvider(): array
    {
        return [
            'true string'  => ['true', true],
            'yes string'   => ['yes', true],
            'one string'   => ['1', true],
            'false string' => ['false', false],
            'no string'    => ['no', false],
            'zero string'  => ['0', false],
            'empty string' => ['', false],
            'TRUE uppercase' => ['TRUE', false],  // getBool is case-sensitive
            'Yes mixed'      => ['Yes', false],
            'random string'  => ['banana', false],
        ];
    }

    public function testGetSettingsBooleanDefaultWhenNull(): void
    {
        // getBool returns default when config returns null
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $default; // null for getBool, string for get
            });

        $result = $this->settings->getSettings(self::USER_ID);
        // show_filename default is true
        $this->assertTrue($result['show_filename']);
        // enable_pick_ui default is false
        $this->assertFalse($result['enable_pick_ui']);
    }

    // ─── saveSettings ────────────────────────────────────────────────────────

    public function testSaveSettingsStoresValidSort(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'default_sort', 'mtime');

        $this->settings->saveSettings(self::USER_ID, ['default_sort' => 'mtime']);
    }

    public function testSaveSettingsStoresValidSortOrder(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'default_sort_order', 'desc');

        $this->settings->saveSettings(self::USER_ID, ['default_sort_order' => 'desc']);
    }

    public function testSaveSettingsStoresValidGridColumns(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'grid_columns', '6');

        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => '6']);
    }

    public function testSaveSettingsBooleanAsString(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, [
            'show_filename' => true,
            'show_rating_overlay' => false,
        ]);

        $this->assertSame('1', $saved['show_filename']);
        $this->assertSame('0', $saved['show_rating_overlay']);
    }

    public function testSaveSettingsStoresRatingTypesAndTiff(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, [
            'enable_rating_ui' => false,
            'enable_color_ui'  => true,
            'show_tiff'        => false,
        ]);

        $this->assertSame('0', $saved['enable_rating_ui']);
        $this->assertSame('1', $saved['enable_color_ui']);
        $this->assertSame('0', $saved['show_tiff']);
    }

    public function testSaveSettingsMultipleKeysAtOnce(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, [
            'default_sort' => 'size',
            'grid_columns' => '3',
        ]);

        $this->assertSame('size', $saved['default_sort']);
        $this->assertSame('3', $saved['grid_columns']);
    }

    // ─── saveSettings – Validation Errors ────────────────────────────────────

    public function testSaveSettingsThrowsForUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['unknown_key' => 'val']);
    }

    public function testSaveSettingsThrowsForInvalidSort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['default_sort' => 'random']);
    }

    public function testSaveSettingsThrowsForInvalidSortOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['default_sort_order' => 'up']);
    }

    public function testSaveSettingsThrowsForInvalidGridColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => '7']);
    }

    public function testSaveSettingsThrowsForGridColumnsOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => '1']);
    }

    public function testSaveSettingsEmptyArrayIsNoop(): void
    {
        $this->config->expects($this->never())->method('setUserValue');
        $this->settings->saveSettings(self::USER_ID, []);
    }

    // ─── Recursive-View Defaults ─────────────────────────────────────────────

    public function testGetSettingsReturnsRecursiveDefaults(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(fn($uid, $app, $key, $default) => $default);

        $result = $this->settings->getSettings(self::USER_ID);

        $this->assertFalse($result['recursion_enabled']);
        $this->assertFalse($result['recursive_default']);
        $this->assertSame(0, $result['recursive_default_depth']);
    }

    public function testSaveSettingsStoresRecursionEnabled(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, ['recursion_enabled' => true]);
        $this->assertSame('1', $saved['recursion_enabled']);
    }

    public function testGetSettingsParsesRecursiveDepthAsInt(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $key === 'recursive_default_depth' ? '3' : $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);
        $this->assertSame(3, $result['recursive_default_depth']);
    }

    public function testSaveSettingsStoresRecursiveDefault(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, [
            'recursive_default'        => true,
            'recursive_default_depth'  => 2,
        ]);

        $this->assertSame('1', $saved['recursive_default']);
        $this->assertSame('2', $saved['recursive_default_depth']);
    }

    public function testSaveSettingsThrowsForDepthOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['recursive_default_depth' => 5]);
    }

    public function testSaveSettingsThrowsForNegativeDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['recursive_default_depth' => -1]);
    }

    public function testSaveSettingsThrowsForNonIntDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['recursive_default_depth' => 'flat']);
    }

    /** @dataProvider validSortProvider */
    public function testSaveSettingsAcceptsAllValidSorts(string $sort): void
    {
        $this->config->expects($this->once())->method('setUserValue');
        $this->settings->saveSettings(self::USER_ID, ['default_sort' => $sort]);
    }

    public static function validSortProvider(): array
    {
        return [['name'], ['mtime'], ['size']];
    }

    /** @dataProvider validGridColumnsProvider */
    public function testSaveSettingsAcceptsAllValidGridColumns(string $cols): void
    {
        $this->config->expects($this->once())->method('setUserValue');
        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => $cols]);
    }

    public static function validGridColumnsProvider(): array
    {
        return [['auto'], ['2'], ['3'], ['4'], ['5'], ['6'], ['8']];
    }

    // ─── Slideshow-Intervall ─────────────────────────────────────────────────

    public function testGetSettingsReturnsSlideshowDefault(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(fn($uid, $app, $key, $default) => $default);

        $result = $this->settings->getSettings(self::USER_ID);
        $this->assertSame(4, $result['slideshow_interval']);
    }

    public function testGetSettingsParsesSlideshowAsInt(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $key === 'slideshow_interval' ? '7' : $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);
        $this->assertSame(7, $result['slideshow_interval']);
    }

    /** @dataProvider validSlideshowIntervalProvider */
    public function testSaveSettingsAcceptsAllValidSlideshowIntervals(int $sec): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });
        $this->settings->saveSettings(self::USER_ID, ['slideshow_interval' => $sec]);
        $this->assertSame((string) $sec, $saved['slideshow_interval']);
    }

    public static function validSlideshowIntervalProvider(): array
    {
        return [[1], [2], [3], [4], [5], [7], [10], [15], [30]];
    }

    public function testSaveSettingsThrowsForInvalidSlideshowInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['slideshow_interval' => 6]);
    }

    public function testSaveSettingsThrowsForOutOfRangeSlideshowInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['slideshow_interval' => 99]);
    }

    public function testSaveSettingsThrowsForNonNumericSlideshowInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['slideshow_interval' => 'fast']);
    }

    // ─── getSection / getPriority ────────────────────────────────────────────

    public function testGetSectionReturnsStarrate(): void
    {
        $this->assertSame('starrate', $this->settings->getSection());
    }

    public function testGetPriorityReturns50(): void
    {
        $this->assertSame(50, $this->settings->getPriority());
    }

    // ─── getForm ─────────────────────────────────────────────────────────────

    public function testGetFormReturnsTemplateResponse(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(fn($uid, $app, $key, $default) => $default);

        $response = $this->settings->getForm();

        $this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $response);
        $this->assertSame('settings/personal', $response->getTemplateName());
    }

    public function testGetFormPassesSettingsAsParams(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(fn($uid, $app, $key, $default) => $default);

        $response = $this->settings->getForm();
        $params = $response->getParams();

        $this->assertArrayHasKey('settings', $params);
        $this->assertArrayHasKey('default_sort', $params['settings']);
        $this->assertArrayHasKey('grid_columns', $params['settings']);
        $this->assertArrayHasKey('enable_pick_ui', $params['settings']);
    }
}

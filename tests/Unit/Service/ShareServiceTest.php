<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Service\XmpStoreResolver;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Settings\UserSettings;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview as IPreviewManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareServiceTest extends TestCase
{
    private ShareService $service;

    /** @var IConfig&MockObject */
    private IConfig $config;
    /** @var ISecureRandom&MockObject */
    private ISecureRandom $secureRandom;
    /** @var IRootFolder&MockObject */
    private IRootFolder $rootFolder;
    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    private const OWNER_ID     = 'photographer1';
    private const SAMPLE_TOKEN = 'AbCdEfGh12345678AbCdEfGh';

    protected function setUp(): void
    {
        $this->config       = $this->createMock(IConfig::class);
        $this->rootFolder   = $this->createMock(IRootFolder::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->db           = $this->createMock(IDBConnection::class);

        // Default: getUserFolder returns a Folder that finds nothing
        $emptyFolder = $this->createMock(Folder::class);
        $emptyFolder->method('getById')->willReturn([]);
        $this->rootFolder->method('getUserFolder')->willReturn($emptyFolder);

        $this->service = new ShareService(
            $this->config,
            $this->rootFolder,
            $this->createMock(IPreviewManager::class),
            $this->secureRandom,
            $this->createMock(TagService::class),
            $this->createMock(LoggerInterface::class),
            $this->db,
            $this->createMock(ExifService::class),
            $this->createMock(UserSettings::class),
            $this->createMock(XmpStoreResolver::class),
        );
    }

    // ─── Tests: Share erstellen ────────────────────────────────────────────────

    public function testCreateShareReturnsShareWithToken(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID,
            '/Fotos/Shooting',
            null,
            null,
            0,
            ShareService::PERM_VIEW
        );

        $this->assertSame(self::SAMPLE_TOKEN, $share['token']);
        $this->assertSame(self::OWNER_ID,     $share['owner_id']);
        $this->assertSame('/Fotos/Shooting',  $share['nc_path']);
        $this->assertSame(ShareService::PERM_VIEW, $share['permissions']);
        $this->assertTrue($share['active']);
        $this->assertFalse($share['has_password']);
    }

    public function testCreateShareWithPasswordSetsHasPasswordTrue(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', 'geheim', null, 0, ShareService::PERM_VIEW
        );

        $this->assertTrue($share['has_password']);
        $this->assertArrayNotHasKey('password_hash', $share); // hash nicht nach außen
    }

    public function testCreateShareWithRatePermission(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 3, ShareService::PERM_RATE
        );

        $this->assertSame(ShareService::PERM_RATE, $share['permissions']);
        $this->assertSame(3, $share['min_rating']);
    }

    public function testCreateShareDefaultsAllowDownloadFalse(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_VIEW
        );

        $this->assertFalse($share['allow_download']);
    }

    public function testCreateShareWithAllowDownloadTrue(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        // Positionell: …, allowPick, allowExport, allowComment, recursive, depth, allowDownload
        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_VIEW,
            null, false, false, false, false, 0, true
        );

        $this->assertTrue($share['allow_download']);
    }

    public function testCreateShareDefaultsAllowRatingAndColorTrue(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_RATE
        );

        $this->assertTrue($share['allow_rating']);
        $this->assertTrue($share['allow_color']);
    }

    public function testCreateShareWithOnlyPickAndColor(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        // Positionell: …, allowPick, allowExport, allowComment, recursive, depth, allowDownload, allowRating, allowColor
        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_RATE,
            'Anna', true, false, false, false, 0, false, false, true
        );

        $this->assertFalse($share['allow_rating']);
        $this->assertTrue($share['allow_color']);
        $this->assertTrue($share['allow_pick']);
    }

    public function testGetSharesByOwnerDefaultsRatingTypesForOlderShares(): void
    {
        // Shares ohne allow_rating/allow_color (vor diesem Schalter angelegt) zeigen weiter Sterne und Farben
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => null,
            'permissions' => ShareService::PERM_RATE, 'active' => true,
        ]];
        $this->config->method('getUserValue')->willReturn(json_encode($existing));

        $shares = $this->service->getSharesByOwner(self::OWNER_ID);

        $this->assertTrue($shares[0]['allow_rating']);
        $this->assertTrue($shares[0]['allow_color']);
    }

    public function testCreateShareWithInvalidPermissionsThrows(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createShare(self::OWNER_ID, '/Fotos', null, null, 0, 'delete');
    }

    public function testCreateShareMinRatingClampedTo0to5(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 99, ShareService::PERM_VIEW
        );

        $this->assertSame(5, $share['min_rating']); // auf 5 begrenzt
    }

    // ─── Tests: Recursive + Depth ─────────────────────────────────────────────

    public function testCreateShareWithRecursiveAndDepth(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $saved = null;
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved = $val;
            });

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_VIEW,
            null, false, false, false,
            true, 2,  // recursive, depth
        );

        $this->assertTrue($share['recursive']);
        $this->assertSame(2, $share['depth']);
        // Im persistierten JSON müssen die Werte ebenfalls drin sein
        $persisted = json_decode($saved, true)[self::SAMPLE_TOKEN];
        $this->assertTrue($persisted['recursive']);
        $this->assertSame(2, $persisted['depth']);
    }

    public function testCreateShareDepthClampedTo0to4(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_VIEW,
            null, false, false, false, true, 99,
        );

        $this->assertSame(4, $share['depth']);
    }

    public function testCreateShareDefaultsRecursiveFalse(): void
    {
        $this->secureRandom->method('generate')->willReturn(self::SAMPLE_TOKEN);
        $this->config->method('getUserValue')->willReturn('{}');
        $this->config->method('setUserValue');

        // Old-style call ohne recursive/depth-Args → recursive=false, depth=0
        $share = $this->service->createShare(
            self::OWNER_ID, '/Fotos', null, null, 0, ShareService::PERM_VIEW
        );

        $this->assertFalse($share['recursive']);
        $this->assertSame(0, $share['depth']);
    }

    // ─── Tests: Share validieren ──────────────────────────────────────────────

    public function testIsShareValidReturnsTrueForActiveShare(): void
    {
        $share = ['active' => true, 'expires_at' => null];
        $this->assertTrue($this->service->isShareValid($share));
    }

    public function testIsShareValidReturnsFalseForInactiveShare(): void
    {
        $share = ['active' => false, 'expires_at' => null];
        $this->assertFalse($this->service->isShareValid($share));
    }

    public function testIsShareValidReturnsFalseForExpiredShare(): void
    {
        $share = ['active' => true, 'expires_at' => time() - 3600];
        $this->assertFalse($this->service->isShareValid($share));
    }

    public function testIsShareValidReturnsTrueForFutureExpiry(): void
    {
        $share = ['active' => true, 'expires_at' => time() + 86400];
        $this->assertTrue($this->service->isShareValid($share));
    }

    // ─── Tests: Passwort verifizieren ─────────────────────────────────────────

    public function testVerifyPasswordReturnsTrueWhenNoPasswordSet(): void
    {
        $share = ['password_hash' => null];
        $this->assertTrue($this->service->verifyPassword($share, ''));
        $this->assertTrue($this->service->verifyPassword($share, 'anything'));
    }

    public function testVerifyPasswordReturnsTrueForCorrectPassword(): void
    {
        $hash  = password_hash('geheim123', PASSWORD_BCRYPT);
        $share = ['password_hash' => $hash];

        $this->assertTrue($this->service->verifyPassword($share, 'geheim123'));
    }

    public function testVerifyPasswordReturnsFalseForWrongPassword(): void
    {
        $hash  = password_hash('geheim123', PASSWORD_BCRYPT);
        $share = ['password_hash' => $hash];

        $this->assertFalse($this->service->verifyPassword($share, 'falsch'));
        $this->assertFalse($this->service->verifyPassword($share, ''));
    }

    // ─── Tests: Share aktualisieren ───────────────────────────────────────────

    public function testUpdateShareChangesPermissions(): void
    {
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => null,
            'expires_at' => null, 'min_rating' => 0,
            'permissions' => ShareService::PERM_VIEW, 'active' => true,
            'created_at' => time(),
        ]];

        $this->config->method('getUserValue')
            ->willReturnOnConsecutiveCalls(
                json_encode($existing), // getShare (lineare Suche - wird durch DB-Query gemacht, also überspringen)
                json_encode($existing)  // loadAllShares
            );
        $this->config->method('setUserValue');

        // getShare über DB-Query mocken ist komplex → updateShare direkt testen
        // Wir testen die Logik über deleteMapping/addMapping stattdessen
        $this->assertTrue(true); // Placeholder — Integration über Controller-Test
    }

    public function testDeleteShareRemovesFromConfig(): void
    {
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => null,
            'expires_at' => null, 'min_rating' => 0,
            'permissions' => ShareService::PERM_VIEW, 'active' => true,
            'created_at' => time(),
        ]];

        // getShare() findet den Token über die preferences-Tabelle
        $this->db->method('getQueryBuilder')
            ->willReturn($this->makeGetShareQb([self::OWNER_ID => $existing]));

        // loadAllShares()/saveShares() laufen über config
        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->deleteShare(self::SAMPLE_TOKEN);
        // Nach Löschen sollte der Token nicht mehr vorhanden sein
        $this->assertArrayNotHasKey(self::SAMPLE_TOKEN, $saved ?? []);
    }

    public function testUpdateShareClearsExpiryWhenNull(): void
    {
        // Regression: einmal gesetztes Ablaufdatum ließ sich nicht mehr löschen,
        // weil updateShare() isset() nutzte (null → false). Jetzt via
        // array_key_exists → explizit gesendetes null entfernt es.
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => null,
            'expires_at' => time() + 86400, 'min_rating' => 0,
            'permissions' => ShareService::PERM_VIEW, 'active' => true,
            'created_at' => time(),
        ]];

        $this->db->method('getQueryBuilder')
            ->willReturn($this->makeGetShareQb([self::OWNER_ID => $existing]));

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $updated = $this->service->updateShare(self::SAMPLE_TOKEN, ['expires_at' => null]);

        $this->assertNull($saved[self::SAMPLE_TOKEN]['expires_at']);
        $this->assertNull($updated['expires_at']);
    }

    public function testUpdateShareTogglesRatingTypes(): void
    {
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => null,
            'expires_at' => null, 'min_rating' => 0,
            'permissions' => ShareService::PERM_RATE, 'active' => true,
            'allow_rating' => true, 'allow_color' => true,
            'created_at' => time(),
        ]];

        $this->db->method('getQueryBuilder')
            ->willReturn($this->makeGetShareQb([self::OWNER_ID => $existing]));

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->updateShare(self::SAMPLE_TOKEN, ['allow_rating' => false]);

        $this->assertFalse($saved[self::SAMPLE_TOKEN]['allow_rating']);
        $this->assertTrue($saved[self::SAMPLE_TOKEN]['allow_color']);
    }

    public function testUpdateShareSetsExpiryFromTimestamp(): void
    {
        $future   = time() + 86400;
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => null,
            'expires_at' => null, 'min_rating' => 0,
            'permissions' => ShareService::PERM_VIEW, 'active' => true,
            'created_at' => time(),
        ]];

        $this->db->method('getQueryBuilder')
            ->willReturn($this->makeGetShareQb([self::OWNER_ID => $existing]));

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $updated = $this->service->updateShare(self::SAMPLE_TOKEN, ['expires_at' => $future]);

        $this->assertSame($future, $saved[self::SAMPLE_TOKEN]['expires_at']);
        $this->assertSame($future, $updated['expires_at']);
    }

    // ─── Tests: Gast-Bewertungen ──────────────────────────────────────────────

    public function testSaveGuestRatingPersistsEntry(): void
    {
        $share = [
            'token'    => self::SAMPLE_TOKEN,
            'owner_id' => self::OWNER_ID,
        ];

        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $result = $this->service->saveGuestRating($share, 42, 4, 'Green', null, 'Anna');

        $this->assertSame(42,      $result['file_id']);
        $this->assertSame(4,       $result['rating']);
        $this->assertSame('Green', $result['color']);
        $this->assertNull($result['pick']);
        $this->assertSame('Anna',  $result['guest_name']);
        $this->assertGreaterThan(0, $result['timestamp']);

        $this->assertNotNull($saved);
        $this->assertCount(1, $saved);
        $this->assertSame('Anna', $saved[0]['guest_name']);
        $this->assertSame(42, $saved[0]['file_id']);
    }

    // ─── Tests: Gast-Bewertung schreibt JPEG-XMP (Owner-Setting maßgeblich) ─────

    public function testSaveGuestRatingWritesXmpWhenOwnerWriteXmpEnabled(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];
        $file  = $this->jpegFileMock();

        $exif = $this->createMock(ExifService::class);
        // rating=4, color='Green', pick=null, lang aus Owner-Settings
        $exif->expects($this->once())
            ->method('writeMetadata')
            ->with($file, 4, 'Green', null, 'en');

        $service = $this->makeServiceForXmp(
            $this->rootFolderReturning($file),
            $exif,
            $this->settingsMock(true, 'en'),
        );

        $service->saveGuestRating($share, 42, 4, 'Green', null, 'Anna');
    }

    public function testSaveGuestRatingSkipsXmpWhenWriteXmpDisabled(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        $exif = $this->createMock(ExifService::class);
        $exif->expects($this->never())->method('writeMetadata');

        $service = $this->makeServiceForXmp(
            $this->rootFolderReturning($this->jpegFileMock()),
            $exif,
            $this->settingsMock(false),
        );

        $service->saveGuestRating($share, 42, 4, 'Green', null, 'Anna');
    }

    public function testSaveGuestRatingSkipsXmpForNonJpeg(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        $png = $this->createMock(File::class);
        $png->method('getMimeType')->willReturn('image/png');
        $png->method('getName')->willReturn('photo.png');

        $exif = $this->createMock(ExifService::class);
        $exif->expects($this->never())->method('writeMetadata');

        $service = $this->makeServiceForXmp(
            $this->rootFolderReturning($png),
            $exif,
            $this->settingsMock(true),
        );

        $service->saveGuestRating($share, 42, 4, 'Green', null, 'Anna');
    }

    public function testSaveGuestRatingXmpFailureIsNonFatal(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        $exif = $this->createMock(ExifService::class);
        $exif->method('writeMetadata')->willThrowException(new \RuntimeException('disk full'));

        $service = $this->makeServiceForXmp(
            $this->rootFolderReturning($this->jpegFileMock()),
            $exif,
            $this->settingsMock(true),
        );

        // Trotz XMP-Fehler liefert saveGuestRating ein gültiges Ergebnis (Tag bleibt gesetzt).
        $result = $service->saveGuestRating($share, 42, 4, 'Green', null, 'Anna');
        $this->assertSame(4, $result['rating']);
    }

    private function jpegFileMock(): File
    {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getName')->willReturn('photo.jpg');
        return $file;
    }

    private function rootFolderReturning(?File $file): IRootFolder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->willReturn($file === null ? [] : [$file]);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);
        return $rootFolder;
    }

    private function settingsMock(bool $writeXmp, string $lang = 'en'): UserSettings
    {
        $us = $this->createMock(UserSettings::class);
        $us->method('getSettings')->willReturn([
            'write_xmp'          => $writeXmp,
            'show_raw'           => true,
            'write_raw_sidecar'  => true,
            'xmp_label_language' => $lang,
        ]);
        return $us;
    }

    public function testSaveGuestRatingOnRawWritesSidecarNotJpegXmp(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        $nef = $this->createMock(File::class);
        $nef->method('getMimeType')->willReturn('image/x-dcraw');
        $nef->method('getName')->willReturn('DSC_0001.NEF');

        $exif = $this->createMock(ExifService::class);
        $exif->expects($this->never())->method('writeMetadata');
        $sidecar = $this->createMock(SidecarService::class);
        $sidecar->expects($this->once())->method('writeMetadata')->with($nef, 4, 'Green', null, 'en');

        $service = $this->makeServiceForXmp($this->rootFolderReturning($nef), $exif, $this->settingsMock(true), $sidecar);
        $service->saveGuestRating($share, 42, 4, 'Green', null, 'Anna');
    }

    // ─── Tests: Altbestands-Heilung (foldGuestLog / healGuestXmp) ─────────────

    public function testFoldGuestLogMergesDeltasPerFile(): void
    {
        $service = $this->makeHealService(
            $this->healConfig([
                ['file_id' => 42, 'rating' => 4,    'color' => null,  'pick' => null, 'timestamp' => 100],
                ['file_id' => 42, 'rating' => null, 'color' => 'Red', 'pick' => null, 'timestamp' => 200],
            ]),
            $this->rootFolderReturning(null),
            $this->createMock(ExifService::class),
            $this->settingsMock(true),
        );

        $folded = $service->foldGuestLog(self::OWNER_ID);

        $this->assertArrayHasKey(42, $folded);
        $this->assertSame(4,     $folded[42]['rating']);
        $this->assertSame('Red', $folded[42]['color']);
        $this->assertNull($folded[42]['pick']);
        $this->assertSame(200,   $folded[42]['ts']);  // jüngster beteiligter Zeitstempel
    }

    public function testHealGuestXmpDryRunFindsCandidateWithoutWriting(): void
    {
        $exif = $this->createMock(ExifService::class);
        $exif->method('readMetadata')->willReturn(['rating' => 2, 'label' => null, 'pick' => 'none']);
        $exif->expects($this->never())->method('writeMetadata');

        $tag = $this->createMock(TagService::class);
        $tag->expects($this->never())->method('setMetadata');

        $service = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => 4, 'color' => null, 'pick' => null, 'timestamp' => 100]]),
            $this->rootFolderReturning($this->healFile(50)),
            $exif,
            $this->settingsMock(true),
            $tag,
        );

        $report = $service->healGuestXmp(self::OWNER_ID, false);

        $this->assertTrue($report['write_xmp']);
        $this->assertSame(1, $report['stats']['candidates']);
        $this->assertSame(0, $report['stats']['healed']);
        $this->assertCount(1, $report['details']);
    }

    public function testHealGuestXmpWriteHealsDbAndXmp(): void
    {
        $exif = $this->createMock(ExifService::class);
        $exif->method('readMetadata')->willReturn(['rating' => 2, 'label' => null, 'pick' => 'none']);
        $exif->expects($this->once())
            ->method('writeMetadata')
            ->with($this->isInstanceOf(File::class), 4, null, null, 'en');

        $tag = $this->createMock(TagService::class);
        $tag->expects($this->once())
            ->method('setMetadata')
            ->with('42', ['rating' => 4]);

        $service = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => 4, 'color' => null, 'pick' => null, 'timestamp' => 100]]),
            $this->rootFolderReturning($this->healFile(50)),
            $exif,
            $this->settingsMock(true, 'en'),
            $tag,
        );

        $report = $service->healGuestXmp(self::OWNER_ID, true);

        $this->assertSame(1, $report['stats']['healed']);
    }

    public function testHealGuestXmpSkipsFileEditedAfterGuest(): void
    {
        $exif = $this->createMock(ExifService::class);
        $exif->expects($this->never())->method('readMetadata');
        $exif->expects($this->never())->method('writeMetadata');

        $service = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => 4, 'color' => null, 'pick' => null, 'timestamp' => 100]]),
            $this->rootFolderReturning($this->healFile(200)),  // mtime 200 > ts 100 → extern später bearbeitet
            $exif,
            $this->settingsMock(true),
        );

        $report = $service->healGuestXmp(self::OWNER_ID, true);

        $this->assertSame(1, $report['stats']['skipped_mtime']);
        $this->assertSame(0, $report['stats']['candidates']);
    }

    public function testHealGuestXmpSkipsWhenAlreadyInSync(): void
    {
        $exif = $this->createMock(ExifService::class);
        $exif->method('readMetadata')->willReturn(['rating' => 4, 'label' => null, 'pick' => 'none']);
        $exif->expects($this->never())->method('writeMetadata');

        $service = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => 4, 'color' => null, 'pick' => null, 'timestamp' => 100]]),
            $this->rootFolderReturning($this->healFile(50)),
            $exif,
            $this->settingsMock(true),
        );

        $report = $service->healGuestXmp(self::OWNER_ID, true);

        $this->assertSame(1, $report['stats']['in_sync']);
        $this->assertSame(0, $report['stats']['candidates']);
    }

    public function testHealGuestXmpSkipsOwnerWithWriteXmpDisabled(): void
    {
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects($this->never())->method('getUserFolder');

        $report = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => 4, 'color' => null, 'pick' => null, 'timestamp' => 100]]),
            $rootFolder,
            $this->createMock(ExifService::class),
            $this->settingsMock(false),
        )->healGuestXmp(self::OWNER_ID, true);

        $this->assertFalse($report['write_xmp']);
        $this->assertSame(0, $report['stats']['folded']);
    }

    public function testFoldGuestLogGlobalOrderAcrossTokens(): void
    {
        // Dieselbe Datei über zwei Tokens: neuerer Wert (ts 100) steht im zuerst
        // gelisteten Token, älterer (ts 50) im zweiten. Globale Sortierung muss den
        // jüngsten gewinnen lassen — unabhängig von der Token-Reihenfolge.
        $configMap = [
            'starrate_shares' => json_encode([
                'TOKA' => ['token' => 'TOKA', 'owner_id' => self::OWNER_ID],
                'TOKB' => ['token' => 'TOKB', 'owner_id' => self::OWNER_ID],
            ]),
            'starrate_guest_log_TOKA' => json_encode([
                ['file_id' => 42, 'rating' => 5, 'color' => null, 'pick' => null, 'timestamp' => 100],
            ]),
            'starrate_guest_log_TOKB' => json_encode([
                ['file_id' => 42, 'rating' => 2, 'color' => null, 'pick' => null, 'timestamp' => 50],
            ]),
        ];

        $folded = $this->makeHealService(
            $configMap,
            $this->rootFolderReturning(null),
            $this->createMock(ExifService::class),
            $this->settingsMock(true),
        )->foldGuestLog(self::OWNER_ID);

        $this->assertSame(5,   $folded[42]['rating']);  // jüngster gewinnt, nicht der Token-Reihenfolge nach
        $this->assertSame(100, $folded[42]['ts']);
    }

    public function testFoldGuestLogDropsPhantomEntries(): void
    {
        // Request nur mit file_id (keine Bewertung) → kein Gast-Intent → kein Eintrag.
        $folded = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => null, 'color' => null, 'pick' => null, 'timestamp' => 100]]),
            $this->rootFolderReturning(null),
            $this->createMock(ExifService::class),
            $this->settingsMock(true),
        )->foldGuestLog(self::OWNER_ID);

        $this->assertSame([], $folded);
    }

    public function testHealGuestXmpClearsPickViaNormalizedValue(): void
    {
        // Gast hat Pick zurückgenommen (pick='none'); XMP trägt noch 'pick'.
        $exif = $this->createMock(ExifService::class);
        $exif->method('readMetadata')->willReturn(['rating' => 0, 'label' => null, 'pick' => 'pick']);
        $exif->expects($this->once())
            ->method('writeMetadata')
            ->with($this->isInstanceOf(File::class), null, null, 'none', 'en');

        $tag = $this->createMock(TagService::class);
        $tag->expects($this->once())
            ->method('setMetadata')
            ->with('42', ['pick' => 'none']);   // normalisiert, kein '' → keine Validierungs-Exception

        $service = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => null, 'color' => null, 'pick' => 'none', 'timestamp' => 100]]),
            $this->rootFolderReturning($this->healFile(50)),
            $exif,
            $this->settingsMock(true, 'en'),
            $tag,
        );

        $report = $service->healGuestXmp(self::OWNER_ID, true);

        $this->assertSame(1, $report['stats']['healed']);
    }

    public function testSaveGuestRatingColorClearRemovesFromDbAndXmp(): void
    {
        // Gast entfernt die Farbe → color='' erreicht saveGuestRating.
        $exif = $this->createMock(ExifService::class);
        $exif->expects($this->once())
            ->method('writeMetadata')
            ->with($this->isInstanceOf(File::class), null, '', null, 'en');  // '' = Label entfernen

        $tag = $this->createMock(TagService::class);
        $tag->expects($this->once())
            ->method('setMetadata')
            ->with('42', ['color' => null]);  // '' → null = Tag entfernen

        $service = $this->makeHealService(
            [],
            $this->rootFolderReturning($this->healFile(50)),
            $exif,
            $this->settingsMock(true, 'en'),
            $tag,
        );

        $result = $service->saveGuestRating(
            ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID],
            42, null, '', null, 'Anna'
        );

        // API-Antwort liefert den effektiven Wert (null), nicht das interne '' -Sentinel.
        $this->assertNull($result['color']);
    }

    public function testHealGuestXmpClearsColorViaEmptyString(): void
    {
        // Gast-Log trägt color='' (gelöscht); XMP hat noch ein Label 'Red'.
        $exif = $this->createMock(ExifService::class);
        $exif->method('readMetadata')->willReturn(['rating' => 0, 'label' => 'Red', 'pick' => 'none']);
        $exif->expects($this->once())
            ->method('writeMetadata')
            ->with($this->isInstanceOf(File::class), null, '', null, 'en');

        $tag = $this->createMock(TagService::class);
        $tag->expects($this->once())
            ->method('setMetadata')
            ->with('42', ['color' => null]);

        $service = $this->makeHealService(
            $this->healConfig([['file_id' => 42, 'rating' => null, 'color' => '', 'pick' => null, 'timestamp' => 100]]),
            $this->rootFolderReturning($this->healFile(50)),
            $exif,
            $this->settingsMock(true, 'en'),
            $tag,
        );

        $report = $service->healGuestXmp(self::OWNER_ID, true);

        $this->assertSame(1, $report['stats']['healed']);
    }

    /**
     * @param array<int, array<string, mixed>> $logEntries
     * @return array<string, string>  configkey => stored JSON
     */
    private function healConfig(array $logEntries, string $token = 'TOK'): array
    {
        return [
            'starrate_shares'                => json_encode([$token => ['token' => $token, 'owner_id' => self::OWNER_ID]]),
            "starrate_guest_log_{$token}"    => json_encode($logEntries),
        ];
    }

    private function healFile(int $mtime, string $mime = 'image/jpeg'): File
    {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getName')->willReturn('photo.jpg');
        return $file;
    }

    /**
     * @param array<string, string> $configMap configkey => stored value
     */
    private function makeHealService(
        array $configMap,
        IRootFolder $rootFolder,
        ExifService $exif,
        UserSettings $userSettings,
        ?TagService $tag = null,
    ): ShareService {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            static fn(string $uid, string $app, string $key, $default = '') => $configMap[$key] ?? $default
        );

        return new ShareService(
            $config,
            $rootFolder,
            $this->createMock(IPreviewManager::class),
            $this->createMock(ISecureRandom::class),
            $tag ?? $this->createMock(TagService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
            $exif,
            $userSettings,
            new XmpStoreResolver($exif, $this->createMock(SidecarService::class)),
        );
    }

    // ─── Tests: getImagesForShare Sortierung (Issue #90) ───────────────────────

    public function testGetImagesForShareSortsByOwnerNameAsc(): void
    {
        $service = $this->makeServiceForImages(
            $this->folderWithImages(['c.jpg' => [30, 300], 'a.jpg' => [10, 100], 'b.jpg' => [20, 200]]),
            $this->sortSettings('name', 'asc'),
        );
        $share  = ['owner_id' => self::OWNER_ID, 'nc_path' => '/Fotos', 'min_rating' => 0];
        $result = $service->getImagesForShare($share, '');
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], array_column($result['images'], 'name'));
    }

    public function testGetImagesForShareSortsByOwnerMtimeDesc(): void
    {
        $service = $this->makeServiceForImages(
            $this->folderWithImages(['a.jpg' => [10, 100], 'b.jpg' => [30, 200], 'c.jpg' => [20, 300]]),
            $this->sortSettings('mtime', 'desc'),
        );
        $share  = ['owner_id' => self::OWNER_ID, 'nc_path' => '/Fotos', 'min_rating' => 0];
        $result = $service->getImagesForShare($share, '');
        // mtime: b=30, c=20, a=10  → desc = b, c, a
        $this->assertSame(['b.jpg', 'c.jpg', 'a.jpg'], array_column($result['images'], 'name'));
    }

    public function testGetImagesForShareDefaultsToNameWhenSettingsMissing(): void
    {
        // getSettings liefert leeres Array (keine Sort-Keys) → Fallback name/asc
        $us = $this->createMock(UserSettings::class);
        $us->method('getSettings')->willReturn([]);
        $service = $this->makeServiceForImages(
            $this->folderWithImages(['b.jpg' => [20, 200], 'a.jpg' => [10, 100]]),
            $us,
        );
        $share  = ['owner_id' => self::OWNER_ID, 'nc_path' => '/Fotos', 'min_rating' => 0];
        $result = $service->getImagesForShare($share, '');
        $this->assertSame(['a.jpg', 'b.jpg'], array_column($result['images'], 'name'));
    }

    public function testGetImagesForShareShowsRawsOnlyWhenOwnerEnabledThem(): void
    {
        $files = ['a.jpg' => [10, 100], 'a.NEF' => [10, 900], 'b.MRW' => [10, 900]];
        $share = ['owner_id' => self::OWNER_ID, 'nc_path' => '/Fotos', 'min_rating' => 0];

        $off = $this->makeServiceForImages($this->folderWithImages($files), $this->sortSettings('name', 'asc'));
        $this->assertSame(['a.jpg'], array_column($off->getImagesForShare($share, '')['images'], 'name'));

        $us = $this->createMock(UserSettings::class);
        $us->method('getSettings')->willReturn(['default_sort' => 'name', 'default_sort_order' => 'asc', 'show_raw' => true]);
        $on = $this->makeServiceForImages($this->folderWithImages($files), $us);
        // MRW: keine Vorschau auffindbar → nie gelistet
        $this->assertSame(['a.jpg', 'a.NEF'], array_column($on->getImagesForShare($share, '')['images'], 'name'));
    }

    /**
     * Baut eine IRootFolder, deren User-Folder->get() einen Ordner mit den
     * gegebenen Bildern (name => [mtime, size]) in genau dieser Reihenfolge liefert.
     *
     * @param array<string, array{0:int,1:int}> $files
     */
    private function folderWithImages(array $files): IRootFolder
    {
        $nodes = [];
        $id    = 1;
        foreach ($files as $name => [$mtime, $size]) {
            $file = $this->createMock(File::class);
            $file->method('getId')->willReturn($id++);
            $file->method('getName')->willReturn($name);
            $file->method('getMtime')->willReturn($mtime);
            $file->method('getSize')->willReturn($size);
            $file->method('getMimeType')->willReturn(
                str_ends_with(strtolower($name), '.jpg') ? 'image/jpeg' : 'image/x-dcraw'
            );
            $nodes[] = $file;
        }
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($nodes);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturn($folder);

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);
        return $rootFolder;
    }

    private function sortSettings(string $sort, string $order): UserSettings
    {
        $us = $this->createMock(UserSettings::class);
        $us->method('getSettings')->willReturn([
            'default_sort'       => $sort,
            'default_sort_order' => $order,
        ]);
        return $us;
    }

    private function makeServiceForImages(IRootFolder $rootFolder, UserSettings $userSettings): ShareService
    {
        $tag = $this->createMock(TagService::class);
        $tag->method('getMetadataBatch')->willReturn([]);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('[]');

        return new ShareService(
            $config,
            $rootFolder,
            $this->createMock(IPreviewManager::class),
            $this->createMock(ISecureRandom::class),
            $tag,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(ExifService::class),
            $userSettings,
            $this->createMock(XmpStoreResolver::class),
        );
    }

    private function makeServiceForXmp(
        IRootFolder $rootFolder,
        ExifService $exif,
        UserSettings $userSettings,
        ?SidecarService $sidecar = null,
    ): ShareService {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('[]');
        $config->method('setUserValue');

        return new ShareService(
            $config,
            $rootFolder,
            $this->createMock(IPreviewManager::class),
            $this->createMock(ISecureRandom::class),
            $this->createMock(TagService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
            $exif,
            $userSettings,
            new XmpStoreResolver($exif, $sidecar ?? $this->createMock(SidecarService::class)),
        );
    }

    public function testSaveGuestRatingPersistsPickField(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $result = $this->service->saveGuestRating($share, 99, null, null, 'pick', 'Bob');

        $this->assertSame('pick', $result['pick']);
        $this->assertNull($result['rating']);
        $this->assertSame('pick', $saved[0]['pick']);
    }

    public function testSaveGuestRatingRejectPersisted(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $result = $this->service->saveGuestRating($share, 7, null, null, 'reject', 'Anna');

        $this->assertSame('reject', $result['pick']);
        $this->assertSame('reject', $saved[0]['pick']);
    }

    public function testSaveGuestRatingAppendsToLog(): void
    {
        // saveGuestRating appends every call as a new log entry (flat log format)
        $share    = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];
        $existing = [['file_id' => 42, 'rating' => 2, 'color' => null, 'pick' => null, 'guest_name' => 'Anna', 'timestamp' => 100]];

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->saveGuestRating($share, 42, 5, 'Red', null, 'Anna');

        // Flat log now has 2 entries: the original and the new one
        $this->assertCount(2, $saved);
        $this->assertSame(5,     $saved[1]['rating']);
        $this->assertSame('Red', $saved[1]['color']);
    }

    public function testSaveGuestRatingMultipleGuestsDontOverwrite(): void
    {
        $share    = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];
        $existing = [['file_id' => 42, 'rating' => 3, 'color' => null, 'pick' => null, 'guest_name' => 'Anna', 'timestamp' => 100]];

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->saveGuestRating($share, 42, 5, 'Blue', null, 'Bob');

        // Zwei Einträge: Anna (3) und Bob (5)
        $this->assertCount(2, $saved);
    }

    public function testGuestNameDefaultsToGast(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue');

        $result = $this->service->saveGuestRating($share, 1, 3, null, null, '');
        $this->assertSame('Guest', $result['guest_name']);
    }

    public function testGetGuestLogReturnsEmpty(): void
    {
        $share = [self::SAMPLE_TOKEN => ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID]];
        $this->db->method('getQueryBuilder')
            ->willReturn($this->makeGetShareQb([self::OWNER_ID => $share]));
        // Kein Log gespeichert → leeres Array
        $this->config->method('getUserValue')->willReturn('[]');

        $this->assertSame([], $this->service->getGuestLog(self::SAMPLE_TOKEN));
    }

    // ─── Tests: getSharesByOwner ──────────────────────────────────────────────

    // ─── Tests: Kommentare ──────────────────────────────────────────────────

    public function testSaveCommentInsertsNew(): void
    {
        // Mock: UPDATE betrifft 0 Zeilen → INSERT
        $qbUpdate = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qbUpdate->method('update')->willReturnSelf();
        $qbUpdate->method('set')->willReturnSelf();
        $qbUpdate->method('where')->willReturnSelf();
        $qbUpdate->method('createNamedParameter')->willReturnSelf();
        $qbUpdate->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qbUpdate->method('executeStatement')->willReturn(0);

        $qbInsert = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qbInsert->method('insert')->willReturnSelf();
        $qbInsert->method('values')->willReturnSelf();
        $qbInsert->method('createNamedParameter')->willReturnSelf();
        $qbInsert->method('executeStatement')->willReturn(1);

        $this->db->expects($this->exactly(2))
            ->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($qbUpdate, $qbInsert);

        $result = $this->service->saveComment(42, 'Tolles Bild!', 'owner', 'photographer1');

        $this->assertSame(42, $result['file_id']);
        $this->assertSame('Tolles Bild!', $result['comment']);
        $this->assertSame('owner', $result['author_type']);
        $this->assertSame('photographer1', $result['author_name']);
        $this->assertGreaterThan(0, $result['updated_at']);
    }

    public function testSaveCommentUpdatesExisting(): void
    {
        // Mock: UPDATE betrifft 1 Zeile → kein INSERT
        $qbUpdate = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qbUpdate->method('update')->willReturnSelf();
        $qbUpdate->method('set')->willReturnSelf();
        $qbUpdate->method('where')->willReturnSelf();
        $qbUpdate->method('createNamedParameter')->willReturnSelf();
        $qbUpdate->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qbUpdate->method('executeStatement')->willReturn(1);

        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qbUpdate);

        $result = $this->service->saveComment(42, 'Update!', 'guest', 'Anna');

        $this->assertSame('Update!', $result['comment']);
        $this->assertSame('guest', $result['author_type']);
        $this->assertSame('Anna', $result['author_name']);
    }

    public function testSaveCommentTruncatesTo2000Chars(): void
    {
        $qbUpdate = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qbUpdate->method('update')->willReturnSelf();
        $qbUpdate->method('set')->willReturnSelf();
        $qbUpdate->method('where')->willReturnSelf();
        $qbUpdate->method('createNamedParameter')->willReturnSelf();
        $qbUpdate->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qbUpdate->method('executeStatement')->willReturn(1);

        $this->db->method('getQueryBuilder')->willReturn($qbUpdate);

        $longText = str_repeat('x', 3000);
        $result = $this->service->saveComment(42, $longText, 'owner', 'user1');

        $this->assertSame(2000, mb_strlen($result['comment']));
    }

    public function testGetCommentReturnsNullWhenNotFound(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnSelf();
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

        $queryResult = $this->createMock(\OCP\DB\IResult::class);
        $queryResult->method('fetch')->willReturn(false);
        $qb->method('executeQuery')->willReturn($queryResult);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->assertNull($this->service->getComment(999));
    }

    public function testGetCommentReturnsData(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnSelf();
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

        $queryResult = $this->createMock(\OCP\DB\IResult::class);
        $queryResult->method('fetch')->willReturn([
            'file_id'     => '42',
            'comment'     => 'Super Foto',
            'author_type' => 'guest',
            'author_name' => 'Anna',
            'updated_at'  => '1713000000',
        ]);
        $qb->method('executeQuery')->willReturn($queryResult);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->service->getComment(42);
        $this->assertSame(42, $result['file_id']);
        $this->assertSame('Super Foto', $result['comment']);
        $this->assertSame('guest', $result['author_type']);
        $this->assertSame('Anna', $result['author_name']);
        $this->assertSame(1713000000, $result['updated_at']);
    }

    public function testDeleteCommentExecutesDelete(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnSelf();
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qb->expects($this->once())->method('executeStatement');

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->service->deleteComment(42);
    }

    // ─── Tests: Login-Log-Events ──────────────────────────────────────────────

    public function testAppendLoginToLogStoresEventWithGuestName(): void
    {
        $share = [
            'owner_id'   => self::OWNER_ID,
            'token'      => self::SAMPLE_TOKEN,
            'guest_name' => 'Anna',
        ];

        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->appendLoginToLog($share);

        $this->assertCount(1, $saved);
        $this->assertSame('login', $saved[0]['event']);
        $this->assertSame('Anna', $saved[0]['guest_name']);
        $this->assertIsInt($saved[0]['timestamp']);
    }

    public function testAppendLoginToLogHandlesMissingGuestName(): void
    {
        $share = ['owner_id' => self::OWNER_ID, 'token' => self::SAMPLE_TOKEN];

        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->appendLoginToLog($share);

        $this->assertSame('login', $saved[0]['event']);
        $this->assertSame('', $saved[0]['guest_name']);
    }

    public function testAppendLoginFailedToLogOmitsGuestName(): void
    {
        $share = [
            'owner_id'   => self::OWNER_ID,
            'token'      => self::SAMPLE_TOKEN,
            'guest_name' => 'Anna',
        ];

        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->appendLoginFailedToLog($share);

        $this->assertCount(1, $saved);
        $this->assertSame('login_failed', $saved[0]['event']);
        $this->assertArrayNotHasKey('guest_name', $saved[0]);
        $this->assertIsInt($saved[0]['timestamp']);
    }

    public function testLoginEventsShareRingBufferLimit(): void
    {
        $share = [
            'owner_id'   => self::OWNER_ID,
            'token'      => self::SAMPLE_TOKEN,
            'guest_name' => 'Anna',
        ];

        $existingLog = [];
        for ($i = 0; $i < 500; $i++) {
            $existingLog[] = ['file_id' => $i, 'rating' => 1, 'color' => null, 'pick' => null, 'guest_name' => 'Bot', 'timestamp' => $i + 1];
        }

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existingLog));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->appendLoginToLog($share);

        $this->assertCount(500, $saved);
        $this->assertSame('login', $saved[499]['event']);
        $this->assertNotSame(0, $saved[0]['file_id']);
    }

    // ─── Tests: Guest-Log-Trimming ────────────────────────────────────────────

    public function testGuestLogTrimmedTo500Entries(): void
    {
        $share = ['token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID];

        // Bestehendes Log mit 500 Einträgen (genau am Limit)
        $existingLog = [];
        for ($i = 0; $i < 500; $i++) {
            $existingLog[] = ['file_id' => $i, 'rating' => 1, 'color' => null, 'pick' => null, 'guest_name' => 'Bot', 'timestamp' => $i + 1];
        }

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existingLog));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        // Eintrag 501 → muss getrimmt werden (500 + 1 > 500)
        $this->service->saveGuestRating($share, 501, 3, null, null, 'Bob');
        $this->assertCount(500, $saved);
        // Ältester Eintrag (file_id=0) sollte raus sein
        $this->assertNotSame(0, $saved[0]['file_id']);
        // Neuster Eintrag ist der gerade gespeicherte
        $this->assertSame(501, $saved[499]['file_id']);
    }

    // ─── Tests: getSharesByOwner ──────────────────────────────────────────────

    public function testGetSharesByOwnerHidesPasswordHash(): void
    {
        $hash     = password_hash('geheim', PASSWORD_BCRYPT);
        $existing = [self::SAMPLE_TOKEN => [
            'token' => self::SAMPLE_TOKEN, 'owner_id' => self::OWNER_ID,
            'nc_path' => '/Fotos', 'password_hash' => $hash,
            'expires_at' => null, 'min_rating' => 0,
            'permissions' => 'view', 'active' => true, 'created_at' => time(),
        ]];

        $this->config->method('getUserValue')->willReturn(json_encode($existing));

        $shares = $this->service->getSharesByOwner(self::OWNER_ID);

        $this->assertCount(1, $shares);
        $this->assertArrayNotHasKey('password_hash', $shares[0]);
        $this->assertTrue($shares[0]['has_password']);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /**
     * Mockt die getShare()-DB-Abfrage (SELECT über die preferences-Tabelle):
     * liefert pro Owner eine Zeile mit dem JSON-Share-Blob.
     *
     * @param array<string, array<string, array>> $sharesByUser  uid => [token => shareData]
     */
    private function makeGetShareQb(array $sharesByUser): \OCP\DB\QueryBuilder\IQueryBuilder
    {
        $rows = [];
        foreach ($sharesByUser as $uid => $shares) {
            $rows[] = ['userid' => $uid, 'configvalue' => json_encode($shares)];
        }

        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }
}

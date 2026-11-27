<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\TagService;
use OCA\StarRate\Service\XmpSyncService;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * „In Dateien schreiben“ (Owner): StarRate-Bewertungen in JPEG-XMP / RAW-Sidecars
 * zurückschreiben, z.B. nachdem Lightroom sie mit seinem Katalogstand überschrieben hat.
 *
 * Das Frontend schickt die IDs der aktuell angezeigten Bilder in Häppchen.
 */
class XmpSyncController extends Controller
{
    use StarRateControllerTrait;

    public const MAX_PREVIEW_IDS = 500;
    public const MAX_APPLY_IDS   = 100;

    public function __construct(
        string                           $appName,
        IRequest                         $request,
        private readonly IRootFolder     $rootFolder,
        private readonly IUserSession    $userSession,
        private readonly TagService      $tagService,
        private readonly XmpSyncService  $sync,
        private readonly UserSettings    $userSettings,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─── POST /api/xmp-sync/preview ───────────────────────────────────────────

    /**
     * Body: { "fileIds": [..] } — nur lesen, nichts schreiben.
     *
     * Antwort: items = Dateien mit mindestens einem fehlenden oder abweichenden
     * Feld (reine „extra“-Abweichungen zählen nur in counts), plus Zähler.
     */
    #[NoAdminRequired]
    public function preview(): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $ids = $this->readIds(self::MAX_PREVIEW_IDS);
        if ($ids instanceof DataResponse) return $ids;

        $settings = $this->userSettings->getSettings($userId);
        if (empty($settings['write_xmp'])) {
            return new DataResponse(['error' => 'xmp_disabled'], Http::STATUS_CONFLICT);
        }

        $meta   = $this->tagService->getMetadataBatch(array_map('strval', $ids));
        $items  = [];
        $counts = [
            XmpSyncService::KIND_MISSING   => ['rating' => 0, 'color' => 0, 'pick' => 0],
            XmpSyncService::KIND_DIFFERENT => ['rating' => 0, 'color' => 0, 'pick' => 0],
            XmpSyncService::KIND_EXTRA     => ['rating' => 0, 'color' => 0, 'pick' => 0],
        ];
        $checked = $noStore = $unreadable = $notFound = $skipped = 0;

        foreach ($ids as $id) {
            $file = $this->getFileById($userId, $id);
            if ($file === null) {
                $notFound++;
                continue;
            }
            $db  = $meta[(string) $id] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
            $cmp = $this->sync->compare($file, $db, $settings);

            if ($cmp['status'] === XmpSyncService::RESULT_NO_STORE) { $noStore++; continue; }
            if ($cmp['status'] === XmpSyncService::RESULT_SKIPPED)  { $skipped++; continue; }
            if ($cmp['status'] !== XmpSyncService::RESULT_OK)       { $unreadable++; continue; }
            $checked++;

            $relevant = false;
            foreach ($cmp['diffs'] as $field => $d) {
                $counts[$d['kind']][$field]++;
                $relevant = $relevant || $d['kind'] !== XmpSyncService::KIND_EXTRA;
            }
            if ($relevant) {
                $items[] = ['id' => $id, 'name' => $file->getName(), 'diffs' => $cmp['diffs']];
            }
        }

        return new DataResponse([
            'items'      => $items,
            'counts'     => $counts,
            'checked'    => $checked,
            'noStore'    => $noStore,
            'unreadable' => $unreadable,
            'notFound'   => $notFound,
            'skipped'    => $skipped,
        ]);
    }

    // ─── POST /api/xmp-sync/apply ─────────────────────────────────────────────

    /**
     * Body: { "fileIds": [..], "fields": ["rating","color"], "overwrite": false }
     */
    #[NoAdminRequired]
    public function apply(): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $ids = $this->readIds(self::MAX_APPLY_IDS);
        if ($ids instanceof DataResponse) return $ids;

        $body   = $this->getJsonBody();
        $fields = array_values(array_intersect(
            is_array($body['fields'] ?? null) ? $body['fields'] : [],
            XmpSyncService::FIELDS,
        ));
        if ($fields === []) {
            return new DataResponse(['error' => 'fields missing'], Http::STATUS_BAD_REQUEST);
        }
        $overwrite = filter_var($body['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $settings = $this->userSettings->getSettings($userId);
        if (empty($settings['write_xmp'])) {
            return new DataResponse(['error' => 'xmp_disabled'], Http::STATUS_CONFLICT);
        }

        $meta    = $this->tagService->getMetadataBatch(array_map('strval', $ids));
        $results = [
            XmpSyncService::RESULT_WRITTEN   => 0,
            XmpSyncService::RESULT_UNCHANGED => 0,
            XmpSyncService::RESULT_NO_STORE  => 0,
            XmpSyncService::RESULT_FAILED    => 0,
            XmpSyncService::RESULT_SKIPPED   => 0,
        ];
        $failed = [];

        foreach ($ids as $id) {
            $file = $this->getFileById($userId, $id);
            if ($file === null) {
                $results[XmpSyncService::RESULT_FAILED]++;
                $failed[] = $id;
                continue;
            }
            $db = $meta[(string) $id] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
            $r  = $this->sync->apply($file, $db, $settings, $fields, $overwrite);
            $results[$r]++;
            if ($r === XmpSyncService::RESULT_FAILED) {
                $failed[] = $id;
            }
        }

        return new DataResponse($results + ['failedIds' => $failed]);
    }

    /**
     * @return list<int>|DataResponse
     */
    private function readIds(int $max): array|DataResponse
    {
        $body = $this->getJsonBody();
        $raw  = $body['fileIds'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return new DataResponse(['error' => 'fileIds missing or not an array'], Http::STATUS_BAD_REQUEST);
        }
        if (count($raw) > $max) {
            return new DataResponse(['error' => "Maximum {$max} files per request"], Http::STATUS_BAD_REQUEST);
        }
        return array_values(array_unique(array_map('intval', $raw)));
    }
}

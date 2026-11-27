<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Service\XmpStoreResolver;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RatingController extends Controller
{
    use StarRateControllerTrait;

    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly IRootFolder  $rootFolder,
        private readonly IUserSession $userSession,
        private readonly TagService   $tagService,
        private readonly XmpStoreResolver $xmpStores,
        private readonly UserSettings $userSettings,
        private readonly ShareService $shareService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─── GET /api/rating/{fileId} ─────────────────────────────────────────────

    /**
     * Liest Rating, Color und Pick-Status einer Datei.
     *
     * Self-healing XMP read: bei aktivem `write_xmp` Setting und JPEG-Datei wird
     * zusätzlich das XMP der Datei gelesen. Weicht es vom DB-Tag ab, gewinnt das
     * File und der Tag wird angeglichen — so heilt sich ein in Lightroom/digiKam
     * extern bearbeitetes Bild beim nächsten Loupe-Open selbst, ohne manuellen
     * `occ starrate:import-xmp`-Lauf.
     *
     * Fail-soft: lesefehler (Permission, korrupt, Storage offline) → DB-Antwort,
     * kein 500.
     *
     * @return DataResponse<array{rating: int, color: string|null, pick: string}>
     */
    #[NoAdminRequired]
    public function get(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $fileIdStr = (string) $fileId;
            $db        = $this->tagService->getMetadata($fileIdStr);

            // Self-healing nur bei JPEG / RAW-Sidecar + write_xmp setting on (User hat XMP-as-master akzeptiert)
            $store = $this->xmpStores->forFile($file, $this->userSettings->getSettings($userId));
            if ($store !== null) {
                try {
                    $xmp    = $store->readMetadata($file);
                    $synced = self::mergeSelfHeal($db, $xmp);

                    // Nur geänderte Felder schreiben: setMetadata löscht pro übergebenem
                    // Feld erst alle Tags und legt dann neu an — unveränderte Felder
                    // (z.B. Gast-Sterne) bleiben so außerhalb dieses Fensters.
                    $changed = [];
                    if ($synced['rating'] !== $db['rating']) $changed['rating'] = $synced['rating'];
                    if ($synced['color']  !== $db['color'])  $changed['color']  = $synced['color'];
                    if ($synced['pick']   !== $db['pick'])   $changed['pick']   = $synced['pick'];

                    if ($changed !== []) {
                        $this->tagService->setMetadata($fileIdStr, $changed);
                        return new DataResponse($synced);
                    }
                } catch (\Exception $e) {
                    // Lesen fehlgeschlagen → DB-Antwort, kein 500.
                    $this->logger->debug("StarRate self-heal XMP skipped for {$fileId}: " . $e->getMessage());
                }
            }

            return new DataResponse($db);
        } catch (\Exception $e) {
            $this->logger->error("StarRate RatingController::get – {$e->getMessage()}");
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── POST /api/rating/{fileId} ────────────────────────────────────────────

    /**
     * Setzt Rating und/oder Color und/oder Pick für eine Datei.
     * Schreibt in NC-Tags UND direkt in JPEG-XMP.
     *
     * Body (JSON): { "rating": 4, "color": "Green", "pick": "pick" }
     * Alle Felder optional — nur gesendete Felder werden geändert.
     *
     * @return DataResponse<array{rating: int, color: string|null, pick: string}>
     */
    #[NoAdminRequired]
    public function set(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $body = $this->getJsonBody();

        // Validierung
        $errors = $this->validateRatingBody($body);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $fileIdStr = (string) $fileId;
            $data      = [];

            if (array_key_exists('rating', $body)) {
                $data['rating'] = (int) $body['rating'];
            }
            if (array_key_exists('color', $body)) {
                $data['color'] = $body['color'] === '' ? null : $body['color'];
            }
            if (array_key_exists('pick', $body)) {
                $data['pick'] = $body['pick'];
            }

            // 1. NC-Tags setzen
            $this->tagService->setMetadata($fileIdStr, $data);

            // 2. XMP schreiben (JPEG eingebettet / RAW-Sidecar) — nur wenn in den Einstellungen aktiviert
            try {
                $this->writeXmp($file, $data, $this->userSettings->getSettings($userId));
            } catch (\Exception $e) {
                $this->logger->warning("StarRate: XMP write skipped for {$fileId}: " . $e->getMessage());
            }

            // Aktuellen Stand zurückgeben
            $updated = $this->tagService->getMetadata($fileIdStr);
            return new DataResponse($updated, Http::STATUS_OK);

        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error("StarRate RatingController::set – {$e->getMessage()}");
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── POST /api/rating/batch ───────────────────────────────────────────────

    /**
     * Stapel-Bewertung: setzt Rating/Color/Pick für mehrere Dateien gleichzeitig.
     *
     * Body (JSON): {
     *   "fileIds": [123, 456, 789],
     *   "rating": 4,          // optional
     *   "color": "Green",     // optional
     *   "pick": "pick"        // optional
     * }
     *
     * @return DataResponse<array{updated: int, errors: int, details: array}>
     */
    #[NoAdminRequired]
    public function setBatch(): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $body = $this->getJsonBody();

        if (empty($body['fileIds']) || !is_array($body['fileIds'])) {
            return new DataResponse(['error' => 'fileIds missing or not an array'], Http::STATUS_BAD_REQUEST);
        }

        if (count($body['fileIds']) > 500) {
            return new DataResponse(['error' => 'Maximum 500 files per batch'], Http::STATUS_BAD_REQUEST);
        }

        $errors = $this->validateRatingBody($body);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $data = [];
        if (array_key_exists('rating', $body)) {
            $data['rating'] = (int) $body['rating'];
        }
        if (array_key_exists('color', $body)) {
            $data['color'] = $body['color'] === '' ? null : $body['color'];
        }
        if (array_key_exists('pick', $body)) {
            $data['pick'] = $body['pick'];
        }

        $updated    = 0;
        $errCount   = 0;
        $xmpWritten = 0;
        $xmpSkipped = 0;
        $details    = [];
        $settings   = $this->userSettings->getSettings($userId);

        foreach ($body['fileIds'] as $rawId) {
            $fileId = (int) $rawId;

            try {
                $file = $this->getFileById($userId, $fileId);
                if ($file === null) {
                    $errCount++;
                    $details[] = ['id' => $fileId, 'error' => 'Not found'];
                    continue;
                }

                // NC-Tags
                $this->tagService->setMetadata((string) $fileId, $data);

                // XMP (JPEG eingebettet / RAW-Sidecar) — non-fatal, nur wenn in den Einstellungen aktiviert
                try {
                    if ($this->writeXmp($file, $data, $settings)) {
                        $xmpWritten++;
                    }
                } catch (\Exception $e) {
                    $xmpSkipped++;
                    $this->logger->warning("StarRate: XMP write skipped for {$fileId}: " . $e->getMessage());
                }

                $updated++;
                $details[] = ['id' => $fileId, 'ok' => true];

            } catch (\Exception $e) {
                $errCount++;
                $details[] = ['id' => $fileId, 'error' => $e->getMessage()];
                $this->logger->warning("StarRate batch rating error for {$fileId}: " . $e->getMessage());
            }
        }

        return new DataResponse([
            'updated'     => $updated,
            'errors'      => $errCount,
            'xmpWritten'  => $xmpWritten,
            'xmpSkipped'  => $xmpSkipped,
            'details'     => $details,
        ], Http::STATUS_OK);
    }

    // ─── DELETE /api/rating/{fileId} ──────────────────────────────────────────

    /**
     * Entfernt alle StarRate-Tags von einer Datei und setzt XMP auf Rating=0, kein Label.
     */
    #[NoAdminRequired]
    public function delete(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->tagService->clearAll((string) $fileId);

            // Non-fatal wie in set(): die Tags sind schon zurückgesetzt
            try {
                $this->writeXmp($file, ['rating' => 0, 'color' => null, 'pick' => 'none'], $this->userSettings->getSettings($userId));
            } catch (\Exception $e) {
                $this->logger->warning("StarRate: XMP reset skipped for {$fileId}: " . $e->getMessage());
            }

            return new DataResponse(['ok' => true]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate RatingController::delete – {$e->getMessage()}");
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Kommentare (Owner) ───────────────────────────────────────────────────

    #[NoAdminRequired]
    public function getComment(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $settings = $this->userSettings->getSettings($userId);
        if (!$settings['comments_enabled']) {
            return new DataResponse(['error' => 'Comments disabled'], Http::STATUS_FORBIDDEN);
        }

        $comment = $this->shareService->getComment($fileId);
        if ($comment === null) {
            return new DataResponse(['comment' => null]);
        }
        return new DataResponse($comment);
    }

    #[NoAdminRequired]
    public function saveComment(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $settings = $this->userSettings->getSettings($userId);
        if (!$settings['comments_enabled']) {
            return new DataResponse(['error' => 'Comments disabled'], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getJsonBody();
        $text = trim($body['comment'] ?? '');
        if ($text === '') {
            return new DataResponse(['error' => 'Comment is empty'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $result = $this->shareService->saveComment($fileId, $text, 'owner', $userId);
        return new DataResponse($result);
    }

    #[NoAdminRequired]
    public function deleteComment(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $settings = $this->userSettings->getSettings($userId);
        if (!$settings['comments_enabled']) {
            return new DataResponse(['error' => 'Comments disabled'], Http::STATUS_FORBIDDEN);
        }

        $this->shareService->deleteComment($fileId);
        return new DataResponse(['ok' => true]);
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Schreibt $data (rating/color/pick, nur gesendete Felder) ins XMP der Datei.
     *
     * @return bool false wenn für diese Datei kein XMP geschrieben wird
     * @throws \Exception wenn das Schreiben fehlschlägt
     */
    private function writeXmp(File $file, array $data, array $settings): bool
    {
        $store = $this->xmpStores->forFile($file, $settings);
        if ($store === null) {
            return false;
        }
        $store->writeMetadata(
            $file,
            $data['rating'] ?? null,
            array_key_exists('color', $data) ? ($data['color'] ?? '') : null,
            array_key_exists('pick', $data)  ? $data['pick']           : null,
            $settings['xmp_label_language'],
        );
        return true;
    }

    /**
     * Self-Healing feldweise: das XMP darf einen Wert setzen oder ändern, aber nie
     * einen DB-Wert löschen. 0★, kein Label und kein Pick im XMP zählen als „keine
     * Aussage“, nicht als „entfernen“.
     *
     * Grund: Lightroom mit „Änderungen automatisch in XMP speichern“ schreibt seinen
     * Katalogstand in jedes Sidecar. Kennt der Katalog die StarRate-Sterne nicht
     * (Metadaten noch nicht aus Datei gelesen), fehlt xmp:Rating dort — und das
     * alte Ganz-oder-gar-nicht-Sync hat dann wegen eines vorhandenen xmpDM:pick
     * alle drei Felder übernommen und Gast-Sterne gelöscht.
     *
     * Trade-off (wie bisher für „XMP komplett leer“): ein in LR explizit entfernter
     * Stern / Label / Pick propagiert nicht zurück. Datenverlust ist schlimmer.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @param array{rating: int, label: string|null, pick: string} $xmp
     * @return array{rating: int, color: string|null, pick: string}
     */
    private static function mergeSelfHeal(array $db, array $xmp): array
    {
        return [
            'rating' => $xmp['rating'] > 0 ? $xmp['rating'] : $db['rating'],
            'color'  => $xmp['label'] ?? $db['color'],
            'pick'   => $xmp['pick'] !== 'none' ? $xmp['pick'] : $db['pick'],
        ];
    }

    private function validateRatingBody(array $body): array
    {
        $errors = [];

        if (array_key_exists('rating', $body)) {
            $r = $body['rating'];
            if (!is_int($r) && !ctype_digit((string) $r)) {
                $errors[] = 'rating must be an integer';
            } elseif ((int) $r < 0 || (int) $r > 5) {
                $errors[] = 'rating must be between 0 and 5';
            }
        }

        if (array_key_exists('color', $body) && $body['color'] !== '' && $body['color'] !== null) {
            if (!in_array($body['color'], TagService::VALID_COLORS, true)) {
                $errors[] = 'color must be one of ' . implode(', ', TagService::VALID_COLORS);
            }
        }

        if (array_key_exists('pick', $body)) {
            if (!in_array($body['pick'], TagService::VALID_PICKS, true)) {
                $errors[] = 'pick must be "pick", "reject" or "none"';
            }
        }

        return $errors;
    }

}

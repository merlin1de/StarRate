<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview as IPreviewManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet öffentliche Freigabe-Links für Gäste (Modelle, Kunden).
 *
 * Shares werden in der NC-Config gespeichert.
 * Gast-Bewertungen werden als echte NC Collaborative Tags gespeichert (via TagService).
 * Ein Log pro Share protokolliert wer wann was bewertet hat.
 */
class ShareService
{
    public const PERM_VIEW = 'view';
    public const PERM_RATE = 'rate';

    // Hartgrenze für Bilder pro ZIP-Download. Schützt Server (RAM/Zeit) und hält
    // zugleich die GET-fileId-Liste innerhalb der URL-Längengrenze. Notbremse
    // (gibt 422); das Frontend warnt schon darunter. MUSS mit ZIP_MAX in
    // src/views/Gallery.vue übereinstimmen — bei Änderung beide anpassen.
    public const MAX_ZIP_FILES = 500;

    private const APP_ID               = 'starrate';
    private const CONFIG_SHARES        = 'starrate_shares';
    private const CONFIG_LOG           = 'starrate_guest_log';  // key: CONFIG_LOG_<token>
    private const TOKEN_LENGTH         = 24;
    private const GUEST_LOG_MAX_ENTRIES = 500;

    // Unterstützte MIME-Typen für Gast-Galerie. RAW: ausgeliefert wird nur die
    // Vorschau — die RAW-Datei selbst nur, wenn der Share Downloads erlaubt.
    private const GUEST_MIME = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', RawPreviewExtractor::MIME];

    public function __construct(
        private readonly IConfig         $config,
        private readonly IRootFolder     $rootFolder,
        private readonly IPreviewManager $previewManager,
        private readonly ISecureRandom   $secureRandom,
        private readonly TagService      $tagService,
        private readonly LoggerInterface $logger,
        private readonly IDBConnection $db,
        private readonly ExifService     $exifService,
        private readonly UserSettings    $userSettings,
        private readonly SidecarService  $sidecarService,
    ) {}

    // ─── Share erstellen / verwalten ─────────────────────────────────────────

    /**
     * Erstellt einen neuen Freigabe-Link.
     *
     * @return array{token: string, nc_path: string, owner_id: string, ...}
     */
    public function createShare(
        string  $ownerId,
        string  $ncPath,
        ?string $password,
        ?int    $expiresAt,
        int     $minRating,
        string  $permissions,
        ?string $guestName = null,
        bool    $allowPick = false,
        bool    $allowExport = false,
        bool    $allowComment = false,
        bool    $recursive = false,
        int     $depth = 0,
        bool    $allowDownload = false,
    ): array {
        $this->validatePermissions($permissions);

        $token = $this->secureRandom->generate(
            self::TOKEN_LENGTH,
            ISecureRandom::CHAR_ALPHANUMERIC
        );

        $share = [
            'token'         => $token,
            'owner_id'      => $ownerId,
            'nc_path'       => rtrim($ncPath, '/'),
            'password_hash' => $password ? password_hash($password, PASSWORD_BCRYPT) : null,
            'expires_at'    => $expiresAt,
            'min_rating'    => max(0, min(5, $minRating)),
            'permissions'   => $permissions,
            'guest_name'    => $guestName ? trim($guestName) : null,
            'allow_pick'    => $allowPick,
            'allow_export'  => $allowExport,
            'allow_comment' => $allowComment,
            'allow_download' => $allowDownload,
            'recursive'     => $recursive,
            'depth'         => max(0, min(4, $depth)),
            'created_at'    => time(),
            'active'        => true,
        ];

        $allShares           = $this->loadAllShares($ownerId);
        $allShares[$token]   = $share;
        $this->saveShares($ownerId, $allShares);

        $safe                 = $share;
        $safe['has_password'] = $password !== null;
        unset($safe['password_hash']);

        $this->logger->info("StarRate: share {$token} created by {$ownerId} for {$ncPath}.");
        return $safe;
    }

    /**
     * Lädt einen Share anhand des Tokens (sucht in allen Benutzern).
     */
    public function getShare(string $token): ?array
    {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('userid', 'configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP_ID)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::CONFIG_SHARES)))
            ->executeQuery();

        while ($row = $result->fetch()) {
            $shares = json_decode($row['configvalue'], true);
            if (is_array($shares) && isset($shares[$token])) {
                $share                 = $shares[$token];
                $share['has_password'] = !empty($share['password_hash']);
                $share['allow_download'] = $share['allow_download'] ?? false;
                return $share;
            }
        }

        return null;
    }

    /**
     * Gibt alle Shares eines Benutzers zurück (ohne Passwort-Hash).
     *
     * @return array[]
     */
    public function getSharesByOwner(string $ownerId): array
    {
        $shares = $this->loadAllShares($ownerId);
        return array_values(array_map(function (array $s): array {
            $s['has_password'] = !empty($s['password_hash']);
            $s['allow_download'] = $s['allow_download'] ?? false;
            unset($s['password_hash']);
            return $s;
        }, $shares));
    }

    /**
     * Aktualisiert einen bestehenden Share.
     */
    public function updateShare(string $token, array $data): array
    {
        $share   = $this->getShare($token);
        if ($share === null) {
            throw new \RuntimeException("Share {$token} nicht gefunden.");
        }

        $ownerId = $share['owner_id'];
        $all     = $this->loadAllShares($ownerId);

        if (array_key_exists('password', $data)) {
            $all[$token]['password_hash'] = $data['password']
                ? password_hash($data['password'], PASSWORD_BCRYPT)
                : null;
        }
        // Wie beim Passwort über array_key_exists: ein explizit gesendetes null
        // entfernt das Ablaufdatum. isset() würde bei null false liefern → das
        // Datum ließe sich nach dem Setzen nie wieder löschen. (Ein 0-Timestamp
        // erreicht diese Stelle nicht — validateShareBody weist ihn vorher als
        // "Vergangenheit" ab; die > 0-Prüfung ist reine Defensive gegen null.)
        if (array_key_exists('expires_at', $data)) {
            $ts = (int) $data['expires_at'];
            $all[$token]['expires_at'] = $ts > 0 ? $ts : null;
        }
        if (isset($data['min_rating'])) {
            $all[$token]['min_rating'] = max(0, min(5, (int) $data['min_rating']));
        }
        if (isset($data['permissions'])) {
            $this->validatePermissions($data['permissions']);
            $all[$token]['permissions'] = $data['permissions'];
        }
        if (isset($data['active'])) {
            $all[$token]['active'] = (bool) $data['active'];
        }
        if (isset($data['allow_pick'])) {
            $all[$token]['allow_pick'] = (bool) $data['allow_pick'];
        }
        if (isset($data['allow_export'])) {
            $all[$token]['allow_export'] = (bool) $data['allow_export'];
        }
        if (isset($data['allow_comment'])) {
            $all[$token]['allow_comment'] = (bool) $data['allow_comment'];
        }
        if (isset($data['allow_download'])) {
            $all[$token]['allow_download'] = (bool) $data['allow_download'];
        }
        if (isset($data['recursive'])) {
            $all[$token]['recursive'] = (bool) $data['recursive'];
        }
        if (isset($data['depth'])) {
            $all[$token]['depth'] = max(0, min(4, (int) $data['depth']));
        }
        if (isset($data['nc_path'])) {
            $all[$token]['nc_path'] = rtrim((string) $data['nc_path'], '/');
        }

        $this->saveShares($ownerId, $all);

        $updated               = $all[$token];
        $updated['has_password'] = !empty($updated['password_hash']);
        unset($updated['password_hash']);
        return $updated;
    }

    /**
     * Löscht einen Share und sein Log.
     */
    public function deleteShare(string $token): void
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return;
        }

        $ownerId = $share['owner_id'];
        $all     = $this->loadAllShares($ownerId);
        unset($all[$token]);
        $this->saveShares($ownerId, $all);

        // Log mit löschen
        $this->config->deleteUserValue($ownerId, self::APP_ID, self::CONFIG_LOG . '_' . $token);

        $this->logger->info("StarRate: share {$token} deleted.");
    }

    // ─── Share-Validierung ────────────────────────────────────────────────────

    public function isShareValid(array $share): bool
    {
        if (!($share['active'] ?? true)) {
            return false;
        }
        if (!empty($share['expires_at']) && $share['expires_at'] < time()) {
            return false;
        }
        return true;
    }

    public function verifyPassword(array $share, string $password): bool
    {
        if (empty($share['password_hash'])) {
            return true;
        }
        return password_verify($password, $share['password_hash']);
    }

    // ─── Gast-Galerie ─────────────────────────────────────────────────────────

    /**
     * Gibt Bilder und Unterordner für einen Share zurück inkl. aktueller NC-Tag-Bewertungen.
     * Filtert nach min_rating wenn gesetzt (verwendet NC-Tags).
     *
     * @param  string $subPath  Relativer Pfad innerhalb des Share-Roots (leer = Root)
     * @return array{images: array[], folders: array[]}
     */
    public function getImagesForShare(array $share, string $subPath = ''): array
    {
        $ownerId   = $share['owner_id'];
        $ncPath    = rtrim($share['nc_path'], '/');
        $minRating = (int) ($share['min_rating'] ?? 0);
        $recursive = (bool) ($share['recursive'] ?? false);
        $depth     = max(0, min(4, (int) ($share['depth'] ?? 0)));

        // Gäste erben die Sortier-Präferenz des Owners (analog eingeloggte Ansicht,
        // GalleryController::listImages). Ohne das kämen die Bilder in Storage-
        // Reihenfolge zurück (Issue #90).
        $settings = $this->userSettings->getSettings($ownerId);
        $sort     = $settings['default_sort']       ?? 'name';
        $order    = $settings['default_sort_order'] ?? 'asc';

        $userFolder = $this->rootFolder->getUserFolder($ownerId);

        if ($recursive) {
            // Bei recursive=true ignorieren wir den subPath konsequent — der
            // Gast bekommt immer den gesamten Subtree ab dem Share-Root, ohne
            // Folder-Drill. Das verhindert unbeabsichtigtes Erschleichen von
            // Subfolder-Listings durch URL-Manipulation.
            $folder = $ncPath === '' || $ncPath === '/'
                ? $userFolder
                : $userFolder->get(ltrim($ncPath, '/'));

            if (!($folder instanceof Folder)) {
                throw new \RuntimeException("Pfad ist kein Ordner: {$ncPath}");
            }

            $images = $this->listImagesRecursiveForShare($folder, $depth);
            // Bei recursive: kein Folder-Listing, der Gast hat keine Navigation
            $folders = [];
        } else {
            // Subpath bereinigen (Traversal-Schutz) — gilt nur im Nicht-Recursive-Modus
            $subPath = $this->sanitizeSubPath($subPath);
            $fullPath = $ncPath . $subPath;
            $folder = $fullPath === '' || $fullPath === '/'
                ? $userFolder
                : $userFolder->get(ltrim($fullPath, '/'));

            if (!($folder instanceof Folder)) {
                throw new \RuntimeException("Pfad ist kein Ordner: {$fullPath}");
            }

            $images  = [];
            $folders = [];

            foreach ($folder->getDirectoryListing() as $node) {
                if ($node instanceof Folder) {
                    if ($node->getName()[0] === '.') continue;
                    $relPath   = $subPath . '/' . $node->getName();
                    $folders[] = [
                        'name' => $node->getName(),
                        'path' => $relPath,
                    ];
                    continue;
                }
                if (!($node instanceof File)) {
                    continue;
                }
                if (!in_array($node->getMimeType(), self::GUEST_MIME, true)
                    || !RawPreviewExtractor::isListable($node->getMimeType(), $node->getName())
                ) {
                    continue;
                }
                $images[] = [
                    'id'    => $node->getId(),
                    'name'  => $node->getName(),
                    'mtime' => $node->getMtime(),
                    'size'  => $node->getSize(),
                ];
            }
        }

        // Zentrale Sortierung nach Owner-Präferenz — deckt beide Zweige ab
        // (rekursiv behält den groupKey-Primärschlüssel für die Ordner-Gruppierung).
        $this->sortImages($images, $sort, $order);

        if (!empty($images)) {
            // Aktuelle NC-Tag-Bewertungen laden
            $fileIds  = array_map(fn($img) => (string) $img['id'], $images);
            $metadata = $this->tagService->getMetadataBatch($fileIds);

            foreach ($images as &$img) {
                $meta          = $metadata[(string) $img['id']] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
                $img['rating'] = $meta['rating'];
                $img['color']  = $meta['color'];
                $img['pick']   = $meta['pick'];
            }
            unset($img);

            // min_rating filtern (basiert auf NC-Tags)
            if ($minRating > 0) {
                $images = array_values(array_filter($images, fn($img) => ($img['rating'] ?? 0) >= $minRating));
            }
        }

        return ['images' => $images, 'folders' => $folders];
    }

    /**
     * Liefert ein Thumbnail für einen Share (prüft Dateizugehörigkeit).
     */
    public function getThumbnailForShare(array $share, int $fileId, int $width = 400, int $height = 400): Response
    {
        $ownerId    = $share['owner_id'];
        $userFolder = $this->rootFolder->getUserFolder($ownerId);
        $nodes      = $userFolder->getById($fileId);

        $file = null;
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                $file = $node;
                break;
            }
        }

        if ($file === null) {
            throw new \RuntimeException("Datei nicht gefunden: {$fileId}");
        }

        // Sicherheit: Datei muss im freigegebenen Ordner liegen
        $sharePath = rtrim($share['nc_path'], '/');
        if (!str_starts_with($file->getPath(), $userFolder->getPath() . '/' . ltrim($sharePath, '/'))) {
            throw new \RuntimeException("File is not in the shared folder.");
        }

        $width  = min(max($width,  32), 3840);
        $height = min(max($height, 32), 2160);

        $crop     = ($width <= 800);  // Thumbnails: crop; Previews: kein Crop
        $preview  = $this->previewManager->getPreview($file, $width, $height, $crop);
        $response = new FileDisplayResponse($preview, 200, [
            'Content-Type' => $preview->getMimeType(),
        ]);
        // 7 Tage Browser-Cache. Hilft beim Wiederbesuch derselben Gast-Galerie
        // (zweite Bewertungsrunde, andere Endgeräte vom selben Empfänger).
        // FileDisplayResponse setzt ETag automatisch — Browser kann nach Ablauf
        // per If-None-Match revalidieren, Server liefert 304 wenn unverändert.
        // private (Default), nicht immutable → bei mtime-Änderung neu geladen.
        $response->cacheFor(60 * 60 * 24 * 7);
        return $response;
    }

    /**
     * Liefert die Original-Datei eines Shares für den Download — mit derselben
     * "Datei liegt im freigegebenen Ordner"-Sicherheitsprüfung wie
     * getThumbnailForShare. Der Aufrufer prüft vorher das allow_download-Flag.
     *
     * @throws \RuntimeException wenn die Datei nicht existiert oder ausserhalb
     *                           des Shares liegt.
     */
    public function getFileForDownload(array $share, int $fileId): File
    {
        $ownerId    = $share['owner_id'];
        $userFolder = $this->rootFolder->getUserFolder($ownerId);
        $nodes      = $userFolder->getById($fileId);

        $file = null;
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                $file = $node;
                break;
            }
        }

        if ($file === null) {
            throw new \RuntimeException("Datei nicht gefunden: {$fileId}");
        }

        // Sicherheit: Datei muss im freigegebenen Ordner liegen (gleiche Prüfung
        // wie getThumbnailForShare — ein Gast kann nur Dateien im Share-Ordner
        // anfassen, nicht beliebige fileIds des Owners).
        $sharePath = rtrim($share['nc_path'], '/');
        if (!str_starts_with($file->getPath(), $userFolder->getPath() . '/' . ltrim($sharePath, '/'))) {
            throw new \RuntimeException("File is not in the shared folder.");
        }

        return $file;
    }

    // ─── Gast-Bewertungen ─────────────────────────────────────────────────────

    /**
     * Speichert eine Gast-Bewertung als echte NC Collaborative Tags.
     * Schreibt zusätzlich einen Log-Eintrag (für den Fotografen).
     *
     * @return array{file_id: int, rating: int|null, color: string|null, guest_name: string, timestamp: int}
     */
    public function saveGuestRating(
        array   $share,
        int     $fileId,
        ?int    $rating,
        ?string $color,
        ?string $pick,
        string  $guestName,
    ): array {
        $token     = $share['token'];
        $ownerId   = $share['owner_id'];
        $fileIdStr = (string) $fileId;

        // Echte NC-Tags setzen (identisch zu normalem User-Rating)
        $metadata = [];
        if ($rating !== null) {
            $metadata['rating'] = $rating;
        }
        if ($color !== null) {
            // '' = Gast hat die Farbe gelöscht → null für setMetadata (= Tag entfernen).
            // writeGuestXmp bekommt $color unverändert ('' = Label im XMP entfernen).
            $metadata['color'] = $color === '' ? null : $color;
        }
        if ($pick !== null) {
            $metadata['pick'] = $pick;
        }
        if (!empty($metadata)) {
            $this->tagService->setMetadata($fileIdStr, $metadata);
            $this->writeGuestXmp($ownerId, $fileId, $rating, $color, $pick);
        }

        $filename = $this->resolveFilename($ownerId, $fileId);

        // Log-Eintrag schreiben
        $entry = [
            'file_id'    => $fileId,
            'filename'   => $filename,
            'rating'     => $rating,
            'color'      => $color,
            'pick'       => $pick,
            'guest_name' => $guestName ?: 'Guest',
            'timestamp'  => time(),
        ];

        $this->appendToLog($ownerId, $token, $entry);

        $parts = [];
        if ($rating !== null)  $parts[] = "Rating {$rating}";
        if ($color  !== null)  $parts[] = "Farbe {$color}";
        if ($pick   !== null)  $parts[] = "Pick {$pick}";
        $action = $parts ? implode(', ', $parts) : 'no change';

        $this->logger->info(
            "StarRate: Gast '{$entry['guest_name']}' hat Datei {$fileId} gesetzt: {$action} (Share {$token})."
        );

        // API-Antwort spiegelt den effektiven Wert: '' (internes Lösch-Sentinel) → null.
        // Der Log-Eintrag oben behält '' für die Heal-Unterscheidung (clear vs. unverändert).
        $entry['color'] = $color === '' ? null : $color;

        return $entry;
    }

    // ─── Gast-Log ─────────────────────────────────────────────────────────────

    /**
     * Gibt alle Log-Einträge eines Shares zurück (neueste zuerst).
     *
     * @return array[]  [{file_id, rating, color, guest_name, timestamp}, ...]
     */
    public function getGuestLog(string $token): array
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return [];
        }

        $key = self::CONFIG_LOG . '_' . $token;
        $raw = $this->config->getUserValue($share['owner_id'], self::APP_ID, $key, '[]');
        $log = json_decode($raw, true);
        if (!is_array($log)) {
            return [];
        }
        return array_reverse($log);  // neueste zuerst
    }

    /**
     * Löscht das gesamte Log eines Shares.
     */
    public function clearGuestLog(string $token): void
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return;
        }
        $this->config->setUserValue(
            $share['owner_id'], self::APP_ID, self::CONFIG_LOG . '_' . $token, '[]'
        );
    }

    /**
     * Entfernt alle Log-Einträge älter als $before (Unix-Timestamp).
     */
    public function trimGuestLog(string $token, int $before): void
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return;
        }

        $ownerId  = $share['owner_id'];
        $key      = self::CONFIG_LOG . '_' . $token;
        $raw      = $this->config->getUserValue($ownerId, self::APP_ID, $key, '[]');
        $log      = json_decode($raw, true);
        if (!is_array($log)) {
            return;
        }

        $filtered = array_values(array_filter($log, fn($e) => ($e['timestamp'] ?? 0) >= $before));
        $this->config->setUserValue($ownerId, self::APP_ID, $key, json_encode($filtered));
    }

    // ─── Kommentare ───────────────────────────────────────────────────────────

    /**
     * Speichert oder aktualisiert einen Kommentar zu einer Datei (UPSERT).
     * Letzter Schreiber gewinnt — ein Kommentar pro Foto global.
     *
     * @return array{file_id: int, comment: string, author_type: string, author_name: string|null, updated_at: int}
     */
    public function saveComment(int $fileId, string $comment, string $authorType, ?string $authorName): array
    {
        $comment = mb_substr(trim($comment), 0, 2000);
        $now     = time();

        // UPDATE zuerst — Race-Condition-sicher: bei gleichzeitigem INSERT greift der UNIQUE-Constraint.
        $qb      = $this->db->getQueryBuilder();
        $updated = $qb->update('starrate_comments')
            ->set('comment',     $qb->createNamedParameter($comment))
            ->set('author_type', $qb->createNamedParameter($authorType))
            ->set('author_name', $qb->createNamedParameter($authorName))
            ->set('updated_at',  $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if ($updated === 0) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('starrate_comments')
                    ->values([
                        'file_id'     => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'comment'     => $qb->createNamedParameter($comment),
                        'author_type' => $qb->createNamedParameter($authorType),
                        'author_name' => $qb->createNamedParameter($authorName),
                        'updated_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    ])
                    ->executeStatement();
            } catch (\Exception) {
                // Race: anderer Request hat gerade denselben fileId eingefügt → aktuellen Wert zurückgeben
                return $this->getComment($fileId) ?? [
                    'file_id' => $fileId, 'comment' => $comment,
                    'author_type' => $authorType, 'author_name' => $authorName, 'updated_at' => $now,
                ];
            }
        }

        return [
            'file_id'     => $fileId,
            'comment'     => $comment,
            'author_type' => $authorType,
            'author_name' => $authorName,
            'updated_at'  => $now,
        ];
    }

    /**
     * Liest den Kommentar zu einer Datei.
     *
     * @return array{file_id: int, comment: string, author_type: string, author_name: string|null, updated_at: int}|null
     */
    public function getComment(int $fileId): ?array
    {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('file_id', 'comment', 'author_type', 'author_name', 'updated_at')
            ->from('starrate_comments')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeQuery()
            ->fetch();

        if ($result === false) {
            return null;
        }

        return [
            'file_id'     => (int) $result['file_id'],
            'comment'     => $result['comment'],
            'author_type' => $result['author_type'],
            'author_name' => $result['author_name'],
            'updated_at'  => (int) $result['updated_at'],
        ];
    }

    /**
     * Prüft ob eine Datei zum Share-Ordner gehört (Sicherheitscheck für Gäste).
     */
    public function fileExistsInShare(array $share, int $fileId): bool
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($share['owner_id']);
            $nodes      = $userFolder->getById($fileId);
            $shareRoot  = $userFolder->getPath() . rtrim($share['nc_path'], '/') . '/';
            foreach ($nodes as $node) {
                if (str_starts_with($node->getPath(), $shareRoot)) {
                    return true;
                }
            }
        } catch (\Exception) {
            // ignore
        }
        return false;
    }

    /**
     * Schreibt einen Kommentar-Eintrag in den Gast-Log.
     */
    public function appendCommentToLog(array $share, int $fileId, string $comment, string $guestName): void
    {
        $this->appendToLog($share['owner_id'], $share['token'], [
            'file_id'    => $fileId,
            'filename'   => $this->resolveFilename($share['owner_id'], $fileId),
            'comment'    => mb_substr($comment, 0, 200),
            'guest_name' => $guestName,
            'timestamp'  => time(),
        ]);
    }

    /**
     * Schreibt einen Login-Erfolgs-Eintrag (erstes Öffnen pro Session).
     */
    public function appendLoginToLog(array $share): void
    {
        $this->appendToLog($share['owner_id'], $share['token'], [
            'event'      => 'login',
            'guest_name' => $share['guest_name'] ?? '',
            'timestamp'  => time(),
        ]);
    }

    /**
     * Schreibt einen Fehlversuch-Eintrag (falsches Passwort).
     * Kein guest_name — der Share ist per Definition an einen Namen gebunden,
     * der Fehlversuch kommt von jemandem, der das Passwort nicht kennt.
     */
    public function appendLoginFailedToLog(array $share): void
    {
        $this->appendToLog($share['owner_id'], $share['token'], [
            'event'     => 'login_failed',
            'timestamp' => time(),
        ]);
    }

    /**
     * Löscht den Kommentar zu einer Datei (auch beim File-Delete aufgerufen).
     */
    public function deleteComment(int $fileId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('starrate_comments')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    // ─── Log-Hilfsmethoden ────────────────────────────────────────────────────

    /**
     * Löst eine file_id in einen Dateinamen auf (Fallback: ID als String).
     * Wird für Log-Einträge verwendet — darf nie werfen.
     */
    private function resolveFilename(string $ownerId, int $fileId): string
    {
        try {
            $nodes = $this->rootFolder->getUserFolder($ownerId)->getById($fileId);
            foreach ($nodes as $n) {
                if ($n instanceof File) {
                    return $n->getName();
                }
            }
        } catch (\Exception) { /* ignore */ }
        return (string) $fileId;
    }

    /**
     * Schreibt eine Gast-Bewertung zusätzlich ins JPEG-XMP — analog zum normalen
     * User-Rating in RatingController::set.
     *
     * Maßgeblich ist die write_xmp-Einstellung des Share-Owners (der Gast hat keine
     * eigene Session/Settings). Ohne diesen Schritt bliebe das JPEG-XMP veraltet,
     * während nur der NC-Tag aktualisiert würde — das Self-Healing beim Owner
     * (RatingController::get) würde die Gast-Bewertung beim nächsten Öffnen mit dem
     * alten XMP-Wert überschreiben.
     *
     * $rating/$color/$pick haben bereits die writeMetadata-Semantik:
     *   null = unverändert, '' = entfernen (Label), 'none' = entfernen (Pick).
     * Non-fatal: schlägt das Schreiben fehl, bleibt der NC-Tag trotzdem gesetzt.
     */
    private function writeGuestXmp(string $ownerId, int $fileId, ?int $rating, ?string $color, ?string $pick): void
    {
        $file = $this->getOwnerFile($ownerId, $fileId);
        if ($file === null) {
            return;
        }

        $settings = $this->userSettings->getSettings($ownerId);
        $isJpeg   = in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true);
        if (!$settings['write_xmp'] || (!$isJpeg && !SidecarService::appliesTo($file, $settings))) {
            return;
        }

        try {
            // RAW → XMP-Sidecar neben der Datei, JPEG → eingebettetes XMP
            $writer = $isJpeg ? $this->exifService : $this->sidecarService;
            $writer->writeMetadata($file, $rating, $color, $pick, $settings['xmp_label_language']);
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: Gast-XMP-Write übersprungen für {$fileId}: " . $e->getMessage());
        }
    }

    /**
     * Löst eine file_id in das File-Objekt des Owners auf (null wenn nicht gefunden).
     */
    private function getOwnerFile(string $ownerId, int $fileId): ?File
    {
        try {
            $nodes = $this->rootFolder->getUserFolder($ownerId)->getById($fileId);
            foreach ($nodes as $n) {
                if ($n instanceof File) {
                    return $n;
                }
            }
        } catch (\Exception) { /* ignore */ }
        return null;
    }

    // ─── Gast-XMP-Heilung (Altbestands-Migration) ─────────────────────────────
    //
    // Vor dem Forward-Fix schrieben Gast-Bewertungen nur NC-Tags, nie JPEG-XMP.
    // Das Self-Healing (RatingController::get) setzt sie dann beim Öffnen auf den
    // veralteten XMP-Wert zurück. Diese Migration heilt den Altbestand: sie liest
    // die Gast-Absicht aus dem Gast-Log (nicht der DB — daher auch korrekt, wenn der
    // Tag bereits gekippt wurde) und schreibt sie zurück in DB + XMP. Konfliktsicher
    // per Zeitvergleich (Datei-mtime ≤ Log-Zeit), damit ein späterer externer
    // LR/digiKam-Edit nicht überschrieben wird.

    /**
     * Liefert alle NC-User-IDs, die mindestens einen StarRate-Share angelegt haben.
     * Direkte preferences-Abfrage — vermeidet teures Iterieren über alle NC-User.
     *
     * @return string[]
     */
    public function getAllShareOwners(): array
    {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->selectDistinct('userid')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP_ID)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::CONFIG_SHARES)))
            ->executeQuery();

        $owners = [];
        while ($row = $result->fetch()) {
            $owners[] = (string) $row['userid'];
        }
        $result->closeCursor();
        return $owners;
    }

    /**
     * Faltet alle Gast-Logs eines Owners pro file_id zur kumulativen Gast-Absicht.
     *
     * Jeder Log-Eintrag ist ein Delta — nur die im jeweiligen Request gesetzten Felder
     * sind non-null. Chronologisch gefaltet: letztes non-null je Feld gewinnt, `ts` =
     * jüngster beteiligter Zeitstempel. rating=0 / color='' / pick='none' sind bewusste
     * Lösch-Werte (non-null) und überschreiben entsprechend.
     *
     * @return array<int, array{rating: int|null, color: string|null, pick: string|null, ts: int}>
     */
    public function foldGuestLog(string $ownerId): array
    {
        // Erst ALLE Einträge aller Shares einsammeln, dann GLOBAL chronologisch sortieren.
        // Pro-Token-Sortierung würde bei derselben Datei über zwei Share-Tokens die
        // Share-Reihenfolge statt des Zeitstempels über den Gewinner entscheiden.
        $entries = [];
        foreach (array_keys($this->loadAllShares($ownerId)) as $token) {
            $raw = $this->config->getUserValue($ownerId, self::APP_ID, self::CONFIG_LOG . '_' . $token, '[]');
            $log = json_decode($raw, true);
            if (!is_array($log)) {
                continue;
            }
            foreach ($log as $e) {
                if (is_array($e)) {
                    $entries[] = $e;
                }
            }
        }
        usort($entries, static fn(array $a, array $b): int => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $folded = [];
        foreach ($entries as $e) {
            $fileId = (int) ($e['file_id'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }
            if (!isset($folded[$fileId])) {
                $folded[$fileId] = ['rating' => null, 'color' => null, 'pick' => null, 'ts' => 0];
            }
            if (($e['rating'] ?? null) !== null) $folded[$fileId]['rating'] = (int) $e['rating'];
            if (($e['color']  ?? null) !== null) $folded[$fileId]['color']  = (string) $e['color'];
            if (($e['pick']   ?? null) !== null) $folded[$fileId]['pick']   = (string) $e['pick'];
            $ts = (int) ($e['timestamp'] ?? 0);
            if ($ts > $folded[$fileId]['ts']) {
                $folded[$fileId]['ts'] = $ts;
            }
        }

        // Phantom-Einträge (Request ohne rating/color/pick) verwerfen — keine Gast-Absicht,
        // würden sonst Repair-Warnung + Heal-Scan unnötig aufblähen.
        return array_filter(
            $folded,
            static fn(array $f): bool => $f['rating'] !== null || $f['color'] !== null || $f['pick'] !== null
        );
    }

    /**
     * Zählt die gast-bewerteten Dateien eines Owners (obere Schranke, kein Datei-I/O).
     * Für den Repair-Step-Scan beim Upgrade.
     */
    public function countGuestRatedFiles(string $ownerId): int
    {
        return count($this->foldGuestLog($ownerId));
    }

    /**
     * Analysiert (Dry-Run) oder heilt gast-bewertete JPEGs eines Owners, deren DB/XMP
     * durch die alte „Gast schreibt kein XMP"-Lücke auseinanderlaufen.
     *
     * Nur aktiv wenn der Owner write_xmp aktiviert hat (sonst kein Self-Healing → kein Drift).
     * Pro Datei: mtime-Guard (extern später bearbeitet → übersprungen), dann XMP-Vergleich
     * gegen die Gast-Absicht; bei Abweichung Kandidat (Dry-Run) bzw. DB+XMP-Write (--write).
     *
     * @param bool $write false = Analyse, true = DB+XMP tatsächlich schreiben.
     * @return array{write_xmp: bool, stats: array<string,int>, details: array<int, array>}
     */
    public function healGuestXmp(string $ownerId, bool $write): array
    {
        $stats = [
            'folded'        => 0,
            'candidates'    => 0,
            'healed'        => 0,
            'skipped_mtime' => 0,
            'in_sync'       => 0,
            'non_jpeg'      => 0,
            'not_found'     => 0,
            'errors'        => 0,
        ];
        $details = [];

        $settings = $this->userSettings->getSettings($ownerId);
        if (!$settings['write_xmp']) {
            return ['write_xmp' => false, 'stats' => $stats, 'details' => $details];
        }
        $lang = $settings['xmp_label_language'];

        $folded          = $this->foldGuestLog($ownerId);
        $stats['folded'] = count($folded);

        foreach ($folded as $fileId => $g) {
            $file = $this->getOwnerFile($ownerId, $fileId);
            if ($file === null) {
                $stats['not_found']++;
                continue;
            }
            if (!in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
                $stats['non_jpeg']++;
                continue;
            }
            // Konflikt-Guard: Datei nach der Gast-Bewertung extern bearbeitet → in Ruhe lassen.
            if ($file->getMTime() > $g['ts']) {
                $stats['skipped_mtime']++;
                continue;
            }

            try {
                $xmp = $this->exifService->readMetadata($file);
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->logger->warning("StarRate heal: XMP-Read fehlgeschlagen für {$fileId}: " . $e->getMessage());
                continue;
            }

            // Nur die Felder vergleichen, die der Gast tatsächlich gesetzt hat.
            $targetColor = $g['color'] === '' ? null : $g['color'];
            $targetPick  = $g['pick']  === '' ? 'none' : $g['pick'];
            $diff = false;
            if ($g['rating'] !== null && $xmp['rating'] !== $g['rating'])          $diff = true;
            if ($g['color']  !== null && ($xmp['label'] ?? null) !== $targetColor) $diff = true;
            if ($g['pick']   !== null && $xmp['pick'] !== $targetPick)             $diff = true;

            if (!$diff) {
                $stats['in_sync']++;
                continue;
            }

            $stats['candidates']++;
            $details[] = [
                'file_id' => $fileId,
                'name'    => $file->getName(),
                'guest'   => ['rating' => $g['rating'], 'color' => $g['color'], 'pick' => $g['pick']],
                'xmp'     => ['rating' => $xmp['rating'], 'color' => $xmp['label'] ?? null, 'pick' => $xmp['pick']],
            ];

            if (!$write) {
                continue;
            }

            try {
                $dbData = [];
                if ($g['rating'] !== null) $dbData['rating'] = $g['rating'];
                if ($g['color']  !== null) $dbData['color']  = $targetColor;
                if ($g['pick']   !== null) $dbData['pick']   = $targetPick;
                if (!empty($dbData)) {
                    $this->tagService->setMetadata((string) $fileId, $dbData);
                }
                $this->exifService->writeMetadata($file, $g['rating'], $g['color'], $g['pick'], $lang);
                $stats['healed']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->logger->warning("StarRate heal: Schreiben fehlgeschlagen für {$fileId}: " . $e->getMessage());
            }
        }

        return ['write_xmp' => true, 'stats' => $stats, 'details' => $details];
    }

    /**
     * Hängt einen Eintrag an den Gast-Log an und trimmt ihn auf MAX_ENTRIES.
     */
    private function appendToLog(string $ownerId, string $token, array $entry): void
    {
        $key = self::CONFIG_LOG . '_' . $token;
        $raw = $this->config->getUserValue($ownerId, self::APP_ID, $key, '[]');
        $log = json_decode($raw, true);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = $entry;
        if (count($log) > self::GUEST_LOG_MAX_ENTRIES) {
            $log = array_slice($log, -self::GUEST_LOG_MAX_ENTRIES);
        }
        $this->config->setUserValue($ownerId, self::APP_ID, $key, json_encode($log));
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Bereinigt einen relativen Unterpfad gegen Directory-Traversal-Angriffe.
     * Liefert immer entweder '' oder einen Pfad der mit '/' beginnt.
     */
    private function sanitizeSubPath(string $subPath): string
    {
        if ($subPath === '' || $subPath === '/') {
            return '';
        }

        $parts  = explode('/', str_replace('\\', '/', $subPath));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                // Traversal-Versuch → einfach ignorieren
                continue;
            }
            $result[] = $part;
        }
        return $result ? '/' . implode('/', $result) : '';
    }

    private function loadAllShares(string $userId): array
    {
        $raw    = $this->config->getUserValue($userId, self::APP_ID, self::CONFIG_SHARES, '{}');
        $shares = json_decode($raw, true);
        return is_array($shares) ? $shares : [];
    }

    private function saveShares(string $userId, array $shares): void
    {
        $this->config->setUserValue($userId, self::APP_ID, self::CONFIG_SHARES, json_encode($shares));
    }

    private function validatePermissions(string $permissions): void
    {
        if (!in_array($permissions, [self::PERM_VIEW, self::PERM_RATE], true)) {
            throw new \InvalidArgumentException(
                "Ungültige Berechtigung: {$permissions}. Erlaubt: view, rate"
            );
        }
    }

    /**
     * !!! SECURITY-KRITISCHE DUPLIKATION mit GalleryController::listImagesRecursiveFromDb.
     *
     * Beide Methoden müssen exakt gleich filtern (Storage-Scope, Hidden-Folder,
     * MIME-Whitelist, Hard-Limit). Wenn sie auseinanderdriften, riskieren wir,
     * dass ein Gast mehr Dateien zu sehen bekommt als der Owner sich beim
     * Anlegen des Shares vorstellt — z.B. Hidden-NC-Verzeichnisse oder
     * Mounts aus anderen Storages.
     *
     * Bewusste Code-Doppelung statt Service-Extraction für V1.3.1: bei jeder
     * Änderung an einer Stelle MUSS die andere mit angepasst werden, und der
     * Diff fällt im Review auf. Ein versehentliches "nur am Owner-Pfad
     * gefixt"-Refactor ist hier gefährlicher als die paar Zeilen Doppelung.
     *
     * Refactor-TODO: irgendwann nach 1.3.1 in einen ImageListingService
     * extrahieren, mit Tests die beide Aufrufer gegen exakt gleiche
     * Erwartungen prüfen.
     *
     * Limit für Guest 25k wie beim Owner — verhindert Memory-Explosion bei
     * recursive=true auf großen Trees.
     */
    private function listImagesRecursiveForShare(Folder $folder, int $depth): array
    {
        $RECURSIVE_HARD_LIMIT = 25000;

        $storageId    = $folder->getStorage()->getCache()->getNumericStorageId();
        $internalPath = $folder->getInternalPath();
        $pathPrefix   = ($internalPath === '' ? '' : $internalPath . '/') . '%';

        $qb = $this->db->getQueryBuilder();
        $qb->select('fc.fileid', 'fc.name', 'fc.path', 'fc.size', 'fc.mtime', 'mt.mimetype')
            ->from('filecache', 'fc')
            ->innerJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('mt.id', 'fc.mimetype'))
            ->where($qb->expr()->eq('fc.storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter($pathPrefix)))
            ->andWhere($qb->expr()->in('mt.mimetype', $qb->createNamedParameter(self::GUEST_MIME, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            // Hidden-Pfade ausschließen — sicherheitskritisch
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%/.%')))
            ->setMaxResults($RECURSIVE_HARD_LIMIT);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $rootInternalPrefix = $internalPath === '' ? 'files' : $internalPath;

        $images = [];
        foreach ($rows as $row) {
            if (!RawPreviewExtractor::isListable($row['mimetype'], $row['name'])) {
                continue;
            }
            $internalRelative = ltrim(substr($row['path'], strlen($rootInternalPrefix)), '/');
            $images[] = [
                'id'       => (int) $row['fileid'],
                'name'     => $row['name'],
                'relPath'  => $internalRelative,
                'size'     => (int) $row['size'],
                'mtime'    => (int) $row['mtime'],
                'groupKey' => $depth > 0 ? self::pathPrefix($internalRelative, $depth) : '',
            ];
        }

        // Sortierung erfolgt zentral in getImagesForShare (Owner-Präferenz,
        // groupKey als Primärschlüssel) — siehe sortImages().
        return array_values($images);
    }

    /**
     * Sortiert Bild-Arrays nach der Owner-Einstellung (name|mtime|size, asc|desc).
     * Ein vorhandener `groupKey` (Pfad-Präfix bei rekursiven Shares mit depth>=1)
     * bleibt Primärschlüssel, damit Items desselben Ordners zusammenbleiben —
     * spiegelt GalleryController::listImages.
     *
     * @param list<array{name: string, mtime: int, size: int, groupKey?: string}> $images
     */
    private function sortImages(array &$images, string $sort, string $order): void
    {
        usort($images, static function (array $a, array $b) use ($sort, $order): int {
            $ga = $a['groupKey'] ?? '';
            $gb = $b['groupKey'] ?? '';
            $cmp = ($ga !== '' || $gb !== '') ? strcasecmp($ga, $gb) : 0;
            if ($cmp !== 0) return $cmp;

            $cmp = match ($sort) {
                'mtime' => $a['mtime'] <=> $b['mtime'],
                'size'  => $a['size']  <=> $b['size'],
                default => strcasecmp($a['name'], $b['name']),
            };
            return $order === 'desc' ? -$cmp : $cmp;
        });
        $images = array_values($images);
    }

    /**
     * Liefert die ersten N '/'-Segmente eines Pfads (ohne Dateiname).
     * Spiegelt GalleryController::pathPrefix.
     */
    private static function pathPrefix(string $relPath, int $depth): string
    {
        $segments = explode('/', $relPath);
        array_pop($segments);
        if ($segments === []) return '';
        return implode('/', array_slice($segments, 0, $depth));
    }
}

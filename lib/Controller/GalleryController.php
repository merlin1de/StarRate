<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\RawPreviewExtractor;
use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IPreview as IPreviewManager;
use Psr\Log\LoggerInterface;

class GalleryController extends Controller
{
    use StarRateControllerTrait;

    // Unterstützte Bildformate
    private const SUPPORTED_MIME = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/tiff',
        'image/heic',
        'image/heif',
        RawPreviewExtractor::MIME,
    ];

    // 5 Minuten — Listen werden bei add/remove im Ordner unscharf, der Trade-off
    // ist gut: spürbarer Speedup bei recursiven Trees ohne nennenswerte Stale-
    // Daten in normalen Workflows.
    private const LIST_CACHE_TTL = 300;

    // Hartgrenze für rekursive Such-Ergebnisse — schützt vor OOM bei Megaordnern
    // ohne Verlust für realistische Foto-Workflows. Bei Erreichen: Liste wird auf
    // dieses Limit getrimmt; Frontend kann später eine Truncation-Warnung zeigen.
    private const RECURSIVE_HARD_LIMIT = 25000;

    private ?ICache $listCache = null;

    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly IRootFolder  $rootFolder,
        private readonly IUserSession $userSession,
        private readonly TagService   $tagService,
        private readonly IPreviewManager $previewManager,
        private readonly IURLGenerator   $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly ICacheFactory   $cacheFactory,
        private readonly IDBConnection   $db,
        private readonly UserSettings    $userSettings,
    ) {
        parent::__construct($appName, $request);
    }

    private function getListCache(): ICache
    {
        return $this->listCache ??= $this->cacheFactory->createDistributed('starrate-gallery');
    }

    // ─── Seiten ───────────────────────────────────────────────────────────────

    /**
     * Hauptseite der App — lädt Vue.js SPA.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index', []);
    }

    /**
     * Ordner-Ansicht — wird clientseitig durch Vue Router gehandhabt.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folder(string $path): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index', []);
    }

    // ─── API: Bilder abrufen ─────────────────────────────────────────────────

    /**
     * Gibt Bilder im angegebenen Ordner zurück, inkl. Metadaten.
     *
     * GET /api/images?path=/Fotos/2024&sort=name&order=asc
     *               &recursive=1&depth=2&limit=500&offset=0
     *
     * recursive: 1 = alle Bilder unterhalb von path inkludieren (kein Tiefen-Cap)
     * depth: 0..4 — Sortier-Modifier. depth>=1 sortiert nach Pfad-Präfix der
     *        ersten N Segmente (relativ zum Recursion-Root), dann nach user-sort.
     *        depth=0 (Default) = reine User-Sortierung. NUR wirksam bei recursive.
     * limit/offset: Slicing für Pagination. limit=0 (Default) = unlimitiert.
     *
     * @return DataResponse<array{
     *     images: array, folders: array,
     *     folder: string, total: int, offset: int, limit: int
     * }>
     */
    #[NoAdminRequired]
    public function images(): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $path  = $this->request->getParam('path', '/');
        $sort  = $this->request->getParam('sort', 'name');   // name | mtime | size
        $order = $this->request->getParam('order', 'asc');   // asc | desc
        $recursive = filter_var($this->request->getParam('recursive', false), FILTER_VALIDATE_BOOLEAN);
        $depth     = max(0, min(4, (int) $this->request->getParam('depth', 0)));
        $limit     = max(0, (int) $this->request->getParam('limit', 0));
        $offset    = max(0, (int) $this->request->getParam('offset', 0));

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folder     = $path === '/' ? $userFolder : $userFolder->get(ltrim($path, '/'));

            if (!($folder instanceof Folder)) {
                return new DataResponse(['error' => 'Path is not a folder'], Http::STATUS_BAD_REQUEST);
            }

            // Vollständige sortierte Liste — gecached (siehe getListCache()).
            // Metadaten werden bewusst NICHT mit gecached: sie ändern sich
            // häufig (jede Bewertung), die Liste hingegen nur bei add/remove.
            $allImages = $this->listImagesCached($folder, $userFolder, $sort, $order, $recursive, $depth);
            // RAWs sind opt-in. Gefiltert wird nach dem Cache, damit der Schalter
            // ohne Cache-Invalidierung sofort wirkt.
            if (empty($this->userSettings->getSettings($userId)['show_raw'])) {
                $allImages = array_values(array_filter(
                    $allImages,
                    static fn(array $img): bool => $img['mimetype'] !== RawPreviewExtractor::MIME,
                ));
            }
            $total     = count($allImages);

            // Pagination-Slicing
            $slice = $limit > 0
                ? array_slice($allImages, $offset, $limit)
                : array_slice($allImages, $offset);

            // Metadaten als Batch laden — nur für die sichtbare Slice
            $sliceIds = array_map(static fn(array $img): string => (string) $img['id'], $slice);
            $metadata = $sliceIds === []
                ? []
                : $this->tagService->getMetadataBatch($sliceIds);

            foreach ($slice as &$img) {
                $id         = (string) $img['id'];
                $meta       = $metadata[$id] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
                $img['rating'] = $meta['rating'];
                $img['color']  = $meta['color'];
                $img['pick']   = $meta['pick'];
            }
            unset($img);

            // Unterordner: nur direkte Kinder, immer alphabetisch (siehe Design-
            // Notes: Ordner-Sort folgt nicht dem User-Sort).
            $folders = [];
            foreach ($folder->getDirectoryListing() as $node) {
                if ($node instanceof Folder && $node->getName()[0] !== '.') {
                    $folders[] = ['name' => $node->getName(), 'path' => $path === '/' ? '/' . $node->getName() : $path . '/' . $node->getName()];
                }
            }
            usort($folders, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return new DataResponse([
                'images'  => $slice,
                'folders' => $folders,
                'folder'  => $path,
                'total'   => $total,
                'offset'  => $offset,
                'limit'   => $limit,
            ]);

        } catch (NotFoundException) {
            return new DataResponse(['error' => "Folder not found: {$path}"], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('StarRate GalleryController::images – ' . $e->getMessage());
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── API: Thumbnail ───────────────────────────────────────────────────────

    /**
     * Liefert ein Thumbnail für eine Datei (nutzt NC Preview-API).
     *
     * GET /api/thumbnail/{fileId}?width=300&height=300
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thumbnail(int $fileId): Response
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $width  = (int) ($this->request->getParam('width', 300));
        $height = (int) ($this->request->getParam('height', 300));

        // Sicherheitsgrenzen
        $width  = min(max($width, 32), 1920);
        $height = min(max($height, 32), 1920);

        try {
            $file = $this->getFileById($userId, $fileId);
            if ($file === null) {
                return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
            }

            $preview = $this->previewManager->getPreview($file, $width, $height, true);

            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600); // 1 Stunde
            return $response;

        } catch (\Exception $e) {
            $this->logger->warning("StarRate: thumbnail error for {$fileId}: " . $e->getMessage());
            return new DataResponse(['error' => 'Preview not available'], Http::STATUS_NOT_FOUND);
        }
    }

    // ─── API: Vollbild-Preview ────────────────────────────────────────────────

    /**
     * Liefert ein hochauflösendes Preview für die Lupenansicht.
     *
     * GET /api/preview/{fileId}?width=1920&height=1200
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preview(int $fileId): Response
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $width  = (int) ($this->request->getParam('width', 1920));
        $height = (int) ($this->request->getParam('height', 1200));

        $width  = min(max($width, 100), 3840);
        $height = min(max($height, 100), 2160);

        try {
            $file = $this->getFileById($userId, $fileId);
            if ($file === null) {
                return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
            }

            $preview = $this->previewManager->getPreview($file, $width, $height, false);

            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(1800);
            return $response;

        } catch (\Exception $e) {
            $this->logger->warning("StarRate: preview error for {$fileId}: " . $e->getMessage());
            return new DataResponse(['error' => 'Preview not available'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * GET /api/download/{fileId} — Original-Datei des eingeloggten Users als
     * Download (Content-Disposition: attachment). Der eingeloggte User ist
     * Eigentümer und hat ohnehin das NC-Recht, seine Dateien herunterzuladen;
     * daher keine zusätzliche Beschränkung (anders als im Guest-Pfad).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(int $fileId): Response
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
        return $this->fileDownloadResponse($file);
    }

    /**
     * GET /api/download-zip?ids=1,2,3 — mehrere Original-Dateien des
     * eingeloggten Users als ZIP. NoCSRFRequired wie download() (per <a>-Klick
     * / Formular ausgelöst, kein requesttoken). Owner-Recht, daher keine
     * weitere Beschränkung ausser dem Mengen-Cap.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadZip(): Response
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $ids = $this->parseFileIds((string) $this->request->getParam('ids', ''));
        if ($ids === []) {
            return new DataResponse(['error' => 'No files'], Http::STATUS_BAD_REQUEST);
        }
        if (count($ids) > ShareService::MAX_ZIP_FILES) {
            return new DataResponse(['error' => 'Too many files'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $files = [];
        foreach ($ids as $id) {
            $file = $this->getFileById($userId, $id);
            if ($file !== null) {
                $files[] = $file;
            }
        }
        if ($files === []) {
            return new DataResponse(['error' => 'No files found'], Http::STATUS_NOT_FOUND);
        }
        return $this->buildZipResponse($files, 'starrate-export');
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Cache-Wrapper um listImages: liefert die vollständige sortierte Liste
     * (ohne Metadaten) aus der Distributed-Cache, sonst frisch generiert.
     *
     * Der Cache enthält bewusst nur Strukturdaten (id, name, path, size, mtime,
     * mimetype). Metadaten (rating/color/pick) werden pro Request frisch geholt,
     * weil sie sich häufig ändern. Die Liste selbst ändert sich nur bei add/
     * remove im Folder — TTL fängt das ab.
     */
    private function listImagesCached(
        Folder $folder, Folder $userFolder, string $sort, string $order,
        bool $recursive, int $depth,
    ): array {
        // v3: RAW-MIME-Typ ergänzt (v2-Listen enthalten keine NEFs).
        // v2: Cache-Format-Bump — neue Felder (relPath/groupKey) und neuer
        // Recursive-DB-Pfad (anderes path-Format als v1).
        $key = sprintf(
            'list-v3:%d:%s:%s:r%d:d%d',
            $folder->getId(), $sort, $order, $recursive ? 1 : 0, $depth,
        );

        $cached = $this->getListCache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $images = $this->listImages($folder, $userFolder, $sort, $order, $recursive, $depth);
        $this->getListCache()->set($key, $images, self::LIST_CACHE_TTL);
        return $images;
    }

    /**
     * Listet unterstützte Bilder in einem Ordner auf — mit optionaler Recursion
     * und Group-Depth-Sortierung.
     *
     * Recursive nutzt NCs Folder::searchByMime() pro MIME-Type und mergt anhand
     * der File-ID. Path-Filter ist implizit (searchByMime läuft im Subtree).
     *
     * Group-Depth (>=1, nur bei recursive sinnvoll) sortiert primär nach den
     * ersten N Pfad-Segmenten relativ zum Recursion-Root, sekundär nach User-
     * Sort. Items mit gleichem Pfad-Präfix landen dadurch nebeneinander, ohne
     * dass das Frontend Group-Header rendern muss.
     *
     * @return array<int, array{
     *     id: int, name: string, path: string, relPath: string,
     *     size: int, mtime: int, mimetype: string,
     *     groupKey: string, width: int|null, height: int|null
     * }>
     */
    private function listImages(
        Folder $folder, Folder $userFolder, string $sort, string $order,
        bool $recursive, int $depth,
    ): array {
        $rootPath = $folder->getPath();
        $userBase = $userFolder->getPath();

        $images = [];

        if ($recursive) {
            // Direct DB statt Folder::searchByMime: searchByMime allokiert für
            // jede Treffer-Datei ein vollwertiges Node-Objekt, was bei großen
            // Trees (10k+ Bilder) den PHP-Memory sprengt (gesehen mit 506 MB
            // bei /). Die direkte oc_filecache-Query liefert nur Roh-Rows und
            // baut daraus unsere kompakten Image-Arrays — Faktor ~10× weniger
            // Speicher.
            $images = $this->listImagesRecursiveFromDb($folder, $userBase, $depth);
        } else {
            foreach ($folder->getDirectoryListing() as $node) {
                if (!($node instanceof File)) continue;
                if (!in_array($node->getMimeType(), self::SUPPORTED_MIME, true)) continue;
                if (!RawPreviewExtractor::isListable($node->getMimeType(), $node->getName())) continue;
                $images[] = $this->buildImageEntry($node, $rootPath, $userBase, $depth);
            }
        }

        usort($images, function (array $a, array $b) use ($sort, $order): int {
            // Bei Group-Depth>=1 kommt der groupKey-Vergleich vor dem User-Sort,
            // damit Items mit gleichem Pfad-Präfix nebeneinander landen.
            $cmp = $a['groupKey'] !== '' || $b['groupKey'] !== ''
                ? strcasecmp($a['groupKey'], $b['groupKey'])
                : 0;
            if ($cmp !== 0) return $cmp;

            $cmp = match ($sort) {
                'mtime' => $a['mtime'] <=> $b['mtime'],
                'size'  => $a['size']  <=> $b['size'],
                default => strcasecmp($a['name'], $b['name']),
            };
            return $order === 'desc' ? -$cmp : $cmp;
        });

        return array_values($images);
    }

    /**
     * Direct-DB-Pfad für rekursive Suche: vermeidet Node-Allocation und damit
     * den OOM-Killer bei großen User-Roots. Liefert dieselbe Strukturform wie
     * buildImageEntry(), aber aus Roh-Rows von oc_filecache + oc_mimetypes.
     *
     * Scope: nur Dateien aus der Storage des angeforderten Folders. Geteilte
     * Mounts und externe Storages bleiben außen vor — entspricht dem typischen
     * 'meine eigenen Fotos rekursiv'-Use-Case und vermeidet permission-
     * Komplexität.
     */
    private function listImagesRecursiveFromDb(Folder $folder, string $userBase, int $depth): array
    {
        // Echte (storage, internalPath) des Recursion-Roots direkt aus dem
        // Filecache holen — NICHT aus der gewrappten Node-Sicht. Bei Group
        // Folders / Shares liefert getInternalPath() einen jail-relativen,
        // gekürzten Pfad (z.B. 'Bench' statt 'files/Bench'), der nicht zu den
        // roh in oc_filecache gespeicherten Pfaden passt → sonst 0 Treffer.
        // Der fileid-Lookup gibt die echte, jail-/mount-unabhängige Koordinate.
        $real = $this->resolveCacheLocation($folder->getId());
        if ($real === null) {
            return [];
        }
        [$storageId, $rootInternalPath] = $real;
        // LIKE-Metazeichen (% und _) im Ordnerpfad escapen, damit ein Ordner
        // wie 'My_Folder' nicht versehentlich Geschwister wie 'MyXFolder'
        // mitmatcht. Der angehängte '/%'-Wildcard bleibt bewusst unescaped.
        $pathPrefix = ($rootInternalPath === ''
            ? ''
            : $this->db->escapeLikeParameter($rootInternalPath) . '/') . '%';

        $qb = $this->db->getQueryBuilder();
        $qb->select('fc.fileid', 'fc.name', 'fc.path', 'fc.size', 'fc.mtime', 'mt.mimetype')
            ->from('filecache', 'fc')
            ->innerJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('mt.id', 'fc.mimetype'))
            ->where($qb->expr()->eq('fc.storage', $qb->createNamedParameter($storageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter($pathPrefix)))
            ->andWhere($qb->expr()->in('mt.mimetype', $qb->createNamedParameter(self::SUPPORTED_MIME, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            // Hidden-Ordner ausschließen: NC-interne Preview-Caches (.@__thumb,
            // .nc-trash, etc.) liegen im selben Storage, gehören aber nicht in
            // die Galerie. searchByMime hatte das implizit über Permissions,
            // direct SQL muss explizit filtern.
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%/.%')))
            ->setMaxResults(self::RECURSIVE_HARD_LIMIT);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Anzeige-Pfade aus dem User-Pfad des Recursion-Roots + relativem Rest
        // bauen — NICHT hart 'files/' strippen. $folder->getPath() enthält den
        // vollen, mount-korrekten User-Pfad inkl. evtl. Group-Folder-Mountpunkt
        // (z.B. '/BenchGF/Bench'); würde man stattdessen den rohen Storage-Pfad
        // ('files/Bench/...') ab 'files/' kürzen, ginge das Mount-Präfix
        // verloren und die Bilder wären nicht mehr auffindbar.
        $rootUserPath = rtrim(substr($folder->getPath(), strlen($userBase)), '/');
        $rootLen = strlen($rootInternalPath);

        $images = [];
        foreach ($rows as $row) {
            if (!RawPreviewExtractor::isListable($row['mimetype'], $row['name'])) {
                continue;
            }
            // Pfad relativ zum Recursion-Root, ohne führenden Slash.
            $relPath = ltrim(substr($row['path'], $rootLen), '/');

            $images[] = [
                'id'       => (int) $row['fileid'],
                'name'     => $row['name'],
                // User-folder-relativer Pfad mit führendem Slash, wie
                // buildImageEntry(): '/Photos/IMG.jpg' statt absolut.
                'path'     => $rootUserPath . '/' . $relPath,
                'relPath'  => $relPath,
                'size'     => (int) $row['size'],
                'mtime'    => (int) $row['mtime'],
                'mimetype' => $row['mimetype'],
                'groupKey' => $depth > 0 ? self::pathPrefix($relPath, $depth) : '',
                'width'    => null,
                'height'   => null,
            ];
        }
        return $images;
    }

    /**
     * Liefert die echte (numeric storage id, storage-interner Pfad) eines
     * Knotens anhand seiner fileid — direkt aus oc_filecache, unabhängig von
     * Jail-/Mount-Wrappern. Group Folders und Shares zeigen über
     * getInternalPath() eine gejailte, gekürzte Pfad-Sicht, die nicht zu den
     * roh gespeicherten Filecache-Pfaden passt; der fileid-Lookup umgeht das.
     *
     * Bewusst NUR dieser eine Storage: verschachtelte Fremd-Mounts (External
     * Storage etc.) unterhalb des Ordners werden nicht verfolgt — siehe
     * docs/recursive-folders.*.md (Limitations). Das hält die Suche auf einem
     * einzigen indizierten Range-Scan und damit deterministisch und schnell.
     *
     * @return array{0: int, 1: string}|null [storageId, internalPath] oder null
     */
    private function resolveCacheLocation(int $fileId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('storage', 'path')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if ($row === false) {
            return null;
        }
        return [(int) $row['storage'], (string) $row['path']];
    }

    /**
     * Baut ein einzelnes Image-Array auf — inkl. relativem Pfad und groupKey
     * für Tooltip/dynamischen Breadcrumb im Frontend.
     */
    private function buildImageEntry(File $node, string $rootPath, string $userBase, int $depth): array
    {
        $absPath = $node->getPath();
        // Relativer Pfad ab Recursion-Root, ohne führenden Slash. Bei nicht-
        // recursiven Aufrufen entspricht das schlicht dem Dateinamen.
        $relPath = ltrim(substr($absPath, strlen($rootPath)), '/');

        return [
            'id'       => $node->getId(),
            'name'     => $node->getName(),
            'path'     => substr($absPath, strlen($userBase)) ?: '/',
            'relPath'  => $relPath,
            'size'     => $node->getSize(),
            'mtime'    => $node->getMtime(),
            'mimetype' => $node->getMimeType(),
            'groupKey' => $depth > 0 ? self::pathPrefix($relPath, $depth) : '',
            'width'    => null,
            'height'   => null,
        ];
    }

    /**
     * Liefert die ersten N '/'-Segmente eines Pfads (ohne abschließenden Slash).
     * Beispiel: pathPrefix('2025/Hochzeit/IMG_001.jpg', 2) → '2025/Hochzeit'
     * Bei weniger als N Segmenten wird der ganze Dirname zurückgegeben.
     */
    private static function pathPrefix(string $relPath, int $depth): string
    {
        $segments = explode('/', $relPath);
        // Letztes Segment ist der Dateiname → für die Gruppen-Bildung weglassen.
        array_pop($segments);
        if ($segments === []) return '';
        return implode('/', array_slice($segments, 0, $depth));
    }

}

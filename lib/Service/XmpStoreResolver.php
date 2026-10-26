<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;

/**
 * Einzige Stelle, die entscheidet, wohin die XMP-Bewertung einer Datei gehört.
 * Owner-Bewertungen (RatingController) und Gast-Bewertungen (ShareService) nutzen
 * beide diesen Resolver — so können die beiden Pfade nicht auseinanderlaufen.
 */
class XmpStoreResolver
{
    public const JPEG_MIME = ['image/jpeg', 'image/jpg'];

    public function __construct(
        private readonly ExifService $exifService,
        private readonly SidecarService $sidecarService,
    ) {}

    /**
     * JPEG → eingebettetes XMP, RAW → Sidecar, sonst / bei write_xmp aus → null.
     */
    public function forFile(File $file, array $settings): ?XmpStore
    {
        if (empty($settings['write_xmp'])) {
            return null;
        }
        if (in_array($file->getMimeType(), self::JPEG_MIME, true)) {
            return $this->exifService;
        }
        return SidecarService::appliesTo($file, $settings) ? $this->sidecarService : null;
    }
}

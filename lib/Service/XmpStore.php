<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;

/**
 * Ein Ort, an dem die XMP-Bewertung einer Datei liegt: eingebettet im JPEG
 * (ExifService) oder als Sidecar neben einer RAW-Datei (SidecarService).
 *
 * Welcher Store für eine Datei gilt, entscheidet ausschließlich XmpStoreResolver.
 */
interface XmpStore
{
    /**
     * @return array{rating: int, label: string|null, pick: string}
     */
    public function readMetadata(File $file): array;

    /**
     * null = Feld unverändert lassen, '' (Label) bzw. 'none' (Pick) = entfernen.
     *
     * @throws \RuntimeException wenn nicht geschrieben werden kann
     */
    public function writeMetadata(
        File $file,
        ?int $rating = null,
        ?string $label = null,
        ?string $pick = null,
        string $lang = XmpService::LABEL_LANG_EN,
    ): void;
}

<?php

declare(strict_types=1);

namespace OCA\StarRate\Preview;

use OCA\StarRate\Service\RawPreviewExtractor;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud-Preview-Provider für Kamera-RAWs (eingebettetes JPEG).
 *
 * Registriert in Application::register() — damit funktionieren RAW-Vorschauen
 * überall, wo NC Previews zeigt: StarRate-Grid, Loupe, Gast-Galerien und Files-App.
 */
class RawPreviewProvider implements IProviderV2
{
    public const MIME_REGEX = '/^image\/x-dcraw$/';

    public function __construct(
        private readonly RawPreviewExtractor $extractor,
        private readonly LoggerInterface $logger,
    ) {}

    public function getMimeType(): string
    {
        return self::MIME_REGEX;
    }

    public function isAvailable(FileInfo $file): bool
    {
        return $file->getSize() > 0 && RawPreviewExtractor::supports($file->getName());
    }

    public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $jpeg = $this->extractor->extractPreview($file, $maxX, $maxY);
        } catch (\Throwable $e) {
            $this->logger->warning('StarRate: RAW preview failed for ' . $file->getName() . ': ' . $e->getMessage());
            return null;
        }
        if ($jpeg === null) {
            $this->logger->debug('StarRate: no embedded preview in ' . $file->getName());
            return null;
        }

        $image = new Image();
        $image->loadFromData($jpeg);
        return $image->valid() ? $image : null;
    }
}

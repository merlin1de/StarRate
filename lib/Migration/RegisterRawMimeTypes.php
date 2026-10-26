<?php

declare(strict_types=1);

namespace OCA\StarRate\Migration;

use OCA\StarRate\Service\RawPreviewExtractor;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Stellt schon hochgeladene Dateien mit RAW-Endungen, die Nextcloud selbst nicht
 * kennt (CR3), im Filecache auf image/x-dcraw um. Neue Uploads erkennt NC über
 * das Laufzeit-Mapping aus Application::registerRawMimeTypes().
 *
 * Nur ein UPDATE auf oc_filecache per Endung — keine Datei wird gelesen oder geändert.
 */
class RegisterRawMimeTypes implements IRepairStep
{
    public function __construct(
        private readonly IMimeTypeLoader $mimeTypeLoader,
    ) {}

    public function getName(): string
    {
        return 'StarRate: register camera RAW file types (CR3)';
    }

    public function run(IOutput $output): void
    {
        $mimeId = $this->mimeTypeLoader->getId(RawPreviewExtractor::MIME);
        foreach (array_keys(RawPreviewExtractor::EXTRA_MAPPINGS) as $ext) {
            $updated = $this->mimeTypeLoader->updateFilecache($ext, $mimeId);
            if ($updated > 0) {
                $output->info("StarRate: {$updated} .{$ext} file(s) recognised as camera RAW.");
            }
        }
    }
}

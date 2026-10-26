<?php

declare(strict_types=1);

namespace OCA\StarRate\Migration;

use OCA\StarRate\Service\RawPreviewExtractor;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Stellt schon hochgeladene Dateien mit RAW-Endungen, die Nextcloud selbst nicht
 * kennt (CR3), im Filecache auf image/x-dcraw um. Neue Uploads erkennt NC über
 * das Laufzeit-Mapping aus Application::registerRawMimeTypes().
 *
 * Nur ein UPDATE auf oc_filecache per Endung — keine Datei wird gelesen oder geändert.
 * Jede Endung genau einmal (merkt sich die erledigten in der App-Config): das UPDATE
 * läuft per LIKE über die ganze Filecache-Tabelle und soll nicht jedes Update bremsen.
 */
class RegisterRawMimeTypes implements IRepairStep
{
    public const CONFIG_KEY = 'raw_mimetypes_registered';

    public function __construct(
        private readonly IMimeTypeLoader $mimeTypeLoader,
        private readonly IAppConfig $appConfig,
    ) {}

    public function getName(): string
    {
        return 'StarRate: register camera RAW file types (CR3)';
    }

    public function run(IOutput $output): void
    {
        $done = array_filter(explode(',', $this->appConfig->getValueString('starrate', self::CONFIG_KEY)));
        $todo = array_diff(array_keys(RawPreviewExtractor::EXTRA_MAPPINGS), $done);
        if ($todo === []) {
            return;
        }

        $mimeId = $this->mimeTypeLoader->getId(RawPreviewExtractor::MIME);
        foreach ($todo as $ext) {
            $updated = $this->mimeTypeLoader->updateFilecache($ext, $mimeId);
            if ($updated > 0) {
                $output->info("StarRate: {$updated} .{$ext} file(s) recognised as camera RAW.");
            }
            $done[] = $ext;
        }
        $this->appConfig->setValueString('starrate', self::CONFIG_KEY, implode(',', $done));
    }
}

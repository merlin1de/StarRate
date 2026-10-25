<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Liest und schreibt XMP-Sidecars neben RAW-Dateien (DSC_0001.NEF → DSC_0001.xmp).
 *
 * Die RAW-Datei selbst wird nie verändert. Benennung wie Lightroom / Camera Raw
 * (gleicher Basisname, `.xmp`) — Lightroom Classic liest sie per „Metadaten →
 * Metadaten aus Datei lesen“. Darktable-Sidecars (`DSC_0001.NEF.xmp`) werden
 * aktualisiert, wenn kein Lightroom-Sidecar existiert.
 *
 * Ein vorhandenes Sidecar wird in-place gepatcht — Entwicklungseinstellungen,
 * Keywords etc. bleiben erhalten. Angefasst werden nur xmp:Rating, xmp:Label
 * (+ photoshop:LabelColor) und xmpDM:pick.
 */
class SidecarService
{
    public function __construct(
        private readonly ExifService $exifService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Sidecar nur für RAWs, und nur wenn der User XMP-Schreiben + RAW-Sidecars aktiviert hat.
     */
    public static function appliesTo(File $file, array $settings): bool
    {
        return $file->getMimeType() === RawPreviewExtractor::MIME
            && !empty($settings['write_xmp'])
            && !empty($settings['write_raw_sidecar']);
    }

    /**
     * Mögliche Sidecar-Namen in Prioritätsreihenfolge.
     *
     * @return list<string>
     */
    public static function sidecarNames(string $rawName): array
    {
        $base = pathinfo($rawName, PATHINFO_FILENAME);
        return [$base . '.xmp', $base . '.XMP', $rawName . '.xmp', $rawName . '.XMP'];
    }

    /**
     * Liest Rating/Label/Pick aus dem Sidecar (leer, wenn keins existiert oder es unlesbar ist).
     *
     * @return array{rating: int, label: string|null, pick: string}
     */
    public function readMetadata(File $raw): array
    {
        $sidecar = $this->findSidecar($raw);
        try {
            $xmp = $sidecar?->getContent() ?? '';
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: cannot read sidecar {$sidecar->getName()}: " . $e->getMessage());
            $xmp = '';
        }
        return $this->exifService->readMetadataFromXmp($xmp);
    }

    /**
     * Semantik wie ExifService::writeMetadata() (null = unverändert, '' / 'none' = entfernen).
     * Ein leeres Sidecar wird nicht neu angelegt (z.B. beim Zurücksetzen).
     *
     * @throws \RuntimeException wenn das Sidecar nicht geschrieben werden kann
     */
    public function writeMetadata(
        File $raw,
        ?int $rating,
        ?string $label,
        ?string $pick,
        string $lang = XmpService::LABEL_LANG_EN,
    ): void {
        $sidecar = $this->findSidecar($raw);
        if ($sidecar !== null) {
            $sidecar->putContent(
                $this->exifService->writeMetadataToXmp($sidecar->getContent(), $rating, $label, $pick, $lang)
            );
            return;
        }

        if (!$rating && !$label && ($pick === null || $pick === 'none')) {
            return;
        }
        $parent = $raw->getParent();
        if (!$parent instanceof Folder) {
            throw new \RuntimeException('RAW file has no parent folder: ' . $raw->getName());
        }
        $parent->newFile(
            self::sidecarNames($raw->getName())[0],
            $this->exifService->writeMetadataToXmp(null, $rating, $label, $pick, $lang),
        );
    }

    private function findSidecar(File $raw): ?File
    {
        try {
            $parent = $raw->getParent();
        } catch (NotFoundException) {
            return null;
        }
        foreach (self::sidecarNames($raw->getName()) as $name) {
            if ($parent->nodeExists($name)) {
                $node = $parent->get($name);
                if ($node instanceof File) {
                    return $node;
                }
            }
        }
        return null;
    }
}

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
 *
 * DNG bekommt kein Sidecar: Lightroom / Camera Raw speichern Metadaten bei DNG
 * in der Datei selbst und ignorieren ein `.xmp` daneben.
 */
class SidecarService implements XmpStore
{
    private const NO_SIDECAR_EXTENSIONS = ['dng'];

    // Ab dem wievielten Sidecar-Lookup im selben Ordner (Batch-Bewertung) das
    // Ordner-Listing einmal geladen wird, statt pro Datei bis zu 4 Einzel-Lookups.
    private const LISTING_THRESHOLD = 3;

    /** @var array<int, array<string, File>> Ordner-ID → Sidecar-Name → Node */
    private array $listings = [];
    /** @var array<int, int> Ordner-ID → bisherige Lookups */
    private array $lookups = [];

    public function __construct(
        private readonly ExifService $exifService,
        private readonly TagService $tagService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Sidecar nur für RAWs, und nur wenn der User RAWs anzeigt und XMP-Schreiben +
     * RAW-Sidecars aktiviert hat. Wer RAWs nicht eingeschaltet hat, dessen
     * Sidecars fasst StarRate nie an (auch nicht beim Löschen/Umbenennen).
     */
    public static function appliesTo(File $file, array $settings): bool
    {
        return self::hasSidecar($file)
            && !empty($settings['show_raw'])
            && !empty($settings['write_xmp'])
            && !empty($settings['write_raw_sidecar']);
    }

    /**
     * RAW-Datei, deren Metadaten in ein Sidecar gehören (alle RAWs außer DNG).
     */
    public static function hasSidecar(File $file): bool
    {
        return $file->getMimeType() === RawPreviewExtractor::MIME
            && !in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), self::NO_SIDECAR_EXTENSIONS, true);
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
    public function readMetadata(File $file): array
    {
        $sidecar = $this->findSidecar($file);
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
     *
     * Ein vorhandenes Sidecar wird gepatcht. Ein neues (oder leeres) startet vom
     * aktuellen DB-Stand — sonst würde z.B. ein reines Farb-Update Rating=0 schreiben
     * und das Self-Healing danach das DB-Rating überschreiben. Ein Sidecar ohne
     * Bewertung wird nicht neu angelegt (z.B. beim Zurücksetzen).
     *
     * @throws \RuntimeException wenn das Sidecar nicht geschrieben werden kann
     */
    public function writeMetadata(
        File $file,
        ?int $rating = null,
        ?string $label = null,
        ?string $pick = null,
        string $lang = XmpService::LABEL_LANG_EN,
    ): void {
        $sidecar = $this->findSidecar($file);
        $content = $sidecar?->getContent() ?? '';
        if (trim($content) !== '') {
            $sidecar->putContent($this->exifService->writeMetadataToXmp($content, $rating, $label, $pick, $lang));
            return;
        }

        $db     = $this->tagService->getMetadata((string) $file->getId());
        $rating = $rating ?? $db['rating'];
        $label  = $label  ?? ($db['color'] ?? '');
        $pick   = $pick   ?? $db['pick'];
        if (!$rating && $label === '' && in_array($pick, ['', 'none'], true)) {
            return;
        }

        $xmp = $this->exifService->writeMetadataToXmp(null, $rating, $label, $pick, $lang);
        if ($sidecar !== null) {
            $sidecar->putContent($xmp);
            return;
        }

        $parent = $file->getParent();
        $name   = self::sidecarNames($file->getName())[0];
        $node   = $parent->newFile($name, $xmp);
        if (isset($this->listings[$parent->getId()]) && $node instanceof File) {
            $this->listings[$parent->getId()][$name] = $node;
        }
    }

    /**
     * RAW wurde gelöscht → Sidecar mitlöschen (landet wie die RAW im Papierkorb).
     * Sonst erbt eine spätere Datei gleichen Namens (Kamera-Zähler läuft über)
     * über das Self-Healing die alte Bewertung.
     */
    public function deleteSidecarOf(File $raw): void
    {
        $sidecar = $this->findSidecar($raw);
        if ($sidecar === null) {
            return;
        }
        $this->forgetListing($sidecar);
        $sidecar->delete();
    }

    /**
     * RAW wurde umbenannt/verschoben → Sidecar mitnehmen (gleiche Namenskonvention
     * wie vorher). Ein am Ziel schon vorhandenes Sidecar wird nie überschrieben.
     */
    public function moveSidecarWith(File $source, File $target): void
    {
        $sidecar = $this->findSidecar($source);
        if ($sidecar === null) {
            return;
        }
        $variant = array_search($sidecar->getName(), self::sidecarNames($source->getName()), true);
        $newName = self::sidecarNames($target->getName())[$variant === false ? 0 : $variant];
        $parent  = $target->getParent();
        $newPath = $parent->getPath() . '/' . $newName;

        if ($newPath === $sidecar->getPath() || $parent->nodeExists($newName)) {
            return;
        }
        $this->forgetListing($sidecar);
        $sidecar->move($newPath);
    }

    private function findSidecar(File $raw): ?File
    {
        try {
            $parent = $raw->getParent();
        } catch (NotFoundException) {
            return null;
        }
        $listing = $this->listingFor($parent);

        foreach (self::sidecarNames($raw->getName()) as $name) {
            if ($listing !== null) {
                $node = $listing[$name] ?? null;
            } else {
                try {
                    $node = $parent->get($name);
                } catch (NotFoundException) {
                    $node = null;
                }
            }
            if ($node instanceof File) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Ordner-Listing (nur .xmp-Dateien) ab dem LISTING_THRESHOLD-ten Lookup im selben
     * Ordner — bei Einzelbewertungen bleibt es bei direkten Lookups.
     *
     * @return array<string, File>|null
     */
    private function listingFor(Folder $folder): ?array
    {
        $id = $folder->getId();
        if (isset($this->listings[$id])) {
            return $this->listings[$id];
        }
        $this->lookups[$id] = ($this->lookups[$id] ?? 0) + 1;
        if ($this->lookups[$id] < self::LISTING_THRESHOLD) {
            return null;
        }

        $map = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) === 'xmp') {
                $map[$node->getName()] = $node;
            }
        }
        return $this->listings[$id] = $map;
    }

    private function forgetListing(File $sidecar): void
    {
        try {
            unset($this->listings[$sidecar->getParent()->getId()]);
        } catch (NotFoundException) {
            // Ordner weg → kein Cache-Eintrag mehr relevant
        }
    }
}

<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * „In Dateien schreiben“: vergleicht die StarRate-Bewertung (DB) mit dem XMP der
 * Datei (JPEG eingebettet / RAW-Sidecar) und schreibt StarRate-Werte zurück.
 *
 * Wozu: Lightroom mit „Änderungen automatisch in XMP speichern“ überschreibt
 * Sidecars mit seinem Katalogstand. Hat der Katalog die StarRate-Bewertungen
 * noch nicht gelesen, fehlen sie danach in der Datei — und „Metadaten aus Datei
 * lesen“ findet nichts. Hiermit stellt man den StarRate-Stand in den Dateien
 * wieder her.
 *
 * Es werden nur StarRate-Werte geschrieben, nie Werte in der Datei gelöscht:
 * - missing:   StarRate hat einen Wert, die Datei keinen   → wird ergänzt
 * - different: beide haben einen Wert, er weicht ab        → nur mit $overwrite
 * - extra:     nur die Datei hat einen Wert               → bleibt immer stehen
 *
 * Dateien mit Werten, die StarRate nicht versteht (xmp:Rating="-1", eigenes
 * Label-Set, …), werden komplett ausgelassen: der XMP-Patch schreibt immer alle
 * drei Felder neu und würde solche Werte sonst löschen.
 */
class XmpSyncService
{
    public const FIELDS = ['rating', 'color', 'pick'];

    public const KIND_MISSING   = 'missing';
    public const KIND_DIFFERENT = 'different';
    public const KIND_EXTRA     = 'extra';

    public const RESULT_OK         = 'ok';
    public const RESULT_UNREADABLE = 'unreadable';
    public const RESULT_WRITTEN    = 'written';
    public const RESULT_UNCHANGED  = 'unchanged';
    public const RESULT_NO_STORE   = 'no_store';
    public const RESULT_FAILED     = 'failed';
    /** Datei enthält Werte, die StarRate nicht lesen kann → nie anfassen */
    public const RESULT_SKIPPED    = 'skipped';

    public function __construct(
        private readonly XmpStoreResolver $xmpStores,
        private readonly XmpService $xmpService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Feldweiser Vergleich DB ↔ Datei. Leer heißt: 0★, kein Label, kein Pick.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @param array{rating: int, label: string|null, pick: string} $xmp
     * @return array<string, array{db: int|string|null, file: int|string|null, kind: string}>
     */
    public static function diff(array $db, array $xmp): array
    {
        $pairs = [
            'rating' => [$db['rating'], $xmp['rating'], 0],
            'color'  => [$db['color'],  $xmp['label'],  null],
            'pick'   => [$db['pick'],   $xmp['pick'],   'none'],
        ];

        $diffs = [];
        foreach ($pairs as $field => [$dbVal, $fileVal, $empty]) {
            if ($dbVal === $fileVal) {
                continue;
            }
            $kind = match (true) {
                $fileVal === $empty => self::KIND_MISSING,
                $dbVal === $empty   => self::KIND_EXTRA,
                default             => self::KIND_DIFFERENT,
            };
            $diffs[$field] = ['db' => $dbVal, 'file' => $fileVal, 'kind' => $kind];
        }
        return $diffs;
    }

    /**
     * Welche Felder ein Schreiblauf anfassen würde.
     *
     * @param array<string, array{kind: string}> $diffs
     * @param list<string> $fields
     * @return list<string>
     */
    public static function fieldsToWrite(array $diffs, array $fields, bool $overwrite): array
    {
        $out = [];
        foreach ($diffs as $field => $d) {
            if (!in_array($field, $fields, true)) {
                continue;
            }
            if ($d['kind'] === self::KIND_MISSING || ($overwrite && $d['kind'] === self::KIND_DIFFERENT)) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * Liest das XMP der Datei und vergleicht es mit $db.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @return array{status: string, diffs: array<string, array{db: mixed, file: mixed, kind: string}>, unreadable?: list<string>}
     *         status: ok | no_store (kein XMP-Ziel, z.B. PNG/DNG oder Einstellung aus)
     *                 | skipped (Datei enthält unlesbare Werte) | unreadable (Lesefehler)
     */
    public function compare(File $file, array $db, array $settings): array
    {
        $store = $this->xmpStores->forFile($file, $settings);
        if ($store === null) {
            return ['status' => self::RESULT_NO_STORE, 'diffs' => []];
        }
        try {
            [$xmp, $unknown] = $this->inspect($store, $file);
        } catch (\Exception $e) {
            $this->logger->info("StarRate xmp-sync: cannot read XMP of {$file->getId()}: " . $e->getMessage());
            return ['status' => self::RESULT_UNREADABLE, 'diffs' => []];
        }
        if ($unknown !== []) {
            return ['status' => self::RESULT_SKIPPED, 'diffs' => [], 'unreadable' => $unknown];
        }
        return ['status' => self::RESULT_OK, 'diffs' => self::diff($db, $xmp)];
    }

    /**
     * Schreibt die StarRate-Werte der gewählten Felder in die Datei — nur fehlende,
     * mit $overwrite auch abweichende. Der Vergleich wird hier neu gemacht (nicht
     * dem Client geglaubt), damit nie mehr geschrieben wird als die Vorschau zeigt.
     *
     * Alle drei Felder werden explizit übergeben (nicht gewählte mit dem aktuellen
     * Datei-Wert), sonst füllt SidecarService::writeMetadata fehlende Felder aus der
     * DB auf — z.B. einen Pick, obwohl Markierungen abgewählt waren. Beim JPEG trägt
     * das auch ein reines EXIF-Rating ins XMP, statt es mit xmp:Rating="0" zu verdecken.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @param list<string> $fields
     */
    public function apply(File $file, array $db, array $settings, array $fields, bool $overwrite): string
    {
        $store = $this->xmpStores->forFile($file, $settings);
        if ($store === null) {
            return self::RESULT_NO_STORE;
        }
        try {
            [$xmp, $unknown] = $this->inspect($store, $file);
            if ($unknown !== []) {
                return self::RESULT_SKIPPED;
            }
            $toWrite = self::fieldsToWrite(self::diff($db, $xmp), $fields, $overwrite);
            if ($toWrite === []) {
                return self::RESULT_UNCHANGED;
            }
            // Nicht gewählte Felder: JPEG-Writer bekommt null (= unverändert, er parst das
            // ganze XMP selbst — der Header-Read oben sieht nur die ersten 256 KB).
            // Sidecars brauchen den Dateiwert, sonst füllt missingFields() aus der DB auf.
            $keep   = $store instanceof SidecarService;
            $rating = in_array('rating', $toWrite, true) ? $db['rating'] : ($keep ? $xmp['rating'] : null);
            $color  = in_array('color', $toWrite, true)  ? ($db['color'] ?? '') : ($keep ? ($xmp['label'] ?? '') : null);
            $pick   = in_array('pick', $toWrite, true)   ? $db['pick']   : ($keep ? $xmp['pick'] : null);

            $store->writeMetadata(
                $file,
                $rating,
                $color,
                $pick,
                $settings['xmp_label_language'] ?? XmpService::LABEL_LANG_EN,
            );
            return self::RESULT_WRITTEN;
        } catch (\Exception $e) {
            $this->logger->warning("StarRate xmp-sync: write failed for {$file->getId()}: " . $e->getMessage());
            return self::RESULT_FAILED;
        }
    }

    /**
     * @return array{0: array{rating: int, label: string|null, pick: string}, 1: list<string>}
     */
    private function inspect(XmpStore $store, File $file): array
    {
        $xmp     = $store->readMetadata($file);
        $unknown = $this->xmpService->unreadableFields($store->readXmpPacket($file));
        return [$xmp, $unknown];
    }
}

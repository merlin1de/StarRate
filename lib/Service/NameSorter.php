<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\L10N\IFactory;

/**
 * Sortierung nach Namen wie in der Files-App (#112).
 *
 * Files sortiert per Intl.Collator in der Sprache des Betrachters mit
 * numerischem Vergleich: 1_photo vor 10_photo, photo_9 vor photo_010,
 * Umlaute bei ihrem Grundbuchstaben statt hinter z. Hier dasselbe mit dem
 * ICU-Collator aus ext/intl. Die Sortierschlüssel werden einmal pro Eintrag
 * berechnet und dann per strcmp verglichen — der Collator steckt nicht im
 * usort-Callback.
 *
 * Ohne intl gibt es einen Fallback-Schlüssel (Kleinbuchstaben, Zahlen auf
 * feste Breite gepolstert): Zahlen und Groß-/Kleinschreibung stimmen, Umlaute
 * nicht. strnatcasecmp wäre keine Alternative — es stellt photo_010 vor photo_9.
 */
class NameSorter
{
    /** Stellen, auf die Zahlenblöcke im Fallback-Schlüssel gepolstert werden */
    private const PAD = 20;

    private ?\Collator $collator = null;
    private bool $resolved = false;

    public function __construct(
        private readonly IFactory $l10nFactory,
    ) {}

    /**
     * Sprache, nach der sortiert wird — eingeloggt die des Users, für Gäste die
     * des Browsers. Gehört in Cache-Keys sortierter Listen.
     */
    public function language(): string
    {
        return $this->l10nFactory->findLanguage();
    }

    /**
     * Sortierschlüssel für einen Namen; Schlüssel werden per strcmp verglichen.
     */
    public function key(string $name): string
    {
        $collator = $this->collator();
        if ($collator !== null) {
            $key = $collator->getSortKey($name);
            if ($key !== false) {
                return $key;
            }
        }
        return self::fallbackKey($name);
    }

    /**
     * Fallback ohne intl: Kleinbuchstaben, jeder Zahlenblock ohne führende
     * Nullen auf PAD Stellen gepolstert. Der angehängte Originalname macht
     * die Reihenfolge bei sonst gleichen Schlüsseln (DSC vs dsc) eindeutig.
     */
    public static function fallbackKey(string $name): string
    {
        $lower  = mb_strtolower($name);
        $padded = preg_replace_callback(
            '/\d+/',
            static fn(array $m): string => str_pad(ltrim($m[0], '0') ?: '0', self::PAD, '0', STR_PAD_LEFT),
            $lower,
        );
        return ($padded ?? $lower) . "\0" . $name;
    }

    /**
     * Sortiert Einträge mit 'name' aufsteigend (Unterordner).
     *
     * @param list<array{name: string}> $items
     */
    public function sortByName(array &$items): void
    {
        $keys = array_map(fn(array $item): string => $this->key($item['name']), $items);
        array_multisort($keys, SORT_STRING, $items);
    }

    /**
     * Sortiert Bild-Arrays nach name|mtime|size, asc|desc. Ein gesetzter
     * `groupKey` (Pfad-Präfix in der rekursiven Ansicht mit Tiefe >= 1) bleibt
     * Primärschlüssel und immer aufsteigend, damit ein Ordner zusammenbleibt.
     *
     * @param list<array{name: string, mtime: int, size: int, groupKey?: string}> $images
     */
    public function sortImages(array &$images, string $sort, string $order): void
    {
        $groupKeys = [];
        $nameKeys  = [];
        foreach ($images as $i => $img) {
            $group         = $img['groupKey'] ?? '';
            $groupKeys[$i] = $group === '' ? '' : $this->key($group);
            if ($sort !== 'mtime' && $sort !== 'size') {
                $nameKeys[$i] = $this->key($img['name']);
            }
        }

        $indexes = array_keys($images);
        usort($indexes, static function (int $a, int $b) use ($images, $groupKeys, $nameKeys, $sort, $order): int {
            $cmp = strcmp($groupKeys[$a], $groupKeys[$b]);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = match ($sort) {
                'mtime' => $images[$a]['mtime'] <=> $images[$b]['mtime'],
                'size'  => $images[$a]['size']  <=> $images[$b]['size'],
                default => strcmp($nameKeys[$a], $nameKeys[$b]),
            };
            return $order === 'desc' ? -$cmp : $cmp;
        });

        $images = array_map(static fn(int $i): array => $images[$i], $indexes);
    }

    private function collator(): ?\Collator
    {
        if ($this->resolved) {
            return $this->collator;
        }
        $this->resolved = true;
        if (!class_exists(\Collator::class)) {
            return null;
        }
        try {
            $collator = new \Collator($this->language());
        } catch (\Throwable) {
            return null; // intl.use_exceptions=1 und unbekannte Sprache
        }
        if (intl_is_failure($collator->getErrorCode())) {
            return null;
        }
        // Wie Intl.Collator({numeric: true}) in Files: Ziffernfolgen als Zahl
        $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        return $this->collator = $collator;
    }
}

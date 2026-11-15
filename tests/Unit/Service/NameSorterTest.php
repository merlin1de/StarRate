<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\NameSorter;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class NameSorterTest extends TestCase
{
    private function sorter(string $lang = 'en'): NameSorter
    {
        $factory = $this->createMock(IFactory::class);
        $factory->method('findLanguage')->willReturn($lang);
        return new NameSorter($factory);
    }

    /** @param list<string> $names */
    private function sortNames(NameSorter $sorter, array $names): array
    {
        $items = array_map(static fn(string $n): array => ['name' => $n], $names);
        $sorter->sortByName($items);
        return array_column($items, 'name');
    }

    private function requireIntl(): void
    {
        if (!class_exists(\Collator::class)) {
            $this->markTestSkipped('ext/intl nicht geladen');
        }
    }

    // ─── Fallback ohne intl ──────────────────────────────────────────────────

    public function testFallbackKeySortsNumbersByValue(): void
    {
        $names = ['10_photo.jpg', '2_photo.jpg', '1_photo.jpg', '20_photo.jpg'];
        usort($names, static fn(string $a, string $b): int => strcmp(NameSorter::fallbackKey($a), NameSorter::fallbackKey($b)));
        $this->assertSame(['1_photo.jpg', '2_photo.jpg', '10_photo.jpg', '20_photo.jpg'], $names);
    }

    public function testFallbackKeyIgnoresLeadingZerosAndCase(): void
    {
        // strnatcasecmp stellt photo_010 vor photo_9 — der Fallback nicht
        $names = ['photo_010.jpg', 'Photo_9.jpg', 'photo_1.jpg'];
        usort($names, static fn(string $a, string $b): int => strcmp(NameSorter::fallbackKey($a), NameSorter::fallbackKey($b)));
        $this->assertSame(['photo_1.jpg', 'Photo_9.jpg', 'photo_010.jpg'], $names);
    }

    public function testFallbackKeyHandlesNumbersLongerThanTwentyDigits(): void
    {
        $names = ['1000000000000000000000000.jpg', '999999999999999999999.jpg'];
        usort($names, static fn(string $a, string $b): int => strcmp(NameSorter::fallbackKey($a), NameSorter::fallbackKey($b)));
        $this->assertSame(['999999999999999999999.jpg', '1000000000000000000000000.jpg'], $names);
    }

    public function testFallbackKeyIsUniquePerName(): void
    {
        $this->assertNotSame(NameSorter::fallbackKey('DSC_1.jpg'), NameSorter::fallbackKey('dsc_1.jpg'));
    }

    // ─── Collator (wie Files) ────────────────────────────────────────────────

    public function testSortsNumberedExportsLikeFiles(): void
    {
        $this->requireIntl();
        $names = [];
        foreach ([10, 2, 20, 1, 11, 3] as $n) {
            $names[] = "{$n}_photo.jpg";
        }
        $this->assertSame(
            ['1_photo.jpg', '2_photo.jpg', '3_photo.jpg', '10_photo.jpg', '11_photo.jpg', '20_photo.jpg'],
            $this->sortNames($this->sorter(), $names),
        );
    }

    public function testNineBeforeZeroPaddedTen(): void
    {
        $this->requireIntl();
        $this->assertSame(['photo_9.jpg', 'photo_010.jpg'], $this->sortNames($this->sorter(), ['photo_010.jpg', 'photo_9.jpg']));
    }

    public function testUmlautsSortWithTheirBaseLetter(): void
    {
        $this->requireIntl();
        $this->assertSame(
            ['Apfel.jpg', 'Äpfel.jpg', 'Birne.jpg', 'Zebra.jpg'],
            $this->sortNames($this->sorter('de'), ['Zebra.jpg', 'Äpfel.jpg', 'Birne.jpg', 'Apfel.jpg']),
        );
    }

    public function testCaseInsensitiveOrder(): void
    {
        $this->requireIntl();
        $this->assertSame(['alpha.jpg', 'Beta.jpg', 'gamma.jpg'], $this->sortNames($this->sorter(), ['gamma.jpg', 'Beta.jpg', 'alpha.jpg']));
    }

    // ─── sortImages ──────────────────────────────────────────────────────────

    public function testSortImagesByNameDesc(): void
    {
        $images = [
            ['name' => '2_a.jpg', 'mtime' => 1, 'size' => 1],
            ['name' => '10_a.jpg', 'mtime' => 1, 'size' => 1],
            ['name' => '1_a.jpg', 'mtime' => 1, 'size' => 1],
        ];
        $this->sorter()->sortImages($images, 'name', 'desc');
        $this->assertSame(['10_a.jpg', '2_a.jpg', '1_a.jpg'], array_column($images, 'name'));
    }

    public function testSortImagesByMtimeAndSize(): void
    {
        $images = [
            ['name' => 'a.jpg', 'mtime' => 30, 'size' => 1],
            ['name' => 'b.jpg', 'mtime' => 10, 'size' => 3],
            ['name' => 'c.jpg', 'mtime' => 20, 'size' => 2],
        ];
        $byMtime = $images;
        $this->sorter()->sortImages($byMtime, 'mtime', 'asc');
        $this->assertSame(['b.jpg', 'c.jpg', 'a.jpg'], array_column($byMtime, 'name'));

        $bySize = $images;
        $this->sorter()->sortImages($bySize, 'size', 'desc');
        $this->assertSame(['b.jpg', 'c.jpg', 'a.jpg'], array_column($bySize, 'name'));
    }

    public function testGroupKeyStaysPrimaryAndAscendingInDescOrder(): void
    {
        $images = [
            ['name' => 'b.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Tag 10'],
            ['name' => 'a.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Tag 2'],
            ['name' => 'c.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Tag 10'],
            ['name' => 'd.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Tag 2'],
        ];
        $this->sorter()->sortImages($images, 'name', 'desc');
        // Gruppen aufsteigend (Tag 2 vor Tag 10), innerhalb absteigend nach Name
        $this->assertSame(['d.jpg', 'a.jpg', 'c.jpg', 'b.jpg'], array_column($images, 'name'));
    }

    public function testSubtreeStaysTogetherBeforeSiblingWithSamePrefix(): void
    {
        // ICU ordnet „_“ vor „/“ — als ganzer String verglichen landete
        // Hochzeit_Extras zwischen Hochzeit und Hochzeit/Feier
        $images = [
            ['name' => 'x.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Hochzeit_Extras'],
            ['name' => 'y.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Hochzeit/Feier'],
            ['name' => 'z.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Hochzeit'],
            ['name' => 'w.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Hochzeit/Ablauf'],
        ];
        $this->sorter()->sortImages($images, 'name', 'asc');
        $this->assertSame(
            ['Hochzeit', 'Hochzeit/Ablauf', 'Hochzeit/Feier', 'Hochzeit_Extras'],
            array_column($images, 'groupKey'),
        );
    }

    public function testNumericallyEqualGroupsStaySeparate(): void
    {
        // „Shoot 1“ und „Shoot 01“ sind numerisch gleich, aber zwei Ordner
        $images = [
            ['name' => 'a.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Shoot 1'],
            ['name' => 'b.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Shoot 01'],
            ['name' => 'c.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Shoot 1'],
            ['name' => 'd.jpg', 'mtime' => 1, 'size' => 1, 'groupKey' => 'Shoot 01'],
        ];
        $this->sorter()->sortImages($images, 'name', 'asc');
        $groups = array_column($images, 'groupKey');
        $this->assertSame($groups[0], $groups[1]);
        $this->assertSame($groups[2], $groups[3]);
        $this->assertNotSame($groups[1], $groups[2]);
    }

    public function testNameWithoutCollatorKeySortsLast(): void
    {
        $this->requireIntl();
        $bad = 'b' . chr(0xC3) . chr(0x28) . '.jpg'; // ungültiges UTF-8
        if ((new \Collator('en'))->getSortKey($bad) !== false) {
            $this->markTestSkipped('ICU liefert hier einen Schlüssel für ungültiges UTF-8');
        }
        $this->assertSame(['a.jpg', 'z.jpg', $bad], $this->sortNames($this->sorter(), [$bad, 'z.jpg', 'a.jpg']));
    }

    public function testSortImagesReturnsList(): void
    {
        $images = [5 => ['name' => 'b.jpg', 'mtime' => 1, 'size' => 1], 9 => ['name' => 'a.jpg', 'mtime' => 1, 'size' => 1]];
        $this->sorter()->sortImages($images, 'name', 'asc');
        $this->assertSame([0, 1], array_keys($images));
    }
}

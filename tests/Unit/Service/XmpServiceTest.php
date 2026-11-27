<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\XmpService;
use PHPUnit\Framework\TestCase;

class XmpServiceTest extends TestCase
{
    private XmpService $service;

    protected function setUp(): void
    {
        $this->service = new XmpService();
    }

    // ─── Tests: XMP aufbauen ──────────────────────────────────────────────────

    public function testBuildXmpContentContainsRating(): void
    {
        $xmp = $this->service->buildXmpContent(4, null);

        $this->assertStringContainsString('xmp:Rating="4"', $xmp);
        $this->assertStringContainsString('<?xpacket', $xmp);
        $this->assertStringContainsString('<?xpacket end=', $xmp);
    }

    public function testBuildXmpContentContainsLabel(): void
    {
        $xmp = $this->service->buildXmpContent(3, 'Green');

        $this->assertStringContainsString('xmp:Rating="3"', $xmp);
        $this->assertStringContainsString('xmp:Label="Green"', $xmp);
    }

    public function testBuildXmpContentContainsPhotoshopLabelColorLowercase(): void
    {
        // photoshop:LabelColor wird parallel zu xmp:Label geschrieben (LR-Kompatibilität)
        $xmp = $this->service->buildXmpContent(3, 'Green');

        $this->assertStringContainsString('photoshop:LabelColor="green"', $xmp);
        $this->assertStringContainsString("xmlns:photoshop='http://ns.adobe.com/photoshop/1.0/'", $xmp);
    }

    /** @dataProvider allColorsProvider */
    public function testBuildXmpContentPhotoshopLabelColorLowercaseForAllColors(string $color): void
    {
        $xmp = $this->service->buildXmpContent(1, $color);

        $this->assertStringContainsString(
            'photoshop:LabelColor="' . strtolower($color) . '"',
            $xmp
        );
    }

    public function testBuildXmpContentWithoutLabelHasNoLabelAttribute(): void
    {
        $xmp = $this->service->buildXmpContent(2, null);

        $this->assertStringNotContainsString('xmp:Label', $xmp);
        $this->assertStringNotContainsString('photoshop:LabelColor', $xmp);
        // Ohne Label brauchen wir auch keinen photoshop-Namespace
        $this->assertStringNotContainsString('xmlns:photoshop', $xmp);
    }

    public function testBuildXmpContentZeroRating(): void
    {
        $xmp = $this->service->buildXmpContent(0, null);

        $this->assertStringContainsString('xmp:Rating="0"', $xmp);
    }

    /** @dataProvider allColorsProvider */
    public function testBuildXmpContentAllColors(string $color): void
    {
        $xmp = $this->service->buildXmpContent(1, $color);

        $this->assertStringContainsString("xmp:Label=\"{$color}\"", $xmp);
    }

    public static function allColorsProvider(): array
    {
        return [['Red'], ['Yellow'], ['Green'], ['Blue'], ['Purple']];
    }

    // ─── Tests: XMP parsen ────────────────────────────────────────────────────

    public function testParseXmpContentAttributeForm(): void
    {
        $xmp = <<<XMP
<?xpacket begin='' id='W5M0MpCehiHzreSzNTczkc9d'?>
<x:xmpmeta xmlns:x='adobe:ns:meta/'>
  <rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>
    <rdf:Description rdf:about=''
      xmlns:xmp='http://ns.adobe.com/xap/1.0/'
      xmp:Rating='5'
      xmp:Label='Red'>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end='w'?>
XMP;
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(5,     $result['rating']);
        $this->assertSame('Red', $result['label']);
    }

    public function testParseXmpContentElementForm(): void
    {
        $xmp = '<xmp:Rating>3</xmp:Rating><xmp:Label>Blue</xmp:Label>';
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(3,      $result['rating']);
        $this->assertSame('Blue', $result['label']);
    }

    public function testParseXmpContentEmptyReturnsDefaults(): void
    {
        $result = $this->service->parseXmpContent('<x:xmpmeta/>');

        $this->assertSame(0,    $result['rating']);
        $this->assertNull($result['label']);
    }

    public function testParseXmpContentInvalidRatingClamped(): void
    {
        $xmp    = "xmp:Rating='9'";
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(0, $result['rating']); // ungültig → 0
    }

    public function testParseXmpContentInvalidLabelIgnored(): void
    {
        $xmp    = "xmp:Rating='2' xmp:Label='Magenta'";
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(2,    $result['rating']);
        $this->assertNull($result['label']); // unbekannte Farbe → null
    }

    public function testParseXmpContentCaseInsensitiveLabel(): void
    {
        $this->assertSame('Red',    $this->service->parseXmpContent("xmp:Label='red'")['label']);
        $this->assertSame('Red',    $this->service->parseXmpContent("xmp:Label='RED'")['label']);
        $this->assertSame('Blue',   $this->service->parseXmpContent("xmp:Label='blue'")['label']);
        $this->assertSame('Purple', $this->service->parseXmpContent("xmp:Label='PURPLE'")['label']);
    }

    /** @dataProvider germanLabelProvider */
    public function testParseXmpContentGermanLabels(string $deLabel, string $expected): void
    {
        $result = $this->service->parseXmpContent("xmp:Label='{$deLabel}'");
        $this->assertSame($expected, $result['label']);
    }

    public static function germanLabelProvider(): array
    {
        return [
            ['Rot',  'Red'],
            ['Gelb', 'Yellow'],
            ['Grün', 'Green'],
            ['Blau', 'Blue'],
            ['Lila', 'Purple'],
        ];
    }

    /** @dataProvider photoshopLabelColorProvider */
    public function testParseXmpContentPhotoshopLabelColor(string $psValue, string $expected): void
    {
        $result = $this->service->parseXmpContent("photoshop:LabelColor='{$psValue}'");
        $this->assertSame($expected, $result['label']);
    }

    public static function photoshopLabelColorProvider(): array
    {
        return [
            ['red',    'Red'],
            ['yellow', 'Yellow'],
            ['green',  'Green'],
            ['blue',   'Blue'],
            ['purple', 'Purple'],
        ];
    }

    public function testPhotoshopLabelColorTakesPriorityOverXmpLabel(): void
    {
        // photoshop:LabelColor="green" + xmp:Label="Rot" → Green gewinnt
        $xmp    = "photoshop:LabelColor='green' xmp:Label='Rot'";
        $result = $this->service->parseXmpContent($xmp);
        $this->assertSame('Green', $result['label']);
    }

    public function testXmpLabelUsedWhenPhotoshopLabelColorAbsent(): void
    {
        $result = $this->service->parseXmpContent("xmp:Rating='3' xmp:Label='Gelb'");
        $this->assertSame('Yellow', $result['label']);
    }

    // ─── Tests: xap: Namespace-Alias (alter exiftool/IDimager Alias) ────────────

    public function testXapRatingAttributeIsRead(): void
    {
        $result = $this->service->parseXmpContent("xap:Rating='4'");
        $this->assertSame(4, $result['rating']);
    }

    public function testXapRatingElementIsRead(): void
    {
        $result = $this->service->parseXmpContent('<xap:Rating>3</xap:Rating>');
        $this->assertSame(3, $result['rating']);
    }

    public function testXapRatingOutOfRangeReturnsZero(): void
    {
        $result = $this->service->parseXmpContent("xap:Rating='9'");
        $this->assertSame(0, $result['rating']);
    }

    public function testXapLabelAttributeIsRead(): void
    {
        $result = $this->service->parseXmpContent("xap:Label='Red'");
        $this->assertSame('Red', $result['label']);
    }

    public function testXapLabelAndRatingTogetherAreRead(): void
    {
        $result = $this->service->parseXmpContent("xap:Rating='4' xap:Label='Yellow'");
        $this->assertSame(4,        $result['rating']);
        $this->assertSame('Yellow', $result['label']);
    }

    // ─── Tests: digiKam:ColorLabel ────────────────────────────────────────────

    /** @dataProvider digiKamColorProvider */
    public function testDigiKamColorLabelIsMapped(int $value, ?string $expected): void
    {
        $result = $this->service->parseXmpContent("digiKam:ColorLabel='{$value}'");
        $this->assertSame($expected, $result['label']);
    }

    public static function digiKamColorProvider(): array
    {
        return [
            'none (0)'     => [0, null],
            'red (1)'      => [1, 'Red'],
            'orange (2)'   => [2, null],   // kein StarRate-Äquivalent
            'yellow (3)'   => [3, 'Yellow'],
            'green (4)'    => [4, 'Green'],
            'blue (5)'     => [5, 'Blue'],
            'purple (6)'   => [6, 'Purple'],
            'grey (7)'     => [7, null],   // kein StarRate-Äquivalent
        ];
    }

    public function testDigiKamColorLabelElementFormIsRead(): void
    {
        $result = $this->service->parseXmpContent('<digiKam:ColorLabel>4</digiKam:ColorLabel>');
        $this->assertSame('Green', $result['label']);
    }

    public function testPhotoshopLabelColorTakesPriorityOverDigiKam(): void
    {
        $xmp    = "photoshop:LabelColor='blue' digiKam:ColorLabel='1'";
        $result = $this->service->parseXmpContent($xmp);
        $this->assertSame('Blue', $result['label']);
    }

    public function testXmpLabelTakesPriorityOverDigiKam(): void
    {
        $xmp    = "xmp:Label='Green' digiKam:ColorLabel='1'";
        $result = $this->service->parseXmpContent($xmp);
        $this->assertSame('Green', $result['label']);
    }

    // ─── Tests: Pick/Reject (xmpDM) ───────────────────────────────────────────

    public function testBuildXmpContentWithPick(): void
    {
        $xmp = $this->service->buildXmpContent(0, null, 'pick');

        $this->assertStringContainsString('xmpDM:pick="1"',    $xmp);
        $this->assertStringContainsString('xmpDM:good="true"', $xmp);
        $this->assertStringContainsString("xmlns:xmpDM='http://ns.adobe.com/xmp/1.0/DynamicMedia/'", $xmp);
    }

    public function testBuildXmpContentWithReject(): void
    {
        $xmp = $this->service->buildXmpContent(0, null, 'reject');

        $this->assertStringContainsString('xmpDM:pick="-1"',    $xmp);
        $this->assertStringContainsString('xmpDM:good="false"', $xmp);
    }

    public function testBuildXmpContentWithNonePickHasNoXmpDmAttrs(): void
    {
        $xmp = $this->service->buildXmpContent(3, 'Red', 'none');

        $this->assertStringNotContainsString('xmpDM:pick',  $xmp);
        $this->assertStringNotContainsString('xmpDM:good',  $xmp);
        // Kein Pick → auch kein xmpDM-Namespace nötig
        $this->assertStringNotContainsString('xmlns:xmpDM', $xmp);
    }

    public function testBuildXmpContentWithoutPickArgHasNoXmpDmAttrs(): void
    {
        $xmp = $this->service->buildXmpContent(3, 'Red');

        $this->assertStringNotContainsString('xmpDM:',      $xmp);
        $this->assertStringNotContainsString('xmlns:xmpDM', $xmp);
    }

    // ─── Tests: Label-Sprache (xmp:Label EN vs. DE für LR-Lokalisierung) ─────

    public function testBuildXmpContentDefaultLangIsEn(): void
    {
        // Default 'en' → xmp:Label="Red" (kanonisch, Bridge/digiKam/EN-LR)
        $xmp = $this->service->buildXmpContent(0, 'Red');
        $this->assertStringContainsString('xmp:Label="Red"', $xmp);
    }

    /** @dataProvider deLabelProvider */
    public function testBuildXmpContentLangDeTranslatesXmpLabel(string $en, string $de): void
    {
        $xmp = $this->service->buildXmpContent(0, $en, null, 'de');

        // xmp:Label in DE-Lokalisierung
        $this->assertStringContainsString("xmp:Label=\"{$de}\"", $xmp);
        // photoshop:LabelColor BLEIBT lowercase EN — sprachneutral, treibt LR-Farbstreifen
        $this->assertStringContainsString('photoshop:LabelColor="' . strtolower($en) . '"', $xmp);
    }

    public static function deLabelProvider(): array
    {
        return [
            ['Red',    'Rot'],
            ['Yellow', 'Gelb'],
            ['Green',  'Grün'],
            ['Blue',   'Blau'],
            ['Purple', 'Lila'],
        ];
    }

    public function testBuildXmpContentLangEnExplicitWritesEnglish(): void
    {
        $xmp = $this->service->buildXmpContent(0, 'Green', null, 'en');
        $this->assertStringContainsString('xmp:Label="Green"', $xmp);
        $this->assertStringNotContainsString('xmp:Label="Grün"', $xmp);
    }

    public function testLocalizeLabelHelper(): void
    {
        // Static helper: kanonisch EN → DE bei lang='de', sonst EN
        $this->assertSame('Rot',    XmpService::localizeLabel('Red',    'de'));
        $this->assertSame('Red',    XmpService::localizeLabel('Red',    'en'));
        $this->assertSame('Lila',   XmpService::localizeLabel('Purple', 'de'));
        // Unbekannte Sprache → fällt auf EN zurück
        $this->assertSame('Red',    XmpService::localizeLabel('Red',    'fr'));
    }

    /** @dataProvider xmpDmPickProvider */
    public function testParseXmpDmPick(string $pickVal, string $expected): void
    {
        $result = $this->service->parseXmpContent("xmpDM:pick='{$pickVal}'");
        $this->assertSame($expected, $result['pick']);
    }

    public static function xmpDmPickProvider(): array
    {
        return [
            'pick (1)'    => ['1',  'pick'],
            'reject (-1)' => ['-1', 'reject'],
            'none (0)'    => ['0',  'none'],
            'invalid (2)' => ['2',  'none'],
        ];
    }

    public function testParseXmpDmPickElementForm(): void
    {
        $result = $this->service->parseXmpContent('<xmpDM:pick>1</xmpDM:pick>');
        $this->assertSame('pick', $result['pick']);
    }

    public function testParseFlashViewIsPickedReadsAsPick(): void
    {
        // Rückwärtskompatibel: alte FlashView-Schreibweise wird beim Lesen erkannt
        $result = $this->service->parseXmpContent("flashView:IsPicked='True'");
        $this->assertSame('pick', $result['pick']);
    }

    public function testParseFlashViewIsRejectedReadsAsReject(): void
    {
        $result = $this->service->parseXmpContent("flashView:IsRejected='True'");
        $this->assertSame('reject', $result['pick']);
    }

    public function testXmpDmPickTakesPriorityOverFlashView(): void
    {
        // Wenn beide gesetzt sind: xmpDM gewinnt (FlashView ist nur Fallback)
        $xmp    = "xmpDM:pick='-1' flashView:IsPicked='True'";
        $result = $this->service->parseXmpContent($xmp);
        $this->assertSame('reject', $result['pick']);
    }

    public function testParseXmpContentEmptyReturnsNonePick(): void
    {
        $result = $this->service->parseXmpContent('<x:xmpmeta/>');
        $this->assertSame('none', $result['pick']);
    }

    // ─── Tests: Round-Trip (bauen + parsen) ───────────────────────────────────

    /** @dataProvider roundTripProvider */
    public function testBuildAndParseRoundTrip(int $rating, ?string $label, ?string $pick = null): void
    {
        $xmp    = $this->service->buildXmpContent($rating, $label, $pick);
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame($rating, $result['rating']);
        $this->assertSame($label,  $result['label']);
        $this->assertSame($pick ?? 'none', $result['pick']);
    }

    public static function roundTripProvider(): array
    {
        return [
            [0, null,     null],
            [1, null,     'pick'],
            [5, 'Red',    'pick'],
            [3, 'Purple', 'reject'],
            [4, 'Green',  'none'],
            [2, 'Blue',   null],
        ];
    }


    // ─── Tests: unreadableFields ─────────────────────────────────────────────

    public function testUnreadableFieldsFlagsValuesWithWhitespace(): void
    {
        // parseXmpContent() liest " 3" nicht → darf nicht als „leer" überschrieben werden
        $this->assertSame(['rating', 'pick'], $this->service->unreadableFields('<d xmp:Rating=" 3" xmpDM:pick=" 1"/>'));
        $this->assertSame(['rating'], $this->service->unreadableFields("<xmp:Rating>\n4\n</xmp:Rating>"));
    }

    public function testUnreadableFieldsFlagsCustomLabelNextToKnownColour(): void
    {
        $xmp = '<d photoshop:LabelColor="red" xmp:Label="To Print"/>';
        $this->assertSame('Red', $this->service->parseXmpContent($xmp)['label']);
        $this->assertSame(['color'], $this->service->unreadableFields($xmp));
    }

    public function testUnreadableFieldsAcceptsMatchingLabelPair(): void
    {
        $this->assertSame([], $this->service->unreadableFields('<d photoshop:LabelColor="red" xmp:Label="Rot" xmp:Rating="4"/>'));
    }
}

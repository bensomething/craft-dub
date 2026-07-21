<?php

namespace bensomething\craftdub\tests\unit;

use bensomething\craftdub\models\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testQrStyleDefaultsAreReturnedAsDubQueryParams(): void
    {
        $settings = new Settings();

        $this->assertSame([
            'size' => 1200,
            'margin' => 2,
            'fgColor' => '#000000',
            'bgColor' => '#ffffff',
        ], $settings->getQrStyle());
    }

    public function testBlankQrStyleCellsAreOmittedSoDubAppliesItsOwnDefaults(): void
    {
        $settings = new Settings();
        $settings->qrStyle = [['size' => '', 'margin' => '', 'foreground' => '#ff0000', 'background' => '  ']];

        $this->assertSame(['fgColor' => '#ff0000'], $settings->getQrStyle());
    }

    public function testQrStyleClampsSizeAndMarginToTheSupportedRange(): void
    {
        $settings = new Settings();
        $settings->qrStyle = [['size' => 10, 'margin' => 999]];

        $style = $settings->getQrStyle();

        $this->assertSame(Settings::QR_SIZE_MIN, $style['size']);
        $this->assertSame(Settings::QR_MARGIN_MAX, $style['margin']);
    }

    public function testQrStyleNegativeMarginIsFlooredAtZero(): void
    {
        $settings = new Settings();
        $settings->qrStyle = [['margin' => -5]];

        $this->assertSame(['margin' => 0], $settings->getQrStyle());
    }

    #[DataProvider('colourProvider')]
    public function testQrStyleColoursAreNormalisedToASingleLeadingHash(string $input, string $expected): void
    {
        $settings = new Settings();
        $settings->qrStyle = [['foreground' => $input]];

        $this->assertSame($expected, $settings->getQrStyle()['fgColor']);
    }

    /** @return array<string, array{string, string}> */
    public static function colourProvider(): array
    {
        return [
            'bare hex' => ['ff0000', '#ff0000'],
            'already hashed' => ['#ff0000', '#ff0000'],
            'padded' => ['  #abcdef  ', '#abcdef'],
        ];
    }

    #[DataProvider('malformedQrStyleProvider')]
    public function testGetQrStyleToleratesMalformedStoredValues(mixed $stored): void
    {
        $settings = new Settings();
        $settings->qrStyle = $stored;

        $this->assertSame([], $settings->getQrStyle());
    }

    /** @return array<string, array{mixed}> */
    public static function malformedQrStyleProvider(): array
    {
        return [
            'empty array' => [[]],
            'string' => ['not-a-table'],
            'row is not an array' => [['nonsense']],
        ];
    }

    public function testValidationClampsTheStoredQrStyleRow(): void
    {
        $settings = new Settings();
        $settings->qrStyle = [['size' => '1', 'margin' => '50', 'foreground' => '#000000']];

        $this->assertTrue($settings->validate());
        $this->assertSame(
            [['size' => Settings::QR_SIZE_MIN, 'margin' => Settings::QR_MARGIN_MAX, 'foreground' => '#000000']],
            $settings->qrStyle,
        );
    }

    public function testValidationLeavesBlankQrStyleCellsBlank(): void
    {
        $settings = new Settings();
        $settings->qrStyle = [['size' => '', 'margin' => '']];

        $this->assertTrue($settings->validate());
        $this->assertSame([['size' => '', 'margin' => '']], $settings->qrStyle);
    }

    public function testEmptySectionsFallBackToAllSections(): void
    {
        $settings = new Settings();
        $settings->sections = [];

        $this->assertTrue($settings->validate());
        $this->assertSame(['*'], $settings->sections);
    }

    public function testNonArraySectionsFallBackToAllSections(): void
    {
        $settings = new Settings();
        $settings->sections = '';

        $this->assertTrue($settings->validate());
        $this->assertSame(['*'], $settings->sections);
    }

    public function testAnUnknownQrViewModeIsRejected(): void
    {
        $settings = new Settings();
        $settings->qrViewMode = 'enormous';

        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey('qrViewMode', $settings->getErrors());
    }

    #[DataProvider('qrViewModeProvider')]
    public function testSupportedQrViewModesAreAccepted(string $mode): void
    {
        $settings = new Settings();
        $settings->qrViewMode = $mode;

        $this->assertTrue($settings->validate());
    }

    /** @return array<array{string}> */
    public static function qrViewModeProvider(): array
    {
        return array_map(fn(string $mode) => [$mode], Settings::QR_VIEW_MODES);
    }
}

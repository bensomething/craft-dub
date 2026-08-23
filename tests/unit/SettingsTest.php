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

    #[DataProvider('siteDomainProvider')]
    public function testSiteDomainOverridesAreNormalised(mixed $stored, array $expected): void
    {
        $settings = new Settings();
        $settings->siteDomains = $stored;

        $this->assertSame($expected, $settings->getSiteDomains());
    }

    /** @return array<string, array{mixed, array<string, string>}> */
    public static function siteDomainProvider(): array
    {
        return [
            // What the editable table posts: a row of cells under the site uid.
            'table rows' => [
                ['uid-a' => ['domain' => 'go.example.com'], 'uid-b' => ['domain' => 'go.example.fr']],
                ['uid-a' => 'go.example.com', 'uid-b' => 'go.example.fr'],
            ],
            // What a config file is likelier to hold.
            'plain strings' => [
                ['uid-a' => 'go.example.com'],
                ['uid-a' => 'go.example.com'],
            ],
            'blank rows are dropped, so the site falls back to the default' => [
                ['uid-a' => ['domain' => ''], 'uid-b' => ['domain' => '   '], 'uid-c' => ['domain' => 'go.example.fr']],
                ['uid-c' => 'go.example.fr'],
            ],
            'whitespace is trimmed' => [
                ['uid-a' => ['domain' => '  go.example.com  ']],
                ['uid-a' => 'go.example.com'],
            ],
            'a row with no domain cell at all' => [
                ['uid-a' => ['site' => 'Example']],
                [],
            ],
            'environment variables are kept verbatim, to resolve at read time' => [
                ['uid-a' => ['domain' => '$DUB_DOMAIN_FR']],
                ['uid-a' => '$DUB_DOMAIN_FR'],
            ],
            'no overrides' => [[], []],
            // Raw CP input can be any shape.
            'not an array at all' => ['nonsense', []],
        ];
    }

    #[DataProvider('distinctDomainProvider')]
    public function testConfiguredDomainsAreDeduplicatedAndBlanksDropped(array $values, array $expected): void
    {
        $method = new \ReflectionMethod(Settings::class, 'distinctDomains');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $values));
    }

    /** @return array<string, array{array<mixed>, list<string>}> */
    public static function distinctDomainProvider(): array
    {
        return [
            'the usual case' => [['bms.so', 'gla.st', 'fg.wtf'], ['bms.so', 'gla.st', 'fg.wtf']],
            // Adoption pages the whole workspace once per domain, so a duplicate is a full
            // redundant scan rather than a cosmetic problem.
            'a site set to the same domain as the default' => [['bms.so', 'bms.so'], ['bms.so']],
            'several sites sharing an override' => [['bms.so', 'gla.st', 'gla.st'], ['bms.so', 'gla.st']],
            // A blank would leave the domain filter off entirely, so that pass would consider
            // every link in the workspace, including ones on domains nothing here manages.
            'no domain configured at all' => [[''], []],
            'an unresolved environment variable' => [['bms.so', null, false], ['bms.so']],
            // array_unique preserves the first occurrence, and the keys have to be reset or the
            // list comes back with holes in it.
            'keys are reindexed' => [['', 'gla.st', '', 'bms.so'], ['gla.st', 'bms.so']],
            'nothing at all' => [[], []],
        ];
    }
}

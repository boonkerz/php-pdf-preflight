<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrintPreflight\Analysis\ColorClass;
use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\PageBox;
use PrintPreflight\Exceptions\InvalidRuleConfigException;
use PrintPreflight\Results\RuleStatus;
use PrintPreflight\Results\Violation;
use PrintPreflight\Rules\ColorPageCountRule;
use PrintPreflight\Rules\ColorSpaceRule;
use PrintPreflight\Rules\ImageResolutionRule;
use PrintPreflight\Rules\PdfPropertiesRule;

final class ColorAndImageRulesTest extends TestCase
{
    private const A4 = [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]];

    /**
     * @param list<Violation> $violations
     * @return list<array{int|null, string}>
     */
    private static function found(array $violations): array
    {
        return array_map(static fn (Violation $v): array => [$v->page, $v->code], $violations);
    }

    private static function recorded(string $name): DocumentAnalysis
    {
        $data = json_decode((string) file_get_contents(__DIR__ . "/data/{$name}.native.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return DocumentAnalysis::fromArray($data);
    }

    // --- image resolution --------------------------------------------------------

    public function testImageResolution(): void
    {
        $doc = AnalysisBuilder::document(
            AnalysisBuilder::page(1, self::A4, images: [AnalysisBuilder::image(300), AnalysisBuilder::image(150)]),
            AnalysisBuilder::page(2, self::A4, images: [AnalysisBuilder::image(299.5, object: null)]),
        );
        $result = (new ImageResolutionRule(minDpi: 300))->validate($doc);
        self::assertSame([[1, 'image_resolution.too_low'], [2, 'image_resolution.too_low']], self::found($result->violations));
        self::assertSame(150.0, $result->violations[0]->actual['dpi']);
        self::assertSame('5 0 R', $result->violations[0]->actual['image']);
        self::assertSame('inline', $result->violations[1]->actual['image']);
    }

    public function testNonUniformScalingUsesTheLowerResolution(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::page(1, self::A4, images: [AnalysisBuilder::image(600, dpiY: 200)]));
        self::assertSame(RuleStatus::Failed, (new ImageResolutionRule(minDpi: 300))->validate($doc)->status);
    }

    public function testBitonalThresholdAndMaximum(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::page(1, self::A4, images: [
            AnalysisBuilder::image(600, bits: 1),
            AnalysisBuilder::image(1200),
        ]));
        $result = (new ImageResolutionRule(minDpi: 300, maxDpi: 1000, minDpiBitonal: 1200))->validate($doc);
        self::assertSame(['image_resolution.too_low', 'image_resolution.too_high'], array_map(static fn ($v) => $v->code, $result->violations));
    }

    public function testImagesOutsideThePageAreIgnoredByDefault(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::page(1, self::A4, images: [AnalysisBuilder::image(72, onPage: false)]));
        self::assertSame(RuleStatus::Passed, (new ImageResolutionRule())->validate($doc)->status);
        self::assertSame(RuleStatus::Failed, (new ImageResolutionRule(onlyOnPage: false))->validate($doc)->status);
    }

    public function testImageResolutionIncompleteAnalysis(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::page(1, self::A4, contentComplete: false));
        self::assertSame(RuleStatus::Undetermined, (new ImageResolutionRule())->validate($doc)->status);
    }

    public function testImageResolutionOnRecordedAnalysis(): void
    {
        $result = (new ImageResolutionRule(minDpi: 300))->validate(self::recorded('images'));
        // Page 2 (150 dpi copy), 3 (50 x 100 dpi), 5, 6-8, 10-13 (small test images); page 9 is off-page.
        self::assertSame([2, 3, 5, 5, 6, 7, 8, 10, 11, 12, 13], array_map(static fn ($v) => $v->page, $result->violations));
    }

    public function testInvalidResolutionConfig(): void
    {
        $this->expectException(InvalidRuleConfigException::class);
        ImageResolutionRule::fromConfig(['min_dpi' => 0]);
    }

    // --- colour spaces -------------------------------------------------------------

    public function testForbiddenColorSpaces(): void
    {
        $doc = AnalysisBuilder::document(
            AnalysisBuilder::page(1, self::A4, colors: AnalysisBuilder::colors(ColorClass::Color, [['cmyk', ['fill']], ['rgb', ['image']]])),
            AnalysisBuilder::page(2, self::A4, colors: AnalysisBuilder::colors(ColorClass::Grayscale, [['gray', ['text']]])),
        );
        $result = (new ColorSpaceRule(forbidden: ['rgb', 'lab']))->validate($doc);
        self::assertSame([[1, 'color_spaces.forbidden']], self::found($result->violations));
        self::assertSame(['image'], $result->violations[0]->actual['targets']);

        // Restricting the targets: RGB only forbidden for vector graphics
        self::assertSame(RuleStatus::Passed, (new ColorSpaceRule(forbidden: ['rgb'], targets: ['fill', 'stroke']))->validate($doc)->status);
    }

    public function testSpotColors(): void
    {
        $doc = AnalysisBuilder::document(
            AnalysisBuilder::page(1, self::A4, colors: AnalysisBuilder::colors(ColorClass::Color, [['spot', ['fill']]], ['HKS 13 K'])),
            AnalysisBuilder::page(2, self::A4, colors: AnalysisBuilder::colors(ColorClass::Color, [['spot', ['fill']]], ['PANTONE 185 C'])),
        );
        $result = (new ColorSpaceRule(allowedSpotColors: ['HKS 13 K'], maxSpotColors: 1))->validate($doc);
        self::assertSame([[2, 'color_spaces.spot_not_allowed'], [null, 'color_spaces.too_many_spot_colors']], self::found($result->violations));
        self::assertSame(['HKS 13 K', 'PANTONE 185 C'], $result->violations[1]->actual['spot_colors']);
    }

    public function testColorSpacesWithoutColorAnalysisAreUndetermined(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::page(1, self::A4));
        self::assertSame(RuleStatus::Undetermined, (new ColorSpaceRule(forbidden: ['rgb']))->validate($doc)->status);
    }

    public function testColorSpacesOnRecordedAnalysis(): void
    {
        $doc = self::recorded('colors');
        $result = (new ColorSpaceRule(forbidden: ['rgb']))->validate($doc);
        // Declared but unused RGB on page 14 is not reported.
        self::assertSame([1, 2, 6, 7, 8, 9, 11, 12, 13, 16], array_values(array_unique(array_map(static fn ($v) => $v->page, $result->violations))));
        self::assertSame(['HKS 13 K'], (new ColorSpaceRule(maxSpotColors: 0))->validate($doc)->violations[0]->actual['spot_colors']);
    }

    // --- colour page count on real classifications ------------------------------------

    public function testColorPageCountOnRecordedAnalysis(): void
    {
        $doc = self::recorded('images');
        $result = (new ColorPageCountRule(maxColor: 2))->validate($doc);
        // Pages 3, 5, 7 and 10 are colour.
        self::assertSame(['color_pages.too_many_color'], array_map(static fn ($v) => $v->code, $result->violations));
        self::assertSame(4, $result->violations[0]->actual['pages']);

        // The colours fixture contains an undecidable page (16).
        self::assertSame(RuleStatus::Undetermined, (new ColorPageCountRule(maxColor: 20))->validate(self::recorded('colors'))->status);
    }

    // --- PDF properties --------------------------------------------------------------------

    public function testPdfProperties(): void
    {
        $plain = AnalysisBuilder::document(AnalysisBuilder::page(1, self::A4));
        $encrypted = new DocumentAnalysis('pdf', true, [AnalysisBuilder::page(1, self::A4)], pdfVersion: '2.0', encrypted: true, pdfxVersion: 'PDF/X-4');

        self::assertSame(RuleStatus::Passed, (new PdfPropertiesRule())->validate($encrypted)->status);
        $strict = new PdfPropertiesRule(allowEncrypted: false, requirePdfx: ['PDF/X-1a', 'PDF/X-4'], maxVersion: '1.7');
        self::assertSame(
            [[null, 'pdf_properties.encrypted'], [null, 'pdf_properties.version_too_high']],
            self::found($strict->validate($encrypted)->violations),
        );
        self::assertSame([[null, 'pdf_properties.pdfx_not_declared']], self::found($strict->validate($plain)->violations));
        self::assertFalse($strict->validate($plain)->violations[0]->expected['validated'], 'identification only');
    }
}

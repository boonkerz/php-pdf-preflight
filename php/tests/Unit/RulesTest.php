<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrintPreflight\Analysis\ColorClass;
use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\FontInfo;
use PrintPreflight\Analysis\Issue;
use PrintPreflight\Analysis\PageBox;
use PrintPreflight\Exceptions\InvalidRuleConfigException;
use PrintPreflight\Results\RuleStatus;
use PrintPreflight\Results\Violation;
use PrintPreflight\Rules\BleedMarginRule;
use PrintPreflight\Rules\BoxRequiredRule;
use PrintPreflight\Rules\ColorPageCountRule;
use PrintPreflight\Rules\FileTypeRule;
use PrintPreflight\Rules\FontsEmbeddedRule;
use PrintPreflight\Rules\PageCountRule;
use PrintPreflight\Rules\RuleOptions;
use PrintPreflight\Rules\Severity;
use PrintPreflight\Rules\UndeterminedPolicy;

final class RulesTest extends TestCase
{
    /**
     * @param list<Violation> $violations
     * @return list<string>
     */
    private static function codes(array $violations): array
    {
        return array_map(static fn (Violation $v): string => $v->code, $violations);
    }

    // --- page count ---------------------------------------------------------

    public function testPageCount(): void
    {
        $pages = static fn (int $n): DocumentAnalysis => AnalysisBuilder::document(
            ...array_map(static fn (int $i) => AnalysisBuilder::simple($i, 210, 297), $n > 0 ? range(1, $n) : []),
        );
        $rule = new PageCountRule(min: 1, max: 2);
        self::assertSame(RuleStatus::Passed, $rule->validate($pages(2))->status);
        self::assertSame(['page_count.too_many'], self::codes($rule->validate($pages(3))->violations));
        self::assertSame(['page_count.exact'], self::codes((new PageCountRule(exact: 2))->validate($pages(1))->violations));
        self::assertSame(['page_count.multiple_of'], self::codes((new PageCountRule(multipleOf: 4))->validate($pages(6))->violations));
        $v = $rule->validate($pages(3))->violations[0];
        self::assertSame(['max_pages' => 2], $v->expected);
        self::assertSame(['pages' => 3], $v->actual);
    }

    public function testPageCountConfigValidation(): void
    {
        $this->expectException(InvalidRuleConfigException::class);
        PageCountRule::fromConfig(['min' => 5, 'max' => 2]);
    }

    // --- boxes and bleed ----------------------------------------------------

    public function testBoxRequired(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::card(1, 85, 55), AnalysisBuilder::simple(2, 85, 55));
        $result = (new BoxRequiredRule(boxes: ['TrimBox', 'BleedBox']))->validate($doc);
        self::assertSame([[2, 'TrimBox'], [2, 'BleedBox']], array_map(static fn (Violation $v) => [$v->page, $v->expected['box']], $result->violations));
        self::assertSame('default', $result->violations[0]->actual['source']);
        self::assertSame('CropBox', $result->violations[0]->actual['derived_from']);
    }

    public function testBleedMargin(): void
    {
        $rule = new BleedMarginRule(minBleed: 3.0);
        self::assertSame(RuleStatus::Passed, $rule->validate(AnalysisBuilder::document(AnalysisBuilder::card(1, 85, 55, bleed: 3.0)))->status);

        $result = $rule->validate(AnalysisBuilder::document(AnalysisBuilder::card(1, 85, 55, bleed: 2.0)));
        self::assertSame(['bleed.insufficient'], self::codes($result->violations));
        self::assertEqualsWithDelta(2.0, $result->violations[0]->actual['bleed_mm']['left'], 0.001);
        self::assertEqualsWithDelta(2.0, (float) $result->violations[0]->params['actual_bleed'], 0.001);
    }

    public function testBleedOnlyOnOneSideIsInsufficient(): void
    {
        $page = AnalysisBuilder::page(1, [
            PageBox::MEDIA_BOX => [0.0, 0.0, 91.0, 61.0],
            PageBox::BLEED_BOX => [0.0, 0.0, 91.0, 61.0],
            PageBox::TRIM_BOX => [3.0, 3.0, 88.0, 61.0], // no bleed at the top
        ]);
        $result = (new BleedMarginRule())->validate(AnalysisBuilder::document($page));
        self::assertSame(0.0, $result->violations[0]->actual['bleed_mm']['top']);
    }

    public function testBleedRequiresDefinedBoxes(): void
    {
        // MediaBox includes 3 mm bleed but no BleedBox/TrimBox is defined.
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, 91, 61));
        $result = (new BleedMarginRule())->validate($doc);
        self::assertSame(['bleed.box_missing'], self::codes($result->violations));
        self::assertSame('TrimBox', $result->violations[0]->expected['box']);
        // Measuring derived boxes: TrimBox = BleedBox = CropBox -> 0 mm.
        $result = (new BleedMarginRule(requireDefinedBoxes: false))->validate($doc);
        self::assertSame(['bleed.insufficient'], self::codes($result->violations));
    }

    public function testBleedWithUserUnit(): void
    {
        // In user space the bleed is 1.5 mm; UserUnit 2 makes it 3 mm physically.
        $page = AnalysisBuilder::page(1, [
            PageBox::MEDIA_BOX => [0.0, 0.0, 45.5, 30.5],
            PageBox::BLEED_BOX => [0.0, 0.0, 45.5, 30.5],
            PageBox::TRIM_BOX => [1.5, 1.5, 44.0, 29.0],
        ], userUnit: 2.0);
        self::assertSame(RuleStatus::Passed, (new BleedMarginRule())->validate(AnalysisBuilder::document($page))->status);
    }

    // --- fonts ---------------------------------------------------------------

    public function testFontsEmbedded(): void
    {
        $page = AnalysisBuilder::page(1, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], fonts: [
            AnalysisBuilder::font('Embedded'),
            AnalysisBuilder::font('Arial', FontInfo::NOT_EMBEDDED),
            AnalysisBuilder::font('Broken', FontInfo::INVALID),
            AnalysisBuilder::font('DeclaredOnly', FontInfo::NOT_EMBEDDED, used: false),
            AnalysisBuilder::font('Invisible', FontInfo::NOT_EMBEDDED, visible: false),
            AnalysisBuilder::font('Helvetica', FontInfo::NOT_EMBEDDED, standard14: true),
        ]);
        $doc = AnalysisBuilder::document($page);

        $result = (new FontsEmbeddedRule())->validate($doc);
        self::assertSame(
            [['fonts.not_embedded', 'Arial'], ['fonts.invalid_embedding', 'Broken'], ['fonts.not_embedded', 'Helvetica']],
            array_map(static fn (Violation $v) => [$v->code, $v->params['font']], $result->violations),
        );
        self::assertSame(1, $result->violations[0]->page);

        $lenient = (new FontsEmbeddedRule(ignoreInvisibleText: false, allowStandard14: true))->validate($doc);
        self::assertSame(['Arial', 'Broken', 'Invisible'], array_map(static fn (Violation $v) => $v->params['font'], $lenient->violations));
    }

    public function testFontsAsWarning(): void
    {
        $page = AnalysisBuilder::page(1, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], fonts: [AnalysisBuilder::font('Arial', FontInfo::NOT_EMBEDDED)]);
        $result = (new FontsEmbeddedRule(options: new RuleOptions(severity: Severity::Warning)))->validate(AnalysisBuilder::document($page));
        self::assertSame(Severity::Warning, $result->violations[0]->severity);
    }

    public function testFontsIncompleteAnalysisIsUndetermined(): void
    {
        $page = AnalysisBuilder::page(1, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], contentComplete: false);
        $doc = AnalysisBuilder::document($page);

        $result = (new FontsEmbeddedRule())->validate($doc);
        self::assertSame(RuleStatus::Undetermined, $result->status);
        self::assertTrue($result->violations[0]->undetermined);
        self::assertSame(Severity::Error, $result->violations[0]->severity);

        $warn = (new FontsEmbeddedRule(options: new RuleOptions(onUndetermined: UndeterminedPolicy::Warning)))->validate($doc);
        self::assertSame(Severity::Warning, $warn->violations[0]->severity);

        $ignore = (new FontsEmbeddedRule(options: new RuleOptions(onUndetermined: UndeterminedPolicy::Ignore)))->validate($doc);
        self::assertSame(RuleStatus::Undetermined, $ignore->status);
        self::assertSame([], $ignore->violations);
    }

    public function testMissingFontResource(): void
    {
        $page = AnalysisBuilder::page(1, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], issues: [new Issue('font_resource_missing', 'x', 1)]);
        self::assertSame(['fonts.missing_resource'], self::codes((new FontsEmbeddedRule())->validate(AnalysisBuilder::document($page))->violations));
    }

    // --- colour pages ----------------------------------------------------------

    public function testColorPagesNeverGuessUnknownPages(): void
    {
        $doc = AnalysisBuilder::document(
            AnalysisBuilder::page(1, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], color: ColorClass::Color),
            AnalysisBuilder::page(2, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], color: ColorClass::Unknown),
        );
        $result = (new ColorPageCountRule(maxColor: 5))->validate($doc);
        self::assertSame(RuleStatus::Undetermined, $result->status);
        self::assertSame(['color_pages.undetermined'], self::codes($result->violations));
        self::assertSame([2], $result->violations[0]->params['unknown_pages']);
    }

    public function testColorPageCounts(): void
    {
        $page = static fn (int $n, ColorClass $c) => AnalysisBuilder::page($n, [PageBox::MEDIA_BOX => [0.0, 0.0, 210.0, 297.0]], color: $c);
        $doc = AnalysisBuilder::document($page(1, ColorClass::Color), $page(2, ColorClass::Color), $page(3, ColorClass::Grayscale));
        $result = (new ColorPageCountRule(maxColor: 1, minGrayscale: 2))->validate($doc);
        self::assertSame(['color_pages.too_many_color', 'color_pages.too_few_grayscale'], self::codes($result->violations));
        self::assertSame(RuleStatus::Passed, (new ColorPageCountRule(maxColor: 2, maxGrayscale: 1))->validate($doc)->status);
    }

    // --- file type ---------------------------------------------------------------

    public function testFileType(): void
    {
        $pdf = AnalysisBuilder::document(AnalysisBuilder::simple(1, 210, 297));
        $jpeg = DocumentAnalysis::unanalyzed('jpeg', 'photo.jpg');
        self::assertSame(RuleStatus::Passed, (new FileTypeRule())->validate($pdf)->status);
        $result = (new FileTypeRule())->validate($jpeg);
        self::assertSame(['file_type.not_allowed'], self::codes($result->violations));
        self::assertSame(['file_type' => 'jpeg'], $result->violations[0]->actual);
        self::assertSame(RuleStatus::Passed, (new FileTypeRule(allowImages: true))->validate($jpeg)->status);
    }

    public function testPageRulesOnUnanalyzedFilesAreUndetermined(): void
    {
        $result = (new PageCountRule(exact: 1))->validate(DocumentAnalysis::unanalyzed('png'));
        self::assertSame(RuleStatus::Undetermined, $result->status);
        self::assertSame(['document.not_analyzed'], self::codes($result->violations));
    }
}

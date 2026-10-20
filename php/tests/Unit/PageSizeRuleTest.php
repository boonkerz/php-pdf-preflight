<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintPreflight\Exceptions\InvalidRuleConfigException;
use PrintPreflight\Results\RuleStatus;
use PrintPreflight\Rules\PageSizeRule;
use PrintPreflight\Rules\RuleOptions;
use PrintPreflight\Rules\Severity;

final class PageSizeRuleTest extends TestCase
{
    public function testMatchingTrimBoxWithinTolerance(): void
    {
        $rule = new PageSizeRule(width: 85, height: 55, tolerance: 0.5, box: 'TrimBox');
        $result = $rule->validate(AnalysisBuilder::document(AnalysisBuilder::card(1, 85.4, 54.7)));
        self::assertSame(RuleStatus::Passed, $result->status);
    }

    public function testMismatchReportsExpectedAndActual(): void
    {
        $rule = new PageSizeRule(width: 85, height: 55, tolerance: 0.5, box: 'TrimBox');
        $result = $rule->validate(AnalysisBuilder::document(AnalysisBuilder::card(1, 85, 55), AnalysisBuilder::card(2, 90, 55)));

        self::assertSame(RuleStatus::Failed, $result->status);
        self::assertCount(1, $result->violations);
        $v = $result->violations[0];
        self::assertSame('page_size.mismatch', $v->code);
        self::assertSame(2, $v->page);
        self::assertSame(Severity::Error, $v->severity);
        self::assertSame([['width' => 85.0, 'height' => 55.0]], $v->expected['sizes']);
        self::assertSame(90.0, $v->actual['width']);
        self::assertSame(55.0, $v->actual['height']);
    }

    public function testSwappedOrientationAcceptedByDefault(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::card(1, 55, 85));
        self::assertSame(RuleStatus::Passed, (new PageSizeRule(width: 85, height: 55))->validate($doc)->status);
        self::assertSame(RuleStatus::Failed, (new PageSizeRule(width: 85, height: 55, allowSwapped: false))->validate($doc)->status);
    }

    public function testRotationIsApplied(): void
    {
        // 55 x 85 mm page rotated by 90° is displayed as 85 x 55 mm landscape.
        $doc = AnalysisBuilder::document(AnalysisBuilder::card(1, 55, 85, rotation: 90));
        $rule = new PageSizeRule(width: 85, height: 55, allowSwapped: false, orientation: 'landscape');
        self::assertSame(RuleStatus::Passed, $rule->validate($doc)->status);
    }

    public function testOrientation(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, 297, 210), AnalysisBuilder::simple(2, 210, 297));
        $result = (new PageSizeRule(format: 'A4', orientation: 'portrait', box: 'MediaBox'))->validate($doc);
        self::assertCount(1, $result->violations);
        self::assertSame('page_size.orientation', $result->violations[0]->code);
        self::assertSame(1, $result->violations[0]->page);
        self::assertSame('landscape', $result->violations[0]->actual['orientation']);
    }

    /**
     * @return iterable<string, array{string, float, float, bool}>
     */
    public static function formats(): iterable
    {
        yield 'A4' => ['A4', 210.0, 297.0, true];
        yield 'A4 lower case' => ['a4', 210.2, 296.8, true];
        yield 'A5 is not A4' => ['A4', 148.0, 210.0, false];
        yield 'A0' => ['A0', 841.0, 1189.0, true];
        yield 'A10' => ['A10', 26.0, 37.0, true];
        yield 'A6 landscape' => ['A6', 148.0, 105.0, true];
    }

    #[DataProvider('formats')]
    public function testDinFormats(string $format, float $w, float $h, bool $valid): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, $w, $h));
        $result = (new PageSizeRule(format: $format, box: 'MediaBox'))->validate($doc);
        self::assertSame($valid ? RuleStatus::Passed : RuleStatus::Failed, $result->status);
    }

    public function testSeveralAllowedFormats(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, 148, 210), AnalysisBuilder::simple(2, 105, 148));
        $result = (new PageSizeRule(format: ['A4', 'A5'], box: 'MediaBox'))->validate($doc);
        self::assertCount(1, $result->violations);
        self::assertSame(2, $result->violations[0]->page);
        self::assertSame([['format' => 'A4', 'width' => 210.0, 'height' => 297.0], ['format' => 'A5', 'width' => 148.0, 'height' => 210.0]], $result->violations[0]->expected['sizes']);
    }

    public function testWidthOnly(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, 100, 500), AnalysisBuilder::simple(2, 120, 500));
        $result = (new PageSizeRule(width: 100, box: 'MediaBox', allowSwapped: false))->validate($doc);
        self::assertSame([2], array_map(static fn ($v) => $v->page, $result->violations));
    }

    public function testMinMaxDimensions(): void
    {
        $rule = new PageSizeRule(minWidth: 50, maxWidth: 300, minHeight: 50, maxHeight: 300, box: 'MediaBox', tolerance: 0);
        $doc = AnalysisBuilder::document(
            AnalysisBuilder::simple(1, 100, 100),
            AnalysisBuilder::simple(2, 40, 100),
            AnalysisBuilder::simple(3, 100, 400),
        );
        $codes = array_map(static fn ($v) => [$v->page, $v->code], $rule->validate($doc)->violations);
        self::assertSame([[2, 'page_size.too_small'], [3, 'page_size.too_large']], $codes);
    }

    public function testRequireDefinedBox(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, 85, 55));
        self::assertSame(RuleStatus::Passed, (new PageSizeRule(width: 85, height: 55))->validate($doc)->status, 'derived TrimBox = MediaBox');
        $result = (new PageSizeRule(width: 85, height: 55, requireDefinedBox: true))->validate($doc);
        self::assertSame('page_size.box_missing', $result->violations[0]->code);
    }

    public function testPageSelection(): void
    {
        $doc = AnalysisBuilder::document(AnalysisBuilder::simple(1, 210, 297), AnalysisBuilder::simple(2, 100, 100), AnalysisBuilder::simple(3, 210, 297));
        self::assertSame(RuleStatus::Passed, (new PageSizeRule(format: 'A4', box: 'MediaBox', pages: 'odd'))->validate($doc)->status);
        self::assertSame(RuleStatus::Failed, (new PageSizeRule(format: 'A4', box: 'MediaBox', pages: [2]))->validate($doc)->status);
    }

    public function testWarningSeverity(): void
    {
        $rule = new PageSizeRule(format: 'A4', box: 'MediaBox', options: new RuleOptions(severity: Severity::Warning));
        $result = $rule->validate(AnalysisBuilder::document(AnalysisBuilder::simple(1, 100, 100)));
        self::assertSame(Severity::Warning, $result->violations[0]->severity);
    }

    public function testFromConfigUsesSnakeCaseParameters(): void
    {
        $rule = PageSizeRule::fromConfig(['width' => 85, 'height' => 55, 'allow_swapped' => false, 'require_defined_box' => true]);
        $result = $rule->validate(AnalysisBuilder::document(AnalysisBuilder::card(1, 55, 85)));
        self::assertSame('page_size.mismatch', $result->violations[0]->code);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown parameter' => [['width' => 85, 'colour' => 'red'], 'colour'];
        yield 'wrong type' => [['width' => '85mm'], 'width'];
        yield 'invalid box' => [['width' => 85, 'box' => 'FooBox'], 'box'];
        yield 'unknown format' => [['format' => 'B5'], 'B5'];
        yield 'format and width' => [['format' => 'A4', 'width' => 85], 'either'];
        yield 'negative tolerance' => [['width' => 85, 'tolerance' => -1], 'tolerance'];
        yield 'no constraint' => [['box' => 'TrimBox'], 'constraint'];
        yield 'invalid pages' => [['width' => 85, 'pages' => 'some'], 'page'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfig(array $params, string $messagePart): void
    {
        $this->expectException(InvalidRuleConfigException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');
        PageSizeRule::fromConfig($params);
    }
}

<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PrintPreflight\Analysis\FontInfo;
use PrintPreflight\Analysis\PageBox;
use PrintPreflight\Analyzer;
use PrintPreflight\Exceptions\AnalysisException;
use PrintPreflight\Preflight;
use PrintPreflight\Profile;
use PrintPreflight\Results\RuleStatus;
use PrintPreflight\Rules\FontsEmbeddedRule;
use PrintPreflight\Rules\PageCountRule;
use PrintPreflight\Rules\PageSizeRule;

/**
 * End-to-end tests: native extension + library + fixtures.
 */
#[RequiresPhpExtension('pdf_preflight')]
final class NativeIntegrationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../tests/fixtures';
    private const PROFILE = __DIR__ . '/../../../examples/profiles/business-card.json';

    public function testTargetApiOnRealFile(): void
    {
        $document = Analyzer::open(self::FIXTURES . '/business_card_bad.pdf');

        $preflight = new Preflight([
            new PageSizeRule(width: 85, height: 55, tolerance: 0.5, box: 'TrimBox'),
            new PageCountRule(min: 1, max: 2),
            new FontsEmbeddedRule(),
        ], 'business-card');
        $result = $preflight->check($document);

        self::assertFalse($result->isValid());
        self::assertSame(['page_size.mismatch', 'fonts.not_embedded'], array_map(static fn ($e) => $e->code, $result->getErrors()));
        self::assertSame('Seite 1: Die Schrift „Helvetica“ ist nicht eingebettet.', $result->getErrors()[1]->getMessage());
    }

    public function testBusinessCardProfile(): void
    {
        $preflight = Preflight::fromProfile(Profile::fromJson((string) file_get_contents(self::PROFILE)));

        $ok = $preflight->checkFile(self::FIXTURES . '/business_card_ok.pdf');
        self::assertTrue($ok->isValid(), $ok->toJson(JSON_PRETTY_PRINT));
        self::assertSame([], $ok->getWarnings());

        $bad = $preflight->checkFile(self::FIXTURES . '/business_card_bad.pdf');
        self::assertFalse($bad->isValid());
        $codes = array_map(static fn ($v) => $v->code, $bad->getErrors());
        self::assertSame(['page_size.mismatch', 'bleed.insufficient', 'fonts.not_embedded'], $codes);
        self::assertSame(
            'Seite 1: Das Endformat muss 85 × 55 mm betragen (gefunden: 90 × 55 mm).',
            $bad->getErrors()[0]->getMessage(),
            'profile-specific message',
        );
        self::assertStringContainsString('1 mm', $bad->getErrors()[1]->getMessage());
        self::assertSame(RuleStatus::Passed, $bad->getResult('box_required')?->status);
    }

    public function testAnalysisDetails(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/boxes.pdf');
        self::assertSame(7, $doc->getPageCount());
        $card = $doc->getPage(1);
        self::assertEqualsWithDelta(85.0, $card->getBox(PageBox::TRIM_BOX)->displayWidthMm, 0.01);
        self::assertTrue($doc->getPage(4)->hasIssue('non_inheritable_box_on_pages_node'));
        self::assertSame(PageBox::SOURCE_FALLBACK, $doc->getPage(7)->getBox(PageBox::MEDIA_BOX)->source);

        $fonts = Analyzer::open(self::FIXTURES . '/fonts.pdf');
        $names = array_map(static fn (FontInfo $f): string => $f->name, $fonts->getPage(2)->getUsedFonts());
        self::assertSame(['Courier'], $names, 'font inside nested form');
    }

    public function testRotatedPagesAgainstOrientationRule(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/rotated.pdf');
        $result = (new PageSizeRule(format: 'A4', orientation: 'portrait', box: 'MediaBox'))->validate($doc);
        // Pages 1, 3, 4 (90/270/-90) and 6 (inherited 90) are displayed landscape.
        self::assertSame([1, 3, 4, 6], array_map(static fn ($v) => $v->page, $result->violations));
    }

    public function testIncompleteAnalysisMakesFontRuleUndetermined(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/nested_forms.pdf');
        $result = (new FontsEmbeddedRule(options: null))->validate($doc);
        // Page 3 nests forms beyond the default depth limit.
        $undetermined = array_values(array_filter($result->violations, static fn ($v) => $v->undetermined));
        self::assertSame([3], array_map(static fn ($v) => $v->page, $undetermined));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableFiles(): iterable
    {
        yield 'password' => ['encrypted_user_password.pdf', AnalysisException::REASON_PASSWORD_REQUIRED];
        yield 'not a pdf' => ['damaged/not_a_pdf.pdf', 'unknown_type'];
        yield 'truncated' => ['damaged/truncated.pdf', AnalysisException::REASON_INVALID];
        yield 'empty' => ['damaged/empty.pdf', 'unknown_type'];
    }

    #[DataProvider('unreadableFiles')]
    public function testUnreadableFiles(string $file, string $reason): void
    {
        $preflight = Preflight::fromProfile(Profile::fromJson((string) file_get_contents(self::PROFILE)));
        $result = $preflight->checkFile(self::FIXTURES . '/' . $file);
        self::assertFalse($result->isValid());
        if ($reason === 'unknown_type') {
            // No PDF header: rejected by the (blocking) file type rule.
            self::assertSame('file_type.not_allowed', $result->getErrors()[0]->code);
            self::assertSame('Bitte laden Sie Ihre Visitenkarte als PDF-Datei hoch.', $result->getErrors()[0]->getMessage());
            return;
        }
        self::assertSame('document.' . $reason, $result->getErrors()[0]->code);
    }

    public function testOptionsArePassedToTheExtension(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/encrypted_user_password.pdf', ['password' => 'secret']);
        self::assertTrue($doc->encrypted);

        $this->expectException(AnalysisException::class);
        Analyzer::open(self::FIXTURES . '/single_a4.pdf', ['max_file_size' => 10]);
    }

    public function testColorClassificationAndColorPageCount(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/images.pdf');
        $classes = array_map(static fn ($p) => $p->colorClass->value, $doc->getPages());
        self::assertSame(['grayscale', 'grayscale', 'color', 'grayscale', 'color', 'grayscale', 'color', 'grayscale', 'grayscale', 'color', 'grayscale', 'grayscale', 'grayscale'], $classes);

        $result = (new Preflight([new \PrintPreflight\Rules\ColorPageCountRule(maxColor: 3, maxGrayscale: 9)]))->check($doc);
        self::assertSame(['color_pages.too_many_color'], array_map(static fn ($v) => $v->code, $result->getErrors()));
        self::assertSame('Das Dokument enthält 4 farbige Seite(n), erlaubt sind höchstens 3.', $result->getErrors()[0]->getMessage());
    }

    public function testImageResolutionWithRealPlacements(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/images.pdf');
        $result = (new \PrintPreflight\Rules\ImageResolutionRule(minDpi: 200, pages: [1, 2, 4]))->validate($doc);
        // Only the copy scaled to two inches (150 dpi) on page 2 is too low.
        self::assertCount(1, $result->violations);
        self::assertSame(2, $result->violations[0]->page);
        self::assertEqualsWithDelta(150.0, (float) $result->violations[0]->actual['dpi'], 0.1);
    }

    public function testSpotColorsAndForbiddenSpaces(): void
    {
        $doc = Analyzer::open(self::FIXTURES . '/colors.pdf');
        $result = (new Preflight([new \PrintPreflight\Rules\ColorSpaceRule(forbidden: ['rgb'], allowedSpotColors: [], pages: [2, 4])]))->check($doc);
        self::assertSame(
            [[2, 'color_spaces.forbidden'], [4, 'color_spaces.spot_not_allowed']],
            array_map(static fn ($v) => [$v->page, $v->code], $result->getErrors()),
        );
        self::assertSame('Seite 4: Die Sonderfarbe „HKS 13 K“ ist nicht zulässig.', $result->getErrors()[1]->getMessage());
    }

    public function testStoredAnalysisCanBeRecheckedWithoutParsing(): void
    {
        $json = Analyzer::open(self::FIXTURES . '/business_card_ok.pdf')->toJson();
        $restored = \PrintPreflight\Analysis\DocumentAnalysis::fromJson($json);
        $result = Preflight::fromProfile(Profile::fromJson((string) file_get_contents(self::PROFILE)))->check($restored);
        self::assertTrue($result->isValid());
    }
}

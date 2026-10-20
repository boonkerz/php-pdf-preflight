<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\FileAnalyzerInterface;
use PrintPreflight\Analyzer;
use PrintPreflight\Exceptions\AnalysisException;
use PrintPreflight\Preflight;
use PrintPreflight\Profile;
use PrintPreflight\Results\MessageFormatter;
use PrintPreflight\Results\RuleStatus;
use PrintPreflight\RuleRegistry;
use PrintPreflight\Rules\AbstractRule;
use PrintPreflight\Rules\FileTypeRule;
use PrintPreflight\Rules\Findings;
use PrintPreflight\Rules\FontsEmbeddedRule;
use PrintPreflight\Rules\PageCountRule;
use PrintPreflight\Rules\PageSizeRule;
use PrintPreflight\Rules\RuleOptions;
use PrintPreflight\Rules\Severity;

/**
 * Custom rule used to show that new rules need no engine changes.
 */
final class MaxRotationRule extends AbstractRule
{
    public function __construct(private readonly int $max = 0, ?RuleOptions $options = null)
    {
        parent::__construct($options);
    }

    public static function type(): string
    {
        return 'max_rotation';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($document->getPages() as $page) {
            if ($page->rotation > $this->max) {
                $findings->fail('max_rotation.exceeded', $page->number, ['max' => $this->max], ['rotation' => $page->rotation], ['rotation' => $page->rotation]);
            }
        }
    }
}

final class PreflightTest extends TestCase
{
    public function testTargetApiAndResultFormat(): void
    {
        $document = AnalysisBuilder::document(AnalysisBuilder::card(1, 90, 55));
        $preflight = new Preflight([
            new PageSizeRule(width: 85, height: 55, tolerance: 0.5, box: 'TrimBox'),
            new PageCountRule(min: 1, max: 2),
            new FontsEmbeddedRule(),
        ], 'business-card');

        $result = $preflight->check($document);

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->getErrors());
        self::assertSame('Seite 1: Das Format 90 × 55 mm (TrimBox) entspricht nicht dem geforderten Format 85 × 55 mm.', $result->getErrors()[0]->getMessage());

        $json = json_decode($result->toJson(), true);
        self::assertIsArray($json);
        self::assertFalse($json['valid']);
        self::assertSame('business-card', $json['profile']);
        self::assertSame([], $json['warnings']);
        $error = $json['errors'][0];
        self::assertSame('page_size', $error['rule']);
        self::assertSame('page_size.mismatch', $error['code']);
        self::assertSame('error', $error['severity']);
        self::assertSame(1, $error['page']);
        self::assertSame(['width' => 85.0, 'height' => 55.0], $error['expected']['sizes'][0]);
        self::assertSame(90.0, $error['actual']['width']);
        self::assertSame(55.0, $error['actual']['height']);
        self::assertSame(
            [['rule' => 'page_size', 'type' => 'page_size', 'status' => 'failed'], ['rule' => 'page_count', 'type' => 'page_count', 'status' => 'passed'], ['rule' => 'fonts_embedded', 'type' => 'fonts_embedded', 'status' => 'passed']],
            $json['rules'],
        );
    }

    public function testWarningsDoNotInvalidate(): void
    {
        $preflight = new Preflight([new PageCountRule(exact: 2, options: new RuleOptions(severity: Severity::Warning))]);
        $result = $preflight->check(AnalysisBuilder::document(AnalysisBuilder::simple(1, 210, 297)));
        self::assertTrue($result->isValid());
        self::assertCount(1, $result->getWarnings());
    }

    public function testBlockingFileTypeRuleSkipsRemainingRules(): void
    {
        $preflight = new Preflight([new FileTypeRule(), new PageCountRule(exact: 1)]);
        $result = $preflight->check(DocumentAnalysis::unanalyzed('jpeg'));
        self::assertSame(['file_type.not_allowed'], array_map(static fn ($v) => $v->code, $result->getErrors()));
        self::assertSame(RuleStatus::Skipped, $result->getResult('page_count')?->status);
    }

    public function testEnglishMessagesFromProfileLocale(): void
    {
        $profile = Profile::fromArray(['id' => 'p', 'name' => 'P', 'locale' => 'en', 'rules' => [['type' => 'page_count', 'params' => ['max' => 1]]]]);
        $result = Preflight::fromProfile($profile)->check(AnalysisBuilder::document(AnalysisBuilder::simple(1, 1, 1), AnalysisBuilder::simple(2, 1, 1)));
        self::assertSame('The document may have at most 1 page(s) but has 2.', $result->getErrors()[0]->getMessage());
    }

    public function testCustomRuleViaRegistry(): void
    {
        $registry = (new RuleRegistry())->register(MaxRotationRule::class);
        $profile = Profile::fromArray([
            'id' => 'p',
            'name' => 'P',
            'rules' => [['type' => 'max_rotation', 'params' => ['max' => 0], 'messages' => ['max_rotation.exceeded' => 'Seite {page} ist um {rotation}° gedreht.']]],
        ]);
        $result = Preflight::fromProfile($profile, $registry)->check(AnalysisBuilder::document(AnalysisBuilder::simple(1, 210, 297, rotation: 90)));
        self::assertSame('Seite 1 ist um 90° gedreht.', $result->getErrors()[0]->getMessage());
    }

    public function testUndeterminedIsReportedSeparately(): void
    {
        $result = (new Preflight([new FontsEmbeddedRule()]))->check(AnalysisBuilder::document(
            AnalysisBuilder::page(1, ['MediaBox' => [0.0, 0.0, 210.0, 297.0]], contentComplete: false),
        ));
        self::assertFalse($result->isValid());
        self::assertTrue($result->hasUndetermined());
        self::assertTrue($result->getErrors()[0]->undetermined);
    }

    public function testCheckFileConvertsAnalysisErrors(): void
    {
        $analyzer = new Analyzer([new class () implements FileAnalyzerInterface {
            public function supports(string $fileType): bool
            {
                return true;
            }

            public function analyze(string $path, string $fileType): DocumentAnalysis
            {
                throw new AnalysisException(AnalysisException::REASON_PASSWORD_REQUIRED, 'encrypted');
            }
        }]);
        $result = (new Preflight([new PageCountRule(min: 1)], 'p'))->checkFile(__FILE__, $analyzer);
        self::assertFalse($result->isValid());
        self::assertSame('document.password_required', $result->getErrors()[0]->code);
        self::assertStringContainsString('passwortgeschützt', $result->getErrors()[0]->getMessage());

        $missing = (new Preflight([]))->checkFile('/does/not/exist.pdf', $analyzer);
        self::assertSame('document.unreadable', $missing->getErrors()[0]->code);
    }

    public function testUnsupportedFileTypeWithoutAnalyzer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pf');
        self::assertIsString($file);
        file_put_contents($file, "\x89PNG\r\n\x1A\n....");
        try {
            $document = (new Analyzer([]))->analyze($file);
            self::assertSame('png', $document->fileType);
            self::assertFalse($document->isAnalyzed());
            $result = (new Preflight([new FileTypeRule(allowImages: true), new PageCountRule(exact: 1)], formatter: new MessageFormatter('de')))->check($document);
            self::assertSame(RuleStatus::Undetermined, $result->getResult('page_count')?->status, 'images cannot be checked yet');
            self::assertSame('Die Datei vom Typ „png“ kann nicht automatisch geprüft werden.', $result->getErrors()[0]->getMessage());
        } finally {
            unlink($file);
        }
    }
}

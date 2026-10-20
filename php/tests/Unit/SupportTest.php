<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintPreflight\FileType;
use PrintPreflight\PageFormat;
use PrintPreflight\Results\MessageFormatter;
use PrintPreflight\Results\Violation;
use PrintPreflight\Rules\PageSelector;
use PrintPreflight\Rules\Severity;

final class SupportTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int, list<int>}>
     */
    public static function selections(): iterable
    {
        yield 'all' => [null, 4, [1, 2, 3, 4]];
        yield 'first' => ['first', 4, [1]];
        yield 'last' => ['last', 4, [4]];
        yield 'odd' => ['odd', 5, [1, 3, 5]];
        yield 'even' => ['even', 5, [2, 4]];
        yield 'list' => [[1, '3-4'], 6, [1, 3, 4]];
        yield 'string' => ['1, 3-5, last', 8, [1, 3, 4, 5, 8]];
        yield 'out of range ignored' => [[2, 9], 3, [2]];
        yield 'empty document' => ['all', 0, []];
        yield 'int' => [2, 3, [2]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('selections')]
    public function testPageSelector(mixed $spec, int $count, array $expected): void
    {
        self::assertSame($expected, PageSelector::from($spec)->select($count));
    }

    public function testInvalidPageSelector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PageSelector::from([0]);
    }

    public function testPageFormats(): void
    {
        self::assertSame([210.0, 297.0], PageFormat::get('a4'));
        self::assertCount(11, array_filter(PageFormat::names(), static fn (string $n): bool => (bool) preg_match('/^A\d+$/', $n)));
        PageFormat::register('Visitenkarte', 85, 55);
        self::assertSame([55.0, 85.0], PageFormat::get('VISITENKARTE'));
    }

    public function testFileTypeDetection(): void
    {
        self::assertSame(FileType::PDF, FileType::detectFromBytes("%PDF-1.7\n"));
        self::assertSame(FileType::PDF, FileType::detectFromBytes("junk\n%PDF-1.4"));
        self::assertSame(FileType::JPEG, FileType::detectFromBytes("\xFF\xD8\xFF\xE0"));
        self::assertSame(FileType::PNG, FileType::detectFromBytes("\x89PNG\r\n\x1A\n"));
        self::assertSame(FileType::TIFF, FileType::detectFromBytes("II*\x00"));
        self::assertSame(FileType::UNKNOWN, FileType::detectFromBytes('PK'));
        self::assertSame(FileType::UNKNOWN, FileType::detect('/does/not/exist'));
    }

    public function testExtensionCompatibility(): void
    {
        $compatible = \PrintPreflight\Analysis\NativePdfAnalyzer::EXTENSION_VERSION . '.7';
        self::assertTrue(\PrintPreflight\Analysis\NativePdfAnalyzer::isCompatible($compatible));
        self::assertFalse(\PrintPreflight\Analysis\NativePdfAnalyzer::isCompatible('9.9.0'));
        self::assertFalse(\PrintPreflight\Analysis\NativePdfAnalyzer::isCompatible(''));
        $this->expectException(\PrintPreflight\Exceptions\IncompatibleExtensionException::class);
        \PrintPreflight\Analysis\NativePdfAnalyzer::assertCompatible('0.0.1');
    }

    public function testMessageFormatting(): void
    {
        $v = new Violation('page_size', 'page_size.mismatch', Severity::Error, 2, params: [
            'box' => 'TrimBox', 'actual_width' => 90.0, 'actual_height' => 55.25, 'expected' => [[85.0, 55.0], [55.0, 85.0]],
        ]);
        self::assertSame(
            'Seite 2: Das Format 90 × 55,25 mm (TrimBox) entspricht nicht dem geforderten Format 85 × 55 / 55 × 85 mm.',
            (new MessageFormatter('de'))->format($v),
        );
        self::assertStringStartsWith('Page 2: The size 90 × 55.25 mm', (new MessageFormatter('en'))->format($v));

        $custom = new Violation('x', 'page_size.orientation', Severity::Error, 1, params: ['expected_orientation' => 'portrait'], template: 'Bitte {expected_orientation} verwenden (Seite {page}).');
        self::assertSame('Bitte Hochformat verwenden (Seite 1).', (new MessageFormatter('de'))->format($custom));

        $unknownCode = new Violation('x', 'custom.code', Severity::Error);
        self::assertSame('custom.code', (new MessageFormatter('fr'))->format($unknownCode));
        self::assertSame('Eigene', (new MessageFormatter('de', ['de' => ['custom.code' => 'Eigene']]))->format($unknownCode));
    }
}

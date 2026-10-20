<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrintPreflight\Analysis\ColorClass;
use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\FontInfo;
use PrintPreflight\Analysis\PageBox;

/**
 * Maps recorded output of `PdfNative::analyze()` (tests/Unit/data/*.native.json)
 * without requiring the extension.
 */
final class DocumentAnalysisTest extends TestCase
{
    private static function native(string $name): DocumentAnalysis
    {
        $data = json_decode((string) file_get_contents(__DIR__ . "/data/{$name}.native.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return DocumentAnalysis::fromArray($data, "{$name}.pdf");
    }

    public function testMapsNativeOutput(): void
    {
        $doc = self::native('business_card_bad');
        self::assertSame('pdf', $doc->fileType);
        self::assertTrue($doc->isAnalyzed());
        self::assertSame('1.4', $doc->pdfVersion);
        self::assertSame(1, $doc->getPageCount());
        self::assertSame('business_card_bad.pdf', $doc->fileName);

        $page = $doc->getPage(1);
        $trim = $page->getBox(PageBox::TRIM_BOX);
        self::assertTrue($trim->defined);
        self::assertEqualsWithDelta(90.0, $trim->displayWidthMm, 0.01);
        self::assertEqualsWithDelta(55.0, $trim->displayHeightMm, 0.01);
        self::assertSame(PageBox::SOURCE_DEFAULT, $page->getBox(PageBox::CROP_BOX)->source);
        self::assertSame(ColorClass::Grayscale, $page->colorClass, 'gray text only');

        $fonts = $page->getUsedFonts();
        self::assertCount(1, $fonts);
        self::assertSame('Helvetica', $fonts[0]->name);
        self::assertSame(FontInfo::NOT_EMBEDDED, $fonts[0]->embedding);
    }

    public function testMapsFontsAndContentIssues(): void
    {
        $doc = self::native('fonts');
        self::assertSame(5, $doc->getPageCount());
        self::assertTrue($doc->getPage(5)->hasIssue('font_resource_missing'));
        $courier = array_values(array_filter($doc->fonts, static fn (FontInfo $f): bool => $f->name === 'Courier'))[0];
        self::assertSame([2], $courier->pages);
        self::assertSame(['form'], $doc->getPage(2)->fonts[0]->contexts);
    }

    public function testJsonRoundTrip(): void
    {
        foreach (['fonts', 'colors', 'images'] as $name) {
            $doc = self::native($name);
            self::assertEquals($doc, DocumentAnalysis::fromJson($doc->toJson()), $name);
        }
    }

    public function testMapsColors(): void
    {
        $doc = self::native('colors');
        self::assertSame(ColorClass::Grayscale, $doc->getPage(1)->colorClass);
        self::assertSame(ColorClass::Color, $doc->getPage(2)->colorClass);
        self::assertSame(ColorClass::Unknown, $doc->getPage(16)->colorClass);
        $spot = $doc->getPage(4)->colors;
        self::assertNotNull($spot);
        self::assertSame(['HKS 13 K'], $spot->spotColors);
        self::assertSame(['spot', 'gray'], $spot->usedCategories());
        $declared = $doc->getPage(14)->colors;
        self::assertNotNull($declared);
        self::assertSame(['gray'], $declared->usedCategories());
        self::assertSame(['Unused', 'UnusedSpot'], array_map(static fn ($c) => $c->name, $declared->declared));
        self::assertFalse($doc->getPage(10)->colors?->painted);
    }

    public function testMapsImages(): void
    {
        $doc = self::native('images');
        $page2 = $doc->getPage(2)->images;
        self::assertCount(2, $page2);
        $dpis = array_map(static fn ($i) => $i->getDpi(), $page2);
        sort($dpis);
        self::assertSame([150.0, 300.0], $dpis);
        $first = $doc->getPage(1)->images[0];
        self::assertSame('DeviceRGB', $first->colorSpace);
        self::assertSame('flate', $first->compression);
        self::assertSame(8, $first->bitsPerComponent);
        self::assertTrue($doc->getPage(5)->images[1]->isBitonal(), 'image mask');
        self::assertFalse($doc->getPage(9)->images[0]->onPage);
        self::assertTrue($doc->getPage(10)->images[0]->inline);
    }

    public function testRejectsMalformedData(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/pages\[0\]\.boxes/');
        DocumentAnalysis::fromArray(['document' => [], 'pages' => [['page' => 1, 'boxes' => 'none']]]);
    }
}

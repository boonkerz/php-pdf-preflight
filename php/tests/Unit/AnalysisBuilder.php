<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PrintPreflight\Analysis\ColorClass;
use PrintPreflight\Analysis\ColorReport;
use PrintPreflight\Analysis\ColorSpaceInfo;
use PrintPreflight\Analysis\ImagePlacement;
use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\FontInfo;
use PrintPreflight\Analysis\Issue;
use PrintPreflight\Analysis\PageAnalysis;
use PrintPreflight\Analysis\PageBox;

/**
 * Builds analyses for rule tests without PDF files or the extension.
 */
final class AnalysisBuilder
{
    private const MM_TO_PT = 72 / 25.4;

    /**
     * A page whose boxes are given in mm as [x0, y0, x1, y1]. Missing boxes
     * are derived like in PDF: CropBox from MediaBox, the others from CropBox.
     *
     * @param array<string, array{float, float, float, float}> $boxesMm
     * @param list<FontInfo> $fonts
     * @param list<Issue> $issues
     * @param list<ImagePlacement> $images
     */
    public static function page(
        int $number,
        array $boxesMm,
        int $rotation = 0,
        array $fonts = [],
        bool $contentComplete = true,
        ColorClass $color = ColorClass::Unknown,
        float $userUnit = 1.0,
        array $issues = [],
        array $images = [],
        ?ColorReport $colors = null,
    ): PageAnalysis {
        $media = $boxesMm[PageBox::MEDIA_BOX] ?? throw new \InvalidArgumentException('MediaBox required');
        $boxes = [];
        foreach (PageBox::NAMES as $name) {
            $derivedFrom = $name === PageBox::MEDIA_BOX ? null : ($name === PageBox::CROP_BOX ? PageBox::MEDIA_BOX : PageBox::CROP_BOX);
            $defined = isset($boxesMm[$name]);
            $rect = $boxesMm[$name] ?? $boxesMm[$derivedFrom ?? ''] ?? $boxesMm[PageBox::CROP_BOX] ?? $media;
            $w = ($rect[2] - $rect[0]) * $userUnit;
            $h = ($rect[3] - $rect[1]) * $userUnit;
            $swap = $rotation % 180 !== 0;
            $pt = array_map(static fn (float $v): float => $v * self::MM_TO_PT, $rect);
            $boxes[$name] = new PageBox(
                $name,
                $defined,
                $defined ? PageBox::SOURCE_PAGE : PageBox::SOURCE_DEFAULT,
                $defined ? null : $derivedFrom,
                [$pt[0], $pt[1], $pt[2], $pt[3]],
                [$pt[0], $pt[1], $pt[2], $pt[3]],
                $w,
                $h,
                $swap ? $h : $w,
                $swap ? $w : $h,
            );
        }
        return new PageAnalysis($number, $boxes, $rotation, $userUnit, $fonts, $issues, $contentComplete, $colors !== null ? $colors->classification : $color, $colors, $images);
    }

    /** A page of the given trim size with `bleed` mm on every side. */
    public static function card(int $number, float $width, float $height, float $bleed = 3.0, int $rotation = 0): PageAnalysis
    {
        return self::page($number, [
            PageBox::MEDIA_BOX => [0.0, 0.0, $width + 2 * $bleed, $height + 2 * $bleed],
            PageBox::BLEED_BOX => [0.0, 0.0, $width + 2 * $bleed, $height + 2 * $bleed],
            PageBox::TRIM_BOX => [$bleed, $bleed, $bleed + $width, $bleed + $height],
        ], $rotation);
    }

    /** A page with only a MediaBox. */
    public static function simple(int $number, float $width, float $height, int $rotation = 0): PageAnalysis
    {
        return self::page($number, [PageBox::MEDIA_BOX => [0.0, 0.0, $width, $height]], $rotation);
    }

    public static function document(PageAnalysis ...$pages): DocumentAnalysis
    {
        return new DocumentAnalysis('pdf', true, array_values($pages), pdfVersion: '1.7');
    }

    public static function image(float $dpi, int $bits = 8, bool $onPage = true, ?string $object = '5 0 R', ?float $dpiY = null): ImagePlacement
    {
        return new ImagePlacement($object, 1000, 1000, $dpi, $dpiY ?? $dpi, bitsPerComponent: $bits, onPage: $onPage);
    }

    /**
     * @param list<array{string, list<string>}> $used [category, targets] pairs
     * @param list<string> $spots
     */
    public static function colors(ColorClass $class, array $used = [], array $spots = []): ColorReport
    {
        return new ColorReport(
            $class,
            used: array_map(static fn (array $u): ColorSpaceInfo => new ColorSpaceInfo(ucfirst($u[0]), $u[0], targets: $u[1]), $used),
            spotColors: $spots,
        );
    }

    public static function font(string $name, string $embedding = FontInfo::EMBEDDED, bool $used = true, bool $visible = true, bool $standard14 = false): FontInfo
    {
        return new FontInfo($name, 'TrueType', $embedding, used: $used, visible: $visible, standard14: $standard14);
    }
}

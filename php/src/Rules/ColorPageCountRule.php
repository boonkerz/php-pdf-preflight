<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\ColorClass;
use PrintPreflight\Analysis\DocumentAnalysis;

/**
 * Limits the number of colour and black-and-white pages. If any page has an
 * unknown classification the counts are not reliable and the rule reports
 * "undetermined" instead of guessing.
 */
final class ColorPageCountRule extends AbstractRule
{
    public function __construct(
        private readonly ?int $minColor = null,
        private readonly ?int $maxColor = null,
        private readonly ?int $minGrayscale = null,
        private readonly ?int $maxGrayscale = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        if ($minColor === null && $maxColor === null && $minGrayscale === null && $maxGrayscale === null) {
            throw new \InvalidArgumentException('at least one of min_color, max_color, min_grayscale, max_grayscale is required');
        }
        foreach (compact('minColor', 'maxColor', 'minGrayscale', 'maxGrayscale') as $name => $value) {
            self::assertNonNegative($name, $value);
        }
    }

    public static function type(): string
    {
        return 'color_pages';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        $counts = ['color' => 0, 'grayscale' => 0, 'unknown' => []];
        foreach ($document->getPages() as $page) {
            match ($page->colorClass) {
                ColorClass::Color => $counts['color']++,
                ColorClass::Grayscale => $counts['grayscale']++,
                ColorClass::Unknown => $counts['unknown'][] = $page->number,
            };
        }
        if ($counts['unknown'] !== []) {
            $findings->undetermined('color_pages.undetermined', null, ['unknown_pages' => $counts['unknown']]);
            return;
        }

        $checks = [
            ['color_pages.too_few_color', $this->minColor, $counts['color'], 'min'],
            ['color_pages.too_many_color', $this->maxColor, $counts['color'], 'max'],
            ['color_pages.too_few_grayscale', $this->minGrayscale, $counts['grayscale'], 'min'],
            ['color_pages.too_many_grayscale', $this->maxGrayscale, $counts['grayscale'], 'max'],
        ];
        foreach ($checks as [$code, $limit, $actual, $kind]) {
            if ($limit === null || ($kind === 'min' ? $actual >= $limit : $actual <= $limit)) {
                continue;
            }
            $findings->fail($code, null, [$kind => $limit], ['pages' => $actual], [$kind => $limit, 'actual' => $actual]);
        }
    }
}

<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\PageBox;
use PrintPreflight\PageFormat;

/**
 * Checks the size of a page box on each selected page.
 *
 * Sizes are compared as displayed (after /Rotate, including /UserUnit).
 * With `allowSwapped` (default) a page also matches if width and height are
 * exchanged, i.e. the orientation is checked separately via `orientation`.
 */
final class PageSizeRule extends AbstractRule
{
    public const PORTRAIT = 'portrait';
    public const LANDSCAPE = 'landscape';

    /** @var list<array{name: string|null, width: float, height: float}> */
    private readonly array $sizes;
    private readonly PageSelector $pageSelector;

    /**
     * @param float|null $width Required width in mm (with $height, or alone)
     * @param float|null $height Required height in mm
     * @param string|list<string>|null $format Named format(s), e.g. "A4" or ["A4", "A5"]
     * @param float $tolerance Allowed deviation in mm
     * @param string $box Box to measure: MediaBox, CropBox, BleedBox, TrimBox, ArtBox
     * @param string|null $orientation portrait, landscape or null (any)
     * @param bool $requireDefinedBox Fail if the box is only derived (e.g. TrimBox falling back to CropBox)
     * @param mixed $pages Page selection, see PageSelector
     */
    public function __construct(
        ?float $width = null,
        ?float $height = null,
        string|array|null $format = null,
        private readonly float $tolerance = 0.5,
        private readonly string $box = PageBox::TRIM_BOX,
        private readonly ?string $orientation = null,
        private readonly bool $allowSwapped = true,
        private readonly ?float $minWidth = null,
        private readonly ?float $maxWidth = null,
        private readonly ?float $minHeight = null,
        private readonly ?float $maxHeight = null,
        private readonly bool $requireDefinedBox = false,
        mixed $pages = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        self::assertOneOf('box', $box, PageBox::NAMES);
        if ($orientation !== null) {
            self::assertOneOf('orientation', $orientation, [self::PORTRAIT, self::LANDSCAPE]);
        }
        foreach (compact('width', 'height', 'tolerance', 'minWidth', 'maxWidth', 'minHeight', 'maxHeight') as $name => $value) {
            self::assertNonNegative($name, $value);
        }
        if ($minWidth !== null && $maxWidth !== null && $minWidth > $maxWidth) {
            throw new \InvalidArgumentException('min_width must not be greater than max_width');
        }
        if ($minHeight !== null && $maxHeight !== null && $minHeight > $maxHeight) {
            throw new \InvalidArgumentException('min_height must not be greater than max_height');
        }

        $sizes = [];
        if ($format !== null && ($width !== null || $height !== null)) {
            throw new \InvalidArgumentException('use either format or width/height');
        }
        foreach ($format === null ? [] : (array) $format as $name) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('format must be a string or a list of strings');
            }
            [$w, $h] = PageFormat::get($name);
            $sizes[] = ['name' => strtoupper($name), 'width' => $w, 'height' => $h];
        }
        if ($width !== null || $height !== null) {
            // A missing dimension is unconstrained.
            $sizes[] = ['name' => null, 'width' => $width ?? -1.0, 'height' => $height ?? -1.0];
        }
        $this->sizes = $sizes;
        $this->pageSelector = PageSelector::from($pages);

        if ($sizes === [] && $orientation === null && $minWidth === null && $maxWidth === null
            && $minHeight === null && $maxHeight === null && !$requireDefinedBox) {
            throw new \InvalidArgumentException('no size constraint given (format, width/height, orientation or min/max)');
        }
    }

    public static function type(): string
    {
        return 'page_size';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($this->pageSelector->select($document->getPageCount()) as $number) {
            $page = $document->getPage($number);
            $box = $page->getBox($this->box);
            if ($this->requireDefinedBox && !$box->defined) {
                $findings->fail('page_size.box_missing', $number, ['box_defined' => true], ['box_source' => $box->source], ['box' => $this->box]);
                continue;
            }
            $w = $box->displayWidthMm;
            $h = $box->displayHeightMm;
            $actual = ['width' => round($w, 3), 'height' => round($h, 3), 'box' => $this->box];
            $params = ['box' => $this->box, 'actual_width' => $w, 'actual_height' => $h];

            if ($this->orientation !== null && abs($w - $h) > $this->tolerance) {
                $actualOrientation = $w > $h ? self::LANDSCAPE : self::PORTRAIT;
                if ($actualOrientation !== $this->orientation) {
                    $findings->fail(
                        'page_size.orientation',
                        $number,
                        ['orientation' => $this->orientation],
                        $actual + ['orientation' => $actualOrientation],
                        $params + ['expected_orientation' => $this->orientation, 'actual_orientation' => $actualOrientation],
                    );
                }
            }

            if ($this->sizes !== [] && !$this->matchesAnySize($w, $h)) {
                $findings->fail(
                    'page_size.mismatch',
                    $number,
                    [
                        'sizes' => array_map(fn (array $s): array => array_filter([
                            'format' => $s['name'],
                            'width' => $s['width'] < 0 ? null : $s['width'],
                            'height' => $s['height'] < 0 ? null : $s['height'],
                        ], static fn ($v): bool => $v !== null), $this->sizes),
                        'tolerance' => $this->tolerance,
                        'swapped_allowed' => $this->allowSwapped,
                    ],
                    $actual,
                    $params + [
                        'expected' => array_map(
                            static fn (array $s): array => [$s['width'] < 0 ? '–' : $s['width'], $s['height'] < 0 ? '–' : $s['height']],
                            $this->sizes,
                        ),
                        'expected_format' => array_values(array_filter(array_column($this->sizes, 'name'))),
                        'tolerance' => $this->tolerance,
                    ],
                );
            }

            $range = $this->rangeViolation($w, $h);
            if ($range !== null) {
                $findings->fail(
                    'page_size.' . $range,
                    $number,
                    array_filter([
                        'min_width' => $this->minWidth,
                        'max_width' => $this->maxWidth,
                        'min_height' => $this->minHeight,
                        'max_height' => $this->maxHeight,
                    ], static fn ($v): bool => $v !== null),
                    $actual,
                    $params + [
                        'min_width' => $this->minWidth ?? '–',
                        'min_height' => $this->minHeight ?? '–',
                        'max_width' => $this->maxWidth ?? '–',
                        'max_height' => $this->maxHeight ?? '–',
                    ],
                );
            }
        }
    }

    private function matchesAnySize(float $w, float $h): bool
    {
        foreach ($this->sizes as $size) {
            if ($this->matches($w, $h, $size['width'], $size['height'])
                || ($this->allowSwapped && $this->matches($w, $h, $size['height'], $size['width']))) {
                return true;
            }
        }
        return false;
    }

    private function matches(float $w, float $h, float $expectedW, float $expectedH): bool
    {
        return ($expectedW < 0 || abs($w - $expectedW) <= $this->tolerance)
            && ($expectedH < 0 || abs($h - $expectedH) <= $this->tolerance);
    }

    /**
     * @return 'too_small'|'too_large'|null
     */
    private function rangeViolation(float $w, float $h): ?string
    {
        $candidates = $this->allowSwapped ? [[$w, $h], [$h, $w]] : [[$w, $h]];
        $smallest = null;
        foreach ($candidates as [$cw, $ch]) {
            $tooSmall = ($this->minWidth !== null && $cw < $this->minWidth - $this->tolerance)
                || ($this->minHeight !== null && $ch < $this->minHeight - $this->tolerance);
            $tooLarge = ($this->maxWidth !== null && $cw > $this->maxWidth + $this->tolerance)
                || ($this->maxHeight !== null && $ch > $this->maxHeight + $this->tolerance);
            if (!$tooSmall && !$tooLarge) {
                return null;
            }
            $smallest ??= $tooSmall ? 'too_small' : 'too_large';
        }
        return $smallest;
    }
}

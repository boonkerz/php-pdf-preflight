<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;

/**
 * Checks the effective resolution of placed images (pixels per inch of the
 * placed size, including scaling in nested forms). Every distinct placement
 * of the same image is checked separately.
 */
final class ImageResolutionRule extends AbstractRule
{
    private readonly PageSelector $pageSelector;

    /**
     * @param float|null $minDpi Minimum effective resolution for colour/gray images
     * @param float|null $maxDpi Maximum effective resolution (oversized images)
     * @param float|null $minDpiBitonal Minimum for 1-bit images and image masks (line art);
     *     defaults to $minDpi
     * @param bool $onlyOnPage Ignore placements entirely outside the CropBox
     */
    public function __construct(
        private readonly ?float $minDpi = 300.0,
        private readonly ?float $maxDpi = null,
        private readonly ?float $minDpiBitonal = null,
        private readonly bool $onlyOnPage = true,
        mixed $pages = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        foreach (compact('minDpi', 'maxDpi', 'minDpiBitonal') as $name => $value) {
            if ($value !== null && $value <= 0) {
                throw new \InvalidArgumentException("{$name} must be positive");
            }
        }
        if ($minDpi === null && $maxDpi === null && $minDpiBitonal === null) {
            throw new \InvalidArgumentException('at least one of min_dpi, max_dpi, min_dpi_bitonal is required');
        }
        $this->pageSelector = PageSelector::from($pages);
    }

    public static function type(): string
    {
        return 'image_resolution';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($this->pageSelector->select($document->getPageCount()) as $number) {
            $page = $document->getPage($number);
            foreach ($page->images as $image) {
                if ($this->onlyOnPage && !$image->onPage) {
                    continue;
                }
                $dpi = $image->getDpi();
                $min = $image->isBitonal() ? ($this->minDpiBitonal ?? $this->minDpi) : $this->minDpi;
                $actual = [
                    'image' => $image->label(),
                    'dpi' => round($dpi, 1),
                    'dpi_x' => round($image->dpiX, 1),
                    'dpi_y' => round($image->dpiY, 1),
                    'width_px' => $image->widthPx,
                    'height_px' => $image->heightPx,
                    'placed_width_mm' => $image->placedWidthMm,
                    'placed_height_mm' => $image->placedHeightMm,
                ];
                $params = ['image' => $image->label(), 'dpi' => round($dpi), 'width_px' => $image->widthPx, 'height_px' => $image->heightPx];
                if ($min !== null && $dpi < $min) {
                    $findings->fail('image_resolution.too_low', $number, ['min_dpi' => $min], $actual, $params + ['min_dpi' => $min]);
                }
                if ($this->maxDpi !== null && $dpi > $this->maxDpi) {
                    $findings->fail('image_resolution.too_high', $number, ['max_dpi' => $this->maxDpi], $actual, $params + ['max_dpi' => $this->maxDpi]);
                }
            }
            if (!$page->contentComplete) {
                $findings->undetermined('image_resolution.analysis_incomplete', $number);
            }
        }
    }
}

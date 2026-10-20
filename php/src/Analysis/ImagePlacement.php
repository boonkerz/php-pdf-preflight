<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * An image placed on a page. Identical placements (same image, same
 * resolution) are aggregated; `placements` counts them.
 */
final class ImagePlacement
{
    /**
     * @param list<string> $filters
     * @param list<string> $contexts page, form, pattern, annotation
     * @param string|null $colorClass grayscale, color, unknown, no_ink or null (not checked)
     */
    public function __construct(
        public readonly ?string $object,
        public readonly int $widthPx,
        public readonly int $heightPx,
        public readonly float $dpiX,
        public readonly float $dpiY,
        public readonly float $placedWidthMm = 0.0,
        public readonly float $placedHeightMm = 0.0,
        public readonly ?string $colorSpace = null,
        public readonly ?string $colorCategory = null,
        public readonly ?int $bitsPerComponent = null,
        public readonly string $compression = 'none',
        public readonly array $filters = [],
        public readonly bool $imageMask = false,
        public readonly bool $inline = false,
        public readonly bool $onPage = true,
        public readonly int $placements = 1,
        public readonly array $contexts = [],
        public readonly ?string $colorClass = null,
    ) {
    }

    /** Effective resolution: the lower of the horizontal and vertical value. */
    public function getDpi(): float
    {
        return min($this->dpiX, $this->dpiY);
    }

    /** 1-bit images and stencil masks (line art). */
    public function isBitonal(): bool
    {
        return $this->imageMask || $this->bitsPerComponent === 1;
    }

    public function label(): string
    {
        return $this->object ?? 'inline';
    }

    public static function fromArray(ArrayReader $r): self
    {
        return new self(
            object: $r->nullableString('object'),
            widthPx: $r->int('width_px'),
            heightPx: $r->int('height_px'),
            dpiX: $r->float('effective_dpi_x'),
            dpiY: $r->float('effective_dpi_y'),
            placedWidthMm: $r->float('placed_width_mm', 0.0),
            placedHeightMm: $r->float('placed_height_mm', 0.0),
            colorSpace: $r->nullableString('color_space'),
            colorCategory: $r->nullableString('color_category'),
            bitsPerComponent: $r->nullableInt('bits_per_component'),
            compression: $r->string('compression', 'none'),
            filters: $r->stringList('filters'),
            imageMask: $r->bool('image_mask', false),
            inline: $r->bool('inline', false),
            onPage: $r->bool('on_page', true),
            placements: $r->int('placements', 1),
            contexts: $r->stringList('contexts'),
            colorClass: $r->nullableString('color_class'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'object' => $this->object,
            'width_px' => $this->widthPx,
            'height_px' => $this->heightPx,
            'effective_dpi_x' => $this->dpiX,
            'effective_dpi_y' => $this->dpiY,
            'effective_dpi' => $this->getDpi(),
            'placed_width_mm' => $this->placedWidthMm,
            'placed_height_mm' => $this->placedHeightMm,
            'color_space' => $this->colorSpace,
            'color_category' => $this->colorCategory,
            'bits_per_component' => $this->bitsPerComponent,
            'compression' => $this->compression,
            'filters' => $this->filters,
            'image_mask' => $this->imageMask,
            'inline' => $this->inline,
            'on_page' => $this->onPage,
            'placements' => $this->placements,
            'contexts' => $this->contexts,
            'color_class' => $this->colorClass,
        ];
    }
}

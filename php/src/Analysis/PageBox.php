<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * A page boundary box. Sizes in mm include /UserUnit; `display*` values are
 * after applying the page's /Rotate.
 */
final class PageBox
{
    public const MEDIA_BOX = 'MediaBox';
    public const CROP_BOX = 'CropBox';
    public const BLEED_BOX = 'BleedBox';
    public const TRIM_BOX = 'TrimBox';
    public const ART_BOX = 'ArtBox';
    public const NAMES = [self::MEDIA_BOX, self::CROP_BOX, self::BLEED_BOX, self::TRIM_BOX, self::ART_BOX];

    /** Defined in the page dictionary. */
    public const SOURCE_PAGE = 'page';
    /** Inherited from the page tree. */
    public const SOURCE_INHERITED = 'inherited';
    /** Not defined; derived by the PDF default rules (see `derivedFrom`). */
    public const SOURCE_DEFAULT = 'default';
    /** Required box missing or invalid; a fallback value is used. */
    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @param array{float, float, float, float} $rect Box as defined (pt, normalised)
     * @param array{float, float, float, float} $effectiveRect Box intersected with the MediaBox (pt)
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $defined,
        public readonly string $source,
        public readonly ?string $derivedFrom,
        public readonly array $rect,
        public readonly array $effectiveRect,
        public readonly float $widthMm,
        public readonly float $heightMm,
        public readonly float $displayWidthMm,
        public readonly float $displayHeightMm,
        public readonly bool $exceedsMediaBox = false,
    ) {
    }

    public static function fromArray(string $name, ArrayReader $r): self
    {
        return new self(
            $name,
            $r->bool('defined'),
            $r->string('source'),
            $r->nullableString('derived_from'),
            self::rect($r->floatList('rect')),
            self::rect($r->floatList($r->has('effective_rect') ? 'effective_rect' : 'rect')),
            $r->float('width_mm'),
            $r->float('height_mm'),
            $r->float('display_width_mm'),
            $r->float('display_height_mm'),
            $r->bool('exceeds_media_box', false),
        );
    }

    /**
     * @param list<float> $values
     * @return array{float, float, float, float}
     */
    private static function rect(array $values): array
    {
        if (count($values) !== 4) {
            throw new \UnexpectedValueException('a rectangle needs four numbers');
        }
        return [$values[0], $values[1], $values[2], $values[3]];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'defined' => $this->defined,
            'source' => $this->source,
            'derived_from' => $this->derivedFrom,
            'rect' => $this->rect,
            'effective_rect' => $this->effectiveRect,
            'width_mm' => $this->widthMm,
            'height_mm' => $this->heightMm,
            'display_width_mm' => $this->displayWidthMm,
            'display_height_mm' => $this->displayHeightMm,
            'exceeds_media_box' => $this->exceedsMediaBox,
        ];
    }
}

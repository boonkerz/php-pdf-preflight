<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * A colour space declared in resources or used for painting.
 */
final class ColorSpaceInfo
{
    public const CATEGORIES = ['gray', 'rgb', 'cmyk', 'lab', 'spot', 'pattern', 'unknown'];

    /**
     * @param string $category gray, rgb, cmyk, lab, spot, pattern or unknown
     * @param list<string> $colorants Separation/DeviceN colorant names
     * @param list<string> $targets Usage: fill, stroke, text, image, image_mask, shading (used spaces only)
     * @param bool|null $neutral Only gray/no ink painted (true), colour painted (false), undecidable (null)
     * @param string|null $name Resource name (declared spaces only)
     */
    public function __construct(
        public readonly string $family,
        public readonly string $category,
        public readonly int $components = 0,
        public readonly array $colorants = [],
        public readonly array $targets = [],
        public readonly ?bool $neutral = null,
        public readonly ?string $name = null,
        public readonly ?string $object = null,
    ) {
    }

    public static function fromArray(ArrayReader $r): self
    {
        return new self(
            family: $r->string('family'),
            category: $r->string('category', 'unknown'),
            components: $r->int('components', 0),
            colorants: $r->stringList('colorants'),
            targets: $r->stringList('targets'),
            neutral: $r->has('neutral') ? $r->bool('neutral') : null,
            name: $r->nullableString('name'),
            object: $r->nullableString('object'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'category' => $this->category,
            'components' => $this->components,
            'colorants' => $this->colorants,
            'targets' => $this->targets,
            'neutral' => $this->neutral,
            'name' => $this->name,
            'object' => $this->object,
        ];
    }
}

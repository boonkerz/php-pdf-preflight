<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * Colour analysis of a page: what is declared, what is actually painted and
 * the resulting print classification.
 */
final class ColorReport
{
    /**
     * @param list<ColorSpaceInfo> $used Colour spaces used by painting operators
     * @param list<ColorSpaceInfo> $declared Colour spaces defined in executed resources
     * @param list<string> $spotColors Spot colours painted with a tint > 0
     * @param list<string> $declaredSpotColors Spot colours defined in the resources
     * @param list<string> $reasons Why the classification is unknown
     */
    public function __construct(
        public readonly ColorClass $classification,
        public readonly bool $painted = true,
        public readonly array $used = [],
        public readonly array $declared = [],
        public readonly array $spotColors = [],
        public readonly array $declaredSpotColors = [],
        public readonly array $reasons = [],
    ) {
    }

    /**
     * @return list<string> Categories of colour spaces used for painting
     */
    public function usedCategories(): array
    {
        return array_values(array_unique(array_map(static fn (ColorSpaceInfo $c): string => $c->category, $this->used)));
    }

    public static function fromArray(ArrayReader $r): self
    {
        return new self(
            classification: ColorClass::tryFrom($r->string('classification', 'unknown')) ?? ColorClass::Unknown,
            painted: $r->bool('painted', true),
            used: array_map(ColorSpaceInfo::fromArray(...), $r->children('used')),
            declared: array_map(ColorSpaceInfo::fromArray(...), $r->children('declared')),
            spotColors: $r->stringList('spot_colors'),
            declaredSpotColors: $r->stringList('declared_spot_colors'),
            reasons: $r->stringList('reasons'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification->value,
            'painted' => $this->painted,
            'used' => array_map(static fn (ColorSpaceInfo $c): array => $c->toArray(), $this->used),
            'declared' => array_map(static fn (ColorSpaceInfo $c): array => $c->toArray(), $this->declared),
            'spot_colors' => $this->spotColors,
            'declared_spot_colors' => $this->declaredSpotColors,
            'reasons' => $this->reasons,
        ];
    }
}

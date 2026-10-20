<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

final class PageAnalysis
{
    /**
     * @param int $number 1-based page number
     * @param array<string, PageBox> $boxes All five boxes, keyed by name
     * @param list<FontInfo> $fonts Fonts of this page (declared and used)
     * @param list<Issue> $issues Geometry and content issues of this page
     * @param bool $contentComplete False if the content analysis hit a limit
     *     or an unreadable stream; content-based results are then a lower bound
     * @param ColorReport|null $colors Colour analysis (null if not available,
     *     e.g. in analyses stored before colour analysis existed)
     * @param list<ImagePlacement> $images
     */
    public function __construct(
        public readonly int $number,
        public readonly array $boxes,
        public readonly int $rotation = 0,
        public readonly float $userUnit = 1.0,
        public readonly array $fonts = [],
        public readonly array $issues = [],
        public readonly bool $contentComplete = true,
        public readonly ColorClass $colorClass = ColorClass::Unknown,
        public readonly ?ColorReport $colors = null,
        public readonly array $images = [],
    ) {
        foreach (PageBox::NAMES as $name) {
            if (!isset($boxes[$name])) {
                throw new \InvalidArgumentException("page {$number}: box {$name} is missing");
            }
        }
    }

    public function getBox(string $name): PageBox
    {
        return $this->boxes[$name] ?? throw new \InvalidArgumentException("unknown page box '{$name}'");
    }

    /** Displayed width in mm (CropBox, after rotation). */
    public function getWidthMm(): float
    {
        return $this->boxes[PageBox::CROP_BOX]->displayWidthMm;
    }

    /** Displayed height in mm (CropBox, after rotation). */
    public function getHeightMm(): float
    {
        return $this->boxes[PageBox::CROP_BOX]->displayHeightMm;
    }

    /**
     * @return list<FontInfo>
     */
    public function getUsedFonts(): array
    {
        return array_values(array_filter($this->fonts, static fn (FontInfo $f): bool => $f->used));
    }

    public function hasIssue(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Accepts the output of `PdfNative::analyze()` as well as `toArray()`.
     */
    public static function fromArray(ArrayReader $r): self
    {
        $boxes = [];
        $boxData = $r->child('boxes');
        foreach (PageBox::NAMES as $name) {
            $boxes[$name] = PageBox::fromArray($name, $boxData->child($name));
        }
        $issues = array_map(Issue::fromArray(...), [...$r->children('issues'), ...$r->children('content_issues')]);
        $complete = $r->has('analysis_complete') ? $r->bool('analysis_complete') : $r->bool('content_complete', true);
        $colors = $r->has('colors') ? ColorReport::fromArray($r->child('colors')) : null;

        return new self(
            number: $r->int('page'),
            boxes: $boxes,
            rotation: $r->int('rotation', 0),
            userUnit: $r->float('user_unit', 1.0),
            fonts: array_map(FontInfo::fromArray(...), $r->children('fonts')),
            issues: $issues,
            contentComplete: $complete,
            colorClass: $colors !== null ? $colors->classification : (ColorClass::tryFrom($r->string('color_class', 'unknown')) ?? ColorClass::Unknown),
            colors: $colors,
            images: array_map(ImagePlacement::fromArray(...), $r->children('images')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->number,
            'width_mm' => $this->getWidthMm(),
            'height_mm' => $this->getHeightMm(),
            'rotation' => $this->rotation,
            'user_unit' => $this->userUnit,
            'boxes' => array_map(static fn (PageBox $b): array => $b->toArray(), $this->boxes),
            'fonts' => array_map(static fn (FontInfo $f): array => $f->toArray(), $this->fonts),
            'issues' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues),
            'content_complete' => $this->contentComplete,
            'color_class' => $this->colorClass->value,
            'colors' => $this->colors?->toArray(),
            'images' => array_map(static fn (ImagePlacement $i): array => $i->toArray(), $this->images),
        ];
    }
}

<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\ColorSpaceInfo;
use PrintPreflight\Analysis\DocumentAnalysis;

/**
 * Restricts the colour spaces *used for painting* (declared but unused
 * colour spaces are ignored) and the spot colours.
 */
final class ColorSpaceRule extends AbstractRule
{
    /** @var list<string> */
    private readonly array $forbidden;
    /** @var list<string> */
    private readonly array $targets;
    /** @var list<string>|null */
    private readonly ?array $allowedSpotColors;
    private readonly PageSelector $pageSelector;

    /**
     * @param list<string> $forbidden Forbidden categories: gray, rgb, cmyk, lab, spot, pattern, unknown
     * @param list<string> $targets Restrict the check to fill, stroke, text, image, image_mask, shading (empty: all)
     * @param list<string>|null $allowedSpotColors Allowed spot colour names (null: any)
     * @param int|null $maxSpotColors Maximum number of distinct spot colours in the document
     */
    public function __construct(
        array $forbidden = [],
        array $targets = [],
        ?array $allowedSpotColors = null,
        private readonly ?int $maxSpotColors = null,
        mixed $pages = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        foreach ($forbidden as $category) {
            if (!is_string($category)) {
                throw new \InvalidArgumentException('forbidden must be a list of categories');
            }
            self::assertOneOf('forbidden', $category, ColorSpaceInfo::CATEGORIES);
        }
        foreach ($targets as $target) {
            if (!is_string($target)) {
                throw new \InvalidArgumentException('targets must be a list of strings');
            }
            self::assertOneOf('targets', $target, ['fill', 'stroke', 'text', 'image', 'image_mask', 'shading']);
        }
        foreach ($allowedSpotColors ?? [] as $spot) {
            if (!is_string($spot)) {
                throw new \InvalidArgumentException('allowed_spot_colors must be a list of names');
            }
        }
        self::assertNonNegative('max_spot_colors', $maxSpotColors);
        if ($forbidden === [] && $allowedSpotColors === null && $maxSpotColors === null) {
            throw new \InvalidArgumentException('at least one of forbidden, allowed_spot_colors, max_spot_colors is required');
        }
        $this->forbidden = array_values($forbidden);
        $this->targets = array_values($targets);
        $this->allowedSpotColors = $allowedSpotColors === null ? null : array_values($allowedSpotColors);
        $this->pageSelector = PageSelector::from($pages);
    }

    public static function type(): string
    {
        return 'color_spaces';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        $allSpots = [];
        foreach ($this->pageSelector->select($document->getPageCount()) as $number) {
            $page = $document->getPage($number);
            if ($page->colors === null) {
                $findings->undetermined('color_spaces.analysis_incomplete', $number);
                continue;
            }
            foreach ($page->colors->used as $space) {
                $targets = $this->targets === [] ? $space->targets : array_values(array_intersect($space->targets, $this->targets));
                if ($targets === [] || !in_array($space->category, $this->forbidden, true)) {
                    continue;
                }
                $findings->fail(
                    'color_spaces.forbidden',
                    $number,
                    ['forbidden' => $this->forbidden],
                    ['category' => $space->category, 'family' => $space->family, 'targets' => $targets],
                    ['category' => $space->category, 'family' => $space->family, 'targets' => $targets],
                );
            }
            foreach ($page->colors->spotColors as $spot) {
                $allSpots[$spot] = true;
                if ($this->allowedSpotColors !== null && !in_array($spot, $this->allowedSpotColors, true)) {
                    $findings->fail(
                        'color_spaces.spot_not_allowed',
                        $number,
                        ['allowed_spot_colors' => $this->allowedSpotColors],
                        ['spot_color' => $spot],
                        ['spot' => $spot, 'allowed' => $this->allowedSpotColors],
                    );
                }
            }
            if (!$page->contentComplete) {
                $findings->undetermined('color_spaces.analysis_incomplete', $number);
            }
        }
        if ($this->maxSpotColors !== null && count($allSpots) > $this->maxSpotColors) {
            $spots = array_map('strval', array_keys($allSpots));
            $findings->fail(
                'color_spaces.too_many_spot_colors',
                null,
                ['max_spot_colors' => $this->maxSpotColors],
                ['spot_colors' => $spots],
                ['max' => $this->maxSpotColors, 'actual' => count($spots), 'spots' => $spots],
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\PageBox;

/**
 * Checks the *geometric* bleed: how far the BleedBox extends beyond the
 * TrimBox on every side. It does not check whether page content actually
 * reaches into the bleed area.
 */
final class BleedMarginRule extends AbstractRule
{
    private const PT_TO_MM = 25.4 / 72;
    private readonly PageSelector $pageSelector;

    /**
     * @param float $minBleed Minimum bleed in mm on every side
     * @param bool $requireDefinedBoxes Require explicitly defined trim and bleed boxes;
     *     if false, derived boxes (e.g. BleedBox = CropBox) are measured
     */
    public function __construct(
        private readonly float $minBleed = 3.0,
        private readonly float $tolerance = 0.01,
        private readonly string $trimBox = PageBox::TRIM_BOX,
        private readonly string $bleedBox = PageBox::BLEED_BOX,
        private readonly bool $requireDefinedBoxes = true,
        mixed $pages = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        self::assertNonNegative('min_bleed', $minBleed);
        self::assertNonNegative('tolerance', $tolerance);
        self::assertOneOf('trim_box', $trimBox, PageBox::NAMES);
        self::assertOneOf('bleed_box', $bleedBox, PageBox::NAMES);
        $this->pageSelector = PageSelector::from($pages);
    }

    public static function type(): string
    {
        return 'bleed_margin';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($this->pageSelector->select($document->getPageCount()) as $number) {
            $page = $document->getPage($number);
            $trim = $page->getBox($this->trimBox);
            $bleed = $page->getBox($this->bleedBox);
            if ($this->requireDefinedBoxes && (!$trim->defined || !$bleed->defined)) {
                $missing = !$trim->defined ? $this->trimBox : $this->bleedBox;
                $findings->fail('bleed.box_missing', $number, ['box' => $missing, 'defined' => true], ['defined' => false], ['box' => $missing]);
                continue;
            }

            $scale = $page->userUnit * self::PT_TO_MM;
            [$tx0, $ty0, $tx1, $ty1] = $trim->effectiveRect;
            [$bx0, $by0, $bx1, $by1] = $bleed->effectiveRect;
            $margins = [
                'left' => round(($tx0 - $bx0) * $scale, 3),
                'bottom' => round(($ty0 - $by0) * $scale, 3),
                'right' => round(($bx1 - $tx1) * $scale, 3),
                'top' => round(($by1 - $ty1) * $scale, 3),
            ];
            $smallest = min($margins);
            if ($smallest < $this->minBleed - $this->tolerance) {
                $findings->fail(
                    'bleed.insufficient',
                    $number,
                    ['min_bleed_mm' => $this->minBleed],
                    ['bleed_mm' => $margins],
                    ['min_bleed' => $this->minBleed, 'actual_bleed' => max(0.0, $smallest)],
                );
            }
        }
    }
}

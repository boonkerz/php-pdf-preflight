<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\PageBox;

/**
 * Requires page boxes to be explicitly defined (in the page or inherited),
 * not merely derived by the PDF default rules.
 */
final class BoxRequiredRule extends AbstractRule
{
    /** @var list<string> */
    private readonly array $boxes;
    private readonly PageSelector $pageSelector;

    /**
     * @param list<string> $boxes e.g. ["TrimBox", "BleedBox"]
     */
    public function __construct(array $boxes = [PageBox::TRIM_BOX], mixed $pages = null, ?RuleOptions $options = null)
    {
        parent::__construct($options);
        if ($boxes === []) {
            throw new \InvalidArgumentException('boxes must not be empty');
        }
        foreach ($boxes as $box) {
            if (!is_string($box)) {
                throw new \InvalidArgumentException('boxes must be a list of box names');
            }
            self::assertOneOf('boxes', $box, PageBox::NAMES);
        }
        $this->boxes = array_values($boxes);
        $this->pageSelector = PageSelector::from($pages);
    }

    public static function type(): string
    {
        return 'box_required';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($this->pageSelector->select($document->getPageCount()) as $number) {
            $page = $document->getPage($number);
            foreach ($this->boxes as $name) {
                $box = $page->getBox($name);
                if (!$box->defined) {
                    $findings->fail(
                        'box.missing',
                        $number,
                        ['box' => $name, 'defined' => true],
                        ['box' => $name, 'defined' => false, 'source' => $box->source, 'derived_from' => $box->derivedFrom],
                        ['box' => $name],
                    );
                }
            }
        }
    }
}

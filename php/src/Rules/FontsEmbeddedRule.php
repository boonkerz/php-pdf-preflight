<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\FontInfo;

/**
 * All fonts used to show text must be embedded. Fonts that are only declared
 * in resources are ignored. Use severity "warning" to only warn.
 */
final class FontsEmbeddedRule extends AbstractRule
{
    private readonly PageSelector $pageSelector;

    /**
     * @param bool $ignoreInvisibleText Ignore fonts only used with text render mode 3 (not printed)
     * @param bool $allowStandard14 Accept non-embedded standard 14 fonts (Helvetica, Times, …)
     */
    public function __construct(
        private readonly bool $ignoreInvisibleText = true,
        private readonly bool $allowStandard14 = false,
        mixed $pages = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        $this->pageSelector = PageSelector::from($pages);
    }

    public static function type(): string
    {
        return 'fonts_embedded';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($this->pageSelector->select($document->getPageCount()) as $number) {
            $page = $document->getPage($number);
            foreach ($page->getUsedFonts() as $font) {
                if ($this->ignoreInvisibleText && !$font->visible) {
                    continue;
                }
                if ($font->embedding === FontInfo::EMBEDDED || ($this->allowStandard14 && $font->standard14 && $font->embedding === FontInfo::NOT_EMBEDDED)) {
                    continue;
                }
                $code = $font->embedding === FontInfo::INVALID ? 'fonts.invalid_embedding' : 'fonts.not_embedded';
                $findings->fail(
                    $code,
                    $number,
                    ['embedded' => true],
                    ['font' => $font->displayName(), 'type' => $font->type, 'embedding' => $font->embedding, 'problems' => $font->problems],
                    ['font' => $font->displayName()],
                );
            }
            if ($page->hasIssue('font_resource_missing')) {
                $findings->fail('fonts.missing_resource', $number, ['font_defined' => true], ['font_defined' => false]);
            }
            if (!$page->contentComplete) {
                $findings->undetermined('fonts.analysis_incomplete', $number);
            }
        }
    }
}

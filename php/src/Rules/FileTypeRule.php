<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\FileType;

/**
 * Restricts the accepted file types (detected from the file content).
 * Blocking: if it fails, the remaining rules are skipped.
 */
final class FileTypeRule extends AbstractRule implements BlockingRuleInterface
{
    /** @var list<string> */
    private readonly array $allowed;

    /**
     * @param list<string> $allowed Allowed types: pdf, jpeg, png, tiff
     * @param bool $allowImages Shorthand for adding jpeg, png and tiff
     */
    public function __construct(array $allowed = [FileType::PDF], bool $allowImages = false, ?RuleOptions $options = null)
    {
        parent::__construct($options);
        $known = [FileType::PDF, ...FileType::IMAGES];
        foreach ($allowed as $type) {
            if (!is_string($type)) {
                throw new \InvalidArgumentException('allowed must be a list of strings');
            }
            self::assertOneOf('allowed', $type, $known);
        }
        $this->allowed = array_values(array_unique($allowImages ? [...$allowed, ...FileType::IMAGES] : $allowed));
    }

    public static function type(): string
    {
        return 'file_type';
    }

    protected function requiresAnalysis(): bool
    {
        return false;
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        if (!in_array($document->fileType, $this->allowed, true)) {
            $findings->fail(
                'file_type.not_allowed',
                null,
                ['file_types' => $this->allowed],
                ['file_type' => $document->fileType],
                ['file_type' => $document->fileType, 'allowed' => $this->allowed],
            );
        }
    }
}

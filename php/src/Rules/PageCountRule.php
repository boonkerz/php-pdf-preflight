<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;

final class PageCountRule extends AbstractRule
{
    public function __construct(
        private readonly ?int $exact = null,
        private readonly ?int $min = null,
        private readonly ?int $max = null,
        private readonly ?int $multipleOf = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        if ($exact === null && $min === null && $max === null && $multipleOf === null) {
            throw new \InvalidArgumentException('at least one of exact, min, max, multiple_of is required');
        }
        foreach (['exact' => $exact, 'min' => $min, 'max' => $max] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new \InvalidArgumentException("{$name} must be at least 1");
            }
        }
        if ($multipleOf !== null && $multipleOf < 1) {
            throw new \InvalidArgumentException('multiple_of must be at least 1');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException('min must not be greater than max');
        }
    }

    public static function type(): string
    {
        return 'page_count';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        $count = $document->getPageCount();
        if ($this->exact !== null && $count !== $this->exact) {
            $findings->fail('page_count.exact', null, ['pages' => $this->exact], ['pages' => $count], ['expected' => $this->exact, 'actual' => $count]);
        }
        if ($this->min !== null && $count < $this->min) {
            $findings->fail('page_count.too_few', null, ['min_pages' => $this->min], ['pages' => $count], ['min' => $this->min, 'actual' => $count]);
        }
        if ($this->max !== null && $count > $this->max) {
            $findings->fail('page_count.too_many', null, ['max_pages' => $this->max], ['pages' => $count], ['max' => $this->max, 'actual' => $count]);
        }
        if ($this->multipleOf !== null && $count % $this->multipleOf !== 0) {
            $findings->fail(
                'page_count.multiple_of',
                null,
                ['multiple_of' => $this->multipleOf],
                ['pages' => $count],
                ['multiple' => $this->multipleOf, 'actual' => $count],
            );
        }
    }
}

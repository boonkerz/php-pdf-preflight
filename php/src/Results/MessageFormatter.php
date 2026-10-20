<?php

declare(strict_types=1);

namespace PrintPreflight\Results;

/**
 * Renders customer-facing messages from templates with `{placeholder}`
 * syntax. Lookup order: template of the violation (profile override), the
 * catalog of the requested locale, the English catalog, the code itself.
 */
final class MessageFormatter
{
    /**
     * @param array<string, array<string, string>> $catalogs Additional or overriding templates per locale
     */
    public function __construct(
        private readonly string $locale = 'de',
        private readonly array $catalogs = [],
    ) {
    }

    public function format(Violation $violation): string
    {
        $template = $violation->template
            ?? $this->catalogs[$this->locale][$violation->code]
            ?? Messages::CATALOGS[$this->locale][$violation->code]
            ?? Messages::CATALOGS['en'][$violation->code]
            ?? $violation->code;

        $replacements = ['{page}' => $violation->page === null ? '' : (string) $violation->page];
        foreach ($violation->params as $key => $value) {
            if (str_ends_with($key, '_orientation') && is_string($value)) {
                $value = Messages::ORIENTATIONS[$this->locale][$value] ?? $value;
            }
            $replacements['{' . $key . '}'] = $this->value($value);
        }
        return strtr($template, $replacements);
    }

    /**
     * Lists are joined with ", " – except lists of lists (sizes), which are
     * rendered as "85 × 55 / 90 × 50".
     *
     * @param scalar|list<scalar|list<scalar>>|null $value
     */
    private function value(mixed $value): string
    {
        return match (true) {
            is_array($value) && $value !== [] && is_array($value[0] ?? null) => implode(' / ', array_map(
                fn (mixed $size): string => implode(' × ', array_map($this->value(...), (array) $size)),
                $value,
            )),
            is_array($value) => implode(', ', array_map($this->value(...), $value)),
            is_float($value) => $this->number($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => (string) $value,
        };
    }

    private function number(float $value): string
    {
        $text = number_format($value, 2, $this->locale === 'de' ? ',' : '.', '');
        return rtrim(rtrim($text, '0'), ',.');
    }
}

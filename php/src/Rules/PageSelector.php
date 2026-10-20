<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

/**
 * Selects the pages a rule applies to. Accepted specifications:
 * null / "all", "first", "last", "odd", "even", a page number, a list such as
 * [1, "3-5"], or a string such as "1,3-5,last".
 */
final class PageSelector
{
    /**
     * @param list<string|int> $parts
     */
    private function __construct(private readonly array $parts)
    {
    }

    public static function all(): self
    {
        return new self(['all']);
    }

    public static function from(mixed $spec): self
    {
        if ($spec === null) {
            return self::all();
        }
        if ($spec instanceof self) {
            return $spec;
        }
        $parts = match (true) {
            is_int($spec) => [$spec],
            is_string($spec) => array_map('trim', explode(',', $spec)),
            is_array($spec) => array_values($spec),
            default => throw new \InvalidArgumentException('pages must be null, an int, a string or a list'),
        };
        foreach ($parts as $part) {
            $valid = (is_int($part) && $part >= 1)
                || (is_string($part) && preg_match('/^(all|first|last|odd|even|[1-9][0-9]*|[1-9][0-9]*-[1-9][0-9]*)$/', $part) === 1);
            if (!$valid) {
                throw new \InvalidArgumentException('invalid page specification ' . var_export($part, true));
            }
        }
        /** @var list<string|int> $parts */
        return new self($parts);
    }

    /**
     * @return list<int> Selected 1-based page numbers that exist in the document
     */
    public function select(int $pageCount): array
    {
        $pages = [];
        foreach ($this->parts as $part) {
            $range = match (true) {
                is_int($part) => [$part],
                $part === 'all' => $pageCount > 0 ? range(1, $pageCount) : [],
                $part === 'first' => [1],
                $part === 'last' => [$pageCount],
                $part === 'odd' => $pageCount > 0 ? range(1, $pageCount, 2) : [],
                $part === 'even' => $pageCount > 1 ? range(2, $pageCount, 2) : [],
                str_contains($part, '-') => range(...array_map('intval', explode('-', $part, 2))),
                default => [(int) $part],
            };
            foreach ($range as $p) {
                if ($p >= 1 && $p <= $pageCount) {
                    $pages[$p] = true;
                }
            }
        }
        $result = array_keys($pages);
        sort($result);
        return $result;
    }
}

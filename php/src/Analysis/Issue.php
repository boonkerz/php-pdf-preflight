<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * A non-fatal finding of the analysis (structure problems, parts that could
 * not be analysed). `code` is stable and machine-readable.
 */
final class Issue
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $page = null,
    ) {
    }

    public static function fromArray(ArrayReader $r): self
    {
        return new self($r->string('code'), $r->string('message', ''), $r->nullableInt('page'));
    }

    /**
     * @return array{code: string, message: string, page: int|null}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'page' => $this->page];
    }
}

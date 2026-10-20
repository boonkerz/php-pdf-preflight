<?php

declare(strict_types=1);

namespace PrintPreflight\Results;

use PrintPreflight\Rules\Severity;

/**
 * One finding of a rule. Machine-readable via `code`, `expected`, `actual`;
 * `message` is the customer-facing text (rendered by the Preflight engine
 * from the message catalog or a profile-specific template).
 */
final class Violation
{
    /**
     * @param string $rule Rule id (defaults to the rule type)
     * @param string $code Stable identifier, e.g. "page_size.mismatch"
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param array<string, scalar|list<scalar|list<scalar>>|null> $params Placeholders for message templates
     * @param bool $undetermined The rule could not decide (see UndeterminedPolicy)
     * @param string|null $template Message template overriding the catalog
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $code,
        public readonly Severity $severity,
        public readonly ?int $page = null,
        public readonly array $expected = [],
        public readonly array $actual = [],
        public readonly array $params = [],
        public readonly bool $undetermined = false,
        public readonly ?string $template = null,
        public readonly string $message = '',
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function withMessage(string $message): self
    {
        return new self(
            $this->rule,
            $this->code,
            $this->severity,
            $this->page,
            $this->expected,
            $this->actual,
            $this->params,
            $this->undetermined,
            $this->template,
            $message,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'code' => $this->code,
            'severity' => $this->severity->value,
            'page' => $this->page,
            'expected' => (object) $this->expected,
            'actual' => (object) $this->actual,
            'message' => $this->message,
            'undetermined' => $this->undetermined,
        ];
    }
}

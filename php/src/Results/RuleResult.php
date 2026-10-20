<?php

declare(strict_types=1);

namespace PrintPreflight\Results;

final class RuleResult
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $type,
        public readonly RuleStatus $status,
        public readonly array $violations = [],
    ) {
    }

    /**
     * Derives the status: failed if any definite violation exists,
     * undetermined if only undetermined findings exist, otherwise passed.
     *
     * @param list<Violation> $violations
     */
    public static function fromViolations(string $rule, string $type, array $violations, bool $undetermined = false): self
    {
        $status = RuleStatus::Passed;
        foreach ($violations as $v) {
            if (!$v->undetermined) {
                $status = RuleStatus::Failed;
                break;
            }
            $undetermined = true;
        }
        if ($status === RuleStatus::Passed && $undetermined) {
            $status = RuleStatus::Undetermined;
        }
        return new self($rule, $type, $status, $violations);
    }

    public static function skipped(string $rule, string $type): self
    {
        return new self($rule, $type, RuleStatus::Skipped);
    }

    /**
     * @param list<Violation> $violations
     */
    public function withViolations(array $violations): self
    {
        return new self($this->rule, $this->type, $this->status, $violations);
    }
}

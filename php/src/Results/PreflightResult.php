<?php

declare(strict_types=1);

namespace PrintPreflight\Results;

use PrintPreflight\Rules\Severity;

final class PreflightResult
{
    /**
     * @param list<RuleResult> $results
     */
    public function __construct(
        public readonly ?string $profile,
        public readonly array $results,
    ) {
    }

    /** True if no violation with severity "error" exists. */
    public function isValid(): bool
    {
        return $this->getErrors() === [];
    }

    /**
     * @return list<Violation>
     */
    public function getViolations(): array
    {
        $all = [];
        foreach ($this->results as $result) {
            array_push($all, ...$result->violations);
        }
        return $all;
    }

    /**
     * @return list<Violation>
     */
    public function getErrors(): array
    {
        return array_values(array_filter($this->getViolations(), static fn (Violation $v): bool => $v->severity === Severity::Error));
    }

    /**
     * @return list<Violation>
     */
    public function getWarnings(): array
    {
        return array_values(array_filter($this->getViolations(), static fn (Violation $v): bool => $v->severity === Severity::Warning));
    }

    public function getResult(string $rule): ?RuleResult
    {
        foreach ($this->results as $result) {
            if ($result->rule === $rule) {
                return $result;
            }
        }
        return null;
    }

    /** True if any rule could not decide (regardless of its reporting policy). */
    public function hasUndetermined(): bool
    {
        foreach ($this->results as $result) {
            if ($result->status === RuleStatus::Undetermined) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{valid: bool, profile: string|null, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, rules: list<array{rule: string, type: string, status: string}>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'profile' => $this->profile,
            'errors' => array_map(static fn (Violation $v): array => $v->toArray(), $this->getErrors()),
            'warnings' => array_map(static fn (Violation $v): array => $v->toArray(), $this->getWarnings()),
            'rules' => array_map(
                static fn (RuleResult $r): array => ['rule' => $r->rule, 'type' => $r->type, 'status' => $r->status->value],
                $this->results,
            ),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}

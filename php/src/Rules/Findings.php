<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Results\Violation;

/**
 * Collects the violations of one rule evaluation.
 */
final class Findings
{
    /** @var list<Violation> */
    private array $violations = [];
    private bool $undetermined = false;

    public function __construct(private readonly string $rule, private readonly RuleOptions $options)
    {
    }

    /**
     * A definite violation with the rule's severity.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param array<string, scalar|list<scalar|list<scalar>>|null> $params
     */
    public function fail(string $code, ?int $page = null, array $expected = [], array $actual = [], array $params = []): void
    {
        $this->violations[] = new Violation(
            $this->rule,
            $code,
            $this->options->severity,
            $page,
            $expected,
            $actual,
            $params,
            false,
            $this->options->messages[$code] ?? null,
        );
    }

    /**
     * The rule cannot decide; reported according to the UndeterminedPolicy.
     *
     * @param array<string, scalar|list<scalar|list<scalar>>|null> $params
     */
    public function undetermined(string $code, ?int $page = null, array $params = []): void
    {
        $this->undetermined = true;
        $severity = match ($this->options->onUndetermined) {
            UndeterminedPolicy::Error => Severity::Error,
            UndeterminedPolicy::Warning => Severity::Warning,
            UndeterminedPolicy::Ignore => null,
        };
        if ($severity === null) {
            return;
        }
        $this->violations[] = new Violation(
            $this->rule,
            $code,
            $severity,
            $page,
            [],
            [],
            $params,
            true,
            $this->options->messages[$code] ?? null,
        );
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    public function isUndetermined(): bool
    {
        return $this->undetermined;
    }
}

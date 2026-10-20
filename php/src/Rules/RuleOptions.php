<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

/**
 * Options shared by all rules.
 */
final class RuleOptions
{
    /**
     * @param string|null $id Identifier of this rule instance in results (defaults to the rule type)
     * @param array<string, string> $messages Message templates per violation code
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly Severity $severity = Severity::Error,
        public readonly UndeterminedPolicy $onUndetermined = UndeterminedPolicy::Error,
        public readonly array $messages = [],
    ) {
    }
}

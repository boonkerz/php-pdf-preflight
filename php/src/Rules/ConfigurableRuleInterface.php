<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

/**
 * A rule that can be created from a (JSON) profile entry. Register the class
 * in a RuleRegistry to make it available to profiles.
 */
interface ConfigurableRuleInterface extends RuleInterface
{
    /** Identifier used as `type` in profiles, e.g. "page_size". */
    public static function type(): string;

    /**
     * @param array<string, mixed> $params Rule parameters (snake_case keys)
     * @throws \PrintPreflight\Exceptions\InvalidRuleConfigException
     */
    public static function fromConfig(array $params, ?RuleOptions $options = null): static;
}

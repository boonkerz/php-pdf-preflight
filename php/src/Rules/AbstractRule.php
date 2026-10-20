<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Exceptions\InvalidRuleConfigException;
use PrintPreflight\Results\RuleResult;

/**
 * Base class for rules: options handling, findings collection and creation
 * from profile parameters via the constructor's named arguments
 * (`tolerance` / `min_bleed` in JSON map to `$tolerance` / `$minBleed`).
 */
abstract class AbstractRule implements ConfigurableRuleInterface
{
    protected readonly RuleOptions $options;

    public function __construct(?RuleOptions $options = null)
    {
        $this->options = $options ?? new RuleOptions();
    }

    public function getId(): string
    {
        return $this->options->id ?? static::type();
    }

    public function getOptions(): RuleOptions
    {
        return $this->options;
    }

    final public function validate(DocumentAnalysis $document): RuleResult
    {
        $findings = new Findings($this->getId(), $this->options);
        if ($this->requiresAnalysis() && !$document->isAnalyzed()) {
            $findings->undetermined('document.not_analyzed', null, ['file_type' => $document->fileType]);
        } else {
            $this->check($document, $findings);
        }
        return RuleResult::fromViolations($this->getId(), static::type(), $findings->violations(), $findings->isUndetermined());
    }

    abstract protected function check(DocumentAnalysis $document, Findings $findings): void;

    /** Whether the rule needs an analysed document (false e.g. for the file type rule). */
    protected function requiresAnalysis(): bool
    {
        return true;
    }

    public static function fromConfig(array $params, ?RuleOptions $options = null): static
    {
        $args = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || $key === 'options' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                throw new InvalidRuleConfigException('params', 'invalid parameter name ' . var_export($key, true));
            }
            $args[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
        }
        try {
            /** @phpstan-ignore new.static (constructors of subclasses take named arguments) */
            return new static(...$args, options: $options);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidRuleConfigException('params', $e->getMessage(), $e);
        } catch (\TypeError $e) {
            throw new InvalidRuleConfigException('params', self::describeError($e), $e);
        } catch (\Error $e) {
            if (str_starts_with($e->getMessage(), 'Unknown named parameter')) {
                throw new InvalidRuleConfigException('params', self::describeError($e), $e);
            }
            throw $e;
        }
    }

    private static function describeError(\Error $e): string
    {
        // "PrintPreflight\Rules\X::__construct(): Argument #1 ($width) must be of type ?float, string given, ..."
        $message = preg_replace('/^.*?::__construct\(\): /', '', $e->getMessage()) ?? $e->getMessage();
        $message = preg_replace('/, called in .*$/s', '', $message) ?? $message;
        return preg_replace_callback(
            '/\$([a-zA-Z0-9]+)/',
            static fn (array $m): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $m[1])),
            $message,
        ) ?? $message;
    }

    /**
     * @param list<string> $allowed
     */
    protected static function assertOneOf(string $name, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$name} must be one of " . implode(', ', $allowed) . ", got '{$value}'");
        }
    }

    protected static function assertNonNegative(string $name, int|float|null $value): void
    {
        if ($value !== null && $value < 0) {
            throw new \InvalidArgumentException("{$name} must not be negative");
        }
    }
}

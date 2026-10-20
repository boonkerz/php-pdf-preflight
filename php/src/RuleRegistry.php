<?php

declare(strict_types=1);

namespace PrintPreflight;

use PrintPreflight\Exceptions\InvalidProfileException;
use PrintPreflight\Exceptions\InvalidRuleConfigException;
use PrintPreflight\Rules\BleedMarginRule;
use PrintPreflight\Rules\BoxRequiredRule;
use PrintPreflight\Rules\ColorPageCountRule;
use PrintPreflight\Rules\ColorSpaceRule;
use PrintPreflight\Rules\ConfigurableRuleInterface;
use PrintPreflight\Rules\FileTypeRule;
use PrintPreflight\Rules\FontsEmbeddedRule;
use PrintPreflight\Rules\ImageResolutionRule;
use PrintPreflight\Rules\PageCountRule;
use PrintPreflight\Rules\PageSizeRule;
use PrintPreflight\Rules\PdfPropertiesRule;
use PrintPreflight\Rules\RuleInterface;
use PrintPreflight\Rules\RuleOptions;

/**
 * Maps rule types used in profiles to rule classes. Custom rules are added
 * with `register()`; the engine itself needs no changes.
 */
final class RuleRegistry
{
    public const BUILT_IN = [
        FileTypeRule::class,
        PageSizeRule::class,
        PageCountRule::class,
        BoxRequiredRule::class,
        BleedMarginRule::class,
        FontsEmbeddedRule::class,
        ColorPageCountRule::class,
        ImageResolutionRule::class,
        ColorSpaceRule::class,
        PdfPropertiesRule::class,
    ];

    /** @var array<string, class-string<ConfigurableRuleInterface>> */
    private array $types = [];

    /**
     * @param list<class-string<ConfigurableRuleInterface>> $classes
     */
    public function __construct(array $classes = self::BUILT_IN)
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /**
     * @param class-string<ConfigurableRuleInterface> $class
     */
    public function register(string $class): self
    {
        if (!is_subclass_of($class, ConfigurableRuleInterface::class)) {
            throw new \InvalidArgumentException("{$class} does not implement " . ConfigurableRuleInterface::class);
        }
        $this->types[$class::type()] = $class;
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->types);
    }

    /**
     * @throws InvalidProfileException
     */
    public function create(RuleConfig $config, string $path = ''): RuleInterface
    {
        $class = $this->types[$config->type]
            ?? throw new InvalidProfileException("{$path}.type", "unknown rule type '{$config->type}'");
        $options = new RuleOptions($config->id, $config->severity, $config->onUndetermined, $config->messages);
        try {
            return $class::fromConfig($config->params, $options);
        } catch (InvalidRuleConfigException $e) {
            throw new InvalidRuleConfigException(ltrim("{$path}.{$e->getPath()}", '.'), $e->getPrevious()?->getMessage() ?? $e->getMessage(), $e);
        }
    }
}

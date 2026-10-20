<?php

declare(strict_types=1);

namespace PrintPreflight;

use PrintPreflight\Exceptions\InvalidProfileException;
use PrintPreflight\Rules\RuleInterface;

/**
 * A preflight profile: an identified, versioned set of rule configurations.
 * Profiles are plain data and (de)serialise to JSON, so they can be stored
 * in a database and edited in a shop backend.
 */
final class Profile
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<RuleConfig> $rules
     * @param string $locale Language of the default messages (de, en)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $rules,
        public readonly string $description = '',
        public readonly int $version = 1,
        public readonly string $locale = 'de',
    ) {
        if ($id === '') {
            throw new InvalidProfileException('id', 'must not be empty');
        }
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidProfileException
     */
    public static function fromArray(array $data): self
    {
        $allowed = ['schema', 'id', 'name', 'description', 'version', 'locale', 'rules'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidProfileException((string) $key, 'unknown key');
            }
        }
        $schema = $data['schema'] ?? self::SCHEMA_VERSION;
        if ($schema !== self::SCHEMA_VERSION) {
            throw new InvalidProfileException('schema', 'unsupported schema version ' . var_export($schema, true));
        }
        foreach (['id', 'name'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new InvalidProfileException($key, 'must be a non-empty string');
            }
        }
        $description = $data['description'] ?? '';
        if (!is_string($description)) {
            throw new InvalidProfileException('description', 'must be a string');
        }
        $version = $data['version'] ?? 1;
        if (!is_int($version) || $version < 1) {
            throw new InvalidProfileException('version', 'must be a positive integer');
        }
        $locale = $data['locale'] ?? 'de';
        if (!is_string($locale) || $locale === '') {
            throw new InvalidProfileException('locale', 'must be a non-empty string');
        }
        $rules = $data['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new InvalidProfileException('rules', 'must be a list');
        }
        $configs = [];
        $ids = [];
        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) {
                throw new InvalidProfileException("rules[{$i}]", 'must be an object');
            }
            $config = RuleConfig::fromArray($rule, "rules[{$i}]");
            $id = $config->id ?? $config->type;
            if (isset($ids[$id])) {
                throw new InvalidProfileException("rules[{$i}]", "duplicate rule id '{$id}'; set a unique \"id\"");
            }
            $ids[$id] = true;
            $configs[] = $config;
        }
        /** @var string $id */
        $id = $data['id'];
        /** @var string $name */
        $name = $data['name'];
        return new self($id, $name, $configs, $description, $version, $locale);
    }

    /**
     * @throws InvalidProfileException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidProfileException('', 'invalid JSON: ' . $e->getMessage(), $e);
        }
        if (!is_array($data)) {
            throw new InvalidProfileException('', 'profile must be a JSON object');
        }
        return self::fromArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'locale' => $this->locale,
            'rules' => array_map(static fn (RuleConfig $r): array => $r->toArray(), $this->rules),
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Instantiates the enabled rules. Also useful to validate a profile
     * before storing it.
     *
     * @return list<RuleInterface>
     * @throws InvalidProfileException
     */
    public function createRules(?RuleRegistry $registry = null): array
    {
        $registry ??= new RuleRegistry();
        $rules = [];
        foreach ($this->rules as $i => $config) {
            if ($config->enabled) {
                $rules[] = $registry->create($config, "rules[{$i}]");
            }
        }
        return $rules;
    }
}

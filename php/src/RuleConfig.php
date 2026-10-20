<?php

declare(strict_types=1);

namespace PrintPreflight;

use PrintPreflight\Exceptions\InvalidProfileException;
use PrintPreflight\Rules\Severity;
use PrintPreflight\Rules\UndeterminedPolicy;

/**
 * Configuration of one rule inside a profile.
 */
final class RuleConfig
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $messages Message templates per violation code
     */
    public function __construct(
        public readonly string $type,
        public readonly array $params = [],
        public readonly bool $enabled = true,
        public readonly Severity $severity = Severity::Error,
        public readonly UndeterminedPolicy $onUndetermined = UndeterminedPolicy::Error,
        public readonly array $messages = [],
        public readonly ?string $id = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidProfileException
     */
    public static function fromArray(array $data, string $path): self
    {
        $allowed = ['type', 'id', 'enabled', 'severity', 'on_undetermined', 'params', 'messages'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidProfileException("{$path}.{$key}", 'unknown key');
            }
        }
        $type = $data['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new InvalidProfileException("{$path}.type", 'must be a non-empty string');
        }
        $id = $data['id'] ?? null;
        if ($id !== null && (!is_string($id) || $id === '')) {
            throw new InvalidProfileException("{$path}.id", 'must be a non-empty string');
        }
        $enabled = $data['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidProfileException("{$path}.enabled", 'must be a boolean');
        }
        $severity = Severity::tryFrom(is_string($data['severity'] ?? null) ? $data['severity'] : (isset($data['severity']) ? '' : 'error'))
            ?? throw new InvalidProfileException("{$path}.severity", 'must be "error" or "warning"');
        $policy = UndeterminedPolicy::tryFrom(is_string($data['on_undetermined'] ?? null) ? $data['on_undetermined'] : (isset($data['on_undetermined']) ? '' : 'error'))
            ?? throw new InvalidProfileException("{$path}.on_undetermined", 'must be "error", "warning" or "ignore"');
        $params = $data['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            throw new InvalidProfileException("{$path}.params", 'must be an object');
        }
        $messages = $data['messages'] ?? [];
        if (!is_array($messages)) {
            throw new InvalidProfileException("{$path}.messages", 'must be an object');
        }
        foreach ($messages as $code => $text) {
            if (!is_string($code) || !is_string($text)) {
                throw new InvalidProfileException("{$path}.messages", 'must map violation codes to strings');
            }
        }
        /** @var array<string, mixed> $params */
        /** @var array<string, string> $messages */
        return new self($type, $params, $enabled, $severity, $policy, $messages, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'id' => $this->id,
            'enabled' => $this->enabled,
            'severity' => $this->severity->value,
            'on_undetermined' => $this->onUndetermined->value,
            'params' => (object) $this->params,
            'messages' => (object) $this->messages,
        ], static fn ($v): bool => $v !== null);
    }
}

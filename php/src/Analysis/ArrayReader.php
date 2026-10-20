<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * Typed access to decoded JSON / extension arrays with descriptive errors.
 *
 * @internal
 */
final class ArrayReader
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(private readonly array $data, private readonly string $path = '')
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function string(string $key, ?string $default = null): string
    {
        $v = $this->data[$key] ?? $default;
        if (!is_string($v)) {
            throw $this->error($key, 'string');
        }
        return $v;
    }

    public function nullableString(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v !== null && !is_string($v)) {
            throw $this->error($key, 'string or null');
        }
        return $v;
    }

    public function int(string $key, ?int $default = null): int
    {
        $v = $this->data[$key] ?? $default;
        if (!is_int($v)) {
            throw $this->error($key, 'int');
        }
        return $v;
    }

    public function nullableInt(string $key): ?int
    {
        $v = $this->data[$key] ?? null;
        if ($v !== null && !is_int($v)) {
            throw $this->error($key, 'int or null');
        }
        return $v;
    }

    public function float(string $key, ?float $default = null): float
    {
        $v = $this->data[$key] ?? $default;
        if (!is_int($v) && !is_float($v)) {
            throw $this->error($key, 'number');
        }
        return (float) $v;
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $v = $this->data[$key] ?? $default;
        if (!is_bool($v)) {
            throw $this->error($key, 'bool');
        }
        return $v;
    }

    /**
     * @param array<mixed>|null $default
     * @return array<mixed>
     */
    public function array(string $key, ?array $default = null): array
    {
        $v = $this->data[$key] ?? $default;
        if (!is_array($v)) {
            throw $this->error($key, 'array');
        }
        return $v;
    }

    public function child(string $key): self
    {
        return new self($this->array($key), $this->sub($key));
    }

    /**
     * @return list<self>
     */
    public function children(string $key): array
    {
        $out = [];
        foreach (array_values($this->array($key, [])) as $i => $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException("{$this->sub($key)}[{$i}]: expected array");
            }
            $out[] = new self($item, "{$this->sub($key)}[{$i}]");
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $out = [];
        foreach (array_values($this->array($key, [])) as $i => $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException("{$this->sub($key)}[{$i}]: expected string");
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        $out = [];
        foreach (array_values($this->array($key, [])) as $i => $item) {
            if (!is_int($item)) {
                throw new \UnexpectedValueException("{$this->sub($key)}[{$i}]: expected int");
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @return list<float>
     */
    public function floatList(string $key): array
    {
        $out = [];
        foreach (array_values($this->array($key, [])) as $i => $item) {
            if (!is_int($item) && !is_float($item)) {
                throw new \UnexpectedValueException("{$this->sub($key)}[{$i}]: expected number");
            }
            $out[] = (float) $item;
        }
        return $out;
    }

    private function sub(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    private function error(string $key, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException("{$this->sub($key)}: expected {$expected}, got " . get_debug_type($this->data[$key] ?? null));
    }
}

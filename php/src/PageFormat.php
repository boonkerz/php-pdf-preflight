<?php

declare(strict_types=1);

namespace PrintPreflight;

/**
 * Named page formats in mm (portrait orientation). Contains DIN A0–A10
 * (ISO 216); further formats can be registered at runtime.
 */
final class PageFormat
{
    /** @var array<string, array{float, float}> */
    private static array $formats = [
        'A0' => [841.0, 1189.0],
        'A1' => [594.0, 841.0],
        'A2' => [420.0, 594.0],
        'A3' => [297.0, 420.0],
        'A4' => [210.0, 297.0],
        'A5' => [148.0, 210.0],
        'A6' => [105.0, 148.0],
        'A7' => [74.0, 105.0],
        'A8' => [52.0, 74.0],
        'A9' => [37.0, 52.0],
        'A10' => [26.0, 37.0],
    ];

    /**
     * @return array{float, float} Width and height in mm (portrait)
     */
    public static function get(string $name): array
    {
        return self::$formats[strtoupper($name)]
            ?? throw new \InvalidArgumentException("unknown page format '{$name}'");
    }

    public static function has(string $name): bool
    {
        return isset(self::$formats[strtoupper($name)]);
    }

    public static function register(string $name, float $width, float $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('format dimensions must be positive');
        }
        self::$formats[strtoupper($name)] = [min($width, $height), max($width, $height)];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::$formats);
    }
}

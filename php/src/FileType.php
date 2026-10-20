<?php

declare(strict_types=1);

namespace PrintPreflight;

/**
 * Detects the file type from the content (magic bytes), never from the file
 * name.
 */
final class FileType
{
    public const PDF = 'pdf';
    public const JPEG = 'jpeg';
    public const PNG = 'png';
    public const TIFF = 'tiff';
    public const UNKNOWN = 'unknown';

    public const IMAGES = [self::JPEG, self::PNG, self::TIFF];

    public static function detect(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return self::UNKNOWN;
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);
        return self::detectFromBytes($head);
    }

    public static function detectFromBytes(string $head): string
    {
        return match (true) {
            // PDF readers accept the header anywhere in the first 1024 bytes.
            str_contains(substr($head, 0, 1024), '%PDF-') => self::PDF,
            str_starts_with($head, "\xFF\xD8\xFF") => self::JPEG,
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => self::PNG,
            str_starts_with($head, "II*\x00"), str_starts_with($head, "MM\x00*") => self::TIFF,
            default => self::UNKNOWN,
        };
    }
}

<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

use PrintPreflight\Exceptions\AnalysisException;
use PrintPreflight\Exceptions\ExtensionNotLoadedException;
use PrintPreflight\Exceptions\IncompatibleExtensionException;
use PrintPreflight\FileType;

/**
 * Analyses PDF files with the native pdf_preflight extension.
 *
 * @phpstan-type NativeOptions array{password?: string, max_file_size?: int, max_objects?: int, max_pages?: int,
 *     max_stream_size?: int, max_total_decoded?: int, max_nesting_depth?: int,
 *     max_operations_per_page?: int, max_analysis_time_ms?: int,
 *     max_color_check_pixels?: int, max_image_placements?: int}
 */
final class NativePdfAnalyzer implements FileAnalyzerInterface
{
    /**
     * Extension versions this library works with: the same major.minor
     * (both are released together from one repository and version tag).
     */
    public const EXTENSION_VERSION = '0.1';

    /**
     * @param NativeOptions $options Options for `PdfNative::open()` (password and
     *     resource limits, see docs/security.md)
     */
    public function __construct(private readonly array $options = [])
    {
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('pdf_preflight');
    }

    /**
     * @throws IncompatibleExtensionException
     */
    public static function assertCompatible(?string $extensionVersion = null): void
    {
        $version = $extensionVersion ?? (phpversion('pdf_preflight') ?: '');
        if (!self::isCompatible($version)) {
            throw new IncompatibleExtensionException(sprintf(
                'pdf_preflight extension %s is not compatible with this library (requires %s.x)',
                $version === '' ? '(unknown)' : $version,
                self::EXTENSION_VERSION,
            ));
        }
    }

    public static function isCompatible(string $version): bool
    {
        return preg_match('/^(\d+\.\d+)\./', $version, $m) === 1 && $m[1] === self::EXTENSION_VERSION;
    }

    public function supports(string $fileType): bool
    {
        return $fileType === FileType::PDF;
    }

    public function analyze(string $path, string $fileType = FileType::PDF): DocumentAnalysis
    {
        if (!self::isAvailable()) {
            throw ExtensionNotLoadedException::create();
        }
        self::assertCompatible();
        try {
            $pdf = \PdfNative::open($path, $this->options);
            try {
                $data = $pdf->analyze();
            } finally {
                $pdf->close();
            }
        } catch (\PdfNativeException $e) {
            throw new AnalysisException(self::reason($e->getCode()), $e->getMessage(), $e);
        }

        $size = @filesize($path);
        return DocumentAnalysis::fromArray($data, basename($path), $size === false ? null : $size);
    }

    private static function reason(int $code): string
    {
        return match ($code) {
            \PdfNativeException::IO => AnalysisException::REASON_UNREADABLE,
            \PdfNativeException::INVALID_PDF => AnalysisException::REASON_INVALID,
            \PdfNativeException::PASSWORD_REQUIRED => AnalysisException::REASON_PASSWORD_REQUIRED,
            \PdfNativeException::UNSUPPORTED_ENCRYPTION => AnalysisException::REASON_UNSUPPORTED_ENCRYPTION,
            \PdfNativeException::FILE_TOO_LARGE => AnalysisException::REASON_TOO_LARGE,
            \PdfNativeException::LIMIT_EXCEEDED => AnalysisException::REASON_LIMIT_EXCEEDED,
            default => AnalysisException::REASON_INTERNAL,
        };
    }
}

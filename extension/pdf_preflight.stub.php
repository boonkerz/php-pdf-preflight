<?php

/**
 * IDE and static-analysis stub for the pdf_preflight extension.
 * Not loaded at runtime. Page numbers are always 1-based.
 *
 * @generated manually from extension/src/php.rs – keep in sync.
 */

final class PdfNative
{
    public const VERSION = '0.1.0';

    /**
     * @param array{password?: string, max_file_size?: int, max_objects?: int, max_pages?: int,
     *     max_stream_size?: int, max_total_decoded?: int, max_nesting_depth?: int,
     *     max_operations_per_page?: int, max_analysis_time_ms?: int,
     *     max_color_check_pixels?: int, max_image_placements?: int}|null $options
     * @throws PdfNativeException
     */
    public static function open(string $path, ?array $options = null): PdfNative {}

    /**
     * @param string $data Complete PDF file contents
     * @throws PdfNativeException
     */
    public static function fromString(string $data, ?array $options = null): PdfNative {}

    /** @return array<string, int> */
    public static function defaultLimits(): array {}

    /** Releases the parsed document and all cached results. */
    public function close(): void {}

    public function isOpen(): bool {}

    /** @throws PdfNativeException */
    public function getPageCount(): int {}

    /**
     * Version, encryption, metadata, PDF/X identification (never validation),
     * output intents and structural issues.
     *
     * @throws PdfNativeException
     */
    public function getDocumentInfo(): array {}

    /**
     * Size in mm (CropBox, after /Rotate and /UserUnit), orientation,
     * rotation, all boxes and geometry issues.
     *
     * @throws PdfNativeException
     */
    public function getPageInfo(int $page): array {}

    /**
     * MediaBox, CropBox, BleedBox, TrimBox, ArtBox with `defined`, `source`
     * (page|inherited|default|fallback) and sizes in pt and mm.
     *
     * @throws PdfNativeException
     */
    public function getPageBoxes(int $page): array {}

    /**
     * Fonts of a page, including fonts used in nested forms, tiling patterns
     * and printable annotations. Computed once per page and cached.
     *
     * @return list<array<string, mixed>>
     * @throws PdfNativeException
     */
    public function getFonts(int $page): array {}

    /**
     * Colour spaces declared in the resources and used for painting, spot
     * colours actually painted, and the print classification of the page:
     * `color`, `grayscale` or `unknown` (never guessed).
     *
     * @throws PdfNativeException
     */
    public function getColorSpaces(int $page): array {}

    /**
     * Image placements: pixel size, colour space, bits per component,
     * compression, effective resolution (dpi) and placed size. The same
     * image placed at different sizes yields one entry per resolution.
     *
     * @return list<array<string, mixed>>
     * @throws PdfNativeException
     */
    public function getImages(int $page): array {}

    /**
     * Completeness of the content analysis of a page and content issues.
     *
     * @return array{page: int, complete: bool, operations: int, issues: list<array{code: string, message: string, page: int|null}>}
     * @throws PdfNativeException
     */
    public function getPageAnalysis(int $page): array {}

    /**
     * Document info, all pages (geometry, fonts, issues) and a document-level
     * font summary.
     *
     * @throws PdfNativeException
     */
    public function analyze(): array {}

    /** @throws PdfNativeException */
    public function getLimits(): array {}
}

class PdfNativeException extends Exception
{
    public const IO = 1;
    public const FILE_TOO_LARGE = 2;
    public const INVALID_PDF = 3;
    public const PASSWORD_REQUIRED = 4;
    public const UNSUPPORTED_ENCRYPTION = 5;
    public const LIMIT_EXCEEDED = 6;
    public const PAGE_OUT_OF_RANGE = 7;
    public const CLOSED = 8;
    public const INVALID_ARGUMENT = 9;
    public const INTERNAL = 99;
}

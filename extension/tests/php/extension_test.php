<?php

declare(strict_types=1);

/**
 * Tests of the PHP API of the native extension. Self-contained (no Composer),
 * so it can run right after building the extension:
 *
 *   php -n -d extension=/path/to/pdf_preflight.so extension/tests/php/extension_test.php
 */

$fixtures = dirname(__DIR__, 3) . '/tests/fixtures';
$failures = 0;
$count = 0;

function check(bool $condition, string $message): void
{
    global $failures, $count;
    $count++;
    if (!$condition) {
        $failures++;
        $trace = debug_backtrace()[0];
        fwrite(STDERR, "FAIL line {$trace['line']}: {$message}\n");
    }
}

function expectException(callable $fn, int $code, string $message): void
{
    try {
        $fn();
        check(false, "{$message}: no exception thrown");
    } catch (PdfNativeException $e) {
        check($e->getCode() === $code, "{$message}: expected code {$code}, got {$e->getCode()} ({$e->getMessage()})");
    }
}

check(extension_loaded('pdf_preflight'), 'extension is loaded');
check(is_subclass_of('PdfNativeException', 'Exception'), 'PdfNativeException extends Exception');

// --- Opening and basic queries -------------------------------------------

$pdf = PdfNative::open("{$fixtures}/multipage.pdf");
check($pdf->isOpen(), 'document is open');
check($pdf->getPageCount() === 4, 'page count');

$info = $pdf->getPageInfo(2);
check($info['page'] === 2, 'page number is 1-based');
check(abs($info['width_mm'] - 297.0) < 0.01 && abs($info['height_mm'] - 210.0) < 0.01, 'A4 landscape size');
check($info['orientation'] === 'landscape', 'orientation');

$boxes = $pdf->getPageBoxes(4);
check($boxes['MediaBox']['source'] === 'inherited', 'inherited MediaBox');
check($boxes['TrimBox']['defined'] === false && $boxes['TrimBox']['derived_from'] === 'CropBox', 'derived TrimBox');
check(count($boxes['TrimBox']['rect']) === 4, 'rect has four numbers');

$doc = $pdf->getDocumentInfo();
check($doc['page_count'] === 4 && $doc['version'] === '1.7', 'document info');
check($doc['pdfx']['validated'] === false, 'PDF/X is never reported as validated');

expectException(fn () => $pdf->getPageInfo(0), PdfNativeException::PAGE_OUT_OF_RANGE, 'page 0');
expectException(fn () => $pdf->getFonts(5), PdfNativeException::PAGE_OUT_OF_RANGE, 'page 5');

$pdf->close();
check(!$pdf->isOpen(), 'closed');
expectException(fn () => $pdf->getPageCount(), PdfNativeException::CLOSED, 'use after close');

// --- Fonts ------------------------------------------------------------------

$pdf = PdfNative::open("{$fixtures}/fonts.pdf");
$fonts = [];
foreach ($pdf->getFonts(1) as $font) {
    $fonts[$font['name']] = $font;
}
check($fonts['Helvetica']['embedded'] === false && $fonts['Helvetica']['used'] === true, 'Helvetica not embedded');
check($fonts['StubSans']['embedded'] === true && $fonts['StubSans']['subset'] === true, 'subset TrueType embedded');
check($fonts['Times-Roman']['used'] === false, 'declared but unused font');
check($fonts['Courier-Bold']['visible'] === false, 'invisible text');

$nested = $pdf->getFonts(2);
check(count($nested) === 1 && $nested[0]['name'] === 'Courier' && $nested[0]['contexts'] === ['form'], 'font in nested form');

$analysis = $pdf->analyze();
check(count($analysis['pages']) === 5, 'analyze() covers all pages');
check($analysis['analysis_complete'] === true, 'analysis complete');
$courier = array_values(array_filter($analysis['fonts'], fn ($f) => $f['name'] === 'Courier'))[0];
check($courier['pages'] === [2], 'document font summary lists pages');
check(json_encode($analysis, JSON_THROW_ON_ERROR) !== '', 'analysis is JSON serialisable');

// --- Colours and images -------------------------------------------------------

$colors = PdfNative::open("{$fixtures}/colors.pdf");
check($colors->getColorSpaces(1)['classification'] === 'grayscale', 'gray page');
check($colors->getColorSpaces(2)['classification'] === 'color', 'RGB page');
check($colors->getColorSpaces(4)['spot_colors'] === ['HKS 13 K'], 'spot colour');
check($colors->getColorSpaces(16)['classification'] === 'unknown', 'undecidable shading');
check($colors->analyze()['colors']['unknown_pages'] === [16], 'colour summary');

$images = PdfNative::open("{$fixtures}/images.pdf");
$placements = $images->getImages(2);
$dpis = array_map(static fn (array $i): float => $i['effective_dpi'], $placements);
sort($dpis);
check($dpis === [150.0, 300.0], 'same image at two resolutions');
check($placements[0]['width_px'] === 300 && $placements[0]['compression'] === 'flate', 'image metadata');
check($images->getImages(9)[0]['on_page'] === false, 'image outside the page');

// --- Options, limits, errors -----------------------------------------------

expectException(fn () => PdfNative::open("{$fixtures}/single_a4.pdf", ['max_file_sise' => 1]), PdfNativeException::INVALID_ARGUMENT, 'unknown option');
expectException(fn () => PdfNative::open("{$fixtures}/single_a4.pdf", ['max_file_size' => -1]), PdfNativeException::INVALID_ARGUMENT, 'negative limit');
expectException(fn () => PdfNative::open("{$fixtures}/single_a4.pdf", ['max_file_size' => 100]), PdfNativeException::FILE_TOO_LARGE, 'file size limit');
expectException(fn () => PdfNative::open("{$fixtures}/does-not-exist.pdf"), PdfNativeException::IO, 'missing file');
expectException(fn () => PdfNative::open("{$fixtures}/damaged/not_a_pdf.pdf"), PdfNativeException::INVALID_PDF, 'not a PDF');
expectException(fn () => PdfNative::open("{$fixtures}/encrypted_user_password.pdf"), PdfNativeException::PASSWORD_REQUIRED, 'password required');
expectException(
    fn () => PdfNative::open("{$fixtures}/bomb_object_stream.pdf", ['max_stream_size' => 1 << 20]),
    PdfNativeException::LIMIT_EXCEEDED,
    'object stream bomb',
);

$enc = PdfNative::open("{$fixtures}/encrypted_user_password.pdf", ['password' => 'secret']);
check($enc->getDocumentInfo()['encrypted'] === true, 'opened with password');

$bomb = PdfNative::open("{$fixtures}/bomb_content_stream.pdf", ['max_stream_size' => 1 << 20]);
$pageAnalysis = $bomb->getPageAnalysis(1);
check($pageAnalysis['complete'] === false && $pageAnalysis['issues'][0]['code'] === 'limit_exceeded', 'content bomb is contained');

$limits = PdfNative::defaultLimits();
check($limits['max_nesting_depth'] === 32 && $limits['max_color_check_pixels'] === 25_000_000, 'default limits');
check(PdfNative::open("{$fixtures}/single_a4.pdf", ['max_pages' => 7])->getLimits()['max_pages'] === 7, 'custom limit applied');

// --- In-memory input ----------------------------------------------------------

$fromString = PdfNative::fromString(file_get_contents("{$fixtures}/rotated.pdf"));
check($fromString->getPageCount() === 6 && $fromString->getPageInfo(1)['rotation'] === 90, 'fromString');

// --- Resource release ----------------------------------------------------------

$rss = static function (): int {
    preg_match('/VmRSS:\s+(\d+)/', (string) @file_get_contents('/proc/self/status'), $m);
    return (int) ($m[1] ?? 0);
};
for ($i = 0; $i < 200; $i++) {
    PdfNative::open("{$fixtures}/fonts.pdf")->analyze();
}
$before = $rss();
for ($i = 0; $i < 2000; $i++) {
    $p = PdfNative::open("{$fixtures}/fonts.pdf");
    $p->analyze();
    if ($i % 2 === 0) {
        $p->close();
    }
}
unset($p);
$growthKb = $rss() - $before;
check($growthKb < 4096, "no memory growth over 2000 open/analyze cycles (grew {$growthKb} KiB)");

echo "{$count} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);

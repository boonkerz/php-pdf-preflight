<?php

declare(strict_types=1);

/**
 * Prints a short preflight overview of a PDF file.
 *
 *   php -d extension=pdf_preflight.so examples/inspect.php file.pdf
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php examples/inspect.php FILE.pdf\n");
    exit(2);
}

try {
    $pdf = PdfNative::open($argv[1], ['max_file_size' => 512 * 1024 * 1024]);
} catch (PdfNativeException $e) {
    fwrite(STDERR, "Cannot open {$argv[1]} (code {$e->getCode()}): {$e->getMessage()}\n");
    exit(1);
}

$doc = $pdf->getDocumentInfo();
printf("PDF %s, %d page(s)%s\n", $doc['version'], $doc['page_count'], $doc['encrypted'] ? ', encrypted' : '');
if ($doc['pdfx']['declared']) {
    printf("Declares %s (identification only, not validated)\n", $doc['pdfx']['version']);
}
foreach ($doc['issues'] as $issue) {
    printf("  ! %s: %s\n", $issue['code'], $issue['message']);
}

for ($page = 1; $page <= $pdf->getPageCount(); $page++) {
    $info = $pdf->getPageInfo($page);
    $trim = $info['boxes']['TrimBox'];
    printf(
        "Page %d: %.1f x %.1f mm (%s, rotate %d), TrimBox %.1f x %.1f mm (%s)\n",
        $page,
        $info['width_mm'],
        $info['height_mm'],
        $info['orientation'],
        $info['rotation'],
        $trim['display_width_mm'],
        $trim['display_height_mm'],
        $trim['defined'] ? 'defined' : 'derived from ' . $trim['derived_from'],
    );
    foreach ($pdf->getFonts($page) as $font) {
        if (!$font['used']) {
            continue;
        }
        printf("    font %-30s %-9s %s\n", $font['name'] ?: '(unnamed)', $font['type'], $font['embedding']);
    }
    $analysis = $pdf->getPageAnalysis($page);
    if (!$analysis['complete']) {
        echo "    ! analysis incomplete – results are a lower bound\n";
    }
}

$pdf->close();

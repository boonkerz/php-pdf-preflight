<?php

declare(strict_types=1);

/**
 * Measures analysis time and memory per PDF file from PHP.
 *
 *   php -d extension=pdf_preflight.so examples/benchmark.php [--runs=N] FILE.pdf...
 *
 * Memory is read from /proc/self/status (Linux), because allocations of the
 * extension are not visible to memory_get_usage().
 */

$runs = 5;
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--runs=')) {
        $runs = max(1, (int) substr($arg, 7));
    } else {
        $files[] = $arg;
    }
}
if ($files === []) {
    fwrite(STDERR, "usage: php examples/benchmark.php [--runs=N] FILE.pdf...\n");
    exit(2);
}

function procStatusKb(string $key): int
{
    $status = @file_get_contents('/proc/self/status');
    if ($status !== false && preg_match('/^' . $key . ':\s+(\d+)/m', $status, $m)) {
        return (int) $m[1];
    }
    return 0;
}

printf("%-40s %6s %10s %10s %10s %12s\n", 'file', 'pages', 'open ms', 'analyze ms', 'total ms', 'RSS +KiB');
foreach ($files as $file) {
    $open = $analyze = [];
    $rssDelta = 0;
    $pages = 0;
    $error = null;
    for ($i = 0; $i < $runs; $i++) {
        $rssBefore = procStatusKb('VmRSS');
        try {
            $t0 = hrtime(true);
            $pdf = PdfNative::open($file);
            $t1 = hrtime(true);
            $pdf->analyze();
            $t2 = hrtime(true);
        } catch (PdfNativeException $e) {
            $error = $e->getMessage();
            break;
        }
        $rssDelta = max($rssDelta, procStatusKb('VmRSS') - $rssBefore);
        $pages = $pdf->getPageCount();
        $pdf->close();
        $open[] = ($t1 - $t0) / 1e6;
        $analyze[] = ($t2 - $t1) / 1e6;
    }
    $name = strlen($file) > 40 ? '…' . substr($file, -39) : $file;
    if ($error !== null) {
        printf("%-40s error: %s\n", $name, $error);
        continue;
    }
    sort($open);
    sort($analyze);
    $median = static fn (array $v): float => $v[intdiv(count($v), 2)];
    printf(
        "%-40s %6d %10.3f %10.3f %10.3f %12d\n",
        $name,
        $pages,
        $median($open),
        $median($analyze),
        $median($open) + $median($analyze),
        $rssDelta,
    );
}
printf("peak RSS of the process: %d KiB (median of %d runs per file)\n", procStatusKb('VmHWM'), $runs);

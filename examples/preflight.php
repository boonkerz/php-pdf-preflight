<?php

declare(strict_types=1);

/**
 * Checks a file against a JSON profile and prints the result as JSON.
 *
 *   composer install
 *   php -d extension=pdf_preflight.so examples/preflight.php examples/profiles/business-card.json file.pdf
 */

use PrintPreflight\Exceptions\InvalidProfileException;
use PrintPreflight\Preflight;
use PrintPreflight\Profile;

require __DIR__ . '/../vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "usage: php examples/preflight.php PROFILE.json FILE\n");
    exit(2);
}

try {
    $profile = Profile::fromJson((string) file_get_contents($argv[1]));
    $preflight = Preflight::fromProfile($profile);
} catch (InvalidProfileException $e) {
    fwrite(STDERR, "Invalid profile: {$e->getMessage()}\n");
    exit(2);
}

$result = $preflight->checkFile($argv[2]);
echo $result->toJson(JSON_PRETTY_PRINT), "\n";
exit($result->isValid() ? 0 : 1);

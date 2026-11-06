<?php

declare(strict_types=1);

// E2E tests run inside a real WordPress with WooCommerce, against the staging API. wp-env doesn't pass
// the host's environment into the container, so scripts/test-e2e.sh leaves the settings in this file.

$settings = dirname(__DIR__, 2) . '/.e2e-env';
foreach (is_file($settings) ? (file($settings, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
    putenv($line);
}

require dirname(__DIR__) . '/Integration/bootstrap.php';

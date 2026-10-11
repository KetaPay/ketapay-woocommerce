<?php

declare(strict_types=1);

// Integration tests run inside a real WordPress with WooCommerce (see CONTRIBUTING: `npm run test:integration`),
// against the site's own database. Each test makes its own orders and products.

$wpLoad = getenv('WP_LOAD_PATH') ?: '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "WordPress not found at $wpLoad. Run the integration tests with `npm run test:integration`.\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $wpLoad;

if (!class_exists(\KetaPay\WooCommerce\Gateway::class, false) && !function_exists('WC')) {
    fwrite(STDERR, "WooCommerce or the KetaPay plugin is not active.\n");
    exit(1);
}

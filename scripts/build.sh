#!/usr/bin/env bash
# Builds build/ketapay-woocommerce-<version>.zip: the plugin files plus production dependencies only.
# The zip is what store owners upload in Plugins > Add New > Upload.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's/^ \* Version: *//p' "$root/ketapay-woocommerce.php")"
out="$root/build"
stage="$out/ketapay-woocommerce"

rm -rf "$out"
mkdir -p "$stage"
cp -R "$root/ketapay-woocommerce.php" "$root/readme.txt" "$root/LICENSE" "$root/composer.json" "$root/src" "$root/assets" "$stage/"

composer install --working-dir="$stage" --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader --quiet
rm -f "$stage/composer.lock"

# Nothing from development may ship.
for path in tests .github node_modules vendor/bin vendor/phpunit vendor/phpstan; do
  if [[ -e "$stage/$path" ]]; then echo "build: $path must not be in the zip" >&2; exit 1; fi
done

(cd "$out" && zip -qr "ketapay-woocommerce-$version.zip" ketapay-woocommerce)
echo "$out/ketapay-woocommerce-$version.zip"

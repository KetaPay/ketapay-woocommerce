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

# The path repository in composer.json points at a ketapay-php checkout, and Composer would copy all of it
# (its vendor/, tests, spec). Use a `git archive` of its committed HEAD instead: the same files Packagist
# will serve, with the library's export-ignore rules applied.
sdk_src="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); foreach ($c["repositories"] ?? [] as $r) { if (($r["type"] ?? "") === "path") { echo realpath($argv[2] . "/" . $r["url"]); } }' "$root/composer.json" "$root")"
if [[ -n "$sdk_src" ]]; then
  sdk="$out/ketapay-php"
  mkdir -p "$sdk"
  git -C "$sdk_src" archive HEAD | tar -x -C "$sdk"
  php -r '
    $c = json_decode(file_get_contents($argv[1]), true);
    foreach ($c["repositories"] as $i => $r) {
      if (($r["type"] ?? "") === "path") { $c["repositories"][$i]["url"] = $argv[2]; }
    }
    file_put_contents($argv[1], json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' "$stage/composer.json" "$sdk"
fi
composer install --working-dir="$stage" --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader --quiet
rm -f "$stage/composer.lock"

# Nothing from development may ship.
for path in tests .github node_modules vendor/bin vendor/phpunit vendor/phpstan vendor/ketapay/ketapay-php/vendor vendor/ketapay/ketapay-php/tests; do
  if [[ -e "$stage/$path" ]]; then echo "build: $path must not be in the zip" >&2; exit 1; fi
done

(cd "$out" && zip -qr "ketapay-woocommerce-$version.zip" ketapay-woocommerce)
rm -rf "$out/ketapay-php"
echo "$out/ketapay-woocommerce-$version.zip"

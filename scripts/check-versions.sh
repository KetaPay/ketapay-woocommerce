#!/usr/bin/env bash
# The version lives in four places; WordPress reads the plugin header, WordPress.org reads readme.txt,
# the User-Agent sends Plugin::VERSION, Release Please tracks version.txt. They must agree.
set -euo pipefail
cd "$(dirname "$0")/.."
header="$(sed -n 's/^ \* Version: *//p' ketapay-woocommerce.php)"
readme="$(sed -n 's/^Stable tag: *//p' readme.txt)"
constant="$(sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p" src/Plugin.php)"
file="$(cat version.txt)"
if [[ "$header" != "$file" || "$readme" != "$file" || "$constant" != "$file" ]]; then
  echo "Versions disagree: version.txt=$file, plugin header=$header, readme.txt Stable tag=$readme, Plugin::VERSION=$constant" >&2
  exit 1
fi
echo "version $file everywhere"

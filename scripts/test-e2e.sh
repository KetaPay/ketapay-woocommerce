#!/usr/bin/env bash
# Runs the E2E tests inside wp-env, against the API in KETAPAY_E2E_BASE_URL. wp-env doesn't pass the
# environment into the container and prints the commands it runs, so the settings go through a file
# that only exists for this run, not the command line.
set -euo pipefail
cd "$(dirname "$0")/.."

umask 077
trap 'rm -f .e2e-env' EXIT
: > .e2e-env
for name in KETAPAY_E2E_BASE_URL KETAPAY_E2E_SECRET_KEY KETAPAY_E2E_RUN_ID; do
    if [ -n "${!name:-}" ]; then
        printf '%s=%s\n' "$name" "${!name}" >> .e2e-env
    fi
done
chmod 644 .e2e-env # the container's user reads it

npx wp-env run tests-cli --env-cwd=wp-content/plugins/ketapay-woocommerce vendor/bin/phpunit -c phpunit.e2e.xml.dist "$@"

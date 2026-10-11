# Contributing

The plugin is a thin layer over [ketapay-php](https://github.com/KetaPay/ketapay-php), like the Laravel bridge (see `documentation/openapi/public/SDK_STANDARDS.md`, section 3a). API behaviour (requests, retries, idempotency, errors, signatures) belongs in the SDK. This repo holds only the WooCommerce wiring.

## Setup

```sh
composer install   # also points git at .githooks (pre-push)
npm install        # wp-env, for the integration tests
```

ketapay-php comes from Packagist (`^0.1@beta`). The contract tests need the API spec, which isn't in the Packagist package: they read `../ketapay-php/spec/v1.yaml` (check ketapay-php out next to this repo) or `KETAPAY_SPEC`. CI checks out the spec of the installed release.

## Checks

| Command | What |
| --- | --- |
| `composer format` / `format:check` | PHP CS Fixer (PER-CS 2.0) |
| `composer lint` | PHPStan level max with WordPress + WooCommerce stubs, as PHP 8.1 |
| `composer test:contract` | Contract tests: every request the plugin builds, through the real SDK, against a Prism mock of the API spec (the SDK's copy in `../ketapay-php/spec`), plus a check that every field the plugin reads is required in the spec. Not the live API: there is no staging API yet. |
| `composer test` | Unit tests: the pure rules (session params, money, phone, settings, event rules). No WordPress needed. |
| `npm run env:start` then `npm run test:integration` | Integration tests inside a real WordPress + WooCommerce (Docker, wp-env, PHP 8.1) |
| `composer build` | The installable zip in `build/` |

Integration tests cover what only real WooCommerce can prove: orders created, paid, completed, refunded (with the refund record), held and cancelled by signed webhooks through the REST route; the return page; checkout link reuse; the block checkout registration. The API is a fake transport installed through the `ketapay_wc_client_config` filter, so no network and no keys are needed.

## Rules

- **Pure first.** Decisions go in `src/Checkout` and `src/Orders/EventRules.php` (no WordPress calls) with a unit test. WordPress and WooCommerce calls stay in `Gateway`, `OrderSync`, `Plugin` and the webhook controller, covered by integration tests.
- **Every event rule is safe to repeat and to arrive out of order.** Webhooks retry for 3 days and aren't ordered.
- **Never trust the redirect.** The return page and webhooks confirm with the API or the signature; nothing changes an order on query parameters alone.
- **No money moves with the store key.** Deliver, release and refund need the customer's one-time code; don't add admin buttons that pretend otherwise.
- **The main plugin file parses on old PHP** (no typed properties, arrow functions, named arguments), so an unsupported host shows a notice, not a fatal error.
- **Translatable admin and checkout strings** use the `ketapay-woocommerce` text domain. Order notes are English for now.
- Releases: conventional commits, Release Please bumps `Version` in the plugin header, `Stable tag` in readme.txt and `Plugin::VERSION`.

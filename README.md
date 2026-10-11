# KetaPay Escrow for WooCommerce

A WooCommerce payment gateway: buyers pay into [KetaPay](https://ketapay.com) escrow, and the store is paid when they confirm they received their order. Built on [ketapay-php](https://github.com/KetaPay/ketapay-php); requires PHP 8.1, WordPress 6.6 and WooCommerce 9.0 or later.

> Beta. The KetaPay API is in design partner testing and may still change.

Store owners: see [readme.txt](readme.txt) for setup and the FAQ.

## How it works

| Step | KetaPay | WooCommerce order |
| --- | --- | --- |
| Buyer picks KetaPay at checkout | Checkout session created from the order (line items, total, buyer email and phone, product photos) | Pending, redirected to KetaPay |
| Buyer pays | `escrow.funded` | Processing (paid; transaction id = escrow id) |
| Paid amount differs from the order total | `escrow.funded` | On hold, with a note |
| Store marks delivered in KetaPay | `milestone.delivered` | Note |
| Buyer releases (or auto release) | `escrow.completed` | Completed |
| Buyer or seller reports a problem | `dispute.opened` / `dispute.resolved` | On hold, then back to Processing |
| KetaPay refunds the buyer | `escrow.refunded` | Refunded (refund recorded) |
| Link not paid in time | `checkout_session.expired` | Cancelled |
| Order cancelled in WooCommerce | Checkout link cancelled | Cancelled |

Other events (payouts, failed refunds, chargebacks) add order notes. Unknown event types are ignored.

Details that matter:

- **Webhooks** arrive at `POST /wp-json/ketapay/v1/webhook`, verified with the SDK (`Webhook::verify`, raw body, 5-minute tolerance, two secrets while rotating). Each event is applied once per order (event ids kept in order meta), in any order: a `completed` that arrives before `funded` records the payment first. A failure answers `500`, so KetaPay retries.
- **The return page** checks the session with the API when the buyer comes back, so they see their order as paid even if the webhook is late. It checks the order key and that the session belongs to the order.
- **Paying again** reuses the open link if the total is unchanged; a changed order cancels the old link and gets a new one; an already paid link sends the buyer to their order instead of a second payment.
- **Two stores, one KetaPay account**: each store stamps a random site id in session metadata and ignores events for other stores.
- **Setup**: saving a secret key registers the webhook (or rotates its secret if the URL is already registered, so events never arrive twice) and stores the signing secret.
- **HTTP** goes through `wp_remote_request` (`WordPressTransport`), so the site's proxy and certificate settings apply.

The plugin can't deliver, release or refund: those need the seller's or buyer's one-time code, so a leaked store key can't move escrowed money.

## Code

```
ketapay-woocommerce.php     plugin header, PHP check, HPOS + block checkout compatibility
src/Plugin.php              hooks, settings, client, site id
src/Gateway.php             WC_Payment_Gateway: settings page, process_payment, webhook setup on save
src/Settings.php            settings + wp-config.php constants, checked
src/Checkout/               order -> checkout session (pure: SessionParams, Money, Phone)
src/Orders/EventRules.php   event -> order update (pure, every rule unit tested)
src/Orders/OrderSync.php    applies updates to WC_Order; return-page and cancel hooks
src/Webhooks/               REST route, signature check; webhook registration
src/Blocks/ + assets/js/    block checkout registration (plain script, no build step)
```

Filter `ketapay_wc_client_config` to change SDK options (the integration tests use it to install a fake transport).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

GPL-2.0-or-later, like WordPress and WooCommerce. The bundled ketapay-php library is MIT.

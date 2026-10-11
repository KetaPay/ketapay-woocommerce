=== KetaPay Escrow for WooCommerce ===
Contributors: ketapay
Tags: escrow, payments, nigeria, naira, payment gateway
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Buyers pay into KetaPay escrow. KetaPay holds the money until they confirm they received their order, then pays you.

== Description ==

New buyers often won't pay a store they don't know. With KetaPay escrow they don't have to trust you first: the money waits with KetaPay until they confirm the order arrived as described.

How an order goes:

1. At checkout the buyer chooses "Pay with KetaPay escrow" and pays on KetaPay by card or bank transfer.
2. The order moves to **Processing**. The money is held in escrow; you ship.
3. You mark the order delivered in KetaPay. The buyer confirms, or the money releases on its own after the wait you chose (for example 3 days) if they don't respond.
4. KetaPay pays you and the order moves to **Completed**.

If the buyer reports a problem, the order goes **On hold** and KetaPay handles the dispute with both of you. If KetaPay refunds the buyer, the order moves to **Refunded**. Every step adds an order note.

Fees: KetaPay adds 1.5% plus VAT to what the buyer pays and takes 1.5% from your payout. Prices in your store don't change.

= Requirements =

* A KetaPay business account with API keys
* Store currency set to Nigerian naira (₦)
* HTTPS, so KetaPay can send payment updates to your store

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to **WooCommerce > Settings > Payments > KetaPay escrow**.
3. Paste your secret key from the KetaPay dashboard (Developers). Start with an `sk_test_` key: no real money moves.
4. Enter the email of the KetaPay account that should receive the money.
5. Save. The plugin registers its webhook with KetaPay and fills in the signing secret.
6. Place a test order, then switch to your `sk_live_` key.

Safer: put the keys in `wp-config.php` instead of the database:

`define( 'KETAPAY_SECRET_KEY', 'sk_live_...' );`
`define( 'KETAPAY_WEBHOOK_SECRET', 'whsec_...' );`

== Frequently Asked Questions ==

= Can I refund from the WooCommerce order screen? =

Not yet. Cancel the escrow in KetaPay; the order moves to Refunded on its own.

= Why do I mark orders delivered in KetaPay, not in WooCommerce? =

Moving escrowed money needs proof from you (a one-time code). Your store's API key alone can't release or refund anything, so a stolen key can't move money.

= Does it work with the block checkout? =

Yes, with both the block-based Checkout and the classic checkout shortcode, and with High-Performance Order Storage.

= Does it work with multi-vendor plugins like Dokan? =

Not yet. Every order is paid to the one KetaPay account in the settings.

= Where are the logs? =

**WooCommerce > Status > Logs**, source `ketapay`. Turn on "Debug log" to record every request. Keys and personal details are never logged.

== Changelog ==

= 0.1.0-beta.1 =
* First beta: checkout, webhooks for every order status, return-page confirmation, block checkout, HPOS.

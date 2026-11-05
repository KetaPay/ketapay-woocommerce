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
3. You mark the order delivered in KetaPay. The buyer has the inspection period you chose (3 days by default) to confirm or report a problem. If they don't respond, you can report them as unresponsive and KetaPay decides.
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
4. Save. The plugin registers its webhook with KetaPay and fills in the signing secret.
5. Place a test order, then switch to your `sk_live_` key.

Payments go to your KetaPay business: your business is the seller on every order, and each release is paid into its KetaPay balance.

Safer: put the keys in `wp-config.php` instead of the database:

`define( 'KETAPAY_SECRET_KEY', 'sk_live_...' );`
`define( 'KETAPAY_WEBHOOK_SECRET', 'whsec_...' );`

== Frequently Asked Questions ==

= Can I refund from the WooCommerce order screen? =

Not yet. Refund it in your KetaPay dashboard; the order moves to Refunded on its own.

= Why do I mark orders delivered in KetaPay, not in WooCommerce? =

The plugin only creates payments and follows them. Delivering and refunding happen in your KetaPay dashboard, so the store's API key never needs permission to move escrowed money.

= Does it work with the block checkout? =

Yes, with both the block-based Checkout and the classic checkout shortcode, and with High-Performance Order Storage.

= Does it work with multi-vendor plugins like Dokan? =

Not yet. Every order is paid to the one KetaPay account in the settings.

= Where are the logs? =

**WooCommerce > Status > Logs**, source `ketapay`. Turn on "Debug log" to record every request. Keys and personal details are never logged.

== Changelog ==

= 0.1.0-beta.1 =
* First beta: checkout, webhooks for every order status, return-page confirmation, block checkout, HPOS.

<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce;

use KetaPay\Errors\KetaPayError;
use KetaPay\KetaPay;
use KetaPay\WooCommerce\Checkout\Money;
use KetaPay\WooCommerce\Checkout\OrderSnapshotFactory;
use KetaPay\WooCommerce\Checkout\SessionParams;
use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Webhooks\WebhookSetup;

/**
 * "Pay with KetaPay escrow" at checkout. The buyer pays on KetaPay's hosted checkout; KetaPay holds
 * the money until they confirm they received the order, then pays the store.
 */
final class Gateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = Plugin::GATEWAY_ID;
        $this->has_fields = false;
        $this->method_title = __('KetaPay escrow', 'ketapay-woocommerce');
        $this->method_description = __('Buyers pay into KetaPay escrow. KetaPay holds the money until they confirm they received their order, then pays you.', 'ketapay-woocommerce');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, function (): void {
            $this->process_admin_options();
        });
    }

    public function init_form_fields(): void
    {
        $inConfig = __('Set in wp-config.php; this field is ignored.', 'ketapay-woocommerce');

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'ketapay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Offer KetaPay escrow at checkout', 'ketapay-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'ketapay-woocommerce'),
                'type' => 'text',
                'default' => __('Pay with KetaPay escrow', 'ketapay-woocommerce'),
                'desc_tip' => __('What buyers see at checkout.', 'ketapay-woocommerce'),
            ],
            'description' => [
                'title' => __('Description', 'ketapay-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pay by card or bank transfer. KetaPay holds your money until you confirm you received your order. KetaPay adds a 1.5% fee plus VAT.', 'ketapay-woocommerce'),
            ],
            'account' => [
                'title' => __('KetaPay account', 'ketapay-woocommerce'),
                'type' => 'title',
            ],
            'secret_key' => [
                'title' => __('Secret key', 'ketapay-woocommerce'),
                'type' => 'password',
                'description' => defined('KETAPAY_SECRET_KEY')
                    ? $inConfig
                    : __('From your KetaPay dashboard, under Developers. sk_test_ keys use the sandbox: no real money moves. Safer: define KETAPAY_SECRET_KEY in wp-config.php instead.', 'ketapay-woocommerce'),
            ],
            'seller_email' => [
                'title' => __('Seller email', 'ketapay-woocommerce'),
                'type' => 'email',
                'description' => __('The KetaPay account that receives the money. It is the seller on every order.', 'ketapay-woocommerce'),
            ],
            'seller_phone' => [
                'title' => __('Seller phone (optional)', 'ketapay-woocommerce'),
                'type' => 'text',
                'placeholder' => '+2348012345678',
            ],
            'webhook_secret' => [
                'title' => __('Webhook signing secret', 'ketapay-woocommerce'),
                'type' => 'password',
                'description' => defined('KETAPAY_WEBHOOK_SECRET')
                    ? $inConfig
                    : sprintf(
                        /* translators: %s: the store's webhook URL */
                        __('Filled in for you when you save your secret key (needs HTTPS). To add it by hand, create a webhook in your KetaPay dashboard for %s and paste its signing secret.', 'ketapay-woocommerce'),
                        '<code>' . esc_html(Plugin::webhookUrl()) . '</code>',
                    ),
            ],
            'escrow' => [
                'title' => __('How the money is released', 'ketapay-woocommerce'),
                'type' => 'title',
            ],
            'category' => [
                'title' => __('What you sell', 'ketapay-woocommerce'),
                'type' => 'select',
                'default' => 'goods',
                'options' => [
                    'goods' => __('Goods', 'ketapay-woocommerce'),
                    'services' => __('Services', 'ketapay-woocommerce'),
                    'digital' => __('Digital products', 'ketapay-woocommerce'),
                    'vehicle' => __('Vehicles', 'ketapay-woocommerce'),
                    'property' => __('Property', 'ketapay-woocommerce'),
                    'other' => __('Other', 'ketapay-woocommerce'),
                ],
            ],
            'release_method' => [
                'title' => __('Release', 'ketapay-woocommerce'),
                'type' => 'select',
                'default' => 'buyer_confirms',
                'options' => [
                    'buyer_confirms' => __('Buyer confirms on KetaPay after you mark the order delivered', 'ketapay-woocommerce'),
                    'release_code' => __('Buyer gives you a 6-digit code at handover (pickup, in person)', 'ketapay-woocommerce'),
                ],
            ],
            'auto_release_hours' => [
                'title' => __('Release automatically', 'ketapay-woocommerce'),
                'type' => 'select',
                'default' => '72',
                'description' => __('After you mark an order delivered, if the buyer neither confirms nor reports a problem. Only with "Buyer confirms".', 'ketapay-woocommerce'),
                'options' => [
                    '' => __('Never: the buyer must confirm', 'ketapay-woocommerce'),
                    '24' => __('After 1 day', 'ketapay-woocommerce'),
                    '48' => __('After 2 days', 'ketapay-woocommerce'),
                    '72' => __('After 3 days', 'ketapay-woocommerce'),
                    '168' => __('After 7 days', 'ketapay-woocommerce'),
                    '336' => __('After 14 days', 'ketapay-woocommerce'),
                    '720' => __('After 30 days', 'ketapay-woocommerce'),
                ],
            ],
            'send_images' => [
                'title' => __('Product photos', 'ketapay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Attach product photos to each escrow, as a record of what was bought', 'ketapay-woocommerce'),
                'default' => 'yes',
            ],
            'debug' => [
                'title' => __('Debug log', 'ketapay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Log every KetaPay request (never keys or personal details) to WooCommerce > Status > Logs', 'ketapay-woocommerce'),
                'default' => 'no',
            ],
        ];
    }

    public function is_available(): bool
    {
        return parent::is_available()
            && get_woocommerce_currency() === 'NGN'
            && Plugin::settings()->problems() === [];
    }

    public function admin_options(): void
    {
        $settings = Plugin::settings();
        $problems = $settings->problems();
        if (get_woocommerce_currency() !== 'NGN') {
            $problems[] = __('KetaPay takes naira only. Set the store currency to Nigerian naira (₦).', 'ketapay-woocommerce');
        }
        if ($problems !== []) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('KetaPay is not shown at checkout yet:', 'ketapay-woocommerce') . '</strong></p><ul>';
            foreach ($problems as $problem) {
                echo '<li>' . esc_html($problem) . '</li>';
            }
            echo '</ul></div>';
        } elseif ($settings->isTestMode()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Sandbox: you are using a test key, so no real money moves.', 'ketapay-woocommerce') . '</p></div>';
        }
        parent::admin_options();
    }

    public function process_admin_options(): bool
    {
        $saved = parent::process_admin_options();
        $this->connectWebhook();

        return $saved;
    }

    /**
     * @param int $order_id
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return ['result' => 'failure'];
        }
        $settings = Plugin::settings();

        try {
            $client = Plugin::client($settings);
            $redirect = $this->existingCheckout($client, $order);
            if ($redirect === null) {
                $snapshot = OrderSnapshotFactory::fromOrder($order, $this->get_return_url($order));
                $session = $client->checkoutSessions->create(SessionParams::build($snapshot, $settings, Plugin::siteId()));
                $order->update_meta_data(OrderSync::META_SESSION, $session->id);
                $order->add_order_note(sprintf('Sent the buyer to KetaPay checkout (%s).', $session->id));
                $order->save();
                $redirect = $session->url;
            }

            return ['result' => 'success', 'redirect' => $redirect];
        } catch (KetaPayError $e) {
            Plugin::log('error', 'Could not start KetaPay checkout: ' . $e->getMessage(), [
                'order_id' => $order->get_id(),
                'code' => $e->code,
                'param' => $e->param,
                'request_id' => $e->requestId,
            ]);
        } catch (\InvalidArgumentException $e) {
            Plugin::log('error', 'Could not start KetaPay checkout: ' . $e->getMessage(), ['order_id' => $order->get_id()]);
        }
        wc_add_notice(__('We couldn\'t start the KetaPay payment. Please try again, or choose another way to pay.', 'ketapay-woocommerce'), 'error');

        return ['result' => 'failure'];
    }

    /**
     * When the buyer comes back to pay an order again: send them to the link they already have if it
     * still matches the order, or to the order page if they already paid it (the webhook may be
     * late). Null means a new checkout session is needed.
     */
    private function existingCheckout(KetaPay $client, \WC_Order $order): ?string
    {
        $sessionId = $order->get_meta(OrderSync::META_SESSION);
        if (!is_string($sessionId) || $sessionId === '') {
            return null;
        }
        $session = $client->checkoutSessions->retrieve($sessionId);
        if ($session->status === 'complete') {
            return add_query_arg('checkout_session_id', $sessionId, $this->get_return_url($order));
        }
        if ($session->status !== 'open') {
            return null;
        }
        if ($session->amount === Money::toKobo($order->get_total())) {
            return $session->url;
        }
        // The order changed since: close the old link so the old amount can't be paid.
        $client->checkoutSessions->cancel($sessionId);

        return null;
    }

    private function connectWebhook(): void
    {
        $settings = Plugin::settings();
        if ($settings->webhookSecrets !== [] || preg_match('/^sk_(test|live)_/', $settings->secretKey) !== 1) {
            return;
        }
        $url = Plugin::webhookUrl();
        if (!str_starts_with($url, 'https://')) {
            \WC_Admin_Settings::add_error(__('KetaPay only sends payment updates to HTTPS addresses. Once this site has HTTPS, save again to connect.', 'ketapay-woocommerce'));

            return;
        }
        try {
            $secret = WebhookSetup::connect(Plugin::client($settings), $url, wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
            $this->update_option('webhook_secret', $secret);
            \WC_Admin_Settings::add_message(__('Connected: KetaPay will send payment updates to this store.', 'ketapay-woocommerce'));
        } catch (KetaPayError $e) {
            Plugin::log('error', 'Could not register the webhook: ' . $e->getMessage(), ['request_id' => $e->requestId]);
            \WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: error message from KetaPay */
                __('Could not connect to KetaPay: %s Check the secret key, or add the webhook by hand.', 'ketapay-woocommerce'),
                $e->getMessage(),
            ));
        }
    }
}

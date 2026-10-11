<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use KetaPay\WooCommerce\Plugin;

/** Shows the gateway in the block-based Checkout, WooCommerce's default checkout since 8.3. */
final class PaymentMethod extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Plugin::GATEWAY_ID;

    public function initialize(): void
    {
        $settings = get_option('woocommerce_' . Plugin::GATEWAY_ID . '_settings', []);
        $this->settings = is_array($settings) ? $settings : [];
    }

    public function is_active(): bool
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways[Plugin::GATEWAY_ID] ?? null;

        return $gateway instanceof \WC_Payment_Gateway && $gateway->is_available();
    }

    /** @return list<string> */
    public function get_payment_method_script_handles(): array
    {
        $src = plugins_url('assets/js/blocks.js', Plugin::file());
        wp_register_script(
            'ketapay-wc-blocks',
            $src !== '' ? $src : false,
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            Plugin::VERSION,
            true,
        );

        return ['ketapay-wc-blocks'];
    }

    /** @return array<string, mixed> */
    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports' => ['products'],
        ];
    }
}

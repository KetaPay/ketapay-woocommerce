<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use KetaPay\KetaPay;
use KetaPay\WooCommerce\Blocks\PaymentMethod;
use KetaPay\WooCommerce\Http\WordPressTransport;
use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Webhooks\WebhookController;

/** Wires the gateway into WordPress and WooCommerce, and hands out the shared pieces. */
final class Plugin
{
    public const VERSION = '0.1.0-beta.1'; // x-release-please-version
    public const GATEWAY_ID = 'ketapay';
    public const LOG_SOURCE = 'ketapay';
    private const SITE_ID_OPTION = 'ketapay_wc_site_id';

    private static ?string $file = null;

    public static function boot(string $pluginFile): void
    {
        self::$file = $pluginFile;

        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = Gateway::class;

            return $gateways;
        });
        add_action('woocommerce_blocks_payment_method_type_registration', static function (PaymentMethodRegistry $registry): void {
            $registry->register(new PaymentMethod());
        });
        add_action('rest_api_init', [WebhookController::class, 'register']);
        add_action('template_redirect', [OrderSync::class, 'confirmReturn']);
        add_action('woocommerce_order_status_cancelled', [OrderSync::class, 'cancelCheckout']);
        add_filter('plugin_action_links_' . plugin_basename($pluginFile), static function (array $links): array {
            $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID);
            array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'ketapay-woocommerce') . '</a>');

            return $links;
        });
    }

    public static function file(): string
    {
        return self::$file ?? dirname(__DIR__) . '/ketapay-woocommerce.php';
    }

    public static function settings(): Settings
    {
        $saved = get_option('woocommerce_' . self::GATEWAY_ID . '_settings', []);
        $options = [];
        foreach (is_array($saved) ? $saved : [] as $key => $value) {
            $options[(string) $key] = $value;
        }
        $constants = [];
        foreach (['KETAPAY_SECRET_KEY', 'KETAPAY_WEBHOOK_SECRET'] as $name) {
            $value = defined($name) ? constant($name) : null;
            if (is_string($value)) {
                $constants[$name] = $value;
            }
        }

        return Settings::from($options, $constants);
    }

    /** A client for the saved settings. Filter `ketapay_wc_client_config` to change options (tests set a transport). */
    public static function client(?Settings $settings = null): KetaPay
    {
        $settings ??= self::settings();
        $config = [
            'apiKey' => $settings->secretKey,
            'transport' => new WordPressTransport(),
            'appInfo' => ['name' => 'ketapay-woocommerce', 'version' => self::VERSION],
            'logLevel' => $settings->debug ? 'debug' : 'warn',
            'logger' => static function (string $level, string $message, array $context): void {
                wc_get_logger()->log($level, $message, $context + ['source' => self::LOG_SOURCE]);
            },
        ];

        /** @var array{apiKey: string} $config */
        $config = apply_filters('ketapay_wc_client_config', $config);

        return new KetaPay($config);
    }

    /**
     * A random id for this store, sent in every session's metadata. Two stores sharing one KetaPay
     * account receive each other's webhooks; each acts only on its own.
     */
    public static function siteId(): string
    {
        $id = get_option(self::SITE_ID_OPTION);
        if (!is_string($id) || $id === '') {
            $id = wp_generate_uuid4();
            update_option(self::SITE_ID_OPTION, $id, false);
        }

        return $id;
    }

    public static function webhookUrl(): string
    {
        return rest_url(WebhookController::ROUTE_NAMESPACE . WebhookController::ROUTE);
    }

    /** @param array<string, mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        wc_get_logger()->log($level, $message, $context + ['source' => self::LOG_SOURCE]);
    }
}

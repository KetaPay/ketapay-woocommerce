<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Webhooks;

use KetaPay\Errors\WebhookSignatureError;
use KetaPay\Webhook;
use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Plugin;

/**
 * POST /wp-json/ketapay/v1/webhook. Answers 200 once the order is updated (or the event isn't for
 * this store), 400 for a bad signature, and 500 when it can't process the event yet, so KetaPay
 * retries it (for up to 3 days).
 */
final class WebhookController
{
    public const ROUTE_NAMESPACE = 'ketapay/v1';
    public const ROUTE = '/webhook';

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            // Public by design: the signature, checked in handle(), is the authentication.
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $secrets = Plugin::settings()->webhookSecrets;
        if ($secrets === []) {
            Plugin::log('error', 'Webhook received but no signing secret is set. Add it in WooCommerce > Settings > Payments > KetaPay.');

            return new \WP_REST_Response(['error' => 'webhook secret not configured'], 500);
        }

        $signature = $request->get_header('ketapay_signature');
        try {
            $event = Webhook::verify($request->get_body(), $signature, $secrets);
        } catch (WebhookSignatureError $e) {
            Plugin::log('warning', 'Webhook rejected: ' . $e->getMessage());

            return new \WP_REST_Response(['error' => 'invalid signature'], 400);
        }

        try {
            $result = OrderSync::handle($event);
        } catch (\Throwable $e) {
            Plugin::log('error', "Webhook {$event->type} failed, KetaPay will retry: " . $e->getMessage(), ['event_id' => $event->id]);

            return new \WP_REST_Response(['error' => 'could not process the event'], 500);
        }
        Plugin::log('info', "Webhook {$event->type}: $result", ['event_id' => $event->id]);

        return new \WP_REST_Response(['received' => true], 200);
    }
}

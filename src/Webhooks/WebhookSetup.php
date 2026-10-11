<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Webhooks;

use KetaPay\KetaPay;

/**
 * Registers this store's webhook URL with KetaPay and returns the signing secret, so the store owner
 * only pastes their secret key. A secret is only ever shown once, so when the URL is already
 * registered (the secret was cleared or lost) the endpoint's secret is rotated instead of adding a
 * second endpoint that would deliver every event twice.
 */
final class WebhookSetup
{
    public static function connect(KetaPay $client, string $url, string $storeName): string
    {
        // One page (the API's maximum of 100) is enough: an account has a few endpoints. Walking every
        // page would trust has_more to end, and a save in the admin should never loop.
        foreach ($client->webhookEndpoints->list(['limit' => 100])->data as $endpoint) {
            if ($endpoint->url === $url) {
                return $client->webhookEndpoints->rotateSecret($endpoint->id)->secret;
            }
        }

        return $client->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => ['*'],
            'description' => mb_substr("WooCommerce: $storeName", 0, 200),
        ])->secret;
    }
}

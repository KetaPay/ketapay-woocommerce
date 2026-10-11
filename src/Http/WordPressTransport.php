<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Http;

use KetaPay\Http\Request;
use KetaPay\Http\Response;
use KetaPay\Http\Transport;
use KetaPay\Http\TransportException;

/**
 * Sends the SDK's requests through WordPress's HTTP API (wp_remote_request), so the site's proxy,
 * certificate and timeout settings apply, and other plugins can see the calls with the usual
 * http_api_debug hooks. Retries, idempotency keys and error mapping stay in the SDK.
 */
final class WordPressTransport implements Transport
{
    public function send(Request $request): Response
    {
        if ($request->cancel?->isCancelled()) {
            throw new TransportException('Request was cancelled', cancelled: true);
        }

        $result = wp_remote_request($request->url, [
            'method' => $request->method,
            'headers' => $request->headers,
            'body' => $request->body ?? '',
            'timeout' => max(1, $request->timeoutMs / 1000),
            'redirection' => 0,
            'user-agent' => $request->headers['User-Agent'] ?? 'ketapay-woocommerce',
        ]);

        if (is_wp_error($result)) {
            $message = $result->get_error_message();

            throw new TransportException(
                $message !== '' ? $message : 'Could not reach KetaPay',
                timedOut: stripos($message, 'timed out') !== false || stripos($message, 'cURL error 28') !== false,
            );
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            // A header sent more than once (Set-Cookie, Vary) comes back as a list.
            $values = array_filter(is_array($value) ? $value : [$value], 'is_string');
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return new Response((int) wp_remote_retrieve_response_code($result), $headers, wp_remote_retrieve_body($result));
    }
}

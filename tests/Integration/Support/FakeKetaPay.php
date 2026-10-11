<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Integration\Support;

use KetaPay\Http\Request;
use KetaPay\Http\Response;
use KetaPay\Http\Transport;

/**
 * Stands in for the KetaPay API: answers each "METHOD /path" with a queued response and records
 * every request. Installed through the plugin's `ketapay_wc_client_config` filter.
 */
final class FakeKetaPay implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var array<string, list<array{int, array<string, mixed>}>> */
    private array $responses = [];

    private ?\Closure $filter = null;

    public function install(): void
    {
        $this->filter = function (array $config): array {
            $config['transport'] = $this;
            $config['maxRetries'] = 0;

            return $config;
        };
        add_filter('ketapay_wc_client_config', $this->filter);
    }

    public function uninstall(): void
    {
        if ($this->filter !== null) {
            remove_filter('ketapay_wc_client_config', $this->filter);
        }
    }

    /** @param array<string, mixed> $body */
    public function on(string $method, string $path, int $status, array $body): self
    {
        $this->responses["$method $path"][] = [$status, $body];

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        $path = (string) preg_replace('#^/v1#', '', $path);
        $key = "{$request->method} $path";
        $queued = $this->responses[$key] ?? [];
        if ($queued === []) {
            return new Response(500, ['content-type' => 'application/json'], (string) json_encode([
                'error' => ['type' => 'api_error', 'code' => 'unexpected', 'message' => "No fake response for $key"],
            ]));
        }
        // The last response for a path repeats; earlier ones are used once each, in order.
        [$status, $body] = count($queued) > 1 ? array_shift($this->responses[$key]) ?? $queued[0] : $queued[0];

        return new Response($status, ['content-type' => 'application/json', 'x-request-id' => 'req_test'], (string) json_encode($body));
    }

    /** @return list<string> "METHOD /path" of every request, in order */
    public function calls(): array
    {
        return array_map(
            static fn(Request $r): string => $r->method . ' ' . preg_replace('#^/v1#', '', (string) parse_url($r->url, PHP_URL_PATH)),
            $this->requests,
        );
    }

    /** @return array<string, mixed> */
    public function body(int $index): array
    {
        $body = json_decode($this->requests[$index]->body ?? '{}', true);
        $fields = [];
        foreach (is_array($body) ? $body : [] as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }
}

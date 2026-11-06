<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\E2E\Support;

/**
 * The buyer and the business acting on the staging API, with plain requests. The plugin under test uses
 * its locked SDK; these calls don't, so the tests don't depend on what that SDK version offers.
 */
final class StagingApi
{
    /** In the sandbox every one-time code is this, and no message is sent. */
    private const SANDBOX_CODE = '123456';

    public function __construct(private readonly string $baseUrl, private readonly string $key) {}

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], ?string $customerToken = null): array
    {
        return $this->send('POST', $path, $body, $customerToken);
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path);
    }

    /** Signs a customer in with the sandbox code and returns their customer token. */
    public function tokenFor(string $email): string
    {
        $session = $this->post('/customer_sessions', ['email' => $email]);
        $verified = $this->post('/customer_sessions/' . self::text($session, 'id') . '/verify', ['code' => self::SANDBOX_CODE]);

        return self::text($verified, 'token');
    }

    /** @param array<string, mixed> $from */
    public static function text(array $from, string $key): string
    {
        $value = $from[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Expected a string at '$key' in " . json_encode($from));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $from
     * @return list<array<string, mixed>>
     */
    public static function rows(array $from, string $key): array
    {
        $rows = [];
        foreach (is_array($from[$key] ?? null) ? $from[$key] : [] as $row) {
            if (is_array($row)) {
                $rows[] = self::fields($row);
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    public static function fields(array $row): array
    {
        $fields = [];
        foreach ($row as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null, ?string $customerToken = null): array
    {
        $headers = ['Authorization' => "Bearer {$this->key}", 'Content-Type' => 'application/json'];
        if ($method === 'POST') {
            $headers['Idempotency-Key'] = wp_generate_uuid4();
        }
        if ($customerToken !== null) {
            $headers['KetaPay-Customer-Token'] = $customerToken;
        }
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 30];
        if ($body !== null) {
            $args['body'] = (string) json_encode($body === [] ? new \stdClass() : $body);
        }
        $response = wp_remote_request($this->baseUrl . $path, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException("$method $path failed: " . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $text = wp_remote_retrieve_body($response);
        if ($status >= 300) {
            throw new \RuntimeException("$method $path answered $status: $text");
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? self::fields($decoded) : [];
    }
}

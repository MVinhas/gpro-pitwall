<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Raw HTTP layer for the GPRO public API. Knows how to talk to v2 endpoints
 * (Bearer-authenticated, JSON) and to the no-auth, gzip-encoded
 * GetMarketFile.asp dump. Knows nothing about caching, per-endpoint keys, or
 * the higher-level naming surface — that's GproApiClient's job.
 */
final class GproApiFetcher
{
    private readonly string $baseUrl;
    private readonly string $userAgent;
    private readonly int $connectTimeout;
    private readonly int $timeout;
    private readonly int $marketTimeout;
    private ?string $token = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, private readonly ?GproApiThrottle $throttle = null)
    {
        $this->baseUrl = rtrim((string) $config['base_url'], '/');
        $this->userAgent = 'GPRO-Pitwall/' . ($config['version'] ?? '0.0.0');
        // Bound both phases so a slow/hung GPRO endpoint can't pin a PHP worker
        // indefinitely. Defaults are generous (GPRO can be slow under load) but
        // env-tunable so they can be tightened or relaxed without a redeploy.
        $this->connectTimeout = max(1, (int) ($config['connect_timeout'] ?? 10));
        $this->timeout        = max(1, (int) ($config['timeout'] ?? 30));
        $this->marketTimeout  = max(1, (int) ($config['market_timeout'] ?? 60));
    }

    public function setToken(string $token): void
    {
        $this->token = trim($token);
    }

    /** @return array<string, mixed> */
    public function fetchJson(string $endpoint): array
    {
        if (empty($this->token)) {
            throw new RuntimeException('GPRO API token not set for current user');
        }

        $this->throttle?->acquire();

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->token}",
                'Accept: application/json',
                "User-Agent: {$this->userAgent}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($error) {
            throw new RuntimeException($error);
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("API error ({$httpCode})");
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response');
        }

        return $data;
    }

    /**
     * No-auth full-market dump. Response is gzip-encoded JSON; we accept
     * any encoding so curl can gunzip when the server declares it, and
     * fall back to in-process gunzip when it doesn't.
     *
     * @return array<string, mixed>
     */
    public function fetchMarketFile(string $market): array
    {
        $this->throttle?->acquire();

        $url = $this->baseUrl . "/GetMarketFile.asp?market={$market}&type=json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            // The market dump is larger than the v2 endpoints, so allow a longer
            // transfer budget — but still bound it.
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->marketTimeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Encoding: gzip',
                "User-Agent: {$this->userAgent}",
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($error) {
            throw new RuntimeException("Market file fetch failed: {$error}");
        }
        if ($httpCode !== 200 || !is_string($raw)) {
            throw new RuntimeException("Market file fetch failed: HTTP {$httpCode}");
        }

        if (str_starts_with($raw, "\x1f\x8b")) {
            $decoded = @gzdecode($raw);
            if ($decoded === false) {
                throw new RuntimeException('Market file gunzip failed');
            }
            $raw = $decoded;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Market file JSON decode failed');
        }

        return $data;
    }
}

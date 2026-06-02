<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Verifies reCAPTCHA v2 "I'm not a robot" checkbox tokens.
 *
 * Pairs with the classic api.js loader and <div class="g-recaptcha">
 * widget in the form. The widget injects a hidden g-recaptcha-response
 * field with the token; this service POSTs that token to Google's
 * siteverify endpoint and reads the success boolean. No score, no
 * action — checkbox is binary pass/fail.
 */
final readonly class ReCaptchaService
{
    private const string VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private string $secretKey,
        private bool $isDev = false,
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if ($this->secretKey === '') {
            // Fail-closed in prod. Dev bypass intentional so local registration
            // doesn't need a real key. Fenced behind isDev so prod can't silently
            // accept every request.
            return $this->isDev;
        }

        if ($token === '') {
            return false;
        }

        $data = [
            'secret'   => $this->secretKey,
            'response' => $token,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $data['remoteip'] = $remoteIp;
        }

        // cURL rather than file_get_contents — shared hosts (Hetzner
        // Webhosting et al.) routinely ship with allow_url_fopen disabled,
        // which makes the streams wrapper fail before sending a packet.
        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);

        if (!is_string($response) || $httpCode !== 200) {
            error_log("[recaptcha] siteverify cURL failed: HTTP {$httpCode} err={$curlErr}");
            return false;
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            error_log('[recaptcha] siteverify returned non-JSON: ' . substr($response, 0, 200));
            return false;
        }

        if (($payload['success'] ?? false) === true) {
            return true;
        }

        // Log Google's error codes so a deploy-time misconfiguration is
        // visible in var/log/ instead of silently failing closed.
        $codes = $payload['error-codes'] ?? [];
        $codeStr = is_array($codes) ? implode(',', $codes) : (string) $codes;
        error_log("[recaptcha] verification failed; error-codes={$codeStr}");

        return false;
    }
}

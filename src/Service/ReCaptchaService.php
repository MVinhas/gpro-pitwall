<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ReCaptchaService
{
    private const string VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private const float SCORE_THRESHOLD = 0.5;

    public function __construct(
        private string $secretKey,
        private bool $isDev = false,
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if ($this->secretKey === '') {
            // Empty key is only acceptable in dev — outside dev it must fail closed,
            // otherwise a misconfigured prod silently allows every request through.
            return $this->isDev;
        }

        if ($token === '') {
            return false;
        }

        $data = [
            'secret'   => $this->secretKey,
            'response' => $token,
        ];

        if ($remoteIp) {
            $data['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
                'timeout' => 3,
            ],
        ]);

        $response = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($response === false) {
            return false;
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return false;
        }

        return ($payload['success'] ?? false)
            && ($payload['score'] ?? 0.0) >= self::SCORE_THRESHOLD;
    }
}

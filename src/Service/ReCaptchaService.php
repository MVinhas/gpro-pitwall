<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Verifies reCAPTCHA Enterprise tokens via the REST API.
 *
 * We use the bare REST endpoint instead of google/cloud-recaptcha-enterprise
 * to keep the dep tree thin — that package pulls in gRPC + protobuf and ~30
 * transitive packages for a single HTTP POST.
 *
 * Auth is via a GCP API key restricted to the reCAPTCHA Enterprise API only
 * (create in GCP → APIs & Services → Credentials → Create API key).
 */
final readonly class ReCaptchaService
{
    private const string ENDPOINT_BASE = 'https://recaptchaenterprise.googleapis.com/v1/projects/';
    private const float SCORE_THRESHOLD = 0.5;
    private const string EXPECTED_ACTION = 'register';

    public function __construct(
        private string $siteKey,
        private string $projectId,
        private string $apiKey,
        private bool $isDev = false,
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        // Fail-closed in prod when any of the three required values is missing.
        // Dev bypass is intentional so local registration doesn't need a real
        // GCP project — fenced behind isDev so it cannot engage in prod.
        if ($this->siteKey === '' || $this->projectId === '' || $this->apiKey === '') {
            return $this->isDev;
        }

        if ($token === '') {
            return false;
        }

        $url = self::ENDPOINT_BASE
             . rawurlencode($this->projectId)
             . '/assessments?key=' . rawurlencode($this->apiKey);

        $event = [
            'token'          => $token,
            'siteKey'        => $this->siteKey,
            'expectedAction' => self::EXPECTED_ACTION,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $event['userIpAddress'] = $remoteIp;
        }

        $body = json_encode(['event' => $event], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $body,
                'timeout'       => 3,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return false;
        }

        // Enterprise response shape:
        //   tokenProperties.valid   bool   — token decoded and not expired
        //   tokenProperties.action  string — matches what the JS called execute() with
        //   riskAnalysis.score      float  — 0.0 (bot) to 1.0 (human)
        $valid  = $payload['tokenProperties']['valid'] ?? false;
        $action = $payload['tokenProperties']['action'] ?? '';
        $score  = $payload['riskAnalysis']['score'] ?? 0.0;

        if ($valid !== true) {
            return false;
        }
        if ($action !== self::EXPECTED_ACTION) {
            return false;
        }
        return (float) $score >= self::SCORE_THRESHOLD;
    }
}

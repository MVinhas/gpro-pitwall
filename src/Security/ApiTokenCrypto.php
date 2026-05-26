<?php

declare(strict_types=1);

namespace App\Security;

final readonly class ApiTokenCrypto
{
    private string $key;

    public function __construct(string $appSecret)
    {
        // Domain-separated key derivation so a leak of one ciphertext family
        // (emails vs api_tokens) cannot help decrypt the other.
        $this->key = hash('sha256', $appSecret . ':api_token', true);
    }

    public function encrypt(string $token): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt(
            $token,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $data = base64_decode($payload, true);

        if ($data === false || strlen($data) < 28) {
            throw new \RuntimeException('Invalid encrypted payload');
        }

        $iv  = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ct  = substr($data, 28);

        $plain = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plain;
    }

    /**
     * Heuristic: distinguish encrypted ciphertext (this class's output)
     * from a raw plaintext token still sitting in the DB pre-migration.
     *
     * A valid ciphertext is base64 of at least 28 bytes (12 IV + 16 tag + ≥0 ct).
     * GPRO API tokens are JWTs — they start with "eyJ" and contain dots,
     * which never satisfies the ciphertext shape after base64 decode.
     */
    public function looksEncrypted(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        $data = base64_decode($candidate, true);
        if ($data === false || strlen($data) < 28) {
            return false;
        }

        // Could still be a coincidence — try a trial decryption.
        try {
            $this->decrypt($candidate);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}

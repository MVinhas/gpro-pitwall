<?php

declare(strict_types=1);

namespace App\Security;

final readonly class EmailCrypto
{
    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = hash('sha256', $appSecret, true);
    }

    public function encrypt(string $email): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt(
            $email,
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


    public function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower($email), $this->key);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiTokenCrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiTokenCrypto::class)]
final class ApiTokenCryptoTest extends TestCase
{
    private const APP_SECRET = 'test-app-secret-do-not-use-in-prod';
    // Plain string fixture — encryption is byte-agnostic, so we don't need a
    // realistic JWT here. Using a JWT shape would trip the repo's secret
    // scanner (bin/check_no_secrets.sh), which flags eyJ.eyJ.* patterns.
    private const SAMPLE_TOKEN   = 'sample-api-token-not-a-real-jwt-12345';

    private ApiTokenCrypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new ApiTokenCrypto(self::APP_SECRET);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $ciphertext = $this->crypto->encrypt(self::SAMPLE_TOKEN);

        $this->assertNotSame(self::SAMPLE_TOKEN, $ciphertext);
        $this->assertSame(self::SAMPLE_TOKEN, $this->crypto->decrypt($ciphertext));
    }

    public function testEachEncryptionProducesADifferentCiphertext(): void
    {
        $a = $this->crypto->encrypt(self::SAMPLE_TOKEN);
        $b = $this->crypto->encrypt(self::SAMPLE_TOKEN);

        $this->assertNotSame($a, $b, 'IV must be random — identical plaintext must not yield identical ciphertext');
        $this->assertSame(self::SAMPLE_TOKEN, $this->crypto->decrypt($a));
        $this->assertSame(self::SAMPLE_TOKEN, $this->crypto->decrypt($b));
    }

    public function testDecryptionFailsForTamperedCiphertext(): void
    {
        $ciphertext = $this->crypto->encrypt(self::SAMPLE_TOKEN);
        $raw = base64_decode($ciphertext, true);
        self::assertNotFalse($raw);

        // Flip a bit in the ciphertext body (after the 12-byte IV + 16-byte tag).
        $raw[28] = chr(ord($raw[28]) ^ 0x01);
        $tampered = base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        $this->crypto->decrypt($tampered);
    }

    public function testDecryptionFailsForGarbagePayload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->crypto->decrypt('not-real-ciphertext');
    }

    public function testDifferentSecretCannotDecrypt(): void
    {
        $ciphertext = $this->crypto->encrypt(self::SAMPLE_TOKEN);

        $foreign = new ApiTokenCrypto('different-app-secret');
        $this->expectException(\RuntimeException::class);
        $foreign->decrypt($ciphertext);
    }

    public function testLooksEncryptedRecognisesOwnCiphertext(): void
    {
        $ciphertext = $this->crypto->encrypt(self::SAMPLE_TOKEN);
        $this->assertTrue($this->crypto->looksEncrypted($ciphertext));
    }

    public function testLooksEncryptedRejectsPlaintextToken(): void
    {
        // The seeder relies on this — a legacy plaintext token must NOT be misread as
        // ciphertext, otherwise encryptLegacyApiTokens() would skip it and leave
        // plaintext in the DB.
        $this->assertFalse($this->crypto->looksEncrypted(self::SAMPLE_TOKEN));
    }

    public function testLooksEncryptedRejectsEmptyString(): void
    {
        $this->assertFalse($this->crypto->looksEncrypted(''));
    }

    public function testLooksEncryptedRejectsCiphertextFromADifferentKey(): void
    {
        $foreign = new ApiTokenCrypto('different-app-secret');
        $foreignCiphertext = $foreign->encrypt(self::SAMPLE_TOKEN);

        $this->assertFalse($this->crypto->looksEncrypted($foreignCiphertext));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\EmailCrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(EmailCrypto::class)]
final class EmailCryptoTest extends TestCase
{
    private const string SECRET = 'test-app-secret-not-a-real-key';

    private EmailCrypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new EmailCrypto(self::SECRET);
    }

    public function testEncryptThenDecryptRoundTrips(): void
    {
        $this->assertSame(
            'racer@example.test',
            $this->crypto->decrypt($this->crypto->encrypt('racer@example.test'))
        );
    }

    public function testTheCiphertextDoesNotContainThePlaintext(): void
    {
        $payload = $this->crypto->encrypt('racer@example.test');

        $this->assertStringNotContainsString('racer@example.test', $payload);
        $this->assertStringNotContainsString('racer', base64_decode($payload, true) ?: '');
    }

    /** A random IV per call means two encryptions of one address never match. */
    public function testEncryptionIsNonDeterministic(): void
    {
        $a = $this->crypto->encrypt('racer@example.test');
        $b = $this->crypto->encrypt('racer@example.test');

        $this->assertNotSame($a, $b);
        $this->assertSame($this->crypto->decrypt($a), $this->crypto->decrypt($b));
    }

    public function testEmptyStringRoundTrips(): void
    {
        $this->assertSame('', $this->crypto->decrypt($this->crypto->encrypt('')));
    }

    public function testUnicodeAddressesRoundTrip(): void
    {
        $address = 'piloto+tag@exemplo.tést';

        $this->assertSame($address, $this->crypto->decrypt($this->crypto->encrypt($address)));
    }

    public function testATamperedCiphertextIsRejected(): void
    {
        $payload = $this->crypto->encrypt('racer@example.test');
        $raw = base64_decode($payload, true);
        $this->assertIsString($raw);

        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        $this->crypto->decrypt(base64_encode($raw));
    }

    public function testATamperedAuthTagIsRejected(): void
    {
        $payload = $this->crypto->encrypt('racer@example.test');
        $raw = base64_decode($payload, true);
        $this->assertIsString($raw);

        $raw[14] = $raw[14] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        $this->crypto->decrypt(base64_encode($raw));
    }

    public function testATruncatedPayloadIsRejectedAsInvalid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid encrypted payload');

        $this->crypto->decrypt(base64_encode('too short'));
    }

    public function testNonBase64GarbageIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->crypto->decrypt('!!! not base64 !!!');
    }

    /** A payload from another deployment's key must never decrypt here. */
    public function testAPayloadFromADifferentSecretDoesNotDecrypt(): void
    {
        $foreign = (new EmailCrypto('a-completely-different-secret'))->encrypt('racer@example.test');

        $this->expectException(RuntimeException::class);
        $this->crypto->decrypt($foreign);
    }

    public function testHashIsDeterministicForTheSameAddress(): void
    {
        $this->assertSame(
            $this->crypto->hash('racer@example.test'),
            $this->crypto->hash('racer@example.test')
        );
    }

    /** Lookups must survive the casing a user happened to type at signup. */
    public function testHashIsCaseInsensitive(): void
    {
        $this->assertSame(
            $this->crypto->hash('racer@example.test'),
            $this->crypto->hash('RaCeR@ExAmPlE.TeSt')
        );
    }

    public function testDifferentAddressesHashDifferently(): void
    {
        $this->assertNotSame(
            $this->crypto->hash('a@example.test'),
            $this->crypto->hash('b@example.test')
        );
    }

    public function testHashIsKeyedSoADifferentSecretGivesADifferentDigest(): void
    {
        $other = new EmailCrypto('a-completely-different-secret');

        $this->assertNotSame(
            $this->crypto->hash('racer@example.test'),
            $other->hash('racer@example.test')
        );
    }

    public function testHashDoesNotLeakThePlaintext(): void
    {
        $digest = $this->crypto->hash('racer@example.test');

        $this->assertSame(64, strlen($digest));
        $this->assertStringNotContainsString('racer', $digest);
    }
}

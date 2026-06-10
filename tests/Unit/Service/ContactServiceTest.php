<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\UserRepository;
use App\Security\EmailCrypto;
use App\Service\ContactService;
use App\Service\EmailService;
use App\Service\RateLimiterService;
use App\Service\SecurityLogger;
use App\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactService::class)]
final class ContactServiceTest extends TestCase
{
    private EmailCrypto $crypto;
    private ArrayCache $cache;

    /** @var list<string> */
    private array $securityEvents;

    protected function setUp(): void
    {
        $this->crypto = new EmailCrypto('contact-test-secret-not-prod');
        $this->cache = new ArrayCache();
        $this->securityEvents = [];
    }

    private function service(EmailService $email, ?UserRepository $users = null): ContactService
    {
        if ($users === null) {
            $users = $this->createStub(UserRepository::class);
            $users->method('findEncryptedEmailById')
                  ->willReturn($this->crypto->encrypt('racer@example.com'));
        }

        return new ContactService(
            $email,
            new RateLimiterService($this->cache),
            $users,
            $this->crypto,
            new SecurityLogger(function (string $line): void {
                $this->securityEvents[] = $line;
            }),
        );
    }

    public function testRejectsSubjectOutsideTheWhitelist(): void
    {
        $email = $this->createMock(EmailService::class);
        $email->expects($this->never())->method('sendContactMessage');

        $result = $this->service($email)->submit(7, 'racer', 'Free money', 'hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('subject', (string) $result['error']);
    }

    public function testRejectsEmptyMessage(): void
    {
        $email = $this->createMock(EmailService::class);
        $email->expects($this->never())->method('sendContactMessage');

        $result = $this->service($email)->submit(7, 'racer', 'Bug Report', "   \n  ");

        $this->assertFalse($result['ok']);
    }

    public function testRejectsOverlongMessage(): void
    {
        $email = $this->createMock(EmailService::class);
        $email->expects($this->never())->method('sendContactMessage');

        $tooLong = str_repeat('a', ContactService::MAX_MESSAGE_LENGTH + 1);
        $result = $this->service($email)->submit(7, 'racer', 'Bug Report', $tooLong);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('too long', (string) $result['error']);
    }

    public function testSendsWithDecryptedReplyToAndWhitelistedSubject(): void
    {
        $email = $this->createMock(EmailService::class);
        $email->expects($this->once())
              ->method('sendContactMessage')
              ->with('racer@example.com', 'racer', 'Feature Request', 'Add dark mode please.')
              ->willReturn(true);

        $result = $this->service($email)->submit(7, 'racer', 'Feature Request', 'Add dark mode please.');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function testRateLimitBlocksAfterFiveMessagesPerHourAndLogsSecurityEvent(): void
    {
        $email = $this->createMock(EmailService::class);
        $email->expects($this->exactly(5))->method('sendContactMessage')->willReturn(true);

        $svc = $this->service($email);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($svc->submit(7, 'racer', 'Other', 'msg ' . $i)['ok']);
        }

        $blocked = $svc->submit(7, 'racer', 'Other', 'one too many');

        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('Too many messages', (string) $blocked['error']);
        $this->assertCount(1, $this->securityEvents);
        $this->assertStringContainsString('contact_rate_limited', $this->securityEvents[0]);
        $this->assertStringContainsString('user_id=7', $this->securityEvents[0]);
    }

    public function testValidationFailuresDoNotConsumeRateLimitQuota(): void
    {
        $email = $this->createStub(EmailService::class);
        $svc = $this->service($email);

        $svc->submit(7, 'racer', 'Nope', 'invalid subject');
        $svc->submit(7, 'racer', 'Other', '');

        $this->assertFalse($this->cache->has('contact:7'));
    }

    public function testFailedSendReportsAnError(): void
    {
        $email = $this->createStub(EmailService::class);
        $email->method('sendContactMessage')->willReturn(false);

        $result = $this->service($email)->submit(7, 'racer', 'Other', 'hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Sending failed', (string) $result['error']);
    }

    public function testMissingAccountEmailReportsAnError(): void
    {
        $email = $this->createMock(EmailService::class);
        $email->expects($this->never())->method('sendContactMessage');

        $users = $this->createStub(UserRepository::class);
        $users->method('findEncryptedEmailById')->willReturn(null);

        $result = $this->service($email, $users)->submit(7, 'racer', 'Other', 'hello');

        $this->assertFalse($result['ok']);
    }
}

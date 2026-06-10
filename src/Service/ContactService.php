<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use App\Security\EmailCrypto;

/**
 * In-app contact form (logged-in users only). Validates input, rate-limits
 * per user, resolves the sender's decrypted email for the Reply-To header,
 * and hands off to EmailService. No CAPTCHA on purpose: the form sits behind
 * authentication + CSRF, and the per-user rate limit caps abuse — a CAPTCHA
 * would only add friction for verified users.
 */
final class ContactService
{
    public const SUBJECTS = [
        'Feature Request',
        'Technical Issue',
        'General Question',
        'Bug Report',
        'Account / Data Issue',
        'Other',
    ];

    public const MAX_MESSAGE_LENGTH = 5000;
    private const MAX_PER_HOUR = 5;
    private const RATE_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly EmailService $email,
        private readonly RateLimiterService $rateLimiter,
        private readonly UserRepository $users,
        private readonly EmailCrypto $crypto,
        private readonly SecurityLogger $securityLog,
    ) {
    }

    /** @return array{ok: bool, error: string|null} */
    public function submit(int $userId, string $username, string $subject, string $message): array
    {
        if (!in_array($subject, self::SUBJECTS, true)) {
            return ['ok' => false, 'error' => 'Pick a subject from the list.'];
        }

        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'error' => 'The message cannot be empty.'];
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return [
                'ok' => false,
                'error' => 'The message is too long (max ' . self::MAX_MESSAGE_LENGTH . ' characters).',
            ];
        }

        $sentThisHour = $this->rateLimiter->increment('contact:' . $userId, self::RATE_WINDOW_SECONDS);
        if ($sentThisHour > self::MAX_PER_HOUR) {
            $this->securityLog->event('contact_rate_limited', ['user_id' => $userId]);
            return ['ok' => false, 'error' => 'Too many messages in the last hour. Please try again later.'];
        }

        $encryptedEmail = $this->users->findEncryptedEmailById($userId);
        if ($encryptedEmail === null) {
            return ['ok' => false, 'error' => 'Could not resolve your account email. Please try again later.'];
        }

        $sent = $this->email->sendContactMessage(
            $this->crypto->decrypt($encryptedEmail),
            $username,
            $subject,
            $message,
        );

        if (!$sent) {
            return [
                'ok' => false,
                'error' => 'Sending failed. Please try again later, or email admin@gpro-pitwall.com directly.',
            ];
        }

        return ['ok' => true, 'error' => null];
    }
}

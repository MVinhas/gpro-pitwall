<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Service\EmailService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * "The code doesn't work" is usually a mistyped username: the code went to
 * another account or nowhere. The login and verify pages point at the welcome
 * email, which is the one place the username is written down.
 */
#[CoversNothing]
final class UsernameTipTest extends TestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));

        // The page body only: layout.twig needs the app's Twig functions.
        return html_entity_decode(
            $twig->load($template)->renderBlock('content', $context + [
                'csrf_token'      => 'test',
                'welcome_subject' => EmailService::WELCOME_SUBJECT,
            ])
        );
    }

    public function testLoginPageSaysWhereTheUsernameIsWrittenDown(): void
    {
        $html = $this->render('auth/login.twig', []);

        self::assertStringContainsString(EmailService::WELCOME_SUBJECT, $html);
        self::assertStringContainsString('aria-describedby="login-username-help"', $html);
    }

    public function testVerifyPagePointsAtTheWelcomeEmailWhenLoggingIn(): void
    {
        $html = $this->render('auth/verify.twig', ['is_login' => true]);

        self::assertStringContainsString('Check the username', $html);
        self::assertStringContainsString(EmailService::WELCOME_SUBJECT, $html);
    }

    public function testVerifyPageSaysNothingAboutTheWelcomeEmailDuringSignUp(): void
    {
        $html = $this->render('auth/verify.twig', ['is_login' => false]);

        self::assertStringNotContainsString('Check the username', $html);
        self::assertStringNotContainsString(EmailService::WELCOME_SUBJECT, $html);
    }
}

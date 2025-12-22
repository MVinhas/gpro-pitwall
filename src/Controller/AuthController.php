<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Service\AuthService;
use Twig\Environment;

class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Environment $twig,
        private readonly string $recaptchaSiteKey
    ) {
    }

    public function showLogin(): void
    {
        $this->redirectIfLoggedIn();

        echo $this->twig->render('auth/login.twig', [
            'flash' => $_SESSION['flash'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);

        unset($_SESSION['flash']);
    }

    public function showRegister(): void
    {
        $this->redirectIfLoggedIn();

        echo $this->twig->render('auth/register.twig', [
            'flash' => $_SESSION['flash'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'recaptcha_site_key' => $this->recaptchaSiteKey,
        ]);

        unset($_SESSION['flash']);
    }

    public function showVerify(): void
    {
        $this->redirectIfLoggedIn();

        if (empty($_SESSION['auth_pending_user_id'])) {
            header('Location: /login');
            exit;
        }

        echo $this->twig->render('auth/verify.twig', [
            'flash' => $_SESSION['flash'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);

        unset($_SESSION['flash']);
    }

    public function handleLoginRequest(Request $request): void
    {
        $this->redirectIfLoggedIn();

        $username = trim((string) $request->post('username'));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $result = $this->auth->login($username, $ip);

        if (!$result['success']) {
            $_SESSION['flash'] = $result['error'];
            header('Location: /login');
            exit;
        }

        $_SESSION['auth_pending_user_id'] = $result['user_id'];
        $_SESSION['flash'] =
            'If the username exists, a code has been sent to the registered email.';

        header('Location: /verify');
        exit;
    }

    public function handleRegister(Request $request): void
    {
        $this->redirectIfLoggedIn();

        $username = trim((string) $request->post('username'));
        $email = trim((string) $request->post('email'));
        $token = (string) $request->post('g-recaptcha-response');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $result = $this->auth->register($username, $email, $token, $ip);

        if (!$result['success']) {
            $_SESSION['flash'] = $result['error'];
            header('Location: /register');
            exit;
        }

        $_SESSION['auth_pending_user_id'] = $result['user_id'];
        $_SESSION['flash'] =
            'Account created! Please check your email for the verification code.';

        header('Location: /verify');
        exit;
    }

    public function handleVerify(Request $request): void
    {
        $this->redirectIfLoggedIn();

        $code = trim((string) $request->post('code'));
        $pendingUserId = (int) ($_SESSION['auth_pending_user_id'] ?? 0);

        if ($pendingUserId === 0) {
            header('Location: /login');
            exit;
        }

        if ($this->auth->verifyCode($pendingUserId, $code)) {
            unset($_SESSION['auth_pending_user_id']);

            if (($_SESSION['sync_status'] ?? '') === 'needs_token') {
                $_SESSION['flash'] =
                    'To continue, please add your GPRO API token to sync your data.';
                header('Location: /control_panel');
                exit;
            }

            header('Location: /');
            exit;
        }

        $_SESSION['flash'] = 'Invalid code or expired.';
        header('Location: /verify');
        exit;
    }

    public function logout(): void
    {
        $this->auth->logout();
        unset($_SESSION['auth_pending_user_id']);

        header('Location: /');
        exit;
    }

    private function redirectIfLoggedIn(): void
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /');
            exit;
        }
    }
}

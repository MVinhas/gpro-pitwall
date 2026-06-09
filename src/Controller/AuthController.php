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
            'recaptcha_site_key' => $this->recaptchaSiteKey,
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
        $token = (string) $request->post('g-recaptcha-response');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $result = $this->auth->login($username, $token, $ip);

        if (!$result['success']) {
            $_SESSION['flash'] = $result['error'];
            header('Location: /login');
            exit;
        }

        $_SESSION['auth_pending_user_id'] = $result['user_id'];
        $_SESSION['auth_remember'] = $request->post('remember') === '1';
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

        $remember = ($_SESSION['auth_remember'] ?? false) === true;

        if ($this->auth->verifyCode($pendingUserId, $code, $remember)) {
            unset($_SESSION['auth_pending_user_id'], $_SESSION['auth_remember']);

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

    /**
     * Resend the verification code for an in-progress login/registration. The
     * target is the pending user id already in the session — never a value the
     * client supplies — so this exposes no enumeration or targeting surface.
     * The flash is generic and identical whether or not a send actually fired
     * (the per-account cap may suppress it).
     */
    public function resendCode(): void
    {
        $this->redirectIfLoggedIn();

        $pendingUserId = (int) ($_SESSION['auth_pending_user_id'] ?? 0);
        if ($pendingUserId === 0) {
            header('Location: /login');
            exit;
        }

        $this->auth->resendCode($pendingUserId);

        $_SESSION['flash'] =
            'If your account is awaiting a code, a new one has been sent. '
            . 'Check your inbox and spam folder.';
        header('Location: /verify');
        exit;
    }

    /**
     * Step-up re-authentication page. Reached when a remembered (not-fresh)
     * session tries a sensitive action. Sends a one-time code and renders the
     * reauth form. Anonymous users are bounced to login.
     */
    public function showReauth(): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId === 0) {
            header('Location: /login');
            exit;
        }

        // Already fresh — nothing to step up; go back where they intended.
        if (($_SESSION['auth_fresh'] ?? false) === true) {
            header('Location: ' . $this->safeReturnTo());
            exit;
        }

        $this->auth->sendReauthCode($userId);

        echo $this->twig->render('auth/reauth.twig', [
            'flash' => $_SESSION['flash'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);

        unset($_SESSION['flash']);
    }

    public function handleReauth(Request $request): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId === 0) {
            header('Location: /login');
            exit;
        }

        $code = trim((string) $request->post('code'));

        if ($this->auth->verifyReauth($userId, $code)) {
            $returnTo = $this->safeReturnTo();
            unset($_SESSION['reauth_return_to']);
            header('Location: ' . $returnTo);
            exit;
        }

        $_SESSION['flash'] = 'Invalid code or expired.';
        header('Location: /reauth');
        exit;
    }

    /**
     * Only ever return to a same-site absolute path, never an attacker-supplied
     * absolute URL (open-redirect guard).
     */
    private function safeReturnTo(): string
    {
        $target = (string) ($_SESSION['reauth_return_to'] ?? '/control_panel');
        if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//')) {
            return '/control_panel';
        }
        return $target;
    }

    public function logout(): void
    {
        $this->auth->logout();
        unset($_SESSION['auth_pending_user_id'], $_SESSION['auth_remember']);

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

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\AuthService;
use Twig\Environment;

class ControlPanelController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly Environment $twig,
        private readonly Authorize $authorize,
        private readonly AuthService $auth,
    ) {
    }

    public function index(): void
    {
        $user = $this->authorize->requireAuth();

        // Never send the decrypted token to the browser. Show only the last 4
        // characters as a recognition hint; the field stays empty and only a
        // freshly typed value replaces it.
        $token = (string) ($user['api_token'] ?? '');
        $hasToken = $token !== '';
        unset($user['api_token']);

        echo $this->twig->render('auth/control_panel.twig', [
            'user' => $user,
            'is_logged_in' => true,
            'has_token' => $hasToken,
            'token_hint' => $hasToken ? substr($token, -4) : null,
            'flash' => $_SESSION['flash'] ?? null,
            'flash_error' => $_SESSION['flash_error'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? ''
        ]);
        unset($_SESSION['flash'], $_SESSION['flash_error']);
    }

    public function updateToken(Request $request): void
    {
        $user = $this->authorize->requireFreshAuth('/control_panel');

        $token = trim((string)$request->post('api_token'));

        // The field is never pre-filled with the existing token, so a blank
        // submit means "leave it as is" rather than "clear it" — don't error.
        if ($token === '' && !empty($user['api_token'])) {
            $_SESSION['flash'] = "API token unchanged.";
            header('Location: /control_panel');
            exit;
        }

        if (strlen($token) < 10) {
            $_SESSION['flash'] = "Token looks too short.";
            header('Location: /control_panel');
            exit;
        }

        $this->userRepo->updateApiToken((int)$user['id'], $token);

        $_SESSION['flash'] = "API Token saved successfully.";
        header('Location: /control_panel');
        exit;
    }

    /**
     * Self-service account deletion (soft delete).
     *
     * Security: the account acted on is ALWAYS the authenticated session
     * user's own id — never anything from the request body. The typed
     * username is only a confirmation: it must equal the session user's own
     * username, or we refuse. So a user cannot delete another account by
     * typing a different username (it won't match theirs), and even a match
     * still targets only their own id.
     */
    public function deleteAccount(Request $request): void
    {
        $user = $this->authorize->requireFreshAuth('/control_panel');

        $typed = trim((string) $request->post('confirm_username'));
        $ownUsername = (string) ($user['username'] ?? '');

        // Constant-time compare against the session user's OWN username only.
        if ($ownUsername === '' || !hash_equals($ownUsername, $typed)) {
            $_SESSION['flash_error'] = 'Confirmation failed: type your exact username to delete your account.';
            header('Location: /control_panel');
            exit;
        }

        $this->userRepo->softDelete((int) $user['id']);

        // Deleted accounts must not stay logged in (and can't log back in —
        // every lookup filters deleted_at IS NULL).
        $this->auth->logout();

        // logout() destroyed the session; start a fresh one so the
        // confirmation flash survives the redirect to the landing page.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = 'Your account has been deleted.';
        header('Location: /');
        exit;
    }
}

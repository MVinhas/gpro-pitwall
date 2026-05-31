<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;

/**
 * Single authorisation gate. Controllers call requireAuth() / requirePremium()
 * at the top of any handler that must not be reachable anonymously or by a
 * free-tier user. Failures short-circuit the request — they exit() after
 * writing a redirect or a 403 JSON body.
 *
 * The rules (mirrored in CLAUDE.md "Access tiers"):
 *   - Anonymous can hit public pages + auth forms only.
 *   - Free (registered, non-premium) can READ everything and can set their
 *     own API token + trigger their own sync + log out. No other mutations.
 *   - Premium can do everything except admin pages.
 *   - Admin (orthogonal flag) can do everything plus /admin/*.
 *
 * This service does NOT enforce CSRF (that already runs in public/index.php)
 * and does NOT decide which routes are mutating — controllers opt in by calling
 * requirePremium() at the top of the relevant handler.
 */
final readonly class Authorize
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function currentUserId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_numeric($id) ? (int)$id : null;
    }

    /** @return array<string, mixed> */
    public function requireAuth(): array
    {
        $id = $this->currentUserId();
        if ($id === null) {
            $this->redirect('/login');
        }

        $user = $this->users->findById((int)$id);
        if ($user === null) {
            // Session points at a deleted user — log it out cleanly.
            session_unset();
            session_destroy();
            $this->redirect('/login');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public function requirePremium(): array
    {
        $user = $this->requireAuth();
        if (self::hasPremiumAccess($user)) {
            return $user;
        }

        $this->denyPremium();
    }

    /** @return array<string, mixed> */
    public function requireAdmin(): array
    {
        $user = $this->requireAuth();
        if (self::hasAdminAccess($user)) {
            return $user;
        }

        $this->forbid();
    }

    /**
     * Premium tier OR admin (admins inherit premium capabilities).
     *
     * @param array<string, mixed> $user
     */
    public static function hasPremiumAccess(array $user): bool
    {
        return (int)($user['is_premium'] ?? 0) === 1
            || (int)($user['is_admin'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function hasAdminAccess(array $user): bool
    {
        return (int)($user['is_admin'] ?? 0) === 1;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    private function denyPremium(): never
    {
        if ($this->isAjax()) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['error' => 'premium_required']);
            exit;
        }

        $_SESSION['flash_error'] = 'Esta acção exige Premium.';
        header('Location: /control_panel?upsell=1', true, 303);
        exit;
    }

    private function forbid(): never
    {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }

    private function isAjax(): bool
    {
        $header = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower($header) === 'xmlhttprequest';
    }
}

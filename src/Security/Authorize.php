<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\HttpException;
use App\Repository\UserRepository;
use App\Support\RequestContext;

/**
 * Single authorisation gate. Controllers call requireAuth() / requireAdmin()
 * at the top of any handler that must not be reachable anonymously. Failures
 * short-circuit the request — they exit() on a redirect, or throw HttpException
 * (caught by the front controller, which renders a styled error page) on a 403.
 *
 * The rules (mirrored in CLAUDE.md "Access tiers"):
 *   - Anonymous can hit public pages + auth forms only.
 *   - Logged in (any user with an API token) can use every player-facing tab.
 *   - Admin (orthogonal flag) can additionally hit /admin/* and /debug.
 *
 * This service does NOT enforce CSRF (that already runs in public/index.php).
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
            $this->denyUnauthenticated();
        }

        $user = $this->users->findById((int)$id);
        if ($user === null) {
            // Session points at a deleted user — log it out cleanly.
            session_unset();
            session_destroy();
            $this->denyUnauthenticated();
        }

        return $user;
    }

    /**
     * Like requireAuth(), but additionally demands a *freshly authenticated*
     * session — i.e. a code was entered this session, not a silent restore from
     * a "remember me" token. Used to gate sensitive actions (account deletion,
     * API-token change). When the session isn't fresh, redirect into the
     * step-up flow, preserving where to return afterwards.
     *
     * @return array<string, mixed>
     */
    public function requireFreshAuth(string $returnTo): array
    {
        $user = $this->requireAuth();

        if (($_SESSION['auth_fresh'] ?? false) !== true) {
            $_SESSION['reauth_return_to'] = $returnTo;
            $this->redirect('/reauth');
        }

        return $user;
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
     * @param array<string, mixed> $user
     */
    public static function hasAdminAccess(array $user): bool
    {
        return (int)($user['is_admin'] ?? 0) === 1;
    }

    /**
     * Non-throwing admin check for endpoints that must stay reachable
     * anonymously (e.g. /healthz) but should show extra detail to admins only.
     */
    public function isAdmin(): bool
    {
        $id = $this->currentUserId();
        if ($id === null) {
            return false;
        }

        $user = $this->users->findById($id);
        return $user !== null && self::hasAdminAccess($user);
    }

    /**
     * No valid session. AJAX/fetch callers get a small JSON 401 they can act on
     * (redirect to login); browsers get the usual 302 to the login page. The
     * JSON body carries only a status string — never any internal detail.
     */
    private function denyUnauthenticated(): never
    {
        if (RequestContext::wantsJson($_SERVER)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'status' => 'unauthenticated']);
            exit;
        }

        $this->redirect('/login?expired=1');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    private function forbid(): never
    {
        throw new HttpException(403);
    }
}

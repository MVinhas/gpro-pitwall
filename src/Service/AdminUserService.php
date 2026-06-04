<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use RuntimeException;

/**
 * Admin-only user management. Wraps UserRepository mutations with
 * authorisation guards (self-demotion prevention) and writes every
 * change to the audit log.
 */
final class AdminUserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * Toggle admin flag on a target user. Refuses to clear the actor's
     * own flag — leaves an escape hatch out of an empty-admin state.
     */
    public function toggleAdmin(int $actorId, int $targetId): void
    {
        if ($actorId === $targetId) {
            throw new RuntimeException('You cannot change your own admin flag.');
        }

        $target = $this->users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }

        $current = (int) ($target['is_admin'] ?? 0) === 1;
        $next    = !$current;

        $this->users->updateAdmin($targetId, $next);
        $this->audit->record($actorId, 'toggle_admin', $targetId, [
            'from' => $current,
            'to'   => $next,
        ]);
    }

    /**
     * Soft-delete a user: marks deleted_at, hides them from the list,
     * leaves the row in place so the audit log stays joinable.
     */
    public function softDelete(int $actorId, int $targetId): void
    {
        if ($actorId === $targetId) {
            throw new RuntimeException('You cannot delete your own account from here.');
        }

        $target = $this->users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }

        $this->users->softDelete($targetId);
        $this->audit->record($actorId, 'soft_delete', $targetId, [
            'username' => $target['username'] ?? null,
        ]);
    }

    /**
     * Restore a soft-deleted user: clears deleted_at so they can log in
     * again. Looks the target up including deleted rows (a normal lookup
     * can't see them).
     */
    public function restore(int $actorId, int $targetId): void
    {
        $target = $this->users->findByIdIncludingDeleted($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }
        if (empty($target['deleted_at'])) {
            throw new RuntimeException('User is not deleted.');
        }

        $this->users->restore($targetId);
        $this->audit->record($actorId, 'restore', $targetId, [
            'username' => $target['username'] ?? null,
        ]);
    }

    /**
     * Resends the email verification code for a user who hasn't completed
     * registration yet. Delegates to AuthService::login() — same flow as
     * the public login form, so the existing rate limit and code TTL
     * apply transparently.
     */
    public function resendVerification(int $actorId, int $targetId, string $ip): void
    {
        $target = $this->users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }

        $username = (string) ($target['username'] ?? '');
        if ($username === '') {
            throw new RuntimeException('User has no username.');
        }

        $this->auth->login($username, $ip);
        $this->audit->record($actorId, 'resend_verification', $targetId);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(int $page, int $perPage): array
    {
        return $this->users->paginate($page, $perPage);
    }

    /** @return list<array<string, mixed>> */
    public function recentAudit(int $limit = 50): array
    {
        return $this->audit->recent($limit);
    }
}

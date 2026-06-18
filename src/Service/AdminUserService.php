<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuditLogRepository;
use App\Repository\PendingRegistrationRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Admin-only user management. Wraps UserRepository mutations with
 * authorisation guards (self-demotion prevention) and writes every
 * change to the audit log.
 */
final class AdminUserService
{
    /** Window lengths (days) the dashboard trend can be viewed over. */
    public const array TREND_WINDOWS = [7, 30, 90];

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly AuthService $auth,
        private readonly PendingRegistrationRepository $pending,
    ) {
    }

    /**
     * Dashboard summary: headline counts plus period-over-period trends so the
     * admin can see at a glance whether the app is growing or fading. Each trend
     * compares the last $windowDays against the equal-length window before it.
     *
     * @return array{
     *     window_days: int,
     *     total: int,
     *     with_token: int,
     *     pending: int,
     *     signups: array{current:int,previous:int,delta:int,pct:?int,direction:string},
     *     active: array{current:int,previous:int,delta:int,pct:?int,direction:string}
     * }
     */
    public function stats(int $windowDays = 30): array
    {
        if (!in_array($windowDays, self::TREND_WINDOWS, true)) {
            $windowDays = 30;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return [
            'window_days' => $windowDays,
            'total'       => $this->users->countLive(),
            'with_token'  => $this->users->countWithApiToken(),
            'pending'     => $this->pending->countActive($now),
            'signups'     => $this->trend(
                $this->users->countCreatedSince($windowDays),
                $this->users->countCreatedBetween($windowDays * 2, $windowDays),
            ),
            'active'      => $this->trend(
                $this->users->countActiveSince($windowDays),
                $this->users->countActiveBetween($windowDays * 2, $windowDays),
            ),
        ];
    }

    /**
     * Build a period-over-period comparison. `pct` is null when the prior window
     * was empty (no meaningful baseline — the view renders "new" instead of an
     * infinite percentage). `direction` is up/down/flat so colour is never the
     * only signal.
     *
     * @return array{current:int,previous:int,delta:int,pct:?int,direction:string}
     */
    private function trend(int $current, int $previous): array
    {
        $delta = $current - $previous;

        return [
            'current'   => $current,
            'previous'  => $previous,
            'delta'     => $delta,
            'pct'       => $previous > 0 ? (int) round(($delta / $previous) * 100) : null,
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
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
     * Sends a fresh login code to an existing user. (Since registration moved to
     * the pending_registrations table, every `users` row is already verified, so
     * this is effectively an admin-triggered login code rather than a
     * registration resend.) Delegates to AuthService::resendCode(), which skips
     * the public captcha gate (the admin is already authorised) but still honours
     * the per-account code cap and TTL.
     */
    public function resendVerification(int $actorId, int $targetId, string $ip): void
    {
        $target = $this->users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }

        $this->auth->resendCode($targetId);
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

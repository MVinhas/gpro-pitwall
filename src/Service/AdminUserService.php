<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuditLogRepository;
use App\Repository\PendingRegistrationRepository;
use App\Repository\UserRepository;
use App\Support\UsernameRule;
use DateTimeImmutable;
use PDOException;
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
     * Rename a user, applying the same whitelist registration enforces.
     *
     * This is the supported repair for accounts created before that whitelist
     * existed. The login lookup is a byte-exact, case-sensitive match, so a
     * username holding an accent, a space or mixed case is unreachable to
     * anyone who misremembers its exact spelling — and the enumeration decoy
     * makes that failure look identical to a successful send. Renaming to a
     * conforming username is the only fix that restores access.
     *
     * The user must be told their new username out of band: it is their sole
     * login credential, and nothing here emails them.
     */
    public function rename(int $actorId, int $targetId, string $newUsername): void
    {
        $newUsername = trim($newUsername);

        $error = UsernameRule::check($newUsername);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $target = $this->users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }

        $current = (string) ($target['username'] ?? '');
        if ($current === $newUsername) {
            throw new RuntimeException('That is already the username.');
        }

        $holder = $this->users->findByUsername($newUsername);
        if ($holder !== null) {
            throw new RuntimeException('Username is already taken.');
        }

        try {
            $this->users->rename($targetId, $newUsername);
        } catch (PDOException) {
            // The UNIQUE index is the authority; the check above is only a
            // friendlier path to the same answer. It misses two cases this
            // catches: a soft-deleted row still holding the name (findByUsername
            // filters those out) and a concurrent registration winning the race.
            throw new RuntimeException('Username is already taken.');
        }

        $this->audit->record($actorId, 'rename_user', $targetId, [
            'from' => $current,
            'to'   => $newUsername,
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
     * Emails a user their own username and last sync time.
     *
     * This replaced an admin-triggered login code, which could not work: a code
     * is only redeemable against auth_pending_user_id, and that session key is
     * set solely by the user's own login POST — so redeeming it required first
     * doing the thing the user was stuck on, and their login would mint a newer
     * code anyway. Telling them what to type is the support action that helps;
     * sending a code was never one.
     */
    public function sendUsernameReminder(int $actorId, int $targetId): void
    {
        $target = $this->users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.');
        }

        if (!$this->auth->sendUsernameReminder($targetId)) {
            throw new RuntimeException('Could not send the reminder — check the mail log.');
        }

        $this->audit->record($actorId, 'username_reminder', $targetId);
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

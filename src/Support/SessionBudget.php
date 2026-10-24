<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lets a background request release the session lock for the length of its
 * work, then save the remaining-calls figure that work produced.
 *
 * PHP holds the session file lock until the request ends; releasing it keeps
 * the manager's next navigation from queueing behind a long request. The figure
 * the work writes then only reaches memory, so persist() reopens the session
 * just long enough to save it, so the status line and the next page show it.
 */
final class SessionBudget
{
    private function __construct(
        private readonly int $userId,
        private readonly bool $released,
        private readonly mixed $stamp,
    ) {
    }

    public static function release(int $userId): self
    {
        $released = session_status() === PHP_SESSION_ACTIVE && session_write_close();

        return new self($userId, $released, $_SESSION['api_limit_updated_at'] ?? null);
    }

    /**
     * The session may have changed hands meanwhile (logout, another login in
     * the same browser): then the figure belongs to nobody here and is dropped.
     */
    public function persist(): void
    {
        if (!$this->released || ($_SESSION['api_limit_updated_at'] ?? null) === $this->stamp) {
            return;
        }

        $limit = $_SESSION['api_limit'] ?? null;
        $updatedAt = $_SESSION['api_limit_updated_at'] ?? null;

        // The id is already known from the first start; re-sending its cookie
        // on every response would only churn the browser's cookie jar.
        if (!session_start(['use_cookies' => 0, 'use_only_cookies' => 1, 'cache_limiter' => ''])) {
            return;
        }
        $owner = $_SESSION['user_id'] ?? null;
        if (!is_scalar($owner) || (int) $owner !== $this->userId) {
            session_abort();
            return;
        }
        $_SESSION['api_limit'] = $limit;
        $_SESSION['api_limit_updated_at'] = $updatedAt;
        session_write_close();
    }
}

<?php

declare(strict_types=1);

namespace App\Archive;

final class ArchiveSanitizer
{
    /**
     * Account, contact and social data, plus apiRequestsRemaining: that one is
     * volatile request bookkeeping, not game data, so it would only add noise.
     */
    private const array DROP_KEYS = [
        'email',
        'emailNeedsUpdate',
        'emailConfirmed',
        'picPath',
        'friends',
        'muted',
        'teamForumUnreadTopics',
        'forumUnreadCount',
        'unreadAnnouncements',
        'warnStatus',
        'accViolationStatus',
        'statusTxt',
        'accStatus',
        'accStatusId',
        'activationPixelFired',
        'apiRequestsRemaining',
    ];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->clean($payload);
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function clean(array $node): array
    {
        $clean = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::DROP_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->clean($value) : $value;
        }

        return $clean;
    }
}

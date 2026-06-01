<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Append-only ledger of admin mutations. Every state-changing operation
 * on the admin user screen records one row here so a future "who did
 * what" question has a deterministic answer.
 */
class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(int $actorId, string $action, ?int $targetUserId, array $metadata = []): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_log (actor_id, action, target_user_id, metadata_json)
             VALUES (:actor, :action, :target, :meta)"
        );
        $stmt->execute([
            'actor'  => $actorId,
            'action' => $action,
            'target' => $targetUserId,
            'meta'   => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Most recent entries first. Capped per request — the screen is a
     * debugging aid, not a search tool.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, actor_id, action, target_user_id, metadata_json, created_at
             FROM audit_log
             ORDER BY id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}

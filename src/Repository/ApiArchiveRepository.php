<?php

declare(strict_types=1);

namespace App\Repository;

use App\Archive\ArchiveSanitizer;
use PDO;

/**
 * Per-user archive of raw GPRO API responses, gzip-compressed JSON.
 *
 * Reads are always scoped to the supplied user_id. The read-through lookups
 * (getRow/get/has) also match the token scope, so a swapped token never reads
 * the previous account's rows; the history readers (latest/paramsFor) span
 * scopes. A row whose blob cannot be decoded reads as absent so a corrupt
 * archive entry never breaks a page.
 */
final class ApiArchiveRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ArchiveSanitizer $sanitizer,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function put(
        int $userId,
        string $tokenScope,
        string $endpoint,
        string $params,
        string $window,
        ?int $season,
        ?int $race,
        array $payload,
    ): void {
        $blob = gzencode(json_encode($this->sanitizer->sanitize($payload), JSON_THROW_ON_ERROR));

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_api_archive
                (user_id, token_scope, endpoint, params, race_window, season, race, fetched_at, payload_gz)
             VALUES
                (:user_id, :scope, :endpoint, :params, :window, :season, :race, :fetched_at, :payload)
             ON CONFLICT(user_id, token_scope, endpoint, params, race_window) DO UPDATE SET
                season = excluded.season,
                race = excluded.race,
                fetched_at = excluded.fetched_at,
                payload_gz = excluded.payload_gz'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':scope', $tokenScope);
        $stmt->bindValue(':endpoint', $endpoint);
        $stmt->bindValue(':params', $params);
        $stmt->bindValue(':window', $window);
        $stmt->bindValue(':season', $season, $season === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':race', $race, $race === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':fetched_at', gmdate('Y-m-d H:i:s'));
        $stmt->bindValue(':payload', $blob, PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, string $tokenScope, string $endpoint, string $params, string $window): ?array
    {
        return $this->getRow($userId, $tokenScope, $endpoint, $params, $window)['payload'] ?? null;
    }

    /**
     * The read-through row with its UTC fetched_at, so a caller can tell how
     * much of a cache TTL it has left.
     *
     * @return array{payload: array<string, mixed>, fetched_at: string, season: ?int, race: ?int}|null
     */
    public function getRow(int $userId, string $tokenScope, string $endpoint, string $params, string $window): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload_gz, fetched_at, season, race FROM user_api_archive
             WHERE user_id = :user_id AND token_scope = :scope AND endpoint = :endpoint
               AND params = :params AND race_window = :window'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':scope' => $tokenScope,
            ':endpoint' => $endpoint,
            ':params' => $params,
            ':window' => $window,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $payload = $this->decode($row['payload_gz'] ?? null);
        if ($payload === null) {
            return null;
        }

        return [
            'payload' => $payload,
            'fetched_at' => (string) $row['fetched_at'],
            'season' => $row['season'] === null ? null : (int) $row['season'],
            'race' => $row['race'] === null ? null : (int) $row['race'],
        ];
    }

    /**
     * Newest row for the endpoint with exactly these params; the default ''
     * matches only unparameterised rows, never parameterised (e.g. backfilled) ones.
     *
     * @return array<string, mixed>|null
     */
    public function latest(int $userId, string $endpoint, string $params = ''): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload_gz FROM user_api_archive
             WHERE user_id = :user_id AND endpoint = :endpoint AND params = :params
             ORDER BY fetched_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId, ':endpoint' => $endpoint, ':params' => $params]);

        return $this->decode($stmt->fetchColumn());
    }

    public function has(int $userId, string $tokenScope, string $endpoint, string $params, string $window): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_api_archive
             WHERE user_id = :user_id AND token_scope = :scope AND endpoint = :endpoint
               AND params = :params AND race_window = :window'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':scope' => $tokenScope,
            ':endpoint' => $endpoint,
            ':params' => $params,
            ':window' => $window,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<string>
     */
    public function paramsFor(int $userId, string $endpoint): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT params FROM user_api_archive
             WHERE user_id = :user_id AND endpoint = :endpoint'
        );
        $stmt->execute([':user_id' => $userId, ':endpoint' => $endpoint]);

        return array_values(array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $blob): ?array
    {
        if (!is_string($blob) || $blob === '') {
            return null;
        }
        $json = @gzdecode($blob);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }
}

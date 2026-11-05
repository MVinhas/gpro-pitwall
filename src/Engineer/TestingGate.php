<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;

final readonly class TestingGate
{
    public const string CLOSED = 'closed';
    public const string ROOKIE = 'rookie';
    public const string BLOCKED = 'blocked';
    public const string UNLOCK = 'unlock';
    public const string OPEN = 'open';

    /**
     * @param list<array{part: string, wear: int}> $blocking
     */
    public function __construct(
        public string $state,
        public array $blocking = [],
    ) {
    }

    /**
     * @param array<string, float|int> $startWear part label => start wear %
     * @param list<string> $replaced parts this race's car update replaces
     */
    public static function decide(
        bool $sessionOpen,
        ?string $division,
        string $objective,
        bool $carUpdated,
        array $startWear,
        array $replaced,
    ): self {
        if (!$sessionOpen) {
            return new self(self::CLOSED);
        }
        if ($division === 'Rookie') {
            return new self(self::ROOKIE);
        }

        $blocking = [];
        foreach ($startWear as $part => $wear) {
            $percent = WearAdvisorService::percent((float) $wear);
            if ($percent > 90 && !in_array($part, $replaced, true)) {
                $blocking[] = ['part' => $part, 'wear' => $percent];
            }
        }

        if ($blocking === []) {
            return new self(self::OPEN);
        }
        if ($objective === SeasonObjective::PROMOTE && !$carUpdated) {
            return new self(self::UNLOCK, $blocking);
        }

        return new self(self::BLOCKED, $blocking);
    }

    public function blocked(): self
    {
        return new self(self::BLOCKED, $this->blocking);
    }

    public function runs(): bool
    {
        return $this->state === self::OPEN || $this->state === self::UNLOCK;
    }
}

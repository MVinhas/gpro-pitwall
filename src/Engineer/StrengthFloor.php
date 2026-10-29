<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type CarStanding from GroupStanding
 */
final readonly class StrengthFloor
{
    public function __construct(
        private ?int $rank,
        private ?int $of,
        private ?int $relegationPos,
    ) {
    }

    /**
     * @param CarStanding|null $carStanding
     */
    public static function fromGroup(?array $carStanding, ?int $relegationPos): self
    {
        return new self($carStanding['rank'] ?? null, $carStanding['of'] ?? null, $relegationPos);
    }

    public function topThree(): bool
    {
        return $this->rank !== null && $this->rank <= 3;
    }

    public function keepsTopHalf(): bool
    {
        if ($this->rank === null || $this->of === null) {
            return false;
        }

        return $this->rank <= intdiv($this->of, 2) - $this->buffer();
    }

    public function keepsAboveRelegation(): bool
    {
        if ($this->rank === null || $this->of === null || $this->relegationPos === null) {
            return false;
        }

        return $this->rank <= $this->relegationPos - 1 - $this->buffer();
    }

    private function buffer(): int
    {
        return (int) ceil(($this->of ?? 0) / 10);
    }
}

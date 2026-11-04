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
    ) {
    }

    /**
     * @param CarStanding|null $carStanding
     */
    public static function fromGroup(?array $carStanding): self
    {
        return new self($carStanding['rank'] ?? null, $carStanding['of'] ?? null);
    }

    public function topThree(): bool
    {
        return $this->rank !== null && $this->rank <= 3;
    }

    public function keepsTopQuarter(): bool
    {
        if ($this->rank === null || $this->of === null) {
            return false;
        }

        return $this->rank + $this->buffer() <= intdiv($this->of, 4);
    }

    public function keepsOutOfBottomQuarter(): bool
    {
        if ($this->rank === null || $this->of === null) {
            return false;
        }

        return $this->rank + $this->buffer() <= intdiv(3 * $this->of, 4);
    }

    private function buffer(): int
    {
        return (int) ceil(($this->of ?? 0) / 10);
    }
}

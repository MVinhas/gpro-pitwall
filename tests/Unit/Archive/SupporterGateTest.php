<?php

declare(strict_types=1);

namespace App\Tests\Unit\Archive;

use App\Archive\SupporterGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupporterGate::class)]
final class SupporterGateTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>, bool}> */
    public static function payloads(): array
    {
        return [
            'non-supporter answer' => ['RaceAnalysis', ['errorMsg' => 'Supporters only', 'data' => []], true],
            'non-supporter on another endpoint' => ['Testing', ['errorMsg' => 'Supporters only', 'data' => []], true],
            'error message with data is not NonSup' => ['Testing', ['errorMsg' => 'x', 'data' => [1]], false],
            'empty error message is not NonSup' => ['Testing', ['errorMsg' => '', 'data' => []], false],
            'archived non-supporter marker' => ['SFHistory', ['nonSupporter' => true], true],
            'analysis without laps is NA' => ['RaceAnalysis', ['selSeasonNb' => 1], true],
            'analysis with laps is available' => ['RaceAnalysis', ['laps' => [['idx' => 0]]], false],
            'other endpoint without laps is available' => ['UpdateCar', ['kind' => 'car'], false],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloads')]
    public function testIsUnavailable(string $endpoint, array $payload, bool $expected): void
    {
        $this->assertSame($expected, SupporterGate::isUnavailable($endpoint, $payload));
    }
}

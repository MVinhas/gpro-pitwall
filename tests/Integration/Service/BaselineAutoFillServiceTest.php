<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Repository\PilotRepository;
use App\Repository\RaceTelemetryRepository;
use App\Security\ApiTokenCrypto;
use App\Service\BaselineAutoFillService;
use App\Service\PilotCalculatorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaselineAutoFillService::class)]
final class BaselineAutoFillServiceTest extends TestCase
{
    private PDO $db;
    private RaceTelemetryRepository $corpus;
    private PilotRepository $pilots;
    private BaselineAutoFillService $service;

    /**
     * The fixture driver's raw OA under FACTORS is 56. The Rookie cap is set
     * below that on purpose, so the promotion path actually has to dwarf the
     * attributes rather than passing them straight through.
     */
    private const array CAPS = ['Rookie' => 45, 'Amateur' => 120, 'Pro' => 160];

    private const array FACTORS = [
        'concentration' => 0.1, 'talent' => 0.2, 'aggressiveness' => 0.05,
        'experience' => 0.1, 'technical_insight' => 0.1, 'stamina' => 0.05,
        'charisma' => 0.05, 'motivation' => 0.1,
    ];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            [
                'Concentration' => 'concentration', 'Talent' => 'talent',
                'Aggressiveness' => 'aggressiveness', 'Experience' => 'experience',
                'Technical Insight' => 'technical_insight', 'Stamina' => 'stamina',
                'Charisma' => 'charisma', 'Motivation' => 'motivation',
                'Weight (Kg)' => 'weight', 'Age' => 'age',
            ],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('autofill-test-secret'),
        ))->migrate();

        $this->corpus = new RaceTelemetryRepository($this->db);
        $this->pilots = new PilotRepository($this->db);
        $this->service = new BaselineAutoFillService(
            $this->corpus,
            $this->pilots,
            new PilotCalculatorService(self::FACTORS, self::CAPS),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function race(array $overrides = []): void
    {
        $this->corpus->insertIfNew(array_merge([
            'season' => 107, 'race' => 13, 'level' => 'Rookie',
            'group_label' => 'Rookie - 31', 'track_id' => 17, 'track_name' => 'Hockenheim',
            'final_pos' => 2, 'start_pos' => 3, 'points' => 18,
            'q1_pos' => 2, 'q2_pos' => 2, 'q1_time_ms' => 74839, 'q2_time_ms' => 76612,
            'positions_gained' => 1, 'dnf' => 0, 'laps_completed' => 67,
            'driver_id' => 3000, 'driver_oa' => 80,
            'driver_con' => 100, 'driver_tal' => 100, 'driver_agg' => 20,
            'driver_exp' => 50, 'driver_tei' => 50, 'driver_sta' => 40,
            'driver_cha' => 60, 'driver_mot' => 100, 'driver_rep' => 0,
            'driver_wei' => 72, 'driver_age' => 26,
            'has_td' => 0, 'was_wet' => 0,
        ], $overrides));
    }

    public function testPromotesADoublePodiumIntoItsOwnDivision(): void
    {
        $this->race();

        $this->assertSame(1, $this->service->run());

        $rookies = $this->pilots->getPilotsByDivision('Rookie');
        $this->assertCount(1, $rookies);
        $this->assertSame(26, (int) $rookies[0]['age']);
        $this->assertSame(72, (int) $rookies[0]['weight']);
    }

    public function testAppliesTheDivisionOaCapExactlyAsAManualEntryWould(): void
    {
        $this->race();
        $this->service->run();

        $promoted = $this->pilots->getPilotsByDivision('Rookie')[0];

        $calculator = new PilotCalculatorService(self::FACTORS, self::CAPS);
        $raw = [
            'concentration' => 100, 'talent' => 100, 'aggressiveness' => 20,
            'experience' => 50, 'technical_insight' => 50, 'stamina' => 40,
            'charisma' => 60, 'motivation' => 100, 'weight' => 72, 'age' => 26,
        ];

        // Raw OA is over the Rookie cap, so the stored row must NOT be the raw one.
        $this->assertGreaterThan(self::CAPS['Rookie'], $calculator->calculateOverall($raw));

        $expected = $calculator->adjustPilotStats($raw, 'Rookie');
        foreach (['concentration', 'talent', 'motivation', 'charisma'] as $key) {
            $this->assertSame(
                (int) $expected[$key],
                (int) $promoted[$key],
                "auto-filled {$key} must match the hand-entry path",
            );
        }

        $this->assertLessThanOrEqual(
            self::CAPS['Rookie'] + 0.001,
            $calculator->calculateOverall($promoted),
        );
    }

    public function testIsIdempotentAcrossRepeatedRuns(): void
    {
        $this->race();

        $this->assertSame(1, $this->service->run());
        $this->assertSame(0, $this->service->run());
        $this->assertSame(0, $this->service->run());
        $this->assertCount(1, $this->pilots->getPilotsByDivision('Rookie'));
    }

    public function testIgnoresRacesThatMissedEitherTopThree(): void
    {
        // Good qualifying, poor race.
        $this->race(['race' => 1, 'q2_pos' => 2, 'final_pos' => 11, 'driver_id' => 1]);
        // Poor qualifying, good race.
        $this->race(['race' => 2, 'q2_pos' => 14, 'final_pos' => 2, 'driver_id' => 2]);
        // Both, but retired.
        $this->race(['race' => 3, 'q2_pos' => 1, 'final_pos' => 1, 'dnf' => 1, 'driver_id' => 3]);

        $this->assertSame(0, $this->service->run());
    }

    public function testSkipsRowsCollectedBeforeDriverAgeWasStored(): void
    {
        // pilots.age is NOT NULL and an invented age would corrupt every
        // recruitment number that reads the baseline.
        $this->race(['driver_age' => null]);

        $this->assertSame(0, $this->service->run());
        $this->assertCount(0, $this->pilots->getPilotsByDivision('Rookie'));
    }

    public function testKeepsEachDivisionSeparate(): void
    {
        $this->race(['race' => 1, 'level' => 'Rookie', 'group_label' => 'Rookie - 1', 'driver_id' => 1]);
        $this->race(['race' => 2, 'level' => 'Amateur', 'group_label' => 'Amateur - 1', 'driver_id' => 2]);
        $this->race(['race' => 3, 'level' => 'Pro', 'group_label' => 'Pro - 1', 'driver_id' => 3]);

        $this->assertSame(3, $this->service->run());
        $this->assertCount(1, $this->pilots->getPilotsByDivision('Rookie'));
        $this->assertCount(1, $this->pilots->getPilotsByDivision('Amateur'));
        $this->assertCount(1, $this->pilots->getPilotsByDivision('Pro'));
    }

    public function testLeavesHandEnteredPilotsAlone(): void
    {
        $this->pilots->addPilot([
            'division' => 'Rookie', 'concentration' => 1, 'talent' => 1,
            'aggressiveness' => 1, 'experience' => 1, 'technical_insight' => 1,
            'stamina' => 1, 'charisma' => 1, 'motivation' => 1,
            'weight' => 70, 'age' => 30,
        ]);

        $this->race();
        $this->service->run();

        // The manual row survives alongside the promoted one; a NULL source is
        // not treated as a duplicate of another NULL source.
        $this->assertCount(2, $this->pilots->getPilotsByDivision('Rookie'));
    }

    public function testRespectsTheBatchLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->race(['race' => $i, 'driver_id' => 1000 + $i]);
        }

        $this->assertSame(2, $this->service->run(2));
        $this->assertSame(3, $this->service->run(10));
        $this->assertSame(0, $this->service->run(10));
    }
}

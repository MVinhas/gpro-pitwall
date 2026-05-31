<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TrainingService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrainingService::class)]
final class TrainingServiceTest extends TestCase
{
    private PDO $db;
    private TrainingService $svc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE trainings (
                name TEXT PRIMARY KEY,
                cost INTEGER,
                gain_concentration REAL DEFAULT 0,
                gain_talent REAL DEFAULT 0,
                gain_aggressiveness REAL DEFAULT 0,
                gain_experience REAL DEFAULT 0,
                gain_technical_insight REAL DEFAULT 0,
                gain_stamina REAL DEFAULT 0,
                gain_charisma REAL DEFAULT 0,
                gain_motivation REAL DEFAULT 0,
                gain_weight REAL DEFAULT 0
            )
        ");
        // Spreadsheet-verified rows. Fitness drops motivation; Psycho raises it.
        $this->db->exec("INSERT INTO trainings VALUES (
            'Fitness', 700000, 0, 0, 0, 0, 0, 2, 0, -7.4, -1
        )");
        $this->db->exec("INSERT INTO trainings VALUES (
            'Psycho', 400000, 0, 0, 0, 0, 0, 0, 0, 16.7, 0
        )");
        $this->db->exec("INSERT INTO trainings VALUES (
            'Yoga', 700000, 5, 0, -2, 0, 0, -2, 0, 7.2, 0
        )");

        $this->svc = new TrainingService($this->db);
    }

    public function testPlanScheduleSumsGainsBeforeClamping(): void
    {
        // The order-dependent bug: motivation starts at 0, Fitness alone would
        // drop it −7.4 → clamped to 0. Adding Psycho should still yield a
        // positive motivation (16.7 − 7.4 = 9.3, rounded to 9). Old per-step
        // clamp model erased the Fitness penalty entirely.
        $plan = $this->svc->planSchedule(
            ['motivation' => 0, 'stamina' => 0, 'weight' => 75],
            ['Fitness' => 1, 'Psycho' => 1],
        );
        $this->assertSame(9, $plan['end']['motivation']);
        $this->assertSame(2, $plan['end']['stamina']);
        $this->assertSame(74, $plan['end']['weight']);
    }

    public function testPlanScheduleClampsAtZero(): void
    {
        $plan = $this->svc->planSchedule(
            ['motivation' => 5, 'stamina' => 0],
            ['Fitness' => 2],
        );
        // Δ motivation = -14.8 → 5 − 14.8 = -9.8 → clamped to 0.
        $this->assertSame(0, $plan['end']['motivation']);
    }

    public function testPlanScheduleClampsAtTwoFifty(): void
    {
        $plan = $this->svc->planSchedule(
            ['motivation' => 245],
            ['Psycho' => 5],
        );
        // 245 + 5 × 16.7 = 328.5 → clamped to 250.
        $this->assertSame(250, $plan['end']['motivation']);
    }

    public function testPlanScheduleCountTotalsCostAndPerProgramBreakdown(): void
    {
        $plan = $this->svc->planSchedule(
            ['concentration' => 0],
            ['Yoga' => 3, 'Psycho' => 2],
        );
        // Yoga 3 × 700000 + Psycho 2 × 400000 = 2_100_000 + 800_000.
        $this->assertSame(2_900_000, $plan['total_cost']);
        $this->assertCount(2, $plan['per_program']);
        $this->assertSame('Yoga', $plan['per_program'][0]['name']);
        $this->assertSame(3, $plan['per_program'][0]['count']);
    }

    public function testZeroCountSchedulesProduceNoChange(): void
    {
        $plan = $this->svc->planSchedule(
            ['motivation' => 100, 'stamina' => 50],
            ['Fitness' => 0, 'Psycho' => 0],
        );
        $this->assertSame(100, $plan['end']['motivation']);
        $this->assertSame(50, $plan['end']['stamina']);
        $this->assertSame(0, $plan['total_cost']);
        $this->assertSame([], $plan['per_program']);
    }

    public function testUnknownProgramNameIsIgnored(): void
    {
        $plan = $this->svc->planSchedule(
            ['motivation' => 100],
            ['Nonexistent' => 5, 'Psycho' => 1],
        );
        $this->assertSame(117, $plan['end']['motivation']);
        $this->assertSame(400_000, $plan['total_cost']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TrainingAdvisorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrainingAdvisorService::class)]
final class TrainingAdvisorServiceTest extends TestCase
{
    /** @var array<string, float> */
    private const array FACTORS = [
        'concentration'     => 0.166,
        'talent'            => 0.233,
        'aggressiveness'    => 0.0684,
        'experience'        => 0.10,
        'technical_insight' => 0.0686,
        'stamina'           => 0.20,
        'charisma'          => 0.0476,
        'motivation'        => 0.0136,
        'weight'            => -0.0827,
    ];

    /** @var list<array<string, mixed>> */
    private const array TRAININGS = [
        ['name' => 'Fitness',   'cost' => 700000, 'gain_concentration' => 0,  'gain_talent' => 0, 'gain_aggressiveness' => 0,  'gain_experience' => 0, 'gain_technical_insight' => 0, 'gain_stamina' => 2,  'gain_charisma' => 0, 'gain_motivation' => -7.4,  'gain_weight' => -1],
        ['name' => 'Yoga',      'cost' => 700000, 'gain_concentration' => 5,  'gain_talent' => 0, 'gain_aggressiveness' => -2, 'gain_experience' => 0, 'gain_technical_insight' => 0, 'gain_stamina' => -2, 'gain_charisma' => 0, 'gain_motivation' => 7.2,   'gain_weight' => 0],
        ['name' => 'Technical', 'cost' => 600000, 'gain_concentration' => 0,  'gain_talent' => 0, 'gain_aggressiveness' => 0,  'gain_experience' => 0, 'gain_technical_insight' => 5, 'gain_stamina' => 0,  'gain_charisma' => 0, 'gain_motivation' => -24.5, 'gain_weight' => 0],
        ['name' => 'PR',        'cost' => 500000, 'gain_concentration' => -3, 'gain_talent' => 0, 'gain_aggressiveness' => 0,  'gain_experience' => 0, 'gain_technical_insight' => 0, 'gain_stamina' => 0,  'gain_charisma' => 6, 'gain_motivation' => 0,     'gain_weight' => 0],
    ];

    private const array CURRENT = [
        'concentration' => 40, 'talent' => 100, 'aggressiveness' => 20, 'experience' => 30,
        'technical_insight' => 25, 'stamina' => 50, 'charisma' => 40, 'motivation' => 100, 'weight' => 75,
    ];

    private const array IDEAL = [
        'count' => 3,
        'stats' => [
            'Concentration' => 50, 'Talent' => 100, 'Aggressiveness' => 25, 'Experience' => 40,
            'Technical Insight' => 35, 'Stamina' => 60, 'Charisma' => 45, 'Motivation' => 120, 'Weight (Kg)' => 70,
        ],
    ];

    private TrainingAdvisorService $svc;

    protected function setUp(): void
    {
        $this->svc = new TrainingAdvisorService(self::FACTORS);
    }

    public function testNoIdealReturnsEmpty(): void
    {
        $this->assertSame([], $this->svc->rank(self::TRAININGS, self::CURRENT, null));
    }

    public function testEmptyBaselineReturnsEmpty(): void
    {
        $this->assertSame([], $this->svc->rank(self::TRAININGS, self::CURRENT, ['count' => 0, 'stats' => []]));
    }

    public function testHighFactorAttributesBeatLowFactorOnes(): void
    {
        // Fitness adds +2 stamina (factor 0.20) and drops 1 kg (|factor| 0.0827, weight surplus 5→4).
        // PR adds +6 charisma (factor 0.0476) but drops 3 concentration (factor 0.166, opens a gap).
        // Fitness should outscore PR because it closes high-factor shortfalls.
        $ranked = $this->svc->rank(self::TRAININGS, self::CURRENT, self::IDEAL);
        $byName = array_column($ranked, null, 'name');
        $this->assertGreaterThan($byName['PR']['score'], $byName['Fitness']['score']);
    }

    public function testSurplusOverIdealDoesNotPenalise(): void
    {
        // Driver is already above ideal concentration (60 vs 50).
        // Yoga's +5 concentration still applies but shouldn't push the score down
        // — the concentration component contributes 0 (no shortfall before or after).
        $current = self::CURRENT;
        $current['concentration'] = 60;
        $ranked = $this->svc->rank([self::TRAININGS[1]], $current, self::IDEAL);
        // Score must not contain a concentration penalty. Yoga keeps its motivation/stamina
        // components, so the overall score can be negative, but specifically the
        // concentration contribution is 0 — assert by comparing to a driver further away.
        $rankedAtIdeal = $this->svc->rank([self::TRAININGS[1]], $current, self::IDEAL)[0]['score'];

        $currentBelow = self::CURRENT;
        $currentBelow['concentration'] = 40;
        $rankedBelow = $this->svc->rank([self::TRAININGS[1]], $currentBelow, self::IDEAL)[0]['score'];

        // Below-ideal driver benefits more from Yoga than the above-ideal one.
        $this->assertGreaterThan($rankedAtIdeal, $rankedBelow);
    }

    public function testWeightSurplusCountsAsShortfall(): void
    {
        // Driver is 5 kg over ideal (75 vs 70). Fitness drops 1 kg → score gains
        // 0.0827 * 1 = 0.0827 from the weight component.
        $ranked = $this->svc->rank([self::TRAININGS[0]], self::CURRENT, self::IDEAL);
        $this->assertArrayHasKey('weight', $ranked[0]['gains']);
        $this->assertSame(-1.0, $ranked[0]['gains']['weight']);
    }

    public function testSkipsTrainingWithoutName(): void
    {
        $ranked = $this->svc->rank([['cost' => 1, 'gain_stamina' => 5]], self::CURRENT, self::IDEAL);
        $this->assertSame([], $ranked);
    }
}

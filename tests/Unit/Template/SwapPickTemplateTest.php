<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class SwapPickTemplateTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function option(int $level, int $delta, string $kind, int $cost, bool $recommended = false): array
    {
        return [
            'level' => $level, 'delta' => $delta, 'kind' => $kind, 'cost' => $cost, 'is_free' => $kind === 'free_downgrade',
            'start' => 0.0, 'end' => 40.0, 'fit' => 90.0, 'fit_delta' => 0.0, 'tier' => 1, 'match' => 'none',
            'pha' => ['power' => 80.0, 'handling' => 80.0, 'acceleration' => 80.0],
            'pha_delta' => ['power' => 0.0, 'handling' => 0.0, 'acceleration' => 0.0],
            'recommended' => $recommended, 'recommend_reason' => $recommended ? 'season' : '',
            'rationale' => 'Because.', 'balance' => 'same',
        ];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private static function plan(array $options, bool $season, ?string $reason = null): array
    {
        return [
            'current' => [
                'level' => 5, 'start' => 95, 'end' => 104.0, 'fit' => 90.0, 'match' => 'none',
                'pha' => ['power' => 80.0, 'handling' => 80.0, 'acceleration' => 80.0],
            ],
            'options' => $options,
            'season' => $season,
            'season_reason' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function row(array $plan): string
    {
        return $this->twig()->render('partials/_cockpit_wear_row.twig', [
            'p' => ['part' => 'Brakes', 'level' => 5, 'start' => 95, 'end' => 104.0],
            'plan' => $plan,
            'badge_class' => 'bg-red-100 text-red-800',
            'badge_text' => 'Replace',
        ]);
    }

    public function testEachKindNamesItsMove(): void
    {
        $html = $this->row(self::plan([
            self::option(4, -1, 'free_downgrade', 0, true),
            self::option(7, 2, 'upgrade', 900_000),
            self::option(5, 0, 'sidegrade', 500_000),
            self::option(4, -1, 'downgrade', 300_000),
        ], true, 'Most PHA the season budget allows'));

        $this->assertStringContainsString('Free downgrade to L4', $html);
        $this->assertStringContainsString('Up to L7', $html);
        $this->assertStringContainsString('Same level L5', $html);
        $this->assertStringContainsString('Down to L4', $html);
    }

    public function testASeasonPickShowsItsReason(): void
    {
        $html = $this->row(self::plan(
            [self::option(5, 0, 'sidegrade', 500_000, true)],
            true,
            'Top-3 car: same level, no extra spend',
        ));

        $this->assertMatchesRegularExpression(
            '#<p id="[^"]+" class="t-caption[^"]*">\s*Top-3 car: same level, no extra spend\s*</p>#',
            $html,
        );
        $this->assertStringNotContainsString('Season plan unavailable', $html);
    }

    public function testWithoutASeasonTheCaptionSaysSo(): void
    {
        $html = $this->row(self::plan([self::option(5, 0, 'sidegrade', 500_000, true)], false));

        $this->assertStringContainsString('Season plan unavailable: ranked for this race', $html);
    }

    public function testAPickedSeasonlessPartExplainsWhyItWasPassedOver(): void
    {
        $html = $this->row(self::plan(
            [self::option(5, 0, 'sidegrade', 500_000, true)],
            false,
            'No season pick for this part: ranked for this race',
        ));

        $this->assertStringContainsString('No season pick for this part: ranked for this race', $html);
        $this->assertStringNotContainsString('Season plan unavailable', $html);
    }

    public function testTheCaptionDescribesTheRadiogroup(): void
    {
        $html = $this->row(self::plan([self::option(5, 0, 'sidegrade', 500_000, true)], false));

        $this->assertMatchesRegularExpression('#role="radiogroup"[^>]*aria-describedby="(swap-why-brakes)"#', $html);
        $this->assertMatchesRegularExpression('#<p id="swap-why-brakes" class="t-caption#', $html);
    }

    public function testAFreeDowngradeChipDropsTheRedundantFreeTag(): void
    {
        $free = self::option(4, -1, 'free_downgrade', 0, true);
        $spare = self::option(5, 0, 'sidegrade', 0);
        $spare['is_free'] = true;
        $html = $this->row(self::plan([$free, $spare], true, 'Most PHA the season budget allows'));

        $chips = array_slice(explode('<label', $html), 1);
        $this->assertCount(2, $chips);
        $this->assertStringContainsString('Free downgrade to L4', $chips[0]);
        $this->assertStringNotContainsString('plan-tag-free', $chips[0]);
        $this->assertStringContainsString('plan-tag-free', $chips[1]);
    }

    public function testTwoPaidPicksOverCashStillWarn(): void
    {
        $brakes = self::plan([self::option(6, 1, 'upgrade', 900_000, true)], true, 'Most PHA the season budget allows');
        $cooling = self::plan([self::option(6, 1, 'upgrade', 800_000, true)], true, 'Most PHA the season budget allows');
        $flagged = static fn (string $part): array => ['part' => $part, 'level' => 5, 'start' => 95, 'est' => 9.0, 'end' => 104.0];
        $pha = ['power' => 80.0, 'handling' => 80.0, 'acceleration' => 80.0];
        $side = ['pha' => $pha, 'fit' => 90.0, 'match' => 'none'];

        $html = $this->twig()->render('partials/_cockpit_wear.twig', [
            'wear_advice' => [
                'swap' => [$flagged('Brakes'), $flagged('Cooling')], 'risky' => [], 'watch' => [], 'headline' => '',
            ],
            'swap_advice' => ['Brakes' => $brakes, 'Cooling' => $cooling],
            'swap_plan' => ['cost' => 1_700_000, 'track' => $pha, 'before' => $side, 'after' => $side],
            'cockpit_cash' => 1_000_000,
            'cockpit_risk' => 30,
            'cockpit_training_laps' => 0,
        ]);

        $this->assertMatchesRegularExpression('#<p class="notice notice-warn notice-slim\s*" data-plan-over-cash>#', $html);
        $this->assertStringContainsString('This plan costs more than the $1,000,000 you have.', $html);
    }
}

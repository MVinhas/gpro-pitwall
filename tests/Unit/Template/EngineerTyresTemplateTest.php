<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class EngineerTyresTemplateTest extends TestCase
{
    private const array VERDICTS = [
        'pick' => 'Pick',
        'close' => 'Close',
        'out_pipirelli' => 'Out: Pipirelli',
        'out_price' => 'Out: Pro price line',
        'out_dry' => 'Out: weak in the dry',
        'out_budget' => 'Out: over budget',
    ];

    /**
     * @return array<string, mixed>
     */
    private static function row(string $name, ?string $verdict, float $total = 71.24): array
    {
        return [
            'name' => $name, 'cost' => 4_800_000, 'season_cost' => 81_100_000, 'total' => $total,
            'parts' => ['performance' => 70.12, 'temperature' => 80.55, 'durability' => 60.0, 'warmup' => 50.04],
            'verdict' => $verdict, 'per_race' => '$4.8M', 'season' => '$81.1M',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function render(array $rows): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));

        return $twig->render('partials/_engineer_tyres.twig', [
            'engineer' => ['tyres' => [
                'pick' => 'Bridgerock', 'cost' => 4_800_000, 'best' => 'Bridgerock', 'reason' => 'best',
                'wet_edge' => false, 'talent' => null, 'baseline' => null, 'finances_known' => true,
                'figure' => '$4.8M a race', 'rows' => $rows,
            ]],
        ]);
    }

    public function testEveryVerdictHasItsWord(): void
    {
        $rows = [];
        foreach (self::VERDICTS as $code => $_) {
            $rows[] = self::row('S-' . $code, $code);
        }
        $html = $this->render($rows);

        foreach (self::VERDICTS as $word) {
            $this->assertStringContainsString($word, $html);
        }
    }

    public function testASupplierWithoutAVerdictShowsNone(): void
    {
        $html = $this->render([self::row('Bridgerock', 'pick'), self::row('Avonn', null)]);

        $this->assertStringNotContainsString('Out:', $html);
        $this->assertStringNotContainsString('Close', $html);
    }

    public function testThePickCarriesEmeraldAndTheOutsCarryRed(): void
    {
        $html = $this->render([self::row('A', 'pick'), self::row('B', 'out_price'), self::row('C', 'close')]);

        $this->assertMatchesRegularExpression('#font-semibold text-emerald-700[^>]*>\s*Pick\s*<#', $html);
        $this->assertMatchesRegularExpression('#text-red-700[^>]*>\s*Out: Pro price line\s*<#', $html);
        $this->assertSame(1, substr_count($html, 'text-emerald-700'));
        $this->assertSame(1, substr_count($html, 'text-red-700'));
    }

    public function testSummaryNamesThePickAndTheFigure(): void
    {
        $html = $this->render([self::row('Bridgerock', 'pick')]);

        $this->assertStringContainsString('Sign Bridgerock · $4.8M a race', $html);
    }

    public function testRowFiguresAreShownAsGivenWithOneDecimal(): void
    {
        $html = $this->render([self::row('Bridgerock', 'pick')]);

        foreach (['$4.8M', '$81.1M', '71.2', '70.1', '80.6', '60.0', '50.0'] as $figure) {
            $this->assertStringContainsString('>' . $figure . '<', preg_replace('#\s+#', '', $html) ?? '');
        }
    }

    public function testSeasonAndPartColumnsDieAtPhoneWidth(): void
    {
        $html = $this->render([self::row('Bridgerock', 'pick')]);

        $this->assertMatchesRegularExpression('#<th class="[^"]*hidden sm:table-cell[^"]*">\s*Season\s*</th>#', $html);
        foreach (['Performance', 'Temperature', 'Durability', 'Warm-up'] as $part) {
            $this->assertMatchesRegularExpression(
                '#<th class="[^"]*hidden md:table-cell[^"]*">\s*' . $part . '\s*</th>#',
                $html,
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '#<th class="[^"]*hidden[^"]*">\s*(Supplier|Per race|Score|Verdict)#',
            $html,
        );
    }

    public function testTheCaptionExplainsTheScoring(): void
    {
        $html = $this->render([self::row('Bridgerock', 'pick')]);

        $this->assertStringContainsString(
            "Scored race by race on this season's calendar: performance 55%, temperature fit 20%, "
            . 'durability 15%, warm-up 10%. Within 10% of the best, the cheaper supplier wins.',
            preg_replace('#\s+#', ' ', html_entity_decode($html, ENT_QUOTES)) ?? '',
        );
    }
}

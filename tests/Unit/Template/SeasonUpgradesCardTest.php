<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class SeasonUpgradesCardTest extends TestCase
{
    /**
     * @param list<array<string, mixed>>|null $upgrades
     */
    private function html(?array $upgrades, bool $withBudget = true): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));

        return $twig->render('season/_upgrades.twig', [
            'budget' => $withBudget ? ['view' => ['as_of' => 'As of race 8', 'upgrades' => $upgrades]] : null,
        ]);
    }

    /** @param list<array<string, mixed>>|null $upgrades */
    private function text(?array $upgrades): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->html($upgrades)), ENT_QUOTES));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function row(array $override = []): array
    {
        return array_merge([
            'part' => 'Engine', 'level' => 'L4 → L5', 'gain' => '3.5', 'price' => '$1.3M', 'net' => '$1.5M', 'fits' => true,
        ], $override);
    }

    public function testRendersAnchorAndColumns(): void
    {
        $html = $this->html([self::row()]);
        $text = $this->text([self::row()]);

        $this->assertMatchesRegularExpression('/<section id="season-upgrades" class="[^"]*scroll-mt-16/', $html);
        $this->assertStringContainsString('Upgrades to season end', $text);
        $this->assertStringContainsString('As of race 8', $text);
        foreach (['Part', 'Level', 'PHA gain', 'Price', 'Net cost', 'Fits budget'] as $header) {
            $this->assertStringContainsString($header, $text);
        }
        $this->assertStringContainsString('Engine L4 → L5 3.5 $1.3M $1.5M Yes', $text);
        $this->assertStringContainsString(
            "Gain = PHA points over the remaining races, weighted by each track's demands; net cost includes the extra repairs a higher level causes.",
            $text,
        );
        $this->assertStringNotContainsString('No upgrade adds PHA', $text);
    }

    public function testPhaGainAndPriceAreHiddenOnMobile(): void
    {
        $html = $this->html([self::row(), self::row(['part' => 'Brakes'])]);

        $this->assertSame(6, substr_count($html, 'hidden sm:table-cell'));
        $this->assertMatchesRegularExpression('/<th class="[^"]*hidden sm:table-cell[^"]*">PHA gain<\/th>/', $html);
        $this->assertMatchesRegularExpression('/<th class="[^"]*hidden sm:table-cell[^"]*">Price<\/th>/', $html);
    }

    public function testFitsReadsYesOrDash(): void
    {
        $html = $this->html([self::row(), self::row(['part' => 'Brakes', 'fits' => false])]);

        $this->assertSame(1, preg_match_all('/<td class="[^"]*font-medium text-emerald-700[^"]*">\s*Yes\s*<\/td>/', $html));
        $this->assertSame(1, preg_match_all('/<td class="dt-td text-right">\s*—\s*<\/td>/', $html));
        $this->assertSame(1, substr_count($html, 'text-emerald-700'));
    }

    public function testEmptyListShowsTheEmptyState(): void
    {
        $html = $this->html([]);
        $text = $this->text([]);

        $this->assertStringContainsString('No upgrade adds PHA', $text);
        $this->assertStringContainsString('No level above yours adds PHA for the remaining tracks.', $text);
        $this->assertStringContainsString('Upgrades to season end', $text);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testNoCardWithoutARankingOrBudget(): void
    {
        $this->assertSame('', trim($this->html(null)));
        $this->assertSame('', trim($this->html([self::row()], withBudget: false)));
    }
}

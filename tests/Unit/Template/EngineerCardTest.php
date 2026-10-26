<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class EngineerCardTest extends TestCase
{
    /**
     * @param array<string, mixed> $objective
     * @param list<array<string, mixed>> $lines
     */
    private function html(array $objective, array $lines): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));

        return $twig->render('partials/_engineer_card.twig', [
            'csrf_token' => 'tok',
            'engineer' => ['objective' => $objective, 'lines' => $lines, 'season' => 107],
        ]);
    }

    /** @return array<string, mixed> */
    private static function objective(string $label = 'Promote', bool $pinned = false, ?string $warning = null): array
    {
        return ['objective' => 'promote', 'label' => $label, 'pinned' => $pinned, 'proposed' => 'promote', 'warning' => $warning];
    }

    /** @return array<string, mixed> */
    private static function line(string $key, string $answer, string $tone = 'ink', ?array $action = null, string $reason = 'Because.'): array
    {
        return ['key' => $key, 'answer' => $answer, 'figure' => '4 pts', 'reason' => $reason, 'anchor' => '#cockpit-wear', 'tone' => $tone, 'action' => $action];
    }

    public function testRendersLinesInOrderWithActions(): void
    {
        $html = $this->html(self::objective(), [
            self::line('objective', 'First answer'),
            self::line('ctr', 'Second answer', 'amber', ['label' => 'Use 41', 'href' => '/cockpit?cockpit_risk=41']),
            self::line('parts', 'Third answer', 'red'),
        ]);

        $this->assertLessThan(strpos($html, 'Second answer'), strpos($html, 'First answer'));
        $this->assertLessThan(strpos($html, 'Third answer'), strpos($html, 'Second answer'));
        $this->assertStringContainsString('href="/cockpit?cockpit_risk=41"', $html);
        $this->assertStringContainsString('Use 41', $html);
        $this->assertStringContainsString('href="#cockpit-wear"', $html);
        $this->assertStringNotContainsString('##', $html);
        $this->assertStringContainsString('<span class="sr-only"> for CTR</span>', $html);
        $this->assertStringContainsString('<span class="sr-only"> for parts</span>', $html);
        $this->assertStringContainsString('text-amber-', $html);
        $this->assertStringContainsString('text-red-', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('name="season" value="107"', $html);
        $this->assertStringContainsString('action="/engineer/objective"', $html);
    }

    public function testPinnedObjectiveShowsBothCalls(): void
    {
        $html = $this->html(
            self::objective('Your call: Hold (engineer proposes Survive)', pinned: true),
            [self::line('objective', 'Hold the position')],
        );

        $this->assertStringContainsString('Your call: Hold (engineer proposes Survive)', $html);
    }

    public function testWarningRendersOverAPin(): void
    {
        $html = $this->html(
            self::objective('Your call: Hold (same as the engineer)', pinned: true, warning: 'Negative balance relegates — the engineer proposes Survive'),
            [self::line('objective', 'Hold')],
        );

        $this->assertStringContainsString('notice-warn', $html);
        $this->assertStringContainsString('Negative balance relegates', $html);
    }

    public function testNoWarningNoticeWhenNoneSet(): void
    {
        $html = $this->html(self::objective(), [self::line('objective', 'Hold', reason: '')]);

        $this->assertStringNotContainsString('notice-warn', $html);
    }

    public function testCardAbsentWithoutEngineerData(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('partials/_engineer_card.twig', ['csrf_token' => 'tok']);

        $this->assertStringNotContainsString('Race engineer', $html);
    }
}

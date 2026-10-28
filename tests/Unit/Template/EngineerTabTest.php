<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Controller\StrategyController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class EngineerTabTest extends TestCase
{
    /**
     * @param array<string, mixed> $vars
     */
    private function html(array $vars): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));

        $twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);

        return $twig->render('partials/_tab_engineer.twig', $vars + ['csrf_token' => 'tok']);
    }

    public function testRendersTheEngineerCard(): void
    {
        $html = $this->html([
            'pha' => [],
            'engineer' => [
                'objective' => ['objective' => 'promote', 'label' => 'Promote', 'pinned' => false, 'proposed' => 'promote', 'warning' => null],
                'lines' => [],
                'season' => 107,
            ],
        ]);

        $this->assertStringContainsString('id="engineer-title"', $html);
    }

    public function testSaysSoWhenThereIsNoAdviceYet(): void
    {
        $html = $this->html(['pha' => []]);

        $this->assertStringContainsString('No advice yet', $html);
        $this->assertStringContainsString('Re-sync to load this weekend&#039;s data.', $html);
    }

    public function testTheCockpitNoLongerCarriesTheEngineerCard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/partials/_tab_cockpit.twig');

        $this->assertStringNotContainsString('_engineer_card.twig', $source);
    }
}

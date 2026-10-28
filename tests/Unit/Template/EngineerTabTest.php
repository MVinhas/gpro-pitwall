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
            'cockpit_data_loaded' => true,
            'engineer' => [
                'objective' => [
                    'objective' => 'promote',
                    'label' => 'Promote',
                    'pinned' => false,
                    'proposed' => 'promote',
                    'warning' => null,
                ],
                'lines' => [],
                'season' => 107,
            ],
        ]);

        $this->assertStringContainsString('id="engineer-title"', $html);
    }

    public function testSaysSoWhenTheEngineerCouldNotBuildTheAdvice(): void
    {
        $html = $this->html(['cockpit_data_loaded' => true]);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString("Couldn't build the advice.", $html);
        $this->assertStringContainsString('The other screens are unaffected.', $html);
        $this->assertStringNotContainsString('Re-sync', $html);
    }

    public function testSaysSoWhenThereIsNoSyncedDataYet(): void
    {
        $html = $this->html([]);

        $this->assertStringContainsString('No synced data yet', $html);
        $this->assertStringNotContainsString("Couldn't build the advice.", $html);
    }

    public function testShowsTheNoPilotNoticeForAManagerWithoutADriver(): void
    {
        $html = $this->html(['cockpit_error' => StrategyController::NO_PILOT_MESSAGE]);

        $this->assertStringContainsString('No driver under contract.', $html);
        $this->assertStringNotContainsString("Couldn't load the race engineer.", $html);
    }

    public function testShowsAGenericErrorWhenTheScreenDataFailed(): void
    {
        $html = $this->html(['cockpit_error' => 'Something broke.']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString("Couldn't load the race engineer.", $html);
        $this->assertStringContainsString('Something broke.', $html);
    }

    public function testTheCockpitNoLongerCarriesTheEngineerCard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/partials/_tab_cockpit.twig');

        $this->assertStringNotContainsString('_engineer_card.twig', $source);
    }
}

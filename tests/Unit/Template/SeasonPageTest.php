<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The Season page tells the manager why a block is empty instead of hiding it.
 */
#[CoversNothing]
final class SeasonPageTest extends TestCase
{
    private const array EMPTY_CALIBRATION = [
        'wear' => ['parts' => [], 'skipped' => 0, 'enough' => false],
        'fuel' => ['mean_gap_pct' => null, 'n' => 0, 'skipped' => 0, 'enough' => false],
        'corpus' => ['mean_gap' => null, 'n' => 0],
    ];

    /** @param array<string, mixed> $view */
    private function text(array $view): string
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader([
                'layout.twig' => '{% block title %}{% endblock %}{% block robots %}{% endblock %}{% block canonical_path %}{% endblock %}{% block content %}{% endblock %}',
                'partials/_nav.twig' => '',
            ]),
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
        ]));

        $html = $twig->render('season/index.twig', array_merge([
            'season' => 107,
            'seasons' => [107],
            'has_races' => true,
            'results' => [],
            'results_line' => '',
            'money' => ['cash' => [], 'cash_line' => '', 'categories' => [], 'income' => 0, 'costs' => 0],
            'changes' => [],
            'calibration' => self::EMPTY_CALIBRATION,
        ], $view));

        return (string) preg_replace('/\s+/', ' ', strip_tags($html));
    }

    public function testNoHistoryShowsOneEmptyStateAndNoCards(): void
    {
        $text = $this->text(['season' => null, 'seasons' => [], 'has_races' => false]);

        $this->assertStringContainsString('No races recorded yet', $text);
        $this->assertStringNotContainsString('Model calibration', $text);
    }

    public function testASeasonWithoutRacesNamesTheSeason(): void
    {
        $this->assertStringContainsString(
            'No races recorded for Season 107 yet',
            $this->text(['has_races' => false]),
        );
    }

    public function testEachEmptyBlockSaysWhyItIsEmpty(): void
    {
        $text = $this->text([]);

        $this->assertStringContainsString('No money movements recorded for this season.', $text);
        $this->assertStringContainsString('No team changes recorded for this season.', $text);
        $this->assertStringContainsString('No dry races to compare against the models yet.', $text);
    }

    public function testASmallSampleSaysTooFewRacesAndNeverZero(): void
    {
        $text = $this->text(['calibration' => array_merge(self::EMPTY_CALIBRATION, [
            'wear' => ['parts' => ['engine' => ['label' => 'Engine', 'mean_gap' => null, 'n' => 2]], 'skipped' => 0, 'enough' => false],
            'fuel' => ['mean_gap_pct' => null, 'n' => 2, 'skipped' => 1, 'enough' => false],
        ])]);

        $this->assertStringContainsString('Engine too few races 2', $text);
        $this->assertStringContainsString('Too few races · 2 races, 1 skipped for missing data', $text);
        $this->assertStringContainsString('at least 3 races', $text);
    }

    public function testResultsShowTheChampionshipAndADnf(): void
    {
        $text = $this->text([
            'results' => [
                ['race' => 1, 'track_name' => 'Testring', 'final_pos' => 5, 'points' => 12, 'dnf' => false, 'champ_pos' => 6, 'champ_pts' => 12],
                ['race' => 2, 'track_name' => 'Ovalton', 'final_pos' => null, 'points' => null, 'dnf' => true, 'champ_pos' => null, 'champ_pts' => null],
            ],
            'results_line' => '0,0 240,40',
        ]);

        $this->assertStringContainsString('P6 · 12 pts', $text);
        $this->assertStringContainsString('DNF', $text);
    }

    public function testApproximateCorpusLineIsLabelled(): void
    {
        $text = $this->text(['calibration' => array_merge(self::EMPTY_CALIBRATION, [
            'corpus' => ['mean_gap' => 1.4, 'n' => 30],
        ])]);

        $this->assertStringContainsString("Everyone's races (approximate)", $text);
        $this->assertStringContainsString('+1.4 pts', $text);
    }
}

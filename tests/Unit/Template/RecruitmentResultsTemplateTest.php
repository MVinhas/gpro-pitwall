<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Support\RecruitmentQuery;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The results fragment: loaded, filtered-to-empty, empty market, market
 * failure and missing baseline must each say what they are — never a blank
 * card — and every link must keep the filters on screen.
 */
#[CoversNothing]
final class RecruitmentResultsTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function render(array $overrides = []): DOMXPath
    {
        $ranges = $overrides['range_filters'] ?? [];
        $hideOffers = $overrides['hide_offers'] ?? false;
        $params = RecruitmentQuery::params('Pro', $hideOffers, $ranges, 'rating', 'desc');

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('partials/_recruitment_results.twig', $overrides + [
            'active_division'              => 'Pro',
            'hide_offers'                  => $hideOffers,
            'recruitment_results'          => [$this->driver(1), $this->driver(2)],
            'recruitment_unfiltered_total' => 45,
            'recruitment_updated_at'       => '14/10/2026 12:00',
            'pagination'                   => ['total_items' => 45, 'total_pages' => 3, 'current' => 2, 'per_page' => 20],
            'sort'                         => ['col' => 'rating', 'order' => 'desc'],
            'filter_params'                => $params,
            'cleared_params'               => RecruitmentQuery::cleared($params),
            'filter_chips'                 => RecruitmentQuery::chips($ranges, $hideOffers, $params),
            'favourite_tracks_available'   => false,
        ]);

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

        return new DOMXPath($doc);
    }

    /** @return array<string, mixed> */
    private function driver(int $id): array
    {
        return [
            'ID' => $id, 'NAME' => 'Driver ' . $id, 'rating' => 90.0, 'OA' => 70, 'OFF' => 0,
            'CON' => 1, 'TAL' => 1, 'AGG' => 1, 'EXP' => 1, 'TEI' => 1, 'STA' => 1,
            'CHA' => 1, 'MOT' => 1, 'WEI' => 70, 'AGE' => 22, 'SAL' => 1000, 'FEE' => 2000,
        ];
    }

    private function state(DOMXPath $xpath): string
    {
        return (string) $xpath->query('//*[@data-result-state]/@data-result-state')->item(0)?->nodeValue;
    }

    /** @return array<string, string> */
    private function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    public function testResultsRenderTheTable(): void
    {
        $xpath = $this->render();

        $this->assertSame('results', $this->state($xpath));
        $this->assertSame(2, $xpath->query('//table//tbody/tr')->length);
    }

    public function testAMarketFailureOffersARetryAndNoTable(): void
    {
        $xpath = $this->render(['recruitment_error' => true, 'range_filters' => ['OA' => ['min' => 60]]]);

        $this->assertSame('failed', $this->state($xpath));
        $this->assertSame(0, $xpath->query('//table')->length);

        $retry = (string) $xpath->query('//a[normalize-space()="Try again"]/@href')->item(0)?->nodeValue;
        $this->assertSame('60', $this->queryOf($retry)['min_OA'] ?? null, 'Retry must keep the filters.');
    }

    public function testAMissingBaselineNamesTheDivision(): void
    {
        $xpath = $this->render(['recruitment_no_baseline' => true]);

        $this->assertSame('no-baseline', $this->state($xpath));
        $this->assertStringContainsString('No Pro baseline', $xpath->document->textContent ?? '');
    }

    public function testFiltersThatExcludeEveryoneSayTheFiltersDidIt(): void
    {
        $xpath = $this->render([
            'range_filters'       => ['OA' => ['min' => 200]],
            'recruitment_results' => [],
            'pagination'          => ['total_items' => 0, 'total_pages' => 0, 'current' => 1, 'per_page' => 20],
        ]);

        $this->assertSame('empty', $this->state($xpath));
        $this->assertStringContainsString('No drivers match these filters', $xpath->document->textContent ?? '');
        $this->assertSame(1, $xpath->query('//a[@data-recruit-nav="clear"]')->length);
    }

    public function testAnEmptyMarketIsNotBlamedOnFilters(): void
    {
        $xpath = $this->render([
            'recruitment_results'          => [],
            'recruitment_unfiltered_total' => 0,
            'pagination'                   => ['total_items' => 0, 'total_pages' => 0, 'current' => 1, 'per_page' => 20],
        ]);

        $text = $xpath->document->textContent ?? '';
        $this->assertStringContainsString('No candidates for Pro', $text);
        $this->assertStringNotContainsString('match these filters', $text);
    }

    public function testAFailedRefreshKeepsTheCachedResultsAndSaysSo(): void
    {
        $xpath = $this->render(['recruitment_refresh_failed' => true]);

        $this->assertStringContainsString("Couldn't refresh the market", $xpath->document->textContent ?? '');
        $this->assertSame(2, $xpath->query('//table//tbody/tr')->length);
    }

    public function testEachChipDropsOnlyItsOwnFilter(): void
    {
        $xpath = $this->render(['range_filters' => ['OA' => ['min' => 60], 'AGE' => ['max' => 25]], 'hide_offers' => true]);

        $chips = $xpath->query('//a[@data-recruit-nav="chip"]/@href');
        $this->assertSame(3, $chips->length);

        $dropOa = $this->queryOf((string) $chips->item(0)?->nodeValue);
        $this->assertArrayNotHasKey('min_OA', $dropOa);
        $this->assertSame('25', $dropOa['max_AGE']);
        $this->assertSame('1', $dropOa['no_offers']);

        $clear = $this->queryOf((string) $xpath->query('//a[@data-recruit-nav="clear"]/@href')->item(0)?->nodeValue);
        $this->assertSame(['division_tab' => 'Pro', 'sort' => 'rating', 'order' => 'desc'], $clear);
    }

    public function testNoFiltersMeansNoChipRow(): void
    {
        $xpath = $this->render();

        $this->assertSame(0, $xpath->query('//a[@data-recruit-nav="chip"]')->length);
        $this->assertSame(0, $xpath->query('//a[@data-recruit-nav="clear"]')->length);
    }

    public function testSortAndPageLinksKeepTheFilters(): void
    {
        $xpath = $this->render(['range_filters' => ['OA' => ['min' => 60]], 'hide_offers' => true]);

        $sort = $this->queryOf((string) $xpath->query('//a[@data-recruit-nav="sort"][normalize-space()="AGE"]/@href')->item(0)?->nodeValue);
        $this->assertSame(['60', '1', 'AGE', 'desc'], [$sort['min_OA'] ?? null, $sort['no_offers'] ?? null, $sort['sort'], $sort['order']]);
        $this->assertArrayNotHasKey('page', $sort, 'A new sort starts from page 1.');

        $page = $this->queryOf((string) $xpath->query('//a[@data-recruit-nav="page"][normalize-space()="3"]/@href')->item(0)?->nodeValue);
        $this->assertSame(['60', '1', '3'], [$page['min_OA'] ?? null, $page['no_offers'] ?? null, $page['page']]);
    }
}

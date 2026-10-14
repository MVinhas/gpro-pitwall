<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Http\Sections;
use App\Service\RecruitmentService;
use App\Support\RecruitmentQuery;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The Recruitment filter form must submit straight to the Recruitment path.
 *
 * It used to GET '/?main_tab=Recruitment Analyzer'. Once every screen got its
 * own path, '/' answered that with a 301 that carries only the legacy keys
 * (division, page, sort) — every min_/max_ value was dropped on the way, so
 * "Apply filters" reloaded the page with the full market (issue #119).
 */
#[CoversNothing]
final class RecruitmentRangeFilterFormTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function render(array $overrides = []): DOMXPath
    {
        $ranges = $overrides['range_filters'] ?? [];
        $hideOffers = $overrides['hide_offers'] ?? false;
        $params = RecruitmentQuery::params('Pro', $hideOffers, $ranges, 'rating', 'desc');

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('partials/_tab_recruitment.twig', $overrides + [
            'csrf_token'                   => 'test',
            'divisions'                    => ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            'active_division'              => 'Pro',
            'hide_offers'                  => $hideOffers,
            'recruitment_results'          => [],
            'recruitment_unfiltered_total' => 0,
            'pagination'                   => ['total_items' => 0, 'total_pages' => 0, 'current' => 1, 'per_page' => 20],
            'range_filters'                => $ranges,
            'range_filter_fields'          => RecruitmentService::RANGE_FILTER_FIELDS,
            'sort'                         => ['col' => 'rating', 'order' => 'desc'],
            'filter_params'                => $params,
            'cleared_params'               => RecruitmentQuery::cleared($params),
            'filter_chips'                 => RecruitmentQuery::chips($ranges, $hideOffers, $params),
            'favourite_tracks_available'   => false,
        ]);

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    private function filterForm(DOMXPath $xpath): DOMElement
    {
        $form = $xpath->query('//form[@id="recruitFilters"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form, 'The filter form did not render.');

        return $form;
    }

    public function testFormSubmitsToTheRecruitmentPathNotTheLegacyRoot(): void
    {
        $form = $this->filterForm($this->render());

        $this->assertSame('get', strtolower($form->getAttribute('method')));
        $this->assertSame(Sections::pathFor('Recruitment Analyzer'), $form->getAttribute('action'));
    }

    public function testFormCarriesNoLegacyMainTab(): void
    {
        $inputs = $this->render()->query('//form[@id="recruitFilters"]//input[@name="main_tab"]');

        $this->assertSame(0, $inputs->length, 'A main_tab field routes the submit through the lossy legacy redirect.');
    }

    public function testEveryRangeFieldHasAMinAndMaxBox(): void
    {
        $xpath = $this->render();

        foreach (array_keys(RecruitmentService::RANGE_FILTER_FIELDS) as $field) {
            foreach (['min', 'max'] as $bound) {
                $this->assertSame(
                    1,
                    $xpath->query('//form[@id="recruitFilters"]//input[@name="' . $bound . '_' . $field . '"]')->length,
                    "Missing {$bound}_{$field}",
                );
            }
        }
    }

    public function testDivisionAndOffersLiveInTheSameFormAsTheRanges(): void
    {
        $xpath = $this->render(['hide_offers' => true]);

        $checked = $xpath->query('//form[@id="recruitFilters"]//input[@name="division_tab"][@checked]');
        $this->assertSame(1, $checked->length);
        $this->assertSame('Pro', $checked->item(0)?->attributes?->getNamedItem('value')?->nodeValue);

        $offers = $xpath->query('//form[@id="recruitFilters"]//input[@name="no_offers"][@checked]');
        $this->assertSame(1, $offers->length);
    }

    public function testActiveValuesAreFilledBackIn(): void
    {
        $xpath = $this->render(['range_filters' => ['OA' => ['min' => 60], 'TAL' => ['max' => 120]]]);

        $this->assertSame('60', $xpath->query('//input[@name="min_OA"]/@value')->item(0)?->nodeValue);
        $this->assertSame('120', $xpath->query('//input[@name="max_TAL"]/@value')->item(0)?->nodeValue);
    }

    public function testMoreAttributesOpensOnlyWhenOneOfThemIsActive(): void
    {
        $closed = $this->render(['range_filters' => ['OA' => ['min' => 60]]]);
        $this->assertSame(0, $closed->query('//details[@data-more-filters][@open]')->length);

        $open = $this->render(['range_filters' => ['TAL' => ['min' => 60]]]);
        $this->assertSame(1, $open->query('//details[@data-more-filters][@open]')->length);
    }

    public function testRefreshPostsWithACsrfToken(): void
    {
        $xpath = $this->render();

        $form = $xpath->query('//form[@action="/recruitment/refresh"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame(1, $xpath->query('//form[@action="/recruitment/refresh"]//input[@name="csrf_token"]')->length);
    }
}

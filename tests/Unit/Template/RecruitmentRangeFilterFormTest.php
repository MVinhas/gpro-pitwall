<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Http\Sections;
use App\Service\RecruitmentService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The value range filter form must submit straight to the Recruitment path.
 *
 * It used to GET '/?main_tab=Recruitment Analyzer'. Once every screen got its
 * own path, '/' answered that with a 301 that carries only the legacy keys
 * (division, page, sort) — every min_/max_ value was dropped on the way, so
 * "Apply filters" reloaded the page with the full market (issue #119).
 */
#[CoversNothing]
final class RecruitmentRangeFilterFormTest extends TestCase
{
    private function filterForm(): DOMElement
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('partials/_tab_recruitment.twig', [
            'csrf_token'                  => 'test',
            'divisions'                   => ['Rookie', 'Elite'],
            'active_division'             => 'Rookie',
            'recruitment_results'         => [],
            'recruitment_unfiltered_total' => 0,
            'pagination'                  => ['total_items' => 0, 'total_pages' => 0, 'current' => 1],
            'range_filters'               => [],
            'range_filter_fields'         => RecruitmentService::RANGE_FILTER_FIELDS,
            'range_filter_query'          => '',
            'sort'                        => ['col' => 'rating', 'order' => 'desc'],
            'favourite_tracks_available'  => false,
        ]);

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $form = (new DOMXPath($doc))->query('//form[.//input[@name="min_OA"]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form, 'The range filter form did not render.');

        return $form;
    }

    public function testFormSubmitsToTheRecruitmentPathNotTheLegacyRoot(): void
    {
        $form = $this->filterForm();

        $this->assertSame('get', strtolower($form->getAttribute('method')));
        $this->assertSame(Sections::pathFor('Recruitment Analyzer'), $form->getAttribute('action'));
    }

    public function testFormCarriesNoLegacyMainTab(): void
    {
        $inputs = (new DOMXPath($this->filterForm()->ownerDocument))
            ->query('//form[.//input[@name="min_OA"]]//input[@name="main_tab"]');

        $this->assertSame(0, $inputs->length, 'A main_tab field routes the submit through the lossy legacy redirect.');
    }
}

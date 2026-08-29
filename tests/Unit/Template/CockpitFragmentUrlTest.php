<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards the cockpit wear card's fragment-refresh URL construction.
 *
 * On a `<form action="">` the DOM's `form.action` resolves to the entire
 * document URL — query string and #hash included. Building the fragment URL
 * by concatenating `'?' + params` onto it shipped a self-compounding bug: the
 * doubled '?' survived by luck (PHP's last-wins duplicate parsing), but the
 * moment a #wear anchor was in the address bar every appended parameter landed
 * inside the fragment, which browsers never send. The request arrived without
 * `fragment=cockpit_wear`, the server answered with the whole page, the
 * recovery path appended the same garbage again, and the address bar grew one
 * `?...#wear` segment per slider move while the sliders stopped reaching the
 * server entirely — the card froze on risk 0 / 0 training laps.
 *
 * There is no JS test harness here, so this asserts the shape of the source:
 * the URL is rebuilt via the URL API, and `form.action` is never a base.
 */
#[CoversNothing]
final class CockpitFragmentUrlTest extends TestCase
{
    private function cockpitTemplate(): string
    {
        $path = dirname(__DIR__, 3) . '/templates/partials/_tab_cockpit.twig';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testFragmentUrlIsNotBuiltByConcatenatingFormAction(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/form\.action\s*\+/',
            $this->cockpitTemplate(),
            'form.action carries the document query string and #hash, so string-concatenating '
            . 'a query onto it compounds the URL and hides the parameters behind the fragment.',
        );
    }

    public function testFragmentUrlIsRebuiltFromTheUrlApi(): void
    {
        $js = $this->cockpitTemplate();

        // Assigning .search replaces the query outright rather than appending,
        // which is what makes repeated refreshes idempotent.
        $this->assertMatchesRegularExpression('/new URL\(window\.location\.href\)/', $js);
        $this->assertMatchesRegularExpression('/\.search\s*=/', $js);
        $this->assertMatchesRegularExpression('/\.hash\s*=/', $js);
    }

    public function testRecoveryStillCarriesTheSlidersAndTheWearAnchor(): void
    {
        $js = $this->cockpitTemplate();

        // A recovery must not silently rewind the sliders to zero, and must
        // land the reader back on the wear card rather than the page top.
        $this->assertStringContainsString("next.delete('fragment')", $js);
        $this->assertStringContainsString("at(next.toString(), 'wear')", $js);
    }
}

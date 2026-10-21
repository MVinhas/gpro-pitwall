<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The oldest PHP release without a known critical vulnerability. Separate from
 * composer.json's `require.php` on purpose: raising that triggers Composer's
 * platform check, which fatals every request on a host that hasn't patched yet
 * and blocks `composer install` on dev machines and CI until their packages
 * catch up. This floor only reports; taking the site down is a human decision
 * (the maintenance switch in public/.htaccess).
 *
 * Override per server with PHP_SECURITY_FLOOR when the next advisory lands
 * before a release can.
 */
final readonly class PhpSecurityFloor
{
    public const string DEFAULT = '8.5.12';

    private string $minimum;

    public function __construct(string $minimum = self::DEFAULT)
    {
        $this->minimum = $minimum !== '' ? $minimum : self::DEFAULT;
    }

    public function minimum(): string
    {
        return $this->minimum;
    }

    public function isMet(string $running = PHP_VERSION): bool
    {
        // Distro builds append "-1+deb13u1"-style suffixes, which version_compare()
        // would read as extra components; only the upstream triple is comparable.
        $triple = preg_match('/^\d+\.\d+\.\d+/', $running, $m) === 1 ? $m[0] : $running;

        return version_compare($triple, $this->minimum, '>=');
    }
}

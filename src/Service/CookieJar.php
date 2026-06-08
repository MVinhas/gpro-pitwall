<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thin seam over PHP's cookie superglobal + setcookie(), so services that
 * read/write cookies stay unit-testable (real setcookie() needs headers not
 * yet sent, which is impossible mid-test).
 */
interface CookieJar
{
    public function get(string $name): ?string;

    /**
     * @param array{expires?:int,path?:string,secure?:bool,httponly?:bool,samesite?:'Lax'|'Strict'|'None'} $options
     */
    public function set(string $name, string $value, array $options): void;

    public function clear(string $name, string $path): void;
}

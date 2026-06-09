<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * A request-terminating HTTP error (403, 404, …). Thrown by the auth gate and
 * the router; caught in the front controller, which renders a styled error
 * page with the carried status code instead of leaking a bare text response.
 */
final class HttpException extends RuntimeException
{
    public function __construct(private readonly int $statusCode, string $message = '')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Dedicated security-event channel (OWASP A09). Emits one structured line per
 * auth/security-relevant event to a sink (error_log by default), so the host's
 * log pipeline can ship and alert on `[security]` lines.
 *
 * Never pass secrets, codes, tokens, or validator values as context — only
 * identifiers and outcomes. Selectors are safe (they are public lookup keys).
 */
final class SecurityLogger
{
    /** @var callable(string): void */
    private $sink;

    /** @param (callable(string): void)|null $sink */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function event(string $action, array $context = []): void
    {
        $parts = ['[security]', 'action=' . $action];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . ($value === null ? '' : (string) $value);
        }

        ($this->sink)(implode(' ', $parts));
    }
}

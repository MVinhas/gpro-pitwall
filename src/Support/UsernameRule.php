<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single definition of what a username may be.
 *
 * Registration and the admin rename must agree exactly: a rename that accepted
 * anything registration rejects would mint the very kind of row the whitelist
 * exists to prevent, and one that rejected what registration accepts would be
 * unable to undo its own mistakes. Both call check().
 */
final class UsernameRule
{
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 20;

    /**
     * Letters, digits, underscore. Excludes every HTML/JS metacharacter and
     * avoids Unicode homoglyph/bidi spoofing, so stored XSS can't depend on a
     * missed escape downstream. It also keeps usernames byte-exact typeable:
     * the login lookup is a case-sensitive `=`, so an accent or a space makes
     * an account unreachable the moment its owner misremembers the spelling.
     */
    public const string PATTERN = '/^[A-Za-z0-9_]+$/';

    /** Returns an error message, or null when $username is acceptable. */
    public static function check(string $username): ?string
    {
        if (strlen($username) < self::MIN_LENGTH || strlen($username) > self::MAX_LENGTH) {
            return 'Username must be ' . self::MIN_LENGTH . '–' . self::MAX_LENGTH . ' characters.';
        }

        if (preg_match(self::PATTERN, $username) !== 1) {
            return 'Username may only contain letters, numbers, and underscores.';
        }

        return null;
    }
}

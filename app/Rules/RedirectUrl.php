<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A web address a respondent may be sent to after submitting (M130, `R-db169c29`, `D76`).
 *
 * The browser NAVIGATES to this value, so it is a sink, and the check is the content-block link check first —
 * {@see ContentBlocks::linkIsSafe()}, which refuses whitespace and control characters (browsers strip them before
 * reading a scheme) and anything but a known scheme — then narrower: `https` only, a host, and no `user:pass@`,
 * whose only use in a link a stranger is sent to is to make `https://trusted.example@evil.example` read as the
 * first host. `http` is refused because the respondent's answers were just sent over TLS, and the next page should
 * not be the one that drops it.
 *
 * A form destination never comes through here: its address is built by the server from the target's slug, so this
 * rule applies to typed addresses only (a server-built address follows `APP_URL`, which is plain `http` locally).
 */
final class RedirectUrl implements ValidationRule
{
    public const MAX_LENGTH = 2000;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isSafe($value)) {
            $fail('Enter a full web address that starts with https://.');
        }
    }

    public static function isSafe(string $url): bool
    {
        if (strlen($url) > self::MAX_LENGTH || ! ContentBlocks::linkIsSafe($url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts);
    }
}

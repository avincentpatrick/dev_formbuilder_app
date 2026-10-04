<?php

declare(strict_types=1);

use App\Rules\RedirectUrl;

/*
|--------------------------------------------------------------------------
| M130 (`R-db169c29`, `D76`) — a typed after-submit address.
|--------------------------------------------------------------------------
| The browser navigates to it, so it is checked as a sink: the content-block link check first, then https only,
| a host, and no `user:pass@`. Every refusal below sits beside accepted addresses that differ from it in one way.
*/

it('accepts a full https address', function (string $url): void {
    expect(RedirectUrl::isSafe($url))->toBeTrue();
})->with([
    'a host' => 'https://example.org',
    'a path, query and fragment' => 'https://example.org/thanks?from=form#top',
    'a port' => 'https://sub.example.org:8443/path',
    'upper case' => 'HTTPS://EXAMPLE.ORG/',
]);

it('refuses every address a respondent should not be sent to', function (string $url): void {
    expect(RedirectUrl::isSafe($url))->toBeFalse();
})->with([
    'a script' => 'javascript:alert(1)',
    'plain http' => 'http://example.org',
    'data' => 'data:text/html,x',
    'a leading space' => ' https://example.org',
    'a control character' => "https://example.org/\x01",
    'userinfo that disguises the host' => 'https://trusted.example@evil.example/',
    'a password' => 'https://user:pass@example.org/',
    'no host' => 'https:///path',
    'a relative path' => '/f/other-form',
    'an email address' => 'mailto:clinic@example.org',
    'too long' => 'https://example.org/'.str_repeat('a', RedirectUrl::MAX_LENGTH),
]);

it('refuses with words an author can act on', function (): void {
    $messages = [];
    (new RedirectUrl)->validate('redirect_url', 'http://example.org', function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    expect($messages)->toBe(['Enter a full web address that starts with https://.']);
});

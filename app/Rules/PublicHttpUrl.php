<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Support\Webhooks\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a value is a public http(s) URL, safe to use as a webhook destination — the save-time SSRF
 * checkpoint (H13a). Delegates to {@see OutboundUrlGuard} so the save-time and delivery-time checks share one
 * implementation and cannot drift. The first invokable {@see ValidationRule} in the repo.
 */
final class PublicHttpUrl implements ValidationRule
{
    public function __construct(private readonly OutboundUrlGuard $guard = new OutboundUrlGuard) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid http(s) URL.');

            return;
        }

        try {
            $this->guard->assertPublic($value);
        } catch (BlockedWebhookUrlException $e) {
            $fail($e->getMessage());
        }
    }
}

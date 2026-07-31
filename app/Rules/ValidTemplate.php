<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\Templates\TemplateSyntaxException;
use App\Services\Forms\TemplateValidationGate;
use App\Services\Templates\TemplateParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a value parses under the piping template grammar v1.0
 * (`docs/piping-output-encoding-design.md` §2). Delegates to {@see TemplateParser} — the same parser the
 * publish gate runs — so the request-time and publish-time checks share one implementation and cannot
 * drift, the reason {@see PublicHttpUrl} exists in this namespace rather than as an inline closure.
 *
 * ── GRAMMAR ONLY, deliberately ──────────────────────────────────────────────────────────────────────
 * This checks that the template PARSES; it does not resolve `${key}` against any field set. Grammar is
 * context-free and always decidable, but reference resolution is version-relative and this rule's only
 * consumer is `forms.confirmation_message` — a FORM-level column that can be edited when the form has no
 * published version at all, and whose holes §6.2 (as amended by H6a) validates against the version being
 * PUBLISHED. There is no version to resolve against at request time.
 *
 * {@see TemplateValidationGate} is therefore the authority for references, eligibility
 * and scope. The residue — a post-publish edit that dangles a reference — is caught at the next publish
 * rather than at the edit; §8 records that gap and assigns it to H6b.
 */
final class ValidTemplate implements ValidationRule
{
    public function __construct(private readonly TemplateParser $parser = new TemplateParser) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }

        try {
            $this->parser->parse($value);
        } catch (TemplateSyntaxException $e) {
            $fail($e->getMessage());
        }
    }
}

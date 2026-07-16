<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

/**
 * The resolved XLSForm `settings` sheet for import (Increment G7b) — deliberately WITHOUT a `version`:
 * import never trusts a client-supplied version number (docs/xlsform-interop-spec.md §4); the product's own
 * versioning model assigns it. `formId` maps to `forms.public_slug` (a generated slug fallback when absent),
 * `formTitle` to `forms.title`, `defaultLanguage` to `forms.default_locale`.
 */
final class SettingsSpec
{
    public function __construct(
        public readonly ?string $formTitle = null,
        public readonly ?string $formId = null,
        public readonly ?string $defaultLanguage = null,
    ) {}
}

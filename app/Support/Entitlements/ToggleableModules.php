<?php

declare(strict_types=1);

namespace App\Support\Entitlements;

use App\Enums\NotificationType;
use App\Services\Entitlements\EntitlementService;

/**
 * The capability keys a tenant may switch OFF for itself (PRD Feature #10, "per-module toggles") —
 * Increment I5.
 *
 * PRD #10 requires the module panel to reuse "the same capability-flag mechanism that already gates
 * per-form OCR eligibility… not a second flagging system", so these are literally
 * `Database\Seeders\Data\PlanCatalog::FEATURE_KEYS` entries and the toggle composes into
 * {@see EntitlementService::feature()} as a third layer: **effective = (legacy override ∥ plan flag) AND
 * NOT tenant-disabled.** One-directional by construction — a tenant can silence a capability its plan
 * grants, and can never grant itself one its plan denies.
 *
 * ── WHY THIS IS A CURATED SUBSET RATHER THAN THE WHOLE CATALOG ──────────────────────────────────────────
 * Two exclusions are about not stranding live state, and they are the ones worth arguing:
 *   - `branding` and `custom_domain` — ADR-0012 §D9's escape hatch says a tenant that loses a paid feature
 *     must retain a path to UNDO what the paid tier let it create. Switching `custom_domain` off here would
 *     hide the /domains surface while a hostname is still resolving to the platform, which is the exact
 *     state that escape hatch exists to prevent; `branding` is the same shape one step down.
 * The rest are excluded because a toggle would be theatre: `sso_saml`, `dedicated_db`, `data_residency` and
 * `embedded_payments` are provisioning/commercial arrangements, not features a tenant turns off on a
 * Tuesday, and none of them is built yet.
 *
 * Adding a key here is a one-line change with no migration — `modules.<key>` is an open namespace in the
 * sparse `settings` table — but it MUST also exist in the plan catalog, which `ToggleableModulesTest` pins.
 */
final class ToggleableModules
{
    /**
     * @var list<string>
     */
    public const array KEYS = [
        'ocr_single',
        'ocr_linelist',
        'webhooks',
        'native_connectors',
        'api_access',
        'offline_sync',
        'save_and_resume',
        'xlsform_export',
        'form_templates',
        'field_library',
        'advanced_analytics',
    ];

    /** The settings key a module toggle is stored under. */
    public static function settingKey(string $module): string
    {
        return 'modules.'.$module;
    }

    public static function isToggleable(string $module): bool
    {
        return in_array($module, self::KEYS, true);
    }

    /**
     * Human labels for the Modules card. Kept beside the list so a new key cannot ship label-less —
     * the same argument {@see NotificationType::label()} makes for its own vocabulary.
     */
    public static function label(string $module): string
    {
        return match ($module) {
            'ocr_single' => 'OCR — single forms',
            'ocr_linelist' => 'OCR — line lists',
            'webhooks' => 'Webhooks',
            'native_connectors' => 'Integrations (Slack, Sheets, Airtable)',
            'api_access' => 'REST API',
            'offline_sync' => 'Offline collection',
            'save_and_resume' => 'Save & resume for respondents',
            'xlsform_export' => 'XLSForm import/export',
            'form_templates' => 'Form templates',
            'field_library' => 'Question library',
            'advanced_analytics' => 'Advanced analytics',
            default => $module,
        };
    }

    /**
     * What turning this module off actually stops, in one line, for the card's hint text.
     *
     * A toggle whose consequence is unstated is a toggle nobody dares touch — and the consequences here
     * genuinely differ (some hide a surface, one stops respondents mid-form).
     */
    public static function hint(string $module): string
    {
        return match ($module) {
            'ocr_single', 'ocr_linelist' => 'Hides scanned-form capture from the builder and the inbox.',
            'webhooks' => 'Existing endpoints stop receiving deliveries and the page is hidden.',
            'native_connectors' => 'Connected destinations stop receiving new submissions.',
            'api_access' => 'Existing API keys stop working until this is switched back on.',
            'offline_sync' => 'Respondents lose offline collection; queued responses still upload.',
            'save_and_resume' => 'Respondents can no longer save a partly-filled form and come back.',
            'xlsform_export' => 'Hides XLSForm import and export from the builder.',
            'form_templates' => 'Hides the template gallery when creating a form.',
            'field_library' => 'Hides the saved-question library in the builder.',
            'advanced_analytics' => 'Hides the analytics workspace; the dashboard and per-form statistics are unaffected.',
            default => '',
        };
    }
}

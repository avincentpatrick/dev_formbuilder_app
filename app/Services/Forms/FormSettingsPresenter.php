<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Exceptions\Ocr\OcrException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Scoping\ScopeNodePresenter;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Entitlements\FeatureAdmission;
use DateTimeZone;

/**
 * A form's settings, as BOTH entry points show them (M129 — `D63`, answered A: both entry points, one set of
 * sections, no second implementation to drift).
 *
 * The builder's "Form settings" modal and the form hub's Settings tab mount the same sections against the same
 * routes, so they read the same fields from here. {@see BuilderPresenter} takes `form()` for its `form`
 * block, which is how the two can never offer a section different values.
 *
 * ── THE SCANNING SECTION IS OFFERED ONLY WHERE ITS ROUTE ADMITS ─────────────────────────────────────
 * `PATCH /forms/{form}/ocr-scanning` stacks the workspace's `ocr_single` module toggle before the plan, so
 * {@see ocrScanning()} answers null for a workspace that cannot scan at all, asked exactly as the route asks
 * it. A section shown to a reader whose save would be refused is a dead end the modal's own docblock refuses.
 */
final class FormSettingsPresenter
{
    public function __construct(
        private readonly FormSharePresenter $share,
        private readonly ScopeNodePresenter $scopes,
        private readonly EntitlementService $entitlements,
        private readonly TenantSettingRegistry $settings,
    ) {}

    /**
     * The fields the settings sections read and write.
     *
     * @return array<string, mixed>
     */
    public function form(Form $form): array
    {
        return [
            'title' => $form->title,
            'description' => $form->description,
            // Per-form save-and-resume opt-in (H10) — drives the builder toggle; the guest runtime reads its
            // own effective flag (tenant plan AND this) from PublicFormPresenter.
            'save_and_resume' => $form->save_and_resume,
            // Presentation mode (`D35`, row `R-f1332829`) — drives the Pages settings section AND the
            // live preview, which until this row had no way to know and was therefore always stepped.
            // The preview reads it off the RENDER model, never off the engine: see `PreviewRuntime`.
            'single_page_mode' => $form->single_page_mode,
            // Raw schedule values (Increment H12b) — the Schedule section prefills from these (the ISO instants
            // are rendered back into `timezone` for the datetime-local inputs). Enforcement uses `acceptance`
            // on the runtime presenters; the settings only need the raw window + cap to round-trip a PATCH.
            'opens_at' => $form->opens_at?->toIso8601String(),
            'closes_at' => $form->closes_at?->toIso8601String(),
            'timezone' => $form->timezone,
            'max_responses' => $form->max_responses,
            // The confirmation template + its locale variants (Increment H6a) — raw, so the Thank-you section
            // round-trips exactly what the author wrote. Settings never render a template: an author needs to
            // see `${child_name}`, not a value there is no submission to supply.
            'confirmation_message' => $form->confirmation_message,
            'confirmation_message_translations' => $form->confirmation_message_translations ?? [],
            // The form's locale set, so the section can offer one message box per supported locale.
            'default_locale' => $form->default_locale,
            'supported_locales' => $form->supported_locales === [] ? [$form->default_locale] : array_values($form->supported_locales),
        ];
    }

    /**
     * The Scanning section's facts, or null when this workspace cannot scan at all (module off, or a plan
     * without `ocr_single`) — the PATCH route's own two gates, in its order.
     *
     * `eligible` is about the form, never the workspace: whether its current published version is one paper
     * can carry (`docs/ocr-pipeline-design.md` §2). An author may switch scanning on for a form that is not
     * eligible yet; the upload refuses until it is, and the section says why in the upload's own words.
     *
     * @return array{enabled: bool, eligible: bool, reason: string|null}|null
     */
    public function ocrScanning(Form $form): ?array
    {
        if (! $this->settings->moduleEnabled('ocr_single') || ! FeatureAdmission::admits($this->entitlements, 'ocr_single')) {
            return null;
        }

        $version = $form->current_published_version_id === null
            ? null
            : FormVersion::query()->whereKey($form->current_published_version_id)->first();
        $eligible = $version !== null && CapabilityFlags::isOcrCompatible($version);

        return [
            'enabled' => $form->allow_ocr_single === true,
            'eligible' => $eligible,
            'reason' => match (true) {
                $eligible => null,
                $version === null => 'Scans can be read once this form is published.',
                default => OcrException::formNotEligible()->getMessage(),
            },
        ];
    }

    /**
     * The form hub's Settings tab: every section the builder offers, plus Scope.
     *
     * Scope lives on the hub and not in the builder because it confers capacity rather than describing the
     * form — which is why `routes/tenant.php` gives it its own route — and only a holder of `scopes.manage`
     * receives it, the gate that route stacks on top of `can:update,form`.
     *
     * @return array<string, mixed>
     */
    public function page(Form $form, User $user): array
    {
        return [
            'form' => ['id' => $form->id, ...$this->form($form)],
            'share' => $this->share->present($form),
            'timezones' => DateTimeZone::listIdentifiers(),
            'ocr_scanning' => $this->ocrScanning($form),
            'scope' => $user->can('viewAny', ScopeNode::class)
                ? ['current_node_id' => $form->scope_node_id, 'options' => $this->scopes->pickerOptions()]
                : null,
        ];
    }
}

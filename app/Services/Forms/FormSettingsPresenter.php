<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormStatus;
use App\Enums\FormThemePreset;
use App\Exceptions\Ocr\OcrException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Authorization\ResponseReadAccess;
use App\Services\Automations\FormAutomationPresenter;
use App\Services\Entitlements\EntitlementService;
use App\Services\Scoping\ScopeNodePresenter;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Entitlements\FeatureAdmission;
use App\Support\Forms\GuestReachability;
use App\Support\Forms\ShareableQuestions;
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
        private readonly FormReferenceFileService $referenceFileService,
        private readonly FormAutomationPresenter $automationPresenter,
        private readonly ResponseReadAccess $responseAccess,
    ) {}

    /**
     * The fields the settings sections read and write.
     *
     * @return array<string, mixed>
     */
    public function form(Form $form, ?User $viewer = null): array
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
            // M130 (`R-db169c29`, `D76`) — where a respondent goes after the thank-you screen, raw, and the forms this
            // author may send them to. `live` is the predicate the submit response resolves with, so the panel warns
            // about exactly the destinations a respondent would not be sent to.
            'redirect_kind' => $form->redirect_form_id !== null ? 'form' : ($form->redirect_url !== null ? 'url' : 'none'),
            'redirect_form_id' => $form->redirect_form_id,
            'redirect_url' => $form->redirect_url,
            'redirect_targets' => $this->redirectTargets($form, $viewer),
            // M131 (`R-6017d6d8`, `D81`) — the form's preset theme, and every preset as the Theme section and the
            // builder preview draw it. Transmitted from `FormThemePreset`, so no client file holds a colour.
            'theme_preset' => FormThemePreset::fromTheme($form->theme)?->value,
            'theme_presets' => FormThemePreset::catalogue(),
            // The form's locale set, so the section can offer one message box per supported locale.
            'default_locale' => $form->default_locale,
            'supported_locales' => $form->supported_locales === [] ? [$form->default_locale] : array_values($form->supported_locales),
        ];
    }

    /**
     * The forms an author may send a respondent to: every other form they may author, archived ones aside, by title.
     * The author's own list, through the scope the forms list uses, so the picker never names a form they cannot
     * open. Empty without a viewer — a presenter call outside a request has nobody to ask on behalf of.
     *
     * @return list<array{id: string, title: string, live: bool}>
     */
    private function redirectTargets(Form $form, ?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        return array_values(Form::query()
            ->visibleTo($viewer)
            ->whereKeyNot($form->id)
            ->where('status', '!=', FormStatus::Archived->value)
            ->orderBy('title')
            ->get()
            ->map(static fn (Form $target): array => [
                'id' => $target->id,
                'title' => $target->title,
                'live' => GuestReachability::reachable($target),
            ])
            ->all());
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
     * The Files section's list (M132, `R-bf49e4c1`): the reference files the form's DRAFT shows, which respondents
     * see once it is published. Beside the `form` block rather than inside it, because that block's keys are what
     * the builder spreads and `FormSettingsPageTest` pins. Empty for a form with no draft (an archived one).
     *
     * @return list<array<string, mixed>>
     */
    public function referenceFiles(Form $form): array
    {
        $draft = $form->draft_version_id === null ? null : FormVersion::query()->whereKey($form->draft_version_id)->first();

        return $draft === null ? [] : $this->referenceFileService->forAuthor($draft);
    }

    /**
     * The Automations section (M132, `R-b7bc5149`): the form's automations as THIS viewer may see them — a web address
     * whole only to whoever may manage webhooks. Beside the `form` block, for the Reference files section's reason.
     *
     * @return array{can_webhook: bool, max: int, items: list<array<string, mixed>>}
     */
    public function automations(Form $form, ?User $viewer): array
    {
        return $this->automationPresenter->forForm($form, $viewer);
    }

    /**
     * The Data sharing section (M133, `R-5da4a30f` — Connect project v1's source key), or null for a viewer whose
     * save would be refused: `D87` lets only someone who can read this form's responses share them, and the PATCH
     * route asks exactly {@see ResponseReadAccess::canRead()}'s two gates. Beside the `form` block, for the
     * Reference files section's reason.
     *
     * `questions` are the shareable questions of the CURRENT PUBLISHED version ({@see ShareableQuestions}) — a
     * draft-only question has no answers. `used_by` lists the forms whose published version takes choices from this
     * one and that this viewer may open; `used_by_others` counts the rest, so a form is never named to someone who
     * cannot see it and the count still tells the author the switch is in use.
     *
     * @return array{enabled: bool, field_keys: list<string>|null, published: bool, questions: list<array{key: string, label: string}>, used_by: list<array{id: string, title: string}>, used_by_others: int}|null
     */
    public function dataSharing(Form $form, ?User $viewer): ?array
    {
        if ($viewer === null || ! $this->responseAccess->canRead($viewer, $form)) {
            return null;
        }

        $snapshot = $form->current_published_version_id === null
            ? null
            : FormVersion::query()->whereKey($form->current_published_version_id)->value('schema_snapshot');
        $snapshot = is_array($snapshot) ? $snapshot : null;

        $linking = Form::query()
            ->whereKeyNot($form->id)
            ->whereIn('current_published_version_id', FormField::query()
                ->select('form_version_id')
                ->where('config->options_source->form_id', $form->id))
            ->orderBy('title');

        $readable = array_values((clone $linking)->readableBy($viewer)->get(['id', 'title'])
            ->map(static fn (Form $source): array => ['id' => (string) $source->id, 'title' => (string) $source->title])
            ->all());

        return [
            'enabled' => $form->data_sharing_enabled === true,
            'field_keys' => $form->dataSharingFieldKeys(),
            'published' => $snapshot !== null,
            'questions' => array_map(
                static fn (array $q): array => ['key' => $q['key'], 'label' => $q['label']],
                ShareableQuestions::fromSnapshot($snapshot),
            ),
            'used_by' => $readable,
            'used_by_others' => max(0, $linking->count() - count($readable)),
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
            'form' => ['id' => $form->id, ...$this->form($form, $user)],
            'share' => $this->share->present($form),
            'timezones' => DateTimeZone::listIdentifiers(),
            'ocr_scanning' => $this->ocrScanning($form),
            'reference_files' => $this->referenceFiles($form),
            'automations' => $this->automations($form, $user),
            'data_sharing' => $this->dataSharing($form, $user),
            'scope' => $user->can('viewAny', ScopeNode::class)
                ? ['current_node_id' => $form->scope_node_id, 'options' => $this->scopes->pickerOptions()]
                : null,
        ];
    }
}

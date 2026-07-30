<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\SubmissionStatus;
use App\Http\Middleware\RequireFeature;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Services\Entitlements\EntitlementService;
use App\Support\Forms\FormScheduleView;

/**
 * Shapes a published form + version for the public guest runtime (Increment F5). Thin by design: the
 * version's `schema_snapshot` is already the canonical, id-free, render-ready structure frozen at publish
 * (data-dictionary §3 — "the shape consumed by the public runtime and the offline PWA client"), so this
 * only wraps it with the form/version metadata the renderer needs. The `checksum` travels so the runtime
 * (F6) can pin the exact schema it rendered against the one a submission is stored under.
 */
final class PublicFormPresenter
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Form $form, FormVersion $version): array
    {
        return [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'default_locale' => $form->default_locale,
                // The form's own locale subset for the runtime language switcher (Increment F6b, UX §6).
                'supported_locales' => $this->supportedLocales($form),
                // Single-page vs. multi-step presentation (UX §3.1); the SPA reads it to pick its flow.
                'single_page_mode' => $form->single_page_mode,
                // Whether the SPA should offer "Save and finish later" (H10, UX §5.2). Both gates must pass:
                // the per-form opt-in AND the tenant plan. The plan half mirrors RequireFeature exactly —
                // fail-open when no catalog resolves — so this flag and the draft route never disagree. The
                // route is still authoritative; this only decides whether the control is shown.
                'save_and_resume' => $form->save_and_resume && $this->tenantAllowsSaveResume(),
                // The author-editable confirmation copy (Increment H6a, Doc #26 §6.2), emitted RAW — as a
                // template, with its `${key}` holes unfilled — plus its locale variants. Two reasons it is
                // not rendered here: `version.schema` below travels VERBATIM with a checksum the runtime
                // pins against, so interpolating server-side would break that pin; and the respondent picks
                // their locale client-side and reactively, so the server does not know which variant to
                // resolve. §4's normative order (resolve the locale, THEN render) can only be honoured on
                // the client. H6b builds that renderer; until then the runtime keeps its hardcoded default
                // when this is null.
                'confirmation_message' => $form->confirmation_message,
                'confirmation_message_translations' => $form->confirmation_message_translations,
                // Scheduled-form window + response cap (Increment H12a). Advisory: the runtime (H12b) reads
                // `acceptance` to show an "opens soon"/"closed"/"full" state and `remaining` for a cap count.
                // Enforcement is authoritative in the write path ({@see FormAcceptanceGuard}); the schema is
                // still returned for a closed form so the runtime can render the closed state.
                'schedule' => $this->schedule($form),
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'checksum' => $version->checksum,
                'schema' => $version->schema_snapshot,
            ],
        ];
    }

    /**
     * The scheduled-form runtime block (Increment H12a). `acceptance` is the live label; `remaining` is the
     * cap headroom (null when uncapped). The live finalized COUNT is taken only when a cap exists, so an
     * uncapped form costs no extra query. The explicit array shape keeps the generated OpenAPI types precise.
     *
     * @return array{opens_at: ?string, closes_at: ?string, timezone: string, max_responses: ?int, acceptance: string, remaining: ?int}
     */
    private function schedule(Form $form): array
    {
        $cap = $form->max_responses;
        $finalizedCount = $cap === null ? null : $this->finalizedCount($form);

        return FormScheduleView::present($form, $finalizedCount);
    }

    /** The live count of finalized (non-draft) submissions for this form, RLS-scoped to the tenant. */
    private function finalizedCount(Form $form): int
    {
        return Submission::query()
            ->where('form_id', $form->id)
            ->where('status', '!=', SubmissionStatus::Draft->value)
            ->count();
    }

    /**
     * Whether the tenant plan permits save-and-resume, mirroring {@see RequireFeature}'s
     * fail-open rule: a request passes unless a plan resolves AND denies the feature. So an unseeded/dev tenant
     * (no catalog) allows it, and a seeded Free tenant denies it — exactly what the draft route enforces.
     */
    private function tenantAllowsSaveResume(): bool
    {
        return $this->entitlements->currentPlan() === null || $this->entitlements->feature('save_and_resume');
    }

    /**
     * The form's supported locales, falling back to just its default locale when none are configured (the
     * column defaults to []). Typed as a plain list so the generated OpenAPI stays a simple string array.
     *
     * @return list<string>
     */
    private function supportedLocales(Form $form): array
    {
        $locales = $form->supported_locales === [] ? [$form->default_locale] : $form->supported_locales;

        return array_values($locales);
    }
}

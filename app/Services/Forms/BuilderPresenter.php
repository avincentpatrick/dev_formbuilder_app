<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\FieldLibrary;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Support\Forms\FormSlug;
use App\Support\Tenancy\TenantUrl;
use DateTimeZone;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Read model for the interactive builder (Increment D4a). Hydrates the form's current draft version into
 * the flat, id-stable shape the Vue builder edits — plus the palette catalog (all 31 field types grouped
 * by category, from the {@see FieldType} enum, the single source) and the enum option lists the config
 * panels bind to. `version` on each row is the optimistic-concurrency token ({@see FormBuilderService::rowVersion}).
 */
final class BuilderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Form $form): array
    {
        $draft = $form->draft_version_id !== null
            ? FormVersion::query()->whereKey($form->draft_version_id)->first()
            : null;

        $sections = $draft
            ? $draft->sections()->orderBy('sequence')->get()->map(fn (FormSection $s) => $this->section($s))->all()
            : [];

        $validationsByField = $draft
            ? $draft->validations()->orderBy('sequence')->get()->groupBy('form_field_id')
            : collect();
        $fieldKeyById = $draft ? $draft->fields()->pluck('key', 'id') : collect();

        $fields = $draft
            ? $draft->fields()->orderBy('sequence')->get()
                ->map(fn (FormField $f) => $this->field($f, $validationsByField->get($f->id), $fieldKeyById))
                ->all()
            : [];

        return [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'status' => $form->status->value,
                // Per-form save-and-resume opt-in (H10) — drives the builder toggle; the guest runtime reads its
                // own effective flag (tenant plan AND this) from PublicFormPresenter.
                'save_and_resume' => $form->save_and_resume,
                // Raw schedule values (Increment H12b) — the Schedule modal prefills from these (the ISO instants
                // are rendered back into `timezone` for the datetime-local inputs). Enforcement uses `acceptance`
                // on the runtime presenters; the builder only needs the raw window + cap to round-trip a PATCH.
                'opens_at' => $form->opens_at?->toIso8601String(),
                'closes_at' => $form->closes_at?->toIso8601String(),
                'timezone' => $form->timezone,
                'max_responses' => $form->max_responses,
                // The confirmation template + its locale variants (Increment H6a) — raw, so the Confirmation
                // modal round-trips exactly what the author wrote. The builder never renders a template:
                // an author needs to see `${child_name}`, not a value there is no submission to supply.
                'confirmation_message' => $form->confirmation_message,
                'confirmation_message_translations' => $form->confirmation_message_translations ?? [],
                // The form's locale set, so the modal can offer one message box per supported locale.
                'default_locale' => $form->default_locale,
                'supported_locales' => $form->supported_locales === [] ? [$form->default_locale] : array_values($form->supported_locales),
            ],
            'share' => $this->share($form),
            'draft' => $draft ? [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
            ] : null,
            'sections' => $sections,
            'fields' => $fields,
            'palette' => $this->palette(),
            'enums' => $this->enums(),
            'library' => $this->libraryList(),
            // The canonical IANA identifier list for the Schedule modal's timezone <select> (Increment H12b).
            // Sourced server-side so every option is guaranteed to pass UpdateFormScheduleRequest's
            // Rule::in(DateTimeZone::listIdentifiers()) — a client-built list could drift and 422.
            'timezones' => DateTimeZone::listIdentifiers(),
        ];
    }

    /**
     * The share surface's read model (Increment I1, PRD Feature #3) — everything the Share modal needs to
     * describe the form's public link without guessing at any of it.
     *
     * ── THE URL IS COMPOSED HERE, NEVER IN THE BROWSER ────────────────────────────────────────────────
     * `TenantUrl` has two arms and picking between them is a security decision (its class docblock): `to()`
     * is the APP arm and never returns a custom domain; `toPublic()` prefers one. A custom host serves the
     * guest runtime and nothing else (ADR-0012 §D1, from ADR-0009 §D2), so a respondent-facing link is
     * `toPublic()` by definition. There is no JS-side URL helper and no Ziggy in this app precisely so that
     * this cannot be re-decided per page: building the link from `window.location` in the modal would emit
     * the app host and silently hand every custom-domain tenant the wrong URL to print on a flyer.
     * `DomainPresenter` states the same rule for its own row: the public host is READ from `TenantUrl`,
     * never re-derived.
     *
     * `public_url` is null whenever the slug is — the modal renders a "no link yet" state rather than a
     * plausible-looking URL that 404s. Same for `is_published`: all three of GuestFormController's gates
     * answer 404, so a live-looking link on an unpublished form would be a link to a dead end with no
     * explanation attached.
     *
     * `suggested_slug` is computed server-side through the same {@see FormSlug} the XLSForm importer uses, so
     * the editor opens on a value that is already free rather than one the author discovers is taken only
     * after a 422.
     *
     * @return array<string, mixed>
     */
    private function share(Form $form): array
    {
        $tenant = $this->tenantFor($form);

        $slug = $form->public_slug;

        return [
            'public_slug' => $slug,
            'allow_guest_submissions' => $form->allow_guest_submissions,
            // Spam protection (I8b) — saved by the same button as the two above, which is why it lives in
            // this block rather than in one of its own.
            'bot_challenge' => $form->bot_challenge->value,
            'guest_rate_limit_per_minute' => $form->guest_rate_limit_per_minute,
            'suggested_slug' => $slug ?? FormSlug::suggest($form),
            'is_published' => $form->current_published_version_id !== null,
            'public_host' => TenantUrl::publicHost($tenant),
            'public_url' => $slug === null ? null : TenantUrl::toPublic($tenant, 'f/'.$slug),
        ];
    }

    /**
     * The tenant whose hosts serve this form — taken from the FORM, not from ambient state.
     *
     * The container binding is used when it is already the right tenant, which is the normal HTTP path and
     * spares a query on every builder render. Otherwise the form's own `tenant_id` answers it. That fallback
     * is not defensive padding: `app(TenantContract::class)` resolves to NULL wherever stancl's tenancy was
     * never initialized — a console command, a queued job, and any test that establishes the RLS context with
     * `enterTenant()` (which sets the GUC but binds no Tenant). A pre-existing schedule test called
     * `present()` that way and turned this into a TypeError the moment the share block landed.
     *
     * Reading it off the form is also simply more correct: the form row carries the authoritative
     * `tenant_id`, so the answer cannot disagree with the record being presented.
     */
    private function tenantFor(Form $form): Tenant
    {
        $bound = app()->bound(TenantContract::class) ? app(TenantContract::class) : null;

        if ($bound instanceof Tenant && $bound->getKey() === $form->tenant_id) {
            return $bound;
        }

        return Tenant::query()->whereKey($form->tenant_id)->firstOrFail();
    }

    /**
     * The question-library picker data (Increment G9b): the tenant's own + all platform items, active only,
     * "popular" first. RLS returns own + platform from a plain `query()`; delivered as an Inertia prop
     * (the builder's fetch sidecar has no GET) alongside `palette`/`enums`, refetchable via `forms.library-items`.
     *
     * @return list<array<string, mixed>>
     */
    public function libraryList(): array
    {
        return array_values(
            FieldLibrary::query()
                ->where('is_active', true)
                ->orderByDesc('usage_count')
                ->orderByDesc('id')
                ->get()
                ->map(fn (FieldLibrary $item): array => $this->libraryItem($item))
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function libraryItem(FieldLibrary $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'category' => $item->category,
            'field_type' => $item->field_type,
            'usage_count' => $item->usage_count,
            'is_platform' => $item->tenant_id === null,
        ];
    }

    /**
     * @param  Collection<int, FormFieldValidation>|null  $validations
     * @param  Collection<int|string, string>  $fieldKeyById
     * @return array<string, mixed>
     */
    public function field(FormField $field, $validations = null, $fieldKeyById = null): array
    {
        $validations ??= $field->validations()->orderBy('sequence')->get();
        $fieldKeyById ??= FormField::query()->where('form_version_id', $field->form_version_id)->pluck('key', 'id');

        return [
            'id' => $field->id,
            'form_section_id' => $field->form_section_id,
            'key' => $field->key,
            'field_type' => $field->field_type->value,
            'label' => $field->label,
            'hint' => $field->hint,
            'placeholder' => $field->placeholder,
            'is_required' => $field->is_required->value,
            'relevant_expression' => $field->relevant_expression,
            'appearance' => $field->appearance,
            'config' => (object) ($field->config ?? []),
            'default_value' => $field->default_value,
            'is_pii' => $field->is_pii,
            'is_sensitive' => $field->is_sensitive,
            'is_queryable' => $field->is_queryable,
            'indexed_data_type' => $field->indexed_data_type?->value,
            'sequence' => $field->sequence,
            'section_sequence' => $field->section_sequence,
            'version' => FormBuilderService::rowVersion($field),
            'validations' => $validations->map(fn (FormFieldValidation $v) => [
                'rule_type' => $v->rule_type?->value,
                'operator' => $v->operator?->value,
                'rule_value' => $v->rule_value,
                'expression' => $v->expression,
                'error_message' => $v->error_message,
                'related_field_key' => $v->related_form_field_id !== null
                    ? $fieldKeyById->get($v->related_form_field_id)
                    : null,
                'sequence' => $v->sequence,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(FormSection $section): array
    {
        return [
            'id' => $section->id,
            'key' => $section->key,
            'label' => $section->label,
            'description' => $section->description,
            'is_repeatable' => $section->is_repeatable,
            'min_instances' => $section->min_instances,
            'max_instances' => $section->max_instances,
            'relevant_expression' => $section->relevant_expression,
            'sequence' => $section->sequence,
            'version' => FormBuilderService::rowVersion($section),
        ];
    }

    /**
     * The palette: every FieldType grouped by category, with labels/icons + the advanced flag. Built from
     * the enum so the frontend never re-lists the 31 types.
     *
     * @return list<array<string, mixed>>
     */
    private function palette(): array
    {
        $groups = [];

        foreach (FieldType::cases() as $type) {
            $category = $type->category();
            $groups[$category->value]['category'] = $category->value;
            $groups[$category->value]['label'] = $category->label();
            $groups[$category->value]['icon'] = $category->icon();
            $groups[$category->value]['types'][] = [
                'value' => $type->value,
                'label' => $type->label(),
                'advanced' => $type->isAdvanced(),
                'has_options' => $type->hasOptions(),
                'config_editor' => $type->configEditor(),
            ];
        }

        return array_values($groups);
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function enums(): array
    {
        return [
            'required_modes' => [
                ['value' => RequiredMode::Optional->value, 'label' => 'Optional'],
                ['value' => RequiredMode::Required->value, 'label' => 'Required'],
                ['value' => RequiredMode::Conditional->value, 'label' => 'Conditional'],
            ],
            'indexed_data_types' => array_map(
                fn (IndexedDataType $t) => ['value' => $t->value, 'label' => ucfirst($t->value)],
                IndexedDataType::cases(),
            ),
            'validation_rule_types' => array_map(
                fn (ValidationRuleType $t) => ['value' => $t->value, 'label' => $this->humanize($t->value)],
                ValidationRuleType::cases(),
            ),
            'comparison_operators' => array_map(
                fn (ComparisonOperator $t) => ['value' => $t->value, 'label' => $this->humanize($t->value)],
                ComparisonOperator::cases(),
            ),
        ];
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}

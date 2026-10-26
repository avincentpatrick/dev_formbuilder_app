<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FieldType;
use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureBuilderDraftCurrent;
use App\Http\Requests\Forms\SaveFieldToLibraryRequest;
use App\Http\Requests\Forms\StoreFieldFromLibraryRequest;
use App\Http\Requests\Forms\StoreFieldRequest;
use App\Http\Requests\Forms\UpdateFieldRequest;
use App\Http\Requests\Forms\UpdateSectionRequest;
use App\Models\FieldLibrary;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FieldHistoryPresenter;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\StepGraphInspector;
use App\Support\Forms\GraphNotice;
use App\Support\Navigation\CrumbTrail;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The interactive builder (Increment D4a). `show` renders the three-pane workspace; every other method is a
 * fine-grained JSON mutation the builder's CSRF fetch sidecar calls (NOT Inertia visits — the builder keeps
 * its own undo/redo state and must not full-reload on each edit). All routes are gated by `can:update,form`;
 * the draft-only RLS guard + optimistic-concurrency 409 live in {@see FormBuilderService}. This controller
 * stays thin and maps the two builder exceptions to their HTTP status in one place ({@see self::respond()}).
 */
final class FormBuilderController extends Controller
{
    public function __construct(
        private readonly FormBuilderService $builder,
        private readonly BuilderPresenter $presenter,
    ) {}

    /**
     * ⚠️ `Request` IS HERE ONLY FOR THE TRAIL, and only `show()` gained it — the JSON mutation methods below
     * read nothing about who is asking (`can:update,form` on the route is the whole authorization story).
     *
     * The builder takes the BREADCRUMB and not the tab strip (user decision, J2b): it is a three-pane
     * workspace on a `height: 100%` grid, and a second ~44px header row costs the one screen that cannot
     * spare it. So this trail is the builder's only way back, which is why its hub crumb being hard-coded
     * mattered — `can:update,form` does not imply `viewOverview`, it merely coincides with it for all five
     * shipped roles.
     */
    public function show(Request $request, Form $form): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/Builder', [
            ...$this->presenter->present($form, $user),
            'crumbs' => CrumbTrail::forms($user)->form($form)->current('Builder'),
        ]);
    }

    // ── Sections ─────────────────────────────────────────────────────────────────

    public function storeSection(Form $form): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->section($this->builder->addSection($form)));
    }

    public function updateSection(UpdateSectionRequest $request, Form $form, FormSection $section): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->section(
            $this->builder->updateSection($form, $section, $request->validated(), $request->input('version')),
        ));
    }

    public function destroySection(Request $request, Form $form, FormSection $section): JsonResponse
    {
        return $this->respond(function () use ($request, $form, $section): array {
            $this->builder->deleteSection($form, $section, $this->actor($request));

            return ['deleted' => true];
        });
    }

    // ── Fields ───────────────────────────────────────────────────────────────────

    public function storeField(StoreFieldRequest $request, Form $form): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->field($this->builder->addField(
            $form,
            $this->actor($request),
            FieldType::from((string) $request->string('field_type')),
            $request->input('section_id'),
        )));
    }

    /**
     * ⛔ `payload()`, NOT `validated()` (M111, `R-d5d6db11`). `validated()` rebuilds `config` from the
     * enumerated `config.*` paths only, so every key no `configRules()` arm lists was dropped here on a
     * 200 OK. The accessor puts the raw config back under the validated tree; the reasoning is at
     * `UpdateFieldRequest::payload()`.
     */
    public function updateField(UpdateFieldRequest $request, Form $form, FormField $field): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->field($this->builder->updateField(
            $form,
            $field,
            $this->actor($request),
            $request->payload(),
            $request->input('version'),
        )));
    }

    public function destroyField(Form $form, FormField $field): JsonResponse
    {
        return $this->respond(function () use ($form, $field): array {
            $this->builder->deleteField($form, $field);

            return ['deleted' => true];
        });
    }

    /**
     * M172 (`R-a44638b6`, `D133`) — the number the delete dialog states before a question responses answered is deleted
     * from the draft. A READ: no `builder.draft` check and no `respond()` mapping. A question of another form is not this
     * form's to count.
     */
    public function answeredCount(Form $form, FormField $field): JsonResponse
    {
        abort_unless($field->version()->where('form_id', $form->id)->exists(), 404);

        return response()->json(['responses' => $this->builder->answeredCount($form, $field)]);
    }

    /**
     * M188 (`R-3894369d`, `D167`, `D168`) — a question's history for the right panel's History tab, newest first, 50 at a
     * time (`?before=<entry id>` for the next 50). A READ, like {@see self::answeredCount()}: no `builder.draft` check and
     * no `respond()` mapping, and a question of another form is not this form's to read.
     */
    public function history(Request $request, Form $form, FormField $field, FieldHistoryPresenter $history): JsonResponse
    {
        abort_unless($field->version()->where('form_id', $form->id)->exists(), 404);
        $before = $request->query('before');

        return response()->json($history->present($form, $field, is_string($before) ? $before : null));
    }

    public function duplicateField(Request $request, Form $form, FormField $field): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->field(
            $this->builder->duplicateField($form, $this->actor($request), $field),
        ));
    }

    // ── Question library (Increment G9b) ──────────────────────────────────────────

    public function storeFieldFromLibrary(StoreFieldFromLibraryRequest $request, Form $form): JsonResponse
    {
        // RLS-scoped to own + platform; is_active narrows out a deactivated item (a validated-but-inactive id 404s).
        $item = FieldLibrary::query()
            ->where('is_active', true)
            ->whereKey($request->validated('library_item_id'))
            ->firstOrFail();

        return $this->respond(fn (): array => $this->presenter->field($this->builder->insertFromLibrary(
            $form,
            $this->actor($request),
            $item,
            $request->input('section_id'),
        )));
    }

    public function saveFieldToLibrary(SaveFieldToLibraryRequest $request, Form $form, FormField $field): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->libraryItem($this->builder->saveFieldToLibrary(
            $form,
            $this->actor($request),
            $field,
            $request->validated(),
        )));
    }

    public function libraryItems(Form $form): JsonResponse
    {
        return response()->json($this->presenter->libraryList());
    }

    // ── Relevance graph (Increment H21d1) ────────────────────────────────────────

    /**
     * The DRAFT's relevance-graph notices, for the builder's Logic view (Doc #27 §6/§8).
     *
     * A read, so it does not go through {@see self::respond()} — nothing here mutates, there is no
     * optimistic-concurrency token to drift and no `FormException` to map. It reads the DRAFT and never a
     * published version: the Logic view sits inside the editor, and what an author needs to see is the graph
     * they are editing, not the one their respondents are currently walking.
     *
     * `['notices' => []]` with no draft rather than a 404 — that is the page's existing read-only state
     * (`Builder.vue`'s `readOnly`), and an empty result reads correctly there while an error would not.
     *
     * These are the SAME notices `FormPublishController` flashes after a successful publish, which is the
     * whole point: the author now meets them while there is still something to do about it.
     */
    public function graph(Form $form, StepGraphInspector $inspector): JsonResponse
    {
        $draft = $form->draftVersion;

        if ($draft === null) {
            return response()->json(['notices' => []]);
        }

        return response()->json([
            'notices' => array_map(
                static fn (GraphNotice $notice): array => $notice->toArray(),
                $inspector->notices($draft),
            ),
        ]);
    }

    // ── Reorder (structural) ──────────────────────────────────────────────────────

    public function reorder(Request $request, Form $form): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['present', 'array'],
            'sections.*.id' => ['required', 'uuid'],
            'sections.*.sequence' => ['required', 'integer'],
            'fields' => ['present', 'array'],
            'fields.*.id' => ['required', 'uuid'],
            'fields.*.form_section_id' => ['nullable', 'uuid'],
            'fields.*.sequence' => ['required', 'integer'],
            'fields.*.section_sequence' => ['nullable', 'integer'],
        ]);

        return $this->respond(function () use ($request, $form, $data): array {
            $this->builder->reorder($form, $this->actor($request), $data['sections'], $data['fields']);

            return ['ok' => true];
        });
    }

    /**
     * Run a builder mutation and JSON-encode its result, mapping the builder exceptions to status:
     *  - 409 + the fresh row on optimistic-concurrency drift (the ConflictDialog reconciles);
     *  - 409 `draft_superseded` when the row is in a version a publish has moved past (M172, `R-81756e95`) — the
     *    race `builder.draft` cannot see; the builder reloads and replays its unsaved edits by key;
     *  - 422 + the message on any other draft-guard / no-draft violation (the builder shows the error).
     *
     * @param  Closure(): array<string, mixed>  $op
     */
    private function respond(Closure $op): JsonResponse
    {
        try {
            return response()->json($op());
        } catch (BuilderConflictException $e) {
            $current = $e->current instanceof FormField
                ? $this->presenter->field($e->current)
                : $this->presenter->section($e->current);

            return response()->json(['message' => $e->getMessage(), 'current' => $current], 409);
        } catch (FormException $e) {
            $form = request()->route('form');

            if ($e->isChildSuperseded() && $form instanceof Form) {
                return EnsureBuilderDraftCurrent::superseded($form);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

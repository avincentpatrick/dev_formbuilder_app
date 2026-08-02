<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FieldType;
use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Http\Controllers\Controller;
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
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\StepGraphInspector;
use App\Support\Forms\GraphNotice;
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

    public function show(Form $form): Response
    {
        return Inertia::render('forms/Builder', $this->presenter->present($form));
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

    public function destroySection(Form $form, FormSection $section): JsonResponse
    {
        return $this->respond(function () use ($form, $section): array {
            $this->builder->deleteSection($form, $section);

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

    public function updateField(UpdateFieldRequest $request, Form $form, FormField $field): JsonResponse
    {
        return $this->respond(fn (): array => $this->presenter->field($this->builder->updateField(
            $form,
            $field,
            $this->actor($request),
            $request->validated(),
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

        return $this->respond(function () use ($form, $data): array {
            $this->builder->reorder($form, $data['sections'], $data['fields']);

            return ['ok' => true];
        });
    }

    /**
     * Run a builder mutation and JSON-encode its result, mapping the two builder exceptions to status:
     *  - 409 + the fresh row on optimistic-concurrency drift (the ConflictDialog reconciles);
     *  - 422 + the message on a draft-guard / no-draft violation (the builder shows an error + refetches).
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

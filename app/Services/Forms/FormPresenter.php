<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use Illuminate\Support\Collection;

/**
 * Read model for the forms list page (Increment D3). Presents every non-archived form in the current
 * tenant with its version history and the viewer's per-form abilities (resolved through FormPolicy).
 * Keeps the controller thin, mirroring TenantMembershipService::listMembers.
 */
final class FormPresenter
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $forms = Form::query()
            ->with(['versions' => fn ($q) => $q->orderByDesc('version_number')])
            // Scope to what the viewer can actually author (Increment G10b). Until G10b this returned every
            // non-archived form in the tenant to anyone passing `viewAny`, with only the per-row `can`
            // flags differing — so a Form Editor saw the titles and version history of forms they hold no
            // grant on. Submissions always had a list twin (`Submission::scopeVisibleTo`); forms did not.
            //
            // J1b EXTRACTED that rule to `Form::scopeVisibleTo()` so global search shares it rather than
            // encoding it a third time — see the scope's docblock for why it must not be folded into
            // `AnalyticsFormSet::visible()`, which answers a different question and would show a Viewer
            // every form in the tenant. The proof this extraction moved nothing is that
            // `FormListScopingTest` passes unedited.
            ->visibleTo($user)
            ->where('status', '!=', FormStatus::Archived->value)
            ->orderByDesc('updated_at')
            ->get();

        // Resolve every scope node on this page in ONE query before the per-row `can()` checks below
        // start asking "does this user hold a grant here" (G10a). Without it each form with a node costs
        // an extra lookup per policy call — five per row — turning the list into an N+1.
        $this->grants->primeNodePaths($forms);

        return array_values($forms->map(fn (Form $form): array => $this->present($form, $user))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Form $form, User $user): array
    {
        /** @var Collection<int, FormVersion> $versions */
        $versions = $form->versions;

        return [
            'id' => $form->id,
            'title' => $form->title,
            'description' => $form->description,
            'status' => $form->status->value,
            // The scope picker's current value (G10b2). The picker itself is gated on `scopes.manage`; this
            // is just the form's own column, already visible to anyone who can see the row.
            'scope_node_id' => $form->scope_node_id !== null ? (string) $form->scope_node_id : null,
            'current_version' => $this->versionNumber($versions, $form->current_published_version_id),
            'draft_version' => $this->versionNumber($versions, $form->draft_version_id),
            'updated_at' => $form->updated_at?->toIso8601String(),
            'versions' => $versions->map(fn (FormVersion $v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status->value,
                'published_at' => $v->published_at?->toIso8601String(),
            ])->all(),
            'can' => [
                'edit' => $user->can('update', $form),
                'publish' => $user->can('publish', $form),
                'delete' => $user->can('delete', $form),
                // Manual-encode entry point (F4b) — the SubmissionPolicy folds in "form is published",
                // so this is false for a draft-only form and the row action stays hidden until publish.
                'encode' => $user->can('create', [Submission::class, $form]),
                // "Save as template" (G9a) — a read/derive of the form, so it gates on view, not update.
                'template' => $user->can('view', $form),
                // Per-form response statistics (I10c) — also a read/derive, so also `view`. Named separately
                // rather than reusing `can.template`: FormPolicy::view and ::update resolve to the same
                // predicate TODAY, and a row action riding on that coincidence would follow the wrong one the
                // day they diverge. It must match the route's own gate exactly: FormListScopingTest pins the
                // key here, and FormAnalyticsGateTest pins the route's refusals — neither alone would.
                'analytics' => $user->can('view', $form),
            ],
        ];
    }

    /**
     * @param  Collection<int, FormVersion>  $versions
     */
    private function versionNumber($versions, ?string $versionId): ?int
    {
        if ($versionId === null) {
            return null;
        }

        return $versions->firstWhere('id', $versionId)?->version_number;
    }
}

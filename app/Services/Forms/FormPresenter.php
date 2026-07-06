<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read model for the forms list page (Increment D3). Presents every non-archived form in the current
 * tenant with its version history and the viewer's per-form abilities (resolved through FormPolicy).
 * Keeps the controller thin, mirroring TenantMembershipService::listMembers.
 */
final class FormPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $forms = Form::query()
            ->with(['versions' => fn ($q) => $q->orderByDesc('version_number')])
            ->where('status', '!=', FormStatus::Archived->value)
            ->orderByDesc('updated_at')
            ->get();

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

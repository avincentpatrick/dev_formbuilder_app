<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormTemplate;
use App\Models\User;

/**
 * Read model for the template gallery page (Increment G9a). Presents every template RLS returns to the viewer
 * — the platform (NULL-tenant) onboarding gallery PLUS the tenant's own saved templates — each with a light
 * card summary and the viewer's ability to instantiate it. The heavy `schema_blueprint` is never sent to the
 * list; only its field count is derived for the card. Platform templates sort first, then by usage ("popular",
 * onboarding-content-plan §4). Keeps the controller thin, mirroring {@see FormPresenter}.
 */
final class TemplateGalleryPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $canUse = $user->can('create', Form::class);

        $templates = FormTemplate::query()
            ->orderByRaw('(tenant_id IS NULL) DESC')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get();

        return array_values($templates->map(fn (FormTemplate $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'category' => $t->category,
            'field_count' => count($t->schema_blueprint['fields'] ?? []),
            'usage_count' => $t->usage_count,
            'is_platform' => $t->tenant_id === null,
            'can' => ['use' => $canUse],
        ])->all());
    }
}

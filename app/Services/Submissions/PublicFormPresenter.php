<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Models\Form;
use App\Models\FormVersion;

/**
 * Shapes a published form + version for the public guest runtime (Increment F5). Thin by design: the
 * version's `schema_snapshot` is already the canonical, id-free, render-ready structure frozen at publish
 * (data-dictionary §3 — "the shape consumed by the public runtime and the offline PWA client"), so this
 * only wraps it with the form/version metadata the renderer needs. The `checksum` travels so the runtime
 * (F6) can pin the exact schema it rendered against the one a submission is stored under.
 */
final class PublicFormPresenter
{
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
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'checksum' => $version->checksum,
                'schema' => $version->schema_snapshot,
            ],
        ];
    }
}

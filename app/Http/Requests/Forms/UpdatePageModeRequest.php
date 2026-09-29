<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Set a form's presentation mode — one page, or one section at a time (`D35`, UX §3.1).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — mirroring the
 * {@see UpdateSaveResumeRequest} and {@see AssignFormScopeRequest} precedents: the write is a dedicated
 * {@see FormService::setSinglePageMode} and never mass-assignment, which matters more here than it looks,
 * because `single_page_mode` IS in `Form::$fillable`.
 *
 * ⚠️ NO `feature:` MIDDLEWARE, AND THE MISSING SECOND GATE IS THE DECISION RATHER THAN A COPY ERROR.
 * {@see UpdateSaveResumeRequest}'s route stacks `feature:save_and_resume` on `can:update,form` because the
 * entitlement catalog holds a key for it. It holds none for presentation mode, and minting one here would
 * be a pricing decision rather than the enforcement of one. So this route copies `forms.schedule` — editing
 * rights alone — and a later reader who spots the asymmetry with save-resume should read this paragraph
 * rather than "fix" it.
 *
 * ⚠️ The payload key is the COLUMN name while the request, route and panel are named for the SETTING. The
 * column name encodes one of the two values this endpoint serves, so `single_page_mode` reads wrong at the
 * `false` end; keeping it on the wire is what lets the rule, the service and the column be read as one.
 */
final class UpdatePageModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'single_page_mode' => ['required', 'boolean'],
        ];
    }
}

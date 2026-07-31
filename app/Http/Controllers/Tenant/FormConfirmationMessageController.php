<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateConfirmationMessageRequest;
use App\Models\Form;
use App\Rules\ValidTemplate;
use App\Services\Forms\FormService;
use App\Services\Forms\TemplateValidationGate;
use Illuminate\Http\RedirectResponse;

/**
 * Set (or clear) a form's confirmation message (Increment H6a,
 * `docs/piping-output-encoding-design.md` §6.2).
 *
 * Its own route and controller rather than a field on FormController::update, mirroring
 * {@see FormSaveResumeController}: the write is a guarded {@see FormService::setConfirmationMessage}. The
 * route stacks only `can:update,form` — confirmation copy carries no tier feature.
 *
 * A session-authed web route, not `/api/v1`, so no OpenAPI operation is generated and redocly's
 * `operation-summary` rule does not apply to it.
 *
 * ── The A3 warning (Increment H6b) ──────────────────────────────────────────────────────────────────
 * Doc #26 amendment A3 left a residue: because this column lives on `forms` and is editable without a
 * publish, an edit can dangle a reference that nothing notices until the next publish refuses it — with
 * the error naming a message edited weeks earlier. H6b closes it as a WARNING, not a rejection, and the
 * distinction is load-bearing: the publish gate resolves against the version being PUBLISHED while this
 * can only resolve against the CURRENTLY PUBLISHED one, so an author who adds the field to their draft
 * and then writes the message referencing it is *correct* and would be refused by a 422. The write
 * always succeeds; the toast just says what will render blank. A form with no published version yet gets
 * no warning at all — there is nothing to resolve against, and inventing an answer would be dishonest.
 */
final class FormConfirmationMessageController extends Controller
{
    /**
     * Why each violation slug means the hole will not fill. Only the slugs reachable for THIS column: its
     * host is at the maximal position in flat scope, so `template_forward_reference` and
     * `template_cross_repeat_reference` cannot fire, and a grammar error never reaches here because
     * {@see ValidTemplate} already 422'd on the same attribute.
     */
    private const REASONS = [
        'unknown_template_reference' => 'is not a field on the published form',
        'template_source_not_pipeable' => 'is a field type that cannot be piped',
        'template_source_has_no_answer' => 'is a field that holds no answer',
        'template_references_section' => 'is a section rather than a field',
        'template_repeat_to_flat_reference' => 'is inside a repeating group',
    ];

    public function __construct(
        private readonly FormService $forms,
        private readonly TemplateValidationGate $templates,
    ) {}

    public function update(UpdateConfirmationMessageRequest $request, Form $form): RedirectResponse
    {
        /** @var array{confirmation_message?: ?string, confirmation_message_translations?: ?array<string, string>} $data */
        $data = $request->validated();

        $message = $data['confirmation_message'] ?? null;
        $message = $message !== null && trim($message) === '' ? null : $message;
        $translations = $data['confirmation_message_translations'] ?? null;

        $this->forms->setConfirmationMessage($form, $message, $translations);

        if ($message === null) {
            return back()->with('toast', ['type' => 'success', 'message' => 'Confirmation message reset to the default.']);
        }

        $warning = $this->danglingReferenceWarning($form, $message, $translations);

        // One flash key, one toast (the design-system §3.7 bridge), so the warning REPLACES rather than
        // follows the success copy — and still says the save happened, because it did.
        return back()->with('toast', $warning === null
            ? ['type' => 'success', 'message' => 'Confirmation message saved.']
            : ['type' => 'info', 'message' => 'Confirmation message saved. '.$warning]);
    }

    /**
     * @param  ?array<string, string>  $translations
     */
    private function danglingReferenceWarning(Form $form, string $message, ?array $translations): ?string
    {
        $version = $form->currentPublishedVersion;

        if ($version === null) {
            return null;
        }

        $violations = $this->templates->confirmationMessageViolations($version, $message, $translations);

        if ($violations === []) {
            return null;
        }

        $parts = [];
        foreach ($violations as $violation) {
            $reason = self::REASONS[$violation['slug']] ?? 'cannot be resolved';
            $parts[] = '“'.$violation['key'].'” '.$reason;
        }

        return 'It will render blank where '.implode('; ', array_unique($parts)).'.';
    }
}

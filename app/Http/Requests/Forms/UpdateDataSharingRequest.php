<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Forms\FormService;
use App\Support\Forms\ShareableQuestions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Let other forms of this workspace use this form's answers as their choices, or stop (M133, `R-5da4a30f` —
 * Connect project v1's source key).
 *
 * Its own request, for {@see UpdateOcrScanningRequest}'s reason: the write is a dedicated
 * {@see FormService::setDataSharing()}, and the two columns are not mass-assignable.
 *
 * The gate is at the route and is `D87`'s: `can:update,form`, then the person must be able to read this form's
 * responses (`can:viewOverview,form` and `can:viewAny` on submissions) — nobody shares what they cannot see.
 *
 * What this request owns:
 *   - **the acknowledgement.** Switching on needs `acknowledged: true`, which the section sends only from its
 *     dialog: a shared answer can be seen by anyone who opens a form that uses it, through its public link
 *     included;
 *   - **`field_keys`: null for every shareable question, otherwise a non-empty list** (the column's CHECK refuses
 *     `[]` too). Each key must name a shareable question of the CURRENT PUBLISHED version
 *     ({@see ShareableQuestions}) — a question only a draft holds has no answers yet, and a personal or sensitive
 *     one is never shared (`D86`);
 *   - **a form with nothing to share cannot switch on,** so the switch never reads "on" over an empty list.
 */
final class UpdateDataSharingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form, can:viewOverview,form, can:viewAny on submissions)
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'acknowledged' => ['exclude_unless:enabled,true', 'accepted'],
            'field_keys' => ['present', 'nullable', 'array', 'min:1'],
            'field_keys.*' => ['string', 'distinct', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'acknowledged.accepted' => 'Confirm that you understand who can see shared answers.',
            'field_keys.min' => 'Choose at least one question, or share them all.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $shareable = array_map(
                    static fn (array $question): string => $question['key'],
                    ShareableQuestions::fromSnapshot($this->publishedSnapshot()),
                );

                if ($this->boolean('enabled') && $shareable === []) {
                    $validator->errors()->add('enabled', $this->publishedSnapshot() === null
                        ? 'Publish this form before sharing its answers.'
                        : 'This form has no questions whose answers can be shared.');

                    return;
                }

                foreach ($this->fieldKeys() ?? [] as $key) {
                    if (! in_array($key, $shareable, true)) {
                        $validator->errors()->add('field_keys', 'A chosen question cannot be shared: it is not in the published form, or it is marked personal or sensitive.');

                        return;
                    }
                }
            },
        ];
    }

    /** @return list<string>|null */
    public function fieldKeys(): ?array
    {
        $keys = $this->input('field_keys');

        return is_array($keys) ? array_values(array_map('strval', $keys)) : null;
    }

    /** @return array<string, mixed>|null */
    private function publishedSnapshot(): ?array
    {
        $form = $this->route('form');
        if (! $form instanceof Form || $form->current_published_version_id === null) {
            return null;
        }

        $snapshot = FormVersion::query()->whereKey($form->current_published_version_id)->value('schema_snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }
}

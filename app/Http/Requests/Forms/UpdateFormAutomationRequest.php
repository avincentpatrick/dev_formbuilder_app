<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to one of a form's automations (M132, `R-b7bc5149`): its name, whether it is on, its addresses (email) or
 * its web address. The action itself never changes. Authorization is the route's `can:update,form` and
 * `can:manage,automation` (`FormAutomationPolicy`: a web-address automation is `webhooks.manage`'s).
 */
final class UpdateFormAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'enabled' => ['sometimes', 'boolean'],
            'recipients' => ['sometimes', 'array', 'min:1', 'max:5'],
            'recipients.*' => ['required', 'string', 'max:255', 'email:rfc', 'distinct:ignore_case'],
            'url' => ['sometimes', 'string', 'max:2048', new PublicHttpUrl],
            // M142 — null clears it: the automation runs for every response again.
            'condition' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the automation a name.',
            'recipients.min' => 'Add at least one email address.',
            'recipients.max' => 'An automation can email at most five addresses.',
            'recipients.*.email' => 'Each address must be a valid email address.',
            'recipients.*.distinct' => 'Each address may appear only once.',
        ];
    }

    /**
     * Only what was sent, so a toggle never rewrites the addresses.
     *
     * @return array{name?: string, enabled?: bool, recipients?: list<string>, url?: string, condition?: string|null}
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];

        if (array_key_exists('name', $validated)) {
            $changes['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('enabled', $validated)) {
            $changes['enabled'] = (bool) $validated['enabled'];
        }
        if (array_key_exists('recipients', $validated) && is_array($validated['recipients'])) {
            $changes['recipients'] = array_values(array_map(static fn (mixed $r): string => trim((string) $r), $validated['recipients']));
        }
        if (array_key_exists('url', $validated)) {
            $changes['url'] = trim((string) $validated['url']);
        }
        if (array_key_exists('condition', $validated)) {
            $changes['condition'] = is_string($validated['condition']) ? $validated['condition'] : null;
        }

        return $changes;
    }
}

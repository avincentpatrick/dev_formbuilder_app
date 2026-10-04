<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\FormAutomationAction;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new automation for a form (M132, `R-b7bc5149`): a name, an action, and the action's one setting — up to five email
 * addresses (`D82`), or a web address (`D83`). The route already carries `can:update,form`.
 *
 * **A web address receives the respondents' answers**, so adding one is `webhooks.manage`'s alone, exactly as a
 * workspace webhook is — refused here as a 403 before anything is validated. The plan half (`webhooks`) is checked by
 * the controller, which turns it into the 402 every other plan gate answers with.
 *
 * The address must be public (`PublicHttpUrl`, the workspace webhooks' rule) and is checked again before every send.
 * The accessors avoid `url()`, which `Request` already defines.
 */
final class StoreFormAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->input('action') !== FormAutomationAction::Webhook->value
            || ($this->user()?->can('webhooks.manage') ?? false);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'action' => ['required', Rule::enum(FormAutomationAction::class)],
            'recipients' => ['required_if:action,email', 'prohibited_unless:action,email', 'array', 'min:1', 'max:5'],
            'recipients.*' => ['required', 'string', 'max:255', 'email:rfc', 'distinct:ignore_case'],
            'url' => ['required_if:action,webhook', 'prohibited_unless:action,webhook', 'string', 'max:2048', new PublicHttpUrl],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the automation a name.',
            'recipients.required_if' => 'Add at least one email address.',
            'recipients.max' => 'An automation can email at most five addresses.',
            'recipients.*.email' => 'Each address must be a valid email address.',
            'recipients.*.distinct' => 'Each address may appear only once.',
            'url.required_if' => 'Add the web address to send to.',
        ];
    }

    public function automationName(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function automationAction(): FormAutomationAction
    {
        return FormAutomationAction::from((string) $this->validated('action'));
    }

    /**
     * @return list<string>|null
     */
    public function recipientList(): ?array
    {
        /** @var list<string>|null $recipients */
        $recipients = $this->validated('recipients');

        return $recipients === null ? null : array_map(static fn (string $r): string => trim($r), $recipients);
    }

    public function webhookUrl(): ?string
    {
        $url = $this->validated('url');

        return is_string($url) ? trim($url) : null;
    }
}

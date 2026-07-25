<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormService;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Set (or clear) a form's schedule + response cap (Increment H12a).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — mirroring the
 * {@see UpdateSaveResumeRequest} precedent: the write is a dedicated {@see FormService::setSchedule} (never
 * mass-assignment), and the route stacks `can:update,form`. Every field is optional so the same endpoint sets,
 * changes and clears a schedule; the `after:opens_at` ordering rule is applied only when an open time is
 * present (so an open-ended-start form that merely sets a close date still validates), backed by
 * {@see FormService::setSchedule}'s own ordering guard.
 */
final class UpdateFormScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, array<int, string|In>>
     */
    public function rules(): array
    {
        $closesAt = ['nullable', 'date'];

        if ($this->filled('opens_at')) {
            $closesAt[] = 'after:opens_at';
        }

        return [
            'opens_at' => ['nullable', 'date'],
            'closes_at' => $closesAt,
            'timezone' => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
            'max_responses' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function opensAt(): ?CarbonInterface
    {
        return $this->date('opens_at');
    }

    public function closesAt(): ?CarbonInterface
    {
        return $this->date('closes_at');
    }

    public function timezone(): string
    {
        return $this->string('timezone')->toString();
    }

    public function maxResponses(): ?int
    {
        return $this->filled('max_responses') ? $this->integer('max_responses') : null;
    }
}

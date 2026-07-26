<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormService;
use Carbon\CarbonImmutable;
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

    /**
     * The open/close instants (Increment H12b). The builder sends a NAIVE wall-clock (a native
     * `datetime-local` string, no offset) plus the chosen IANA `timezone`, so the wall-clock is interpreted
     * IN that zone to derive the absolute instant. Enforcement stays purely absolute
     * ({@see FormAcceptanceGuard} compares `now()`); `timezone` governs input interpretation + display only.
     * Both fields parse with the SAME zone, so the `after:opens_at` ordering (and {@see FormService::setSchedule}'s
     * backstop) is preserved regardless of zone.
     */
    public function opensAt(): ?CarbonInterface
    {
        return $this->scheduleInstant('opens_at');
    }

    public function closesAt(): ?CarbonInterface
    {
        return $this->scheduleInstant('closes_at');
    }

    /**
     * Parse a naive wall-clock in the submitted timezone, then NORMALIZE TO UTC. The UTC conversion is
     * load-bearing: Eloquent's datetime cast serializes a Carbon in its OWN timezone as a naive string, and
     * the `timestamptz` column reads that string back in the (UTC) session zone — so a non-UTC instant would
     * be stored with its offset silently dropped unless we convert here. A string that already carries an
     * explicit offset (an ISO/API caller) keeps its instant; the tz only fills in a bare wall-clock.
     */
    private function scheduleInstant(string $key): ?CarbonInterface
    {
        if (! $this->filled($key)) {
            return null;
        }

        return CarbonImmutable::parse((string) $this->input($key), $this->timezone())->utc();
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

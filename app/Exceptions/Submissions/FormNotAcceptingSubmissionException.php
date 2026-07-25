<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

use App\Exceptions\Entitlements\QuotaExceededException;
use App\Services\Submissions\FormAcceptanceGuard;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A scheduled form refused a submission (Increment H12a), raised by {@see FormAcceptanceGuard}. Three distinct
 * reasons, each with its own machine-readable code so the guest runtime (H12b) can render "opens soon" vs
 * "closed" vs "full" and an integration can branch:
 *  - `form_not_open`        — the form has an `opens_at` in the future.
 *  - `form_closed`          — the form has a `closes_at` in the past (a pre-close save-and-resume draft is
 *                             exempt by the grace window and never reaches here on promote).
 *  - `max_responses_reached` — the response cap is full (a transactional COUNT-under-RLS decided this).
 *
 * Modelled on the entitlement family ({@see QuotaExceededException}) — `code()` +
 * `status()` + `details()`, rendered in bootstrap/app.php. Status is 403 (the form exists; you may not submit
 * to it right now), deliberately distinct from the 402 upgrade prompts and the 409 version/content conflicts.
 */
final class FormNotAcceptingSubmissionException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    private function __construct(
        string $message,
        private readonly string $reasonCode,
        private readonly array $details,
    ) {
        parent::__construct($message);
    }

    public static function notYetOpen(CarbonInterface $opensAt): self
    {
        return new self(
            'This form is not open for responses yet.',
            'form_not_open',
            ['opens_at' => $opensAt->toIso8601String()],
        );
    }

    public static function closed(CarbonInterface $closesAt): self
    {
        return new self(
            'This form is closed and no longer accepting responses.',
            'form_closed',
            ['closes_at' => $closesAt->toIso8601String()],
        );
    }

    public static function capacityReached(int $max, int $current): self
    {
        return new self(
            'This form has reached its response limit and is no longer accepting responses.',
            'max_responses_reached',
            ['max_responses' => $max, 'current_count' => $current],
        );
    }

    /** The stable, machine-readable error code (never the HTTP status text, never translated). */
    public function code(): string
    {
        return $this->reasonCode;
    }

    /** The HTTP status for the /api/v1 envelope — 403 (the form exists; not accepting right now). */
    public function status(): int
    {
        return 403;
    }

    /**
     * Reason-specific context for the /api/v1 envelope's `details` (schedule boundary or cap figures).
     *
     * @return array<string, scalar|null>
     */
    public function details(): array
    {
        return $this->details;
    }
}

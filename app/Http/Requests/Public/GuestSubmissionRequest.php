<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The guest submit request (Increment F5). Authorization is the share-token middleware, not a policy, so
 * authorize() is true. Like the manual-encode request this asserts only the coarse shape — `answers` is a
 * key⇒value map; all per-field validation is the {@see SubmissionPipeline}, the single shared write path.
 * The guest-only optional inputs (contact email, an idempotency uuid, a locale) are shallow-validated here.
 */
final class GuestSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'guest_contact_email' => ['nullable', 'email', 'max:255'],
            'client_submission_uuid' => ['nullable', 'uuid'],
            'locale' => ['nullable', 'string', 'max:10'],
            // Increment G8b — offline device provenance (data-dictionary §7); bounded to the column widths.
            'device_id' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
            // Increment P3a — the lost-update baseline, carried on SUBMIT because a submit against an
            // existing draft performs a draft SAVE first (see GuestSubmissionController: capture final edits,
            // then promote). That save is a whole-document replace, so without this a stale device could
            // overwrite another device's answers AND finalize the row in one request — the same defect as on
            // the draft channel, but terminal, since no later save can undo a promotion.
            'base_content_checksum' => ['nullable', 'string', 'size:64'],
        ];
    }

    /**
     * The lost-update baseline, and whether one was CLAIMED at all.
     *
     * ⚠️ THIS CHANNEL CHECKS ONLY WHEN THE CLIENT MAKES A CLAIM, WHICH IS THE OPPOSITE POSTURE TO THE DRAFT
     * CHANNEL, AND THE ASYMMETRY IS DELIBERATE. A draft save is one tick of a live loop: refusing a client
     * that omits the token costs a retype and is the safe direction. A SUBMIT can arrive from the offline
     * OUTBOX, replayed hours later by a service worker from a row serialized by an earlier build — refusing
     * that strands a real, finished response that nothing can resubmit, which is a direct breach of the
     * offline-first promise this whole subsystem exists to keep. So an absent claim replays exactly as it did
     * before P3a, and a present-but-stale claim is refused. Our own client always sends one, including from
     * the outbox.
     */
    public function baseContentChecksum(): ?string
    {
        $value = $this->input('base_content_checksum');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** True when the request actually carried a baseline — see the accessor above for why absence is not a claim. */
    public function claimsBaseline(): bool
    {
        return $this->baseContentChecksum() !== null;
    }

    public function deviceId(): ?string
    {
        $value = $this->input('device_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function appVersion(): ?string
    {
        $value = $this->input('app_version');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = $this->input('answers', []);

        return is_array($answers) ? $answers : [];
    }

    public function clientSubmissionUuid(): ?string
    {
        $value = $this->input('client_submission_uuid');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function guestContactEmail(): ?string
    {
        $value = $this->input('guest_contact_email');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function localeInput(): ?string
    {
        $value = $this->input('locale');

        return is_string($value) && $value !== '' ? $value : null;
    }
}

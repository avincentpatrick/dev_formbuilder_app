<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationType;
use App\Enums\SubmissionPdfOutcome;
use App\Jobs\Submissions\GeneratePdfJob;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPresenter;
use App\Support\Connectors\Providers\SlackMessageFormatter;

/**
 * The words in a notification EMAIL (Increment I3). Pure — no database, no container, no config — so it
 * unit-tests against a payload array, the {@see SlackMessageFormatter}
 * posture.
 *
 * ── TWO renderings of one row, and BOTH are built here — AMENDED I4 ─────────────────────────────────────
 * ~~A `notifications` row stores `type` + `data` and nothing else; I4 renders the bell list from those on
 * the TS side.~~ It does not, and the reason is structural rather than stylistic. `data` is a SIX-ARM
 * discriminated union whose arms are authored only by {@see NotificationDispatcher}'s
 * call sites — `form_title` is nullable on the two review outcomes and absent entirely on `member_invited`
 * and `webhook_failed`; `form_id` is absent on `export_ready`. A TS renderer would be a second declaration
 * of that shape, and where PHP's exhaustive `match` makes an eighth case a FATAL, a TS `default:` arm makes
 * it a blank row nobody notices. {@see NotificationPresenter} is already reading
 * `data` and branching on `type` for the row's `url` (via {@see NotificationType::pathFor()}), so
 * server-built copy costs one extra arm in a match that has to exist anyway. It is the
 * {@see AuditLogPresenter} call made again — `event_label`, `target.label` and
 * `target.url` are all server-supplied there, for the same reason.
 *
 * So {@see self::for()} is the EMAIL copy and {@see self::inApp()} is the BELL copy, and they are not
 * duplicates. An email stands alone in an inbox weeks later, so it names the TENANT and gets a sentence; a
 * bell row is read inside that tenant, beside its own timestamp, three seconds after the fact — which is
 * why {@see self::inApp()} deliberately takes no `$tenantName` parameter and must never grow one
 * (`NotificationCopyTest` asserts the tenant name never appears there). The one string they genuinely
 * share is {@see NotificationType::label()}, and they share it.
 */
final class NotificationCopy
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{headline: string, body: string}
     */
    public static function for(NotificationType $type, string $tenantName, array $data): array
    {
        $form = self::string($data, 'form_title') ?? 'one of your forms';

        return match ($type) {
            NotificationType::SubmissionReceived => [
                'headline' => "A new submission arrived on {$form}.",
                'body' => "Someone completed {$form} in {$tenantName}.",
            ],
            NotificationType::ReviewRequested => [
                'headline' => "A submission on {$form} is waiting for review.",
                'body' => "A response to {$form} has arrived in {$tenantName} and needs a reviewer.",
            ],
            NotificationType::SubmissionApproved => [
                'headline' => "Your submission to {$form} was approved.",
                'body' => "A reviewer in {$tenantName} approved your response to {$form}. No further action is needed.",
            ],
            NotificationType::SubmissionReturned => [
                'headline' => "Your submission to {$form} was returned for changes.",
                // The reason itself is deliberately not repeated here — it lives on the submission, which
                // is where the recipient can act on it, and an email is not a place to accumulate reviewer
                // prose about someone's answers.
                'body' => "A reviewer in {$tenantName} returned your response to {$form}. Open it to see what they asked for.",
            ],
            NotificationType::MemberInvited => [
                'headline' => self::string($data, 'email') === null
                    ? 'Someone was invited to your workspace.'
                    : self::string($data, 'email').' was invited to your workspace.',
                'body' => 'An invitation to join '.$tenantName.' was sent'
                    .(self::string($data, 'role') === null ? '' : ' as '.str_replace('_', ' ', (string) self::string($data, 'role')))
                    .'. They appear on the members page once they accept.',
            ],
            // Both of the site-owned types keep their own purpose-built emails; this copy exists only so
            // the catalog is total and a future caller cannot land in an undefined arm.
            NotificationType::ExportReady => [
                'headline' => "Your export for {$form} is ready.",
                'body' => "The file you requested from {$tenantName} has finished generating.",
            ],
            NotificationType::WebhookFailed => [
                'headline' => 'A webhook endpoint was paused after repeated failures.',
                'body' => "An endpoint in {$tenantName} stopped accepting deliveries and has been paused. Open it to see the failures and re-enable it.",
            ],
        };
    }

    /**
     * The words in a notification's BELL ROW (Increment I4) — see the class docblock for why they are built
     * here rather than in TypeScript.
     *
     * `title` is {@see NotificationType::label()} structurally, not by seven repetitions of the same arm —
     * with ONE exception, and the exception is a bug fix rather than a special case. {@see GeneratePdfJob}
     * records an `export_ready` row for every terminal outcome, including
     * {@see SubmissionPdfOutcome::QuotaExceeded} and {@see SubmissionPdfOutcome::Failed}, so before I4 a
     * failed export produced a bell row headed "Export ready" pointing at a submission with no artifact on
     * it. The email arm was always honest ({@see SubmissionPdfOutcome::subject()}); this makes the bell
     * agree with it.
     *
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: string}
     */
    public static function inApp(NotificationType $type, array $data): array
    {
        // `!== Ready` would be the obvious spelling and it is wrong: a MISSING outcome would then read as
        // a failure, contradicting {@see self::exportDescription()} one method down, which treats null as
        // success on purpose. Name the two failures instead, so the title and the sentence beneath it
        // cannot disagree.
        $failed = $type === NotificationType::ExportReady
            && in_array(self::exportOutcome($data), [SubmissionPdfOutcome::QuotaExceeded, SubmissionPdfOutcome::Failed], true);

        return [
            'title' => $failed ? 'Export failed' : $type->label(),
            'description' => self::inAppDescription($type, $data),
        ];
    }

    /**
     * One short fragment per type — no tenant name, no reviewer prose.
     *
     * The reviewer's `returned_reason` is deliberately absent for the reason I3 recorded when it kept the
     * reason out of the payload in the first place: it lives on the submission, which is where the
     * recipient can act on it, and a notification row is not a place to accumulate prose about someone's
     * answers. `failure_count` is likewise not interpolated — it is on /webhooks/{id}, which is where the
     * row links.
     *
     * Exhaustive, no `default`: an eighth case must be a fatal here rather than a row with no words.
     *
     * @param  array<string, mixed>  $data
     */
    private static function inAppDescription(NotificationType $type, array $data): string
    {
        $form = self::string($data, 'form_title') ?? 'one of your forms';

        return match ($type) {
            NotificationType::SubmissionReceived => "A new response arrived on {$form}.",
            NotificationType::ReviewRequested => "A response on {$form} is waiting for a reviewer.",
            NotificationType::SubmissionApproved => "Your response to {$form} was approved.",
            NotificationType::SubmissionReturned => "Your response to {$form} was returned for changes.",
            NotificationType::ExportReady => self::exportDescription($form, self::exportOutcome($data)),
            NotificationType::MemberInvited => self::inviteDescription($data),
            NotificationType::WebhookFailed => (self::string($data, 'endpoint_name') ?? 'A webhook endpoint')
                .' stopped accepting deliveries and has been paused.',
        };
    }

    /** @param  array<string, mixed>  $data */
    private static function exportOutcome(array $data): ?SubmissionPdfOutcome
    {
        $value = self::string($data, 'outcome');

        return $value === null ? null : SubmissionPdfOutcome::tryFrom($value);
    }

    /**
     * A null outcome reads as success rather than as failure: `outcome` has been on the payload since the
     * row's first day, so a missing one means a hand-built or future payload, and calling that "failed" is
     * the worse of the two wrong answers.
     */
    private static function exportDescription(string $form, ?SubmissionPdfOutcome $outcome): string
    {
        return match ($outcome) {
            SubmissionPdfOutcome::QuotaExceeded => "Your export for {$form} could not be generated — the storage quota is full.",
            SubmissionPdfOutcome::Failed => "Your export for {$form} could not be generated.",
            SubmissionPdfOutcome::Ready, null => "Your export for {$form} has finished generating.",
        };
    }

    /** @param  array<string, mixed>  $data */
    private static function inviteDescription(array $data): string
    {
        $email = self::string($data, 'email') ?? 'Someone';
        $role = self::string($data, 'role');

        return $role === null
            ? "{$email} was invited to your workspace."
            : $email.' was invited as '.str_replace('_', ' ', $role).'.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

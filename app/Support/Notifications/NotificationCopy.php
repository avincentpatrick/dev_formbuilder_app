<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationType;
use App\Support\Connectors\Providers\SlackMessageFormatter;

/**
 * The words in a notification EMAIL (Increment I3). Pure — no database, no container, no config — so it
 * unit-tests against a payload array, the {@see SlackMessageFormatter}
 * posture.
 *
 * ── This is not the in-app copy, and that is not duplication ────────────────────────────────────────────
 * A `notifications` row stores `type` + `data` and nothing else; I4 renders the bell list from those on the
 * TS side. An email has to stand alone in an inbox weeks later, so it gets a sentence. Two renderings of
 * one row, for two audiences with different context — the one string they genuinely share is
 * {@see NotificationType::label()}, and they share it.
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
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

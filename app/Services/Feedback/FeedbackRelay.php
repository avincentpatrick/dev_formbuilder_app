<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\AuditEvent;
use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Load comments that reached the team outside the Feedback button as RELAYED reports (M179, `R-e727ca94`, `D150`;
 * ADR-0023 §D7) — the first support-API write the console does not also have.
 *
 * **Create or update, by reference.** Each item names the code the testers know it by (`R1-7a`, `U4`). An item
 * whose reference the workspace has not seen becomes a report, in the status the item gives (Confirmed included —
 * at creation only, for a fix the user already checked, `D150`), with the support team's note. An item whose
 * reference exists updates its WORDS — title, remarks, source line — and nothing else: **a status is never
 * rewritten after creation**, because after the load the status belongs to the lifecycle (the testers' It works,
 * our triage), and re-running the file must not undo a tester's confirmation. Running the same file twice changes
 * nothing.
 *
 * **Tells no one.** The relay is a bulk load of history; a bell per item would be noise. Every item is audited in
 * the workspace's own ledger (`created` or `updated`; `remarks` redacted), and the API adds one platform row.
 *
 * ⚠️ Call it INSIDE the transaction that adopted the workspace's tenant context —
 * {@see SuperAdminService::relayFeedback()} is that caller. Items are locked in reference order, so two loads at
 * once queue rather than deadlock.
 */
final class FeedbackRelay
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{reference: string, title: string, remarks: string, source_label?: ?string, status?: ?string, note?: ?string, type?: ?string, received_at?: ?string, confirmed_at?: ?string}>  $items
     * @return list<array{reference: string, id: string, outcome: string}>
     */
    public function relay(User $reporter, array $items, User $actor): array
    {
        // The file's own order, taken BEFORE the batch is sorted for locking — the answer is read beside the file.
        $order = array_flip(array_map(static fn (array $item): string => $item['reference'], $items));

        usort($items, static fn (array $a, array $b): int => strcmp($a['reference'], $b['reference']));

        $results = [];

        foreach ($items as $item) {
            /** @var FeedbackReport|null $existing */
            $existing = FeedbackReport::query()->where('reference', $item['reference'])->lockForUpdate()->first();

            $results[] = $existing === null
                ? $this->create($reporter, $item, $actor)
                : $this->refreshWords($existing, $item, $actor);
        }

        // Answered in the file's own order, so the caller can read the outcomes beside its items.
        usort($results, static fn (array $a, array $b): int => $order[$a['reference']] <=> $order[$b['reference']]);

        return $results;
    }

    /**
     * @param  array{reference: string, title: string, remarks: string, source_label?: ?string, status?: ?string, note?: ?string, type?: ?string, received_at?: ?string, confirmed_at?: ?string}  $item
     * @return array{reference: string, id: string, outcome: string}
     */
    private function create(User $reporter, array $item, User $actor): array
    {
        $status = FeedbackStatus::tryFrom((string) ($item['status'] ?? '')) ?? FeedbackStatus::New;
        $received = isset($item['received_at']) ? CarbonImmutable::parse($item['received_at']) : CarbonImmutable::now();
        $closed = $status->stampsResolution() || $status === FeedbackStatus::Confirmed;
        $confirmed = $status === FeedbackStatus::Confirmed;

        $report = new FeedbackReport;
        $report->forceFill([
            'id' => Uuid::uuid7()->toString(),
            'user_id' => (string) $reporter->getKey(),
            'type' => FeedbackType::tryFrom((string) ($item['type'] ?? '')),
            // A relayed comment came from no page of the app; the card shows its title and source line instead.
            'route' => '',
            'remarks' => $item['remarks'],
            'browser_info' => [],
            'status' => $status,
            'submitted_at' => $received,
            'resolution_note' => $item['note'] ?? null,
            // A fix the user already confirmed was resolved first, so it carries both stamps; resolving is the
            // support team's act (the actor), confirming the reporter's.
            'resolved_at' => $closed ? CarbonImmutable::now() : null,
            'resolved_by' => $closed ? (string) $actor->getKey() : null,
            'confirmed_at' => $confirmed ? (isset($item['confirmed_at']) ? CarbonImmutable::parse($item['confirmed_at']) : CarbonImmutable::now()) : null,
            'confirmed_by' => $confirmed ? (string) $reporter->getKey() : null,
            'source' => FeedbackSource::Relayed,
            'source_label' => $item['source_label'] ?? null,
            'reference' => $item['reference'],
            'title' => $item['title'],
        ])->save();

        $this->audit->record(AuditEvent::Created, 'feedback_reports', (string) $report->getKey(), new: [
            'source' => FeedbackSource::Relayed->value,
            'reference' => $item['reference'],
            'title' => $item['title'],
            'status' => $status->value,
            'remarks' => $item['remarks'],
        ], actorId: (string) $actor->getKey());

        return ['reference' => $item['reference'], 'id' => (string) $report->getKey(), 'outcome' => 'created'];
    }

    /**
     * @param  array{reference: string, title: string, remarks: string, source_label?: ?string}  $item
     * @return array{reference: string, id: string, outcome: string}
     */
    private function refreshWords(FeedbackReport $report, array $item, User $actor): array
    {
        $old = ['title' => $report->title, 'remarks' => $report->remarks, 'source_label' => $report->source_label];
        $new = ['title' => $item['title'], 'remarks' => $item['remarks'], 'source_label' => $item['source_label'] ?? null];

        if ($old === $new) {
            return ['reference' => $item['reference'], 'id' => (string) $report->getKey(), 'outcome' => 'unchanged'];
        }

        $report->forceFill($new)->save();
        $this->audit->record(AuditEvent::Updated, 'feedback_reports', (string) $report->getKey(), old: $old, new: $new, actorId: (string) $actor->getKey());

        return ['reference' => $item['reference'], 'id' => (string) $report->getKey(), 'outcome' => 'updated'];
    }
}

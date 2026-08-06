<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I3 adds three {@see DomainEventType} cases — `submission.approved`, `submission.returned` and
 * `member.invited` — so the `webhook_deliveries.event_type` CHECK, generated from
 * {@see DomainEventType::values()} when H13a created the table, has to be regenerated from the now-7-value
 * enum.
 *
 * This is not housekeeping. Subscription validation is `Rule::in(DomainEventType::values())` in eight
 * FormRequests, so the moment the enum grows a tenant can legally subscribe an endpoint to
 * `submission.approved` — and on an already-migrated database the fan-out INSERT would then die with
 * SQLSTATE 23514 against a constraint still frozen at H13a's four values. `E2eSeeder` subscribes its demo
 * endpoint to `DomainEventType::values()` wholesale, so the demo tenant hits it first.
 *
 * Alter-only (no `Schema::create`) → scripts/migration-lint.php short-circuits.
 */
return new class extends Migration
{
    /** The three cases this migration adds — named once, used by both directions. */
    private const array ADDED = [
        DomainEventType::SubmissionApproved,
        DomainEventType::SubmissionReturned,
        DomainEventType::MemberInvited,
    ];

    public function up(): void
    {
        $this->recreate(DomainEventType::values());
    }

    public function down(): void
    {
        // Recreate the CHECK without the I3 cases (the four pre-I3 values).
        $this->recreate(array_values(array_map(
            static fn (DomainEventType $case): string => $case->value,
            array_filter(
                DomainEventType::cases(),
                static fn (DomainEventType $case): bool => ! in_array($case, self::ADDED, true),
            )
        )));
    }

    /**
     * @param  list<string>  $values
     */
    private function recreate(array $values): void
    {
        $eventTypes = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $values
        ));

        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_event_type_check');
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_type_check '
            ."CHECK (event_type IN ({$eventTypes}))"
        );
    }
};

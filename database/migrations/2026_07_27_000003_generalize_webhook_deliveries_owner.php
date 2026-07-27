<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generalizes `webhook_deliveries` into the SHARED OUTBOUND DELIVERY LEDGER (data-dictionary §15, H15a):
 * a row is owned by EITHER a `webhook_endpoints` row (H13a) OR a `connection_subscriptions` row (H15a),
 * never both and never neither. One ledger keeps one retry ladder, one quota metric, and one delivery-log
 * shape across both outbound channels instead of forking them.
 *
 * Alter-only (no `Schema::create`) → `scripts/migration-lint.php` short-circuits, and no RLS is re-emitted:
 * the table's strict policies are `tenant_id` row predicates, unaffected by adding a column.
 *
 * Why every existing webhook row and code path is unaffected:
 *   - MATCH SIMPLE (the FK default) skips a composite constraint whose columns include a NULL, so an
 *     endpoint-owned row is not checked against `connection_subscriptions`, and vice versa — the same
 *     mechanism that already lets `webhook_endpoints.form_id` be nullable under a composite FK.
 *   - The replacement partial unique on `(webhook_endpoint_id, event_id) WHERE webhook_endpoint_id IS NOT
 *     NULL` is exactly the dropped constraint for every endpoint-owned row. It has to be partial: under
 *     Postgres NULL semantics a plain unique would let unlimited connector rows share an `event_id`, so the
 *     idempotency key would silently stop being one for half the table.
 *   - `webhook_deliveries_retry_idx (tenant_id, next_retry_at) WHERE status = 'failed'` already covers both
 *     kinds, and the `event_type` CHECK needs no change: connectors subscribe to the same `DomainEventType`
 *     vocabulary (its docblock's "reference these cases rather than mint a second enum").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE webhook_deliveries ALTER COLUMN webhook_endpoint_id DROP NOT NULL');
        DB::statement('ALTER TABLE webhook_deliveries ADD COLUMN connection_subscription_id uuid NULL');

        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_subscription_fk '
            .'FOREIGN KEY (tenant_id, connection_subscription_id) '
            .'REFERENCES connection_subscriptions (tenant_id, id) ON DELETE CASCADE'
        );

        // Exactly one owner. This is what keeps "the ledger row's owner" a total function for the sweeper,
        // the delivery jobs and the presenters — an orphan row would be un-deliverable and un-attributable.
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_owner_check '
            .'CHECK (num_nonnulls(webhook_endpoint_id, connection_subscription_id) = 1)'
        );

        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_endpoint_event_unique');

        DB::statement(
            'CREATE UNIQUE INDEX webhook_deliveries_endpoint_event_unique '
            .'ON webhook_deliveries (webhook_endpoint_id, event_id) '
            .'WHERE webhook_endpoint_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX webhook_deliveries_subscription_event_unique '
            .'ON webhook_deliveries (connection_subscription_id, event_id) '
            .'WHERE connection_subscription_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // Connector-owned rows have no home in the pre-H15a shape; drop them before restoring NOT NULL.
        DB::statement('DELETE FROM webhook_deliveries WHERE connection_subscription_id IS NOT NULL');

        DB::statement('DROP INDEX IF EXISTS webhook_deliveries_subscription_event_unique');
        DB::statement('DROP INDEX IF EXISTS webhook_deliveries_endpoint_event_unique');
        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_owner_check');
        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_subscription_fk');
        DB::statement('ALTER TABLE webhook_deliveries DROP COLUMN connection_subscription_id');
        DB::statement('ALTER TABLE webhook_deliveries ALTER COLUMN webhook_endpoint_id SET NOT NULL');
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_endpoint_event_unique '
            .'UNIQUE (webhook_endpoint_id, event_id)'
        );
    }
};

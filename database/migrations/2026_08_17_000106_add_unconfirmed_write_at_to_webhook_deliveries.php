<?php

declare(strict_types=1);

use App\Support\Connectors\ConnectorDeliveryResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 — the one bit a retry needs and could not previously know: **the last attempt issued a write and never
 * learned its outcome**, so the destination may already hold this submission.
 *
 * The tabular connectors append to a table the tenant analyses, and neither Google Sheets `values.append` nor
 * Airtable's create-record endpoint accepts an idempotency token, so nothing at the provider can make a
 * re-drive safe. The only thing that can settle it is a read of the destination — and the only reason to pay
 * for that read is this column being set. See {@see ConnectorDeliveryResult::unconfirmed()}
 * for why a lost answer is the ONLY thing that sets it and a 429/403/422/5xx never does.
 *
 * NULLABLE AND SELF-EXPIRING: every path that SETTLES the outcome clears it, so a set value can only ever
 * describe the IMMEDIATELY PRECEDING attempt -- a stale one would let a later attempt skip a write that was
 * never issued. ONE DELIBERATE EXCEPTION: a delivery DEAD-LETTERED while unconfirmed keeps it, because it is
 * then the only record anyone has that the row may or may not be in the destination, and a terminal delivery
 * is never retried so nothing can act on it. A timestamp rather than a
 * boolean because "when" is what an operator needs when a tenant asks why one row is missing or doubled, and
 * because it bounds the window a future provider-side `CREATED_TIME()` filter would search.
 *
 * NO INDEX: it is read only by primary key, on a row the delivery job already has in hand.
 * NO CONSTRAINT: nothing to check, so `ConstraintBoundaries` and the constraint-boundary linter are untouched.
 * The column lives on the SHARED ledger (H15a) but is written only by the connector channel — the webhook
 * channel's receiver dedupes on `X-Webhook-Event-Id` and needs nothing here (data-dictionary §15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->timestampTz('unconfirmed_write_at')->nullable()->after('response_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('unconfirmed_write_at');
        });
    }
};

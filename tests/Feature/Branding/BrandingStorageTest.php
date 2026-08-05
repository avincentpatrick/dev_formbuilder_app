<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Branding\BrandRampGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The H23a2 storage layer (ADR-0014 §D8) — the three `tenants` columns and the accessors over them.
 *
 * **This file exists because the migration's own CI green is VACUOUS.** `scripts/migration-lint.php` keys
 * on `Schema::create` and skips alter-only migrations entirely, so nothing in the build would notice if
 * these columns were wrong — the H25 lesson, which applies here verbatim.
 *
 * The failure it is really guarding is quieter than a broken column. `tenants` is a stancl/tenancy model
 * with a `data` json virtual-column store, and a real column NOT listed in {@see Tenant::getCustomColumns()}
 * is silently relocated into `data` and read back as **null** — no error, no warning, a green write path,
 * and branding that simply never applies. `JobContractTest` pins that property for `status`; this pins it
 * for the three branding columns.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = inboxTenant();
});

it('lists all three branding columns as stancl custom columns', function (string $column): void {
    // The cheap half of the guard: assert the list. The expensive half — that a value actually survives a
    // round trip — is the test below, and BOTH are needed. This one names the fix when the other fails.
    expect(Tenant::getCustomColumns())->toContain($column);
})->with(['primary_color', 'brand_ramp', 'logo_attachment_id']);

it('round-trips a stored ramp through real columns, not the data json store', function (): void {
    $ramp = (new BrandRampGenerator)->generate('#C0392B');

    $this->tenant->primary_color = $ramp->input;
    $this->tenant->brand_ramp = json_encode($ramp->toArray(), JSON_THROW_ON_ERROR);
    $this->tenant->save();

    $fresh = Tenant::findOrFail($this->tenant->id);

    expect($fresh->primary_color)->toBe('#C0392B')
        ->and($fresh->hasBrandRamp())->toBeTrue()
        ->and($fresh->brandRamp()?->token('light', 'bg'))->toBe($ramp->token('light', 'bg'))
        ->and($fresh->brandRamp()?->token('dark', 'ring'))->toBe($ramp->token('dark', 'ring'));

    // The half that actually catches the stancl trap: read the columns through raw SQL, bypassing the
    // model entirely. If getCustomColumns() were missing an entry the accessor above could still pass by
    // reading `data` back, while the column stayed NULL — and every SQL-level consumer would see nothing.
    $row = DB::table('tenants')->where('id', $this->tenant->id)->first();

    expect($row->primary_color)->toBe('#C0392B')
        ->and($row->brand_ramp)->not->toBeNull()
        ->and((string) $row->data)->not->toContain('primary_color')
        ->and((string) $row->data)->not->toContain('brand_ramp');
});

it('reads no ramp on a tenant that has never set one', function (): void {
    expect($this->tenant->hasBrandRamp())->toBeFalse()
        ->and($this->tenant->brandRamp())->toBeNull()
        ->and($this->tenant->primary_color)->toBeNull();
});

it('never re-derives a stored ramp on read', function (): void {
    // ADR-0014 §D8. A stored ramp was validated by the engine version recorded inside it. If brandRamp()
    // re-ran the generator, a future VERSION bump would silently repaint every live tenant — and this
    // deliberately impossible payload (a hex no engine would ever produce) proves it does not: it comes
    // back exactly as stored.
    $ramp = (new BrandRampGenerator)->generate('#1B5E5E');
    $payload = $ramp->toArray();
    $payload['tokens']['light']['bg'] = '#ABCDEF';
    $payload['engine_version'] = 99;

    $this->tenant->primary_color = '#1B5E5E';
    $this->tenant->brand_ramp = json_encode($payload, JSON_THROW_ON_ERROR);
    $this->tenant->save();

    $stored = Tenant::findOrFail($this->tenant->id)->brandRamp();

    expect($stored?->token('light', 'bg'))->toBe('#ABCDEF')
        ->and($stored?->engineVersion)->toBe(99);
});

it('stops resolving the logo relation once the attachment is soft-deleted', function (): void {
    // THE REACHABLE PATH, and it is not the FK. `Attachment` uses SoftDeletes, so an ordinary delete()
    // leaves the row in place and `ON DELETE SET NULL` never fires — `logo_attachment_id` goes on
    // pointing at a trashed row. What saves the render path is the SoftDeletes global scope on the
    // relation: the pointer is stale but `logoAttachment` resolves to null, so the logo stops rendering.
    //
    // Both halves are asserted because they disagree, and a reader who assumed the FK did this work
    // would draw the wrong conclusion from either one alone.
    //
    // `attachments` is RLS-scoped and BelongsToTenant auto-fills `tenant_id` from the GUC, so the row
    // cannot be written — or read back — without tenant context. `tenants` itself is RLS-EXEMPT, which is
    // why every other test in this file needs none.
    enterTenant($this->tenant->id);

    $attachment = brandingLogoAttachment($this->tenant->id);
    $this->tenant->forceFill(['logo_attachment_id' => $attachment->id])->save();

    expect(Tenant::findOrFail($this->tenant->id)->logoAttachment)->not->toBeNull();

    $attachment->delete();

    $fresh = Tenant::findOrFail($this->tenant->id);

    expect($fresh->logo_attachment_id)->toBe($attachment->id) // the pointer survives a SOFT delete …
        ->and($fresh->logoAttachment()->first())->toBeNull(); // … and resolves to nothing anyway
});

it('nulls the logo pointer on a HARD delete rather than blocking it', function (): void {
    // The FK's actual job. `ON DELETE SET NULL` fires only on a forceDelete, and the H25 finding is why
    // the referential action matters: PostgreSQL runs it as an ordinary UPDATE through SPI, which
    // bypasses RLS but NOT a trigger. `RESTRICT` here would have made a brand logo un-deletable and
    // turned erasure of a stored object into a constraint violation raised against an unrelated table —
    // exactly the shape that made `form_versions.published_by` freezable-but-not-frozen in H25.
    enterTenant($this->tenant->id);

    $attachment = brandingLogoAttachment($this->tenant->id);
    $this->tenant->forceFill(['logo_attachment_id' => $attachment->id])->save();

    $attachment->forceDelete();

    expect(Tenant::findOrFail($this->tenant->id)->logo_attachment_id)->toBeNull();
});

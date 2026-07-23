<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Models\Attachment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Hard-block storage_bytes (ADR-0008 §D4) — but ONLY for a signed-in member. A guest respondent's upload is
// data collection and is NEVER rejected over the tenant's billing status (the same never-block principle
// that protects submissions_count). The live gauge sums non-trashed attachment bytes.

beforeEach(function (): void {
    TenantContext::flush();
    Storage::fake('local');
    Queue::fake(); // don't auto-run the scan job
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->storage = app(AttachmentStorageService::class);

    // A draft version with one media field (built with no plan ⇒ forms_count unlimited, no interference).
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Media form');
    $this->version = $form->draftVersion;
    addFormField($this->version, $this->owner, 'photos', FieldType::ImageCapture, 0);
    $this->version = $this->version->refresh();
});

/** A tiny (~70-byte) real 1×1 PNG so getMimeType() content-sniffs image/png with no GD dependency. */
function h5bTinyImage(string $name = 'pin.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $png);
}

/** Cap storage_bytes to 10 (any real file crosses it) while leaving every other quota unlimited. */
function h5bCapStorage(int $limit = 10): void
{
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::StorageBytes->value => $limit])
        ->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget(); // beforeEach resolved a null plan; refresh to the assigned one
}

it('blocks a staff upload over the storage_bytes limit', function (): void {
    h5bCapStorage();

    expect(fn () => $this->storage->store(h5bTinyImage(), $this->version, 'photos', $this->owner->id))
        ->toThrow(QuotaExceededException::class);

    expect(Attachment::query()->count())->toBe(0); // nothing stored past the block
});

it('NEVER blocks a guest respondent upload, even over the storage limit', function (): void {
    h5bCapStorage();

    // $uploadedBy === null ⇒ a public respondent. Their in-progress data is never rejected over the
    // tenant's billing status; the per-field size cap + guest rate limit are the real bounds.
    $attachment = $this->storage->store(h5bTinyImage(), $this->version, 'photos', null);

    expect($attachment->uploaded_by)->toBeNull()
        ->and(Attachment::query()->count())->toBe(1);
});

it('never blocks a staff upload on an unlimited plan', function (): void {
    assignUnlimitedPlan();

    $this->storage->store(h5bTinyImage('a.png'), $this->version, 'photos', $this->owner->id);
    $this->storage->store(h5bTinyImage('b.png'), $this->version, 'photos', $this->owner->id);

    expect(Attachment::query()->count())->toBe(2);
});

it('excludes soft-deleted attachments from the storage gauge', function (): void {
    // No plan ⇒ unlimited, so both uploads succeed; we are testing the SUM, not the block.
    $keep = $this->storage->store(h5bTinyImage('keep.png'), $this->version, 'photos', $this->owner->id);
    $drop = $this->storage->store(h5bTinyImage('drop.png'), $this->version, 'photos', $this->owner->id);

    $drop->delete(); // soft delete

    $entitlements = app(EntitlementService::class);
    $entitlements->forget($this->tenant->id);

    expect($entitlements->countGauge(UsageMetric::StorageBytes))->toBe((int) $keep->size_bytes);
});

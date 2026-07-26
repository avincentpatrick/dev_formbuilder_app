<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Events\SubmissionCreated;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Attachment;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEventDispatcher;
use App\Services\Webhooks\WebhookPayloadArchive;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Oversized-payload archival (H13b — payload_attachment_id + AttachmentKind::WebhookPayloadArchive). At
| delivery creation an envelope over the byte threshold is off-loaded to attachment storage and the inline
| payload trimmed; the delivery job reads it back and signs the FULL envelope.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    Http::preventStrayRequests();
    Storage::fake((string) config('filesystems.default'));

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id);
});

function archiveSubEvent(string $tenantId, string $formId): SubmissionCreated
{
    $s = new Submission([
        'tenant_id' => $tenantId,
        'form_id' => $formId,
        'form_version_id' => (string) Str::uuid(),
        'status' => SubmissionStatus::Submitted,
        'source' => SubmissionSource::Guest,
    ]);
    $s->id = (string) Str::uuid();

    return SubmissionCreated::for($s);
}

it('archives an oversized envelope at creation, trimming the inline payload to a marker', function (): void {
    config()->set('webhooks.payload_archive_threshold_bytes', 10); // any real envelope exceeds this
    $endpoint = WebhookEndpoint::factory()->create(['form_id' => null]);

    app(WebhookEventDispatcher::class)->fanOut(archiveSubEvent($this->tenant->id, (string) Str::uuid()));

    enterTenant($this->tenant->id);
    $delivery = WebhookDelivery::query()->firstOrFail();
    expect($delivery->payload_attachment_id)->not->toBeNull()
        ->and($delivery->payload)->toBe(['archived' => true]);

    $attachment = Attachment::query()->findOrFail($delivery->payload_attachment_id);
    expect($attachment->kind)->toBe(AttachmentKind::WebhookPayloadArchive)
        ->and($attachment->attachable_type)->toBe('webhook_delivery')
        ->and($attachment->attachable_id)->toBe($delivery->id)
        ->and($attachment->uploaded_by)->toBeNull();

    $stored = json_decode((string) Storage::disk($attachment->disk)->get($attachment->path), true);
    expect($stored['event_type'])->toBe('submission.created');
});

it('keeps a sub-threshold payload fully inline (no archive row)', function (): void {
    config()->set('webhooks.payload_archive_threshold_bytes', 1_000_000);
    WebhookEndpoint::factory()->create(['form_id' => null]);

    app(WebhookEventDispatcher::class)->fanOut(archiveSubEvent($this->tenant->id, (string) Str::uuid()));

    enterTenant($this->tenant->id);
    $delivery = WebhookDelivery::query()->firstOrFail();
    expect($delivery->payload_attachment_id)->toBeNull()
        ->and($delivery->payload)->toHaveKey('event_type');
    expect(Attachment::query()->count())->toBe(0);
});

it('reads the archived envelope back and signs the full payload on delivery', function (): void {
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://8.8.8.8/hook', 'form_id' => null]);
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();
    $fullPayload = $delivery->payload;

    $attachmentId = app(WebhookPayloadArchive::class)->archive($delivery, $fullPayload);
    $delivery->forceFill(['payload_attachment_id' => $attachmentId, 'payload' => ['archived' => true]])->save();

    Http::fake(['*' => Http::response('ok', 200)]);
    DeliverWebhookJob::dispatch($this->tenant->id, (string) $delivery->id);
    workOneJob('webhooks');
    enterTenant($this->tenant->id);

    // The body sent is the full archived envelope, not the trimmed `{"archived":true}` marker.
    Http::assertSent(function (Request $r): bool {
        $sent = json_decode($r->body(), true);

        return is_array($sent) && ! array_key_exists('archived', $sent) && array_key_exists('event_type', $sent);
    });
    expect($delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

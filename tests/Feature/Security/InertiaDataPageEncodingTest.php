<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Doc #26 §5's Inertia `data-page` row + §7's `</script>` vector (Increment H6a).
|--------------------------------------------------------------------------
| That row's Owner column reads "— (holds)" — the behaviour is already correct — but its Required test is
| H6a's, because piping is what first carries a RESPONDENT's untrusted answer onto a server-rendered page.
| Every Inertia page in the app serializes its props into a `<script type="application/json">` block via the
| directive's `{!! json_encode($page) !!}`, so a `</script>`-bearing answer is the question. NOTE the shape
| the installed Inertia version emits: `data-page` is the root element's ID ("app"), and the JSON is the
| script block's BODY — so no HTML escaping applies to it at all, which is exactly why the forward-slash
| dependency below is the whole control rather than one layer of two.
|
| WHAT THIS ACTUALLY DEPENDS ON, stated honestly because no code in this repo controls it: `json_encode`
| escapes forward slashes BY DEFAULT, so `</script>` serialises as `<\/script>` and cannot close the block.
| It is a dependency to PRESERVE, not a defect — the failure mode is a future `JSON_UNESCAPED_SLASHES` on
| that path. Note the webhook channel deliberately sets exactly that flag (DeliverWebhookJob and three
| siblings) for a JSON-body context: same function, opposite requirement. This test is what would catch it
| if the flag ever migrated into an HTML/script context.
|
| CSP is NOT a second layer here — PublicRuntimeSecurityHeaders sets no `default-src`/`script-src`, so
| output encoding is the sole control (§5 / security-threat-model §9 item 9).
|
| Deliberately NO `Http::preventStrayRequests()` in the beforeEach: this suite RENDERS a page, and the SSR
| gateway POSTs to the render server while building the root view, so that helper turns every Inertia render
| into a 500 (the standing H15b lesson).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('cannot be broken out of by a script-closing answer', function (): void {
    $this->withoutVite(); // this test renders the blade SHELL; the Pest job builds no assets

    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Intake');
    $payload = '</script><script>alert(1)</script>';
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => $payload]);

    $html = $this->actingAs($owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertOk()
        ->getContent();

    // (a) The injected markup never appears as parseable markup anywhere in the document.
    expect($html)->not->toContain('<script>alert(1)</script>');

    // (b) It survives INTACT as data — the answer must round-trip, not be silently mangled. This is what
    //     separates "correctly encoded" from "accidentally stripped": strip the value and (a) would also
    //     pass while the product would be broken.
    expect($html)->toContain('<\\/script>');
});

it('round-trips a script-closing answer through the page-data block unchanged', function (): void {
    $this->withoutVite();

    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Intake');
    $payload = '</script><script>alert(1)</script>';
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => $payload]);

    $html = $this->actingAs($owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertOk()
        ->getContent();

    expect(preg_match('~<script data-page="app" type="application/json">(.*?)</script>~s', (string) $html, $matches))->toBe(1);

    /** @var array<string, mixed> $page */
    $page = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    $values = collect(data_get($page, 'props.blocks.*.fields.*.value'))->all();

    expect($values)->toContain($payload);
});

it('renders a piped label as text rather than as markup', function (): void {
    // The piping-specific half: H6a's renderer fills a label from an untrusted answer, so the piped value
    // reaches the same `data-page` block. It must be data, never markup. (The DOM-side assertion that Vue
    // renders it as visible text is H6b's, per testing-strategy.md §3.)
    $this->withoutVite();

    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    // Built by hand rather than through publishedInboxForm(): the piped label must be authored on the DRAFT
    // and then published. `form_fields` carries the draft-child RLS shape, so a published version's rows are
    // immutable — an UPDATE against them affects zero rows and silently defeats the test.
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    $draft = $form->draftVersion;
    addFormField($draft, $owner, 'full_name', FieldType::ShortText, 1);
    addFormField($draft, $owner, 'color', FieldType::SingleSelect, 2, [
        'label' => 'Favourite colour, ${full_name}?',
        'config' => ['options' => [['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue']]],
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form = $form->refresh();

    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, [
        'full_name' => '<script>alert(1)</script>',
        'color' => 'r',
    ]);

    $html = (string) $this->actingAs($owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');

    expect(preg_match('~<script data-page="app" type="application/json">(.*?)</script>~s', $html, $matches))->toBe(1);

    /** @var array<string, mixed> $page */
    $page = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    $labels = collect(data_get($page, 'props.blocks.*.fields.*.label'))->all();

    // The hole FILLED — this is the one surface where it does — and the markup travelled as data.
    expect($labels)->toContain('Favourite colour, <script>alert(1)</script>?');
});

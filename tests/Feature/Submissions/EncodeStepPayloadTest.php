<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Support\Forms\StepProjection;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H21c — the encode payload's step-model envelope (Doc #27 §7 and §9).
|--------------------------------------------------------------------------
| §9 owes three of these by name: that the encode path applies client relevance (it applied none), that
| `single_page_mode` is now READ on this path, and that the keyable-hidden asymmetry survives the port. The
| clock cases are H21a's amendment A1 obligation reaching a second channel.
|
| The parity suite next door proves the two channels agree; this file proves the envelope the agreement
| RIDES ON is actually complete — a payload missing `checksum`, `default_locale` or `now` would satisfy every
| parity assertion and still fail to mount a runtime store in the browser.
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

function payloadTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

function encodePayloadFor(Form $form): array
{
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    return app(EncodeFormPresenter::class)->present($form, $version);
}

it('reads single_page_mode on this path at last, in both states', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Modes');
    addFormField($form->draftVersion, $owner, 'a', FieldType::ShortText, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh();

    // The column default is FALSE — the stepped flow — which is why this was worth a test rather than an
    // assumption: before H21c the encode page ignored the flag entirely and every form scrolled flat.
    expect(encodePayloadFor($form)['form']['single_page_mode'])->toBeFalse();

    $form->update(['single_page_mode' => true]);
    expect(encodePayloadFor($form->refresh())['form']['single_page_mode'])->toBeTrue();
});

it('carries every key the runtime store\'s SchemaResponse requires', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Envelope');
    addFormField($form->draftVersion, $owner, 'a', FieldType::ShortText, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $payload = encodePayloadFor($form->refresh());

    // `SchemaResponse` in `resources/public-runtime/lib/types.ts` — the store destructures these directly, so
    // a missing one is a runtime TypeError in the browser that no PHP test would otherwise see.
    expect($payload['form'])->toHaveKeys(['id', 'title', 'description', 'default_locale', 'supported_locales', 'single_page_mode'])
        ->and($payload['version'])->toHaveKeys(['id', 'version_number', 'checksum', 'schema', 'now'])
        ->and($payload['version']['checksum'])->toBeString()->not->toBeEmpty()
        ->and($payload['version']['schema'])->toHaveKeys(['sections', 'fields'])
        // `blocks` is untouched by H21c and stays the render source for FieldInput (Doc #27 §10's recorded
        // deferral); a port that replaced it would break every G4/G5/G6 encode control.
        ->and($payload)->toHaveKeys(['blocks', 'steps']);
});

it('keeps the encode channel single-locale even when the form supports several', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Multilingual');
    addFormField($form->draftVersion, $owner, 'a', FieldType::ShortText, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh();
    $form->update(['supported_locales' => ['en', 'es', 'fr']]);

    // A recorded narrowing, not an oversight: `blocks` resolves no `*_translations` either, so offering the
    // switcher would show a language whose field labels never change. The guest channel gets all three.
    $payload = encodePayloadFor($form->refresh());
    expect($payload['form']['supported_locales'])->toBe(['en'])
        ->and($payload['form']['default_locale'])->toBe('en');
});

it('stamps the session clock in Carbon\'s exact ISO-8601 shape', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Clock');
    addFormField($form->draftVersion, $owner, 'a', FieldType::ShortText, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);

    Carbon::setTestNow(Carbon::parse('2026-07-11T09:30:00+00:00'));
    $now = encodePayloadFor($form->refresh())['version']['now'];
    Carbon::setTestNow();

    // Byte-identical to `isoClock()`'s output (H21a amendment A1): seconds precision, explicit offset, never
    // a `Z`, never milliseconds. `today()` takes a 10-character slice of this, so a differing shape is not a
    // formatting nit — it is a different DATE.
    expect($now)->toBe('2026-07-11T09:30:00+00:00')
        ->and($now)->not->toContain('Z')
        ->and($now)->not->toContain('.');
});

it('projects the steps against the SERVER clock, not the request date', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Dated');
    $draft = $form->draftVersion;
    $gated = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'later',
        'label' => 'Later',
        'sequence' => 2,
        // `today()` is the only clock-dependent predicate the grammar has that a step can hang on.
        'relevant_expression' => "today() = '2026-07-11'",
    ]);
    addFormField($draft, $owner, 'a', FieldType::ShortText, 1);
    addFormField($draft, $owner, 'b', FieldType::ShortText, 3, ['form_section_id' => $gated->id]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh();

    Carbon::setTestNow(Carbon::parse('2026-07-11T09:30:00+00:00'));
    $onTheDay = encodePayloadFor($form);
    Carbon::setTestNow(Carbon::parse('2026-07-12T09:30:00+00:00'));
    $theNextDay = encodePayloadFor($form);
    Carbon::setTestNow();

    // The step list moved with the clock, and the clock the payload advertises is the one it was projected
    // against — which is the whole reason `now` is emitted rather than left to the browser. A client stamping
    // its own would disagree with this list near midnight or on a skewed machine.
    expect(array_column($onTheDay['steps'], 'key'))->toBe(['__lead__', 'later'])
        ->and(array_column($theNextDay['steps'], 'key'))->toBe(['__lead__'])
        ->and($onTheDay['version']['now'])->toBe('2026-07-11T09:30:00+00:00');
});

it('drops a hidden-only section from the steps while KEEPING its keyable row in blocks', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    // Doc #27 §4.1's own example, and the `Branching Router` shape H21b seeded: a `url`-prefilled `hidden`
    // gate alone in its section, with the branches it routes to hanging off it.
    $form = app(FormService::class)->create($tenant, $owner, 'Router');
    $draft = $form->draftVersion;
    $routing = FormSection::create(['form_version_id' => $draft->id, 'key' => 'routing', 'label' => 'Routing', 'sequence' => 1]);
    addFormField($draft, $owner, 'role', FieldType::Hidden, 1, [
        'form_section_id' => $routing->id,
        'config' => ['prefill_source' => 'url'],
    ]);
    $staff = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'staff',
        'label' => 'Staff details',
        'sequence' => 2,
        'relevant_expression' => "\${role} = 'staff'",
    ]);
    addFormField($draft, $owner, 'staff_number', FieldType::ShortText, 2, ['form_section_id' => $staff->id]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    $payload = encodePayloadFor($form->refresh());

    // THE STEP LIST IS THE RESPONDENT'S, byte for byte — that is what makes the parity assertion a clean
    // equality rather than an equality-modulo-something. Bare, this form's whole graph is empty: §4.1's
    // terminal state, reached on the encode channel too.
    expect($payload['steps'])->toBe([]);

    // And H7 is NOT flattened: the row survives in `blocks`, keyable, because a keyer transcribing paper is
    // the only possible source of a `url`-sourced value on this channel (data-dictionary.md's read/render
    // asymmetry). `Encode.vue` renders it in the reference block precisely BECAUSE it is in no step.
    $rows = collect($payload['blocks'])->flatMap(fn (array $b): array => $b['fields'])->keyBy('key');
    expect($rows)->toHaveKey('role')
        ->and($rows['role']['prefill'])->toBe('url')
        ->and($rows['role']['supported'])->toBeTrue();
});

it('projects a repeatable section with no instances as a visible step', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Roster');
    $draft = $form->draftVersion;
    $hh = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'hh',
        'label' => 'Household members',
        'sequence' => 1,
        'is_repeatable' => true,
        'min_instances' => 2,
    ]);
    addFormField($draft, $owner, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $hh->id]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    $payload = encodePayloadFor($form->refresh());

    // Zero instances is VACUOUSLY visible — the step is what lets the keyer add the first one. The encode
    // page opens with exactly zero, so getting this wrong would make every repeat form unencodable.
    expect(array_column($payload['steps'], 'key'))->toBe(['hh'])
        ->and($payload['steps'][0]['is_repeat'])->toBeTrue()
        ->and($payload['steps'][0]['section_key'])->toBe('hh')
        ->and($payload['steps'][0]['field_keys'])->toBe(['member_name']);
});

it('emits the synthetic lead step for section-less fields, first', function (): void {
    $tenant = payloadTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Lead');
    $draft = $form->draftVersion;
    $later = FormSection::create(['form_version_id' => $draft->id, 'key' => 'later', 'label' => 'Later', 'sequence' => 2]);
    addFormField($draft, $owner, 'intro', FieldType::ShortText, 1);
    addFormField($draft, $owner, 'b', FieldType::ShortText, 3, ['form_section_id' => $later->id]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    $steps = encodePayloadFor($form->refresh())['steps'];

    expect(array_column($steps, 'key'))->toBe([StepProjection::LEAD_STEP_KEY, 'later'])
        // The lead step is the one step that can carry no `relevant_expression` of its own — it has no
        // `form_sections` row to hang one on — so its `section_key` is null and stays null.
        ->and($steps[0]['section_key'])->toBeNull()
        ->and($steps[0]['is_repeat'])->toBeFalse();
});

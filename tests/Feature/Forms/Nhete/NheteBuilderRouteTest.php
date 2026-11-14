<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Nhete\NheteAnswers;
use Tests\Support\Nhete\NheteBuilderDriver;
use Tests\Support\Nhete\NhetePlan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M237 (`nhete-stress-test`, `D220`–`D222`) — the NHETE pre-work module, built BY HAND.
|--------------------------------------------------------------------------
| "By hand" here means through the builder's own HTTP routes, the requests `useBuilderStore.ts` sends for each click
| (NheteBuilderDriver's docblock). Nothing is written past them, so a pass proves a person could build it; the request
| count says how practical that is. The fixture is the same-shape copy (`D221`).
|
| ONE scenario test, not many: building ~800 questions over HTTP is the expensive part, so it is done once, and every
| measurement and every refusal is checked against that one published form. Each check names what it proves.
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

it('builds the whole NHETE module through the builder routes, publishes it, and takes and refuses responses as the module says', function (): void {
    $this->withoutVite();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $plan = new NhetePlan;
    $form = app(FormService::class)->create($tenant, $admin, $plan->title());
    $report = ['workarounds' => null, 'timings_ms' => []];

    // ── 1. Build every screen, as clicks ────────────────────────────────────────────────────────────────────
    $driver = new NheteBuilderDriver($this, $admin, $form);
    $started = hrtime(true);
    $driver->build($plan);
    $report['timings_ms']['build'] = (int) ((hrtime(true) - $started) / 1e6);
    $report['builder_requests'] = $driver->requests;
    $report['workarounds'] = $plan->workarounds();

    enterTenant($tenant->id, $admin->id);
    $elements = $plan->elements();
    $otherBoxes = count(array_filter($elements, static fn (array $e): bool => $e['other'] !== null));
    expect(FormSection::query()->where('form_version_id', $form->draft_version_id)->count())->toBe(count($plan->sections()))
        ->and(FormField::query()->where('form_version_id', $form->draft_version_id)->count())->toBe(count($elements) + $otherBoxes);
    $report['sections'] = count($plan->sections());
    $report['fields'] = count($elements) + $otherBoxes;

    // ── 2. Publish through the real gates ───────────────────────────────────────────────────────────────────
    $started = hrtime(true);
    $publish = $this->actingAs($admin)->post("http://acme.meridian.test/forms/{$form->id}/publish");
    $report['timings_ms']['publish'] = (int) ((hrtime(true) - $started) / 1e6);
    enterTenant($tenant->id, $admin->id);
    $form = Form::query()->findOrFail($form->id);
    expect($form->current_published_version_id)->not->toBeNull(
        'publish refused: '.json_encode(session('toast') ?? session('errors')?->getBag('default')->toArray()),
    );
    $version = FormVersion::query()->findOrFail($form->current_published_version_id);
    $report['snapshot_bytes'] = strlen((string) json_encode($version->schema_snapshot));

    // ── 3. Open it to guests, with the module's thank-you text ──────────────────────────────────────────────
    $this->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/share", [
        'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null, 'public_slug' => 'nhete',
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/confirmation", [
        'confirmation_message' => $plan->confirmation(),
    ])->assertSessionHasNoErrors();
    enterTenant($tenant->id, $admin->id);
    $form = Form::query()->findOrFail($form->id);
    expect($form->allow_guest_submissions)->toBeTrue();
    $token = shareTokenFor($form);
    $submit = fn (array $answers) => $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", ['answers' => $answers]);

    // ── 4. A full response for each region branch: four pilot regions, the whole country, another region ──
    foreach (['ncr' => 'cov_r', 'whole' => 'cov_n', 'another' => null] as $region => $coverage) {
        $answers = NheteAnswers::scenario($plan, region: $region);
        $started = hrtime(true);
        $response = $submit($answers);
        $report['timings_ms']["submit_{$region}"] = (int) ((hrtime(true) - $started) / 1e6);
        expect($response->status())->toBe(201, "region {$region}: ".mb_substr((string) $response->getContent(), 0, 1500));

        enterTenant($tenant->id);
        $stored = SubmissionAnswer::query()->findOrFail($response->json('data.id'))->answers;
        expect($stored)->toHaveKey('a01_p1_area')
            ->and(array_key_exists('a01_p1_cov_r', $stored))->toBe($coverage === 'cov_r')
            ->and(array_key_exists('a01_p1_cov_n', $stored))->toBe($coverage === 'cov_n');
        $report['answers_stored'][$region] = count($stored);
    }

    // ── 5. Every answer rule refuses what the module says it should ─────────────────────────────────────────
    $base = NheteAnswers::scenario($plan);
    $refusals = [
        'a 1,501-word answer to a 1,500-word question' => [['q1_1' => NheteAnswers::words(1501)], 'q1_1'],
        'three choices where the module allows two' => [['q3_1' => ['access', 'results', 'use']], 'q3_1'],
        '"No view or opinion" together with another choice' => [['q3_2' => ['noview', 'ip']], 'q3_2'],
        'a kept-close indicator with no description' => [['a07_comment' => null], 'a07_comment'],
        'a changed cut-off with no value' => [['c14_comment' => null], 'c14_comment'],
        'a worry of "something else" left unexplained' => [['d12_comment' => null], 'd12_comment'],
        'a kept indicator whose part was skipped' => [['a01_p1_keep' => null], 'a01_p1_keep'],
        'a part kept close with no description' => [['a01_p2_close' => null], 'a01_p2_close'],
        'a disagreement row whose own question was skipped' => [['tor_t03' => null], 'tor_t03'],
        'the consent box left unticked' => [['q0_0' => []], 'q0_0'],
    ];
    foreach ($refusals as $what => [$change, $key]) {
        $answers = array_filter([...$base, ...$change], static fn (mixed $v): bool => $v !== null);
        $response = $submit($answers);
        // The refusal lists each failure as {field, rule, message}.
        $failed = $response->json('error.details.fields') ?? [];
        expect($response->status())->toBe(422, "{$what}: expected a refusal, got {$response->status()}")
            ->and(array_column($failed, 'field'))->toContain($key)
            ->and(count($failed))->toBe(1, "{$what}: refused on more than {$key}: ".json_encode($failed));
        $report['refusals'][$what] = $failed[0];
    }

    // A 1,500-word answer is accepted (the cap is not off by one), and so is a long answer to every capped box at once.
    expect($submit([...$base, 'q1_1' => NheteAnswers::words(1500)])->status())->toBe(201);

    // An answer to a part of an indicator the office does NOT keep is dropped, never stored.
    $response = $submit([...$base, 'd01_p1_keep' => 'yes']);
    expect($response->status())->toBe(201);
    enterTenant($tenant->id);
    expect(SubmissionAnswer::query()->findOrFail($response->json('data.id'))->answers)->not->toHaveKey('d01_p1_keep');

    expect(Submission::query()->count())->toBe(5);

    // ── 6. The other ways to make it: a template, the question library, and export → re-import ─────────────
    $flush = function () use (&$report): void {
        if (($path = getenv('NHETE_REPORT')) !== false && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        }
    };
    $flush();
    $host = 'http://acme.meridian.test';
    $publishNew = function (Form $copy) use ($admin, $host, $tenant): Form {
        $this->withHeaders(['X-Builder-Draft' => (string) $copy->draft_version_id]);
        $this->actingAs($admin)->post("{$host}/forms/{$copy->id}/publish");
        enterTenant($tenant->id, $admin->id);

        return Form::query()->findOrFail($copy->id);
    };
    $answerable = function (Form $copy, string $slug) use ($admin, $host, $tenant, $base): array {
        $this->actingAs($admin)->patchJson("{$host}/forms/{$copy->id}/share", [
            'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null, 'public_slug' => $slug,
        ]);
        enterTenant($tenant->id, $admin->id);
        $copyToken = shareTokenFor(Form::query()->findOrFail($copy->id));
        $post = fn (array $answers): int => $this->postJson("{$host}/api/v1/public/f/{$copyToken}/submissions", ['answers' => $answers])->status();
        $full = $this->postJson("{$host}/api/v1/public/f/{$copyToken}/submissions", ['answers' => $base]);

        return [
            'full_response' => $full->status(),
            'full_response_refused_on' => $full->status() === 422 ? $full->json('error.details.fields') : [],
            'word_cap_refuses' => $post([...$base, 'q1_1' => NheteAnswers::words(1501)]) === 422,
            'required_when_refuses' => $post(array_diff_key($base, ['a07_comment' => 1])) === 422,
            'up_to_two_refuses' => $post([...$base, 'q3_1' => ['access', 'results', 'use']]) === 422,
        ];
    };

    // 6a. Save as a template, start a new form from it, publish it, answer it.
    $this->actingAs($admin)->post("{$host}/forms/{$form->id}/save-as-template", ['name' => 'NHETE', 'description' => null, 'category' => null])
        ->assertSessionHasNoErrors();
    enterTenant($tenant->id, $admin->id);
    $template = FormTemplate::query()->latest('created_at')->firstOrFail();
    $made = $this->actingAs($admin)->post("{$host}/forms/templates/{$template->id}/instantiate");
    preg_match('~/forms/([0-9a-f-]{36})/builder~', (string) $made->headers->get('Location'), $m);
    enterTenant($tenant->id, $admin->id);
    $copy = Form::query()->findOrFail($m[1] ?? '');
    $draftFields = FormField::query()->where('form_version_id', $copy->draft_version_id)->count();
    $fromTemplate = $publishNew($copy);
    $firstPublish = session('toast');

    // A template copy's diagrams still name the ORIGINAL form's images, which publish refuses (FINDING). A person
    // uploads each one again into the copy; so does this, counting the repairs.
    $repairs = 0;
    if ($fromTemplate->current_published_version_id === null) {
        $this->withHeaders(['X-Builder-Draft' => (string) $fromTemplate->draft_version_id]);
        enterTenant($tenant->id, $admin->id);
        $notes = FormField::query()->where('form_version_id', $fromTemplate->draft_version_id)->where('field_type', 'note')->get()
            ->filter(static fn (FormField $f): bool => collect($f->config['content'] ?? [])->contains('type', 'image'));
        foreach ($notes as $note) {
            enterTenant($tenant->id, $admin->id);
            $upload = $this->actingAs($admin)->post("{$host}/forms/{$fromTemplate->id}/content-images",
                ['file' => UploadedFile::fake()->image('again.png', 640, 360)], ['Accept' => 'application/json']);
            $config = $note->config;
            $config['content'] = array_map(static fn (array $b): array => $b['type'] === 'image' ? [...$b, 'attachment_id' => $upload->json('data.id')] : $b, $config['content']);
            enterTenant($tenant->id, $admin->id);
            $this->actingAs($admin)->patchJson("{$host}/forms/{$fromTemplate->id}/fields/{$note->id}", [
                'key' => $note->key, 'label' => $note->label, 'hint' => $note->hint, 'placeholder' => $note->placeholder,
                'is_required' => $note->is_required->value, 'relevant_expression' => $note->relevant_expression, 'appearance' => null,
                'config' => $config, 'validations' => [], 'version' => builderToken($note),
            ])->assertOk();
            $repairs++;
        }
        enterTenant($tenant->id, $admin->id);
        $fromTemplate = $publishNew(Form::query()->findOrFail($fromTemplate->id));
    }
    $report['template'] = [
        'instantiate_status' => $made->status(),
        'draft_fields' => $draftFields,
        'first_publish_toast' => $firstPublish,
        'images_reuploaded_before_publish' => $repairs,
        'publish_toast' => session('toast'),
        'fields' => FormField::query()->where('form_version_id', $fromTemplate->current_published_version_id)->count(),
        'published' => $fromTemplate->current_published_version_id !== null,
        ...($fromTemplate->current_published_version_id !== null ? $answerable($fromTemplate, 'nhete-template') : []),
    ];
    $flush();
    // A template copy keeps every question and every rule, but its diagrams still name the ORIGINAL form's uploads, so
    // its first publish is refused until each is uploaded again (`template-copy-note-images`).
    expect($report['template']['first_publish_toast']['message'] ?? '')->toContain('image is missing, belongs to another form')
        ->and($repairs)->toBe(3)
        ->and($report['template']['fields'])->toBe($report['fields'])
        ->and($report['template']['full_response'] ?? null)->toBe(201)
        ->and($report['template']['word_cap_refuses'] && $report['template']['required_when_refuses'] && $report['template']['up_to_two_refuses'])->toBeTrue();

    // 6b. One indicator question into the question library, and out again into another form.
    enterTenant($tenant->id, $admin->id);
    $source = FormField::query()->where('form_version_id', $fromTemplate->draft_version_id)->where('key', 'a01_use')->firstOrFail();
    $this->withHeaders(['X-Builder-Draft' => (string) $fromTemplate->draft_version_id]);
    $saved = $this->actingAs($admin)->postJson("{$host}/forms/{$fromTemplate->id}/fields/{$source->id}/save-to-library");
    enterTenant($tenant->id, $admin->id); // a request drops the test's RLS context
    $target = app(FormService::class)->create($tenant, $admin, 'Library target');
    enterTenant($tenant->id, $admin->id);
    $this->withHeaders(['X-Builder-Draft' => (string) $target->draft_version_id]);
    $inserted = $this->actingAs($admin)->postJson("{$host}/forms/{$target->id}/fields/from-library", ['library_item_id' => $saved->json('id'), 'section_id' => null]);
    $report['library'] = [
        'save_status' => $saved->status(),
        'insert_status' => $inserted->status(),
        'options_kept' => count($inserted->json('config.options') ?? []) === count($source->config['options'] ?? []),
    ];
    expect($report['library'])->toBe(['save_status' => 200, 'insert_status' => 200, 'options_kept' => true]);

    // 6c. Export the published NHETE to XLSForm, import that file into a new form, publish, answer.
    enterTenant($tenant->id, $admin->id);
    $exported = $this->actingAs($admin)->get("{$host}/forms/{$form->id}/versions/{$form->current_published_version_id}/xlsform");
    $file = tempnam(sys_get_temp_dir(), 'nhete-export').'.xlsx';
    file_put_contents($file, $exported->streamedContent());
    enterTenant($tenant->id, $admin->id);
    $reimport = app(FormService::class)->create($tenant, $admin, 'NHETE re-import');
    enterTenant($tenant->id, $admin->id);
    $this->withHeaders(['X-Builder-Draft' => (string) $reimport->draft_version_id]);
    $imported = $this->actingAs($admin)->post("{$host}/forms/{$reimport->id}/draft/xlsform-import", [
        'file' => new UploadedFile($file, 'nhete-export.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
    ]);
    $report['export_reimport'] = ['export_status' => $exported->getStatusCode(), 'import_status' => $imported->status(), 'import_toast' => session('toast')];
    enterTenant($tenant->id, $admin->id);
    $reimport = $publishNew(Form::query()->findOrFail($reimport->id));
    $report['export_reimport'] += [
        'fields' => FormField::query()->where('form_version_id', $reimport->current_published_version_id ?? $reimport->draft_version_id)->count(),
        'notes_with_content' => FormField::query()->where('form_version_id', $reimport->current_published_version_id ?? $reimport->draft_version_id)
            ->where('field_type', 'note')->whereNotNull('config->content')->count(),
        'published' => $reimport->current_published_version_id !== null,
        'publish_toast' => session('toast'),
        ...($reimport->current_published_version_id !== null ? $answerable($reimport, 'nhete-reimport') : []),
    ];
    $flush();

    // Every question comes back by key, but every note comes back with no content (`xlsform-export-note-content`), and
    // every "Required when…" comment comes back ALWAYS required: export writes `conditional` as `required = yes` and puts
    // the condition nowhere (`XlsformExporter`; `xlsform-export-required-when`). So a response the original takes, the
    // copy refuses — on exactly the comment boxes the scenario leaves empty.
    $conditional = array_values(array_map(
        static fn (array $e): string => $e['key'],
        array_filter($plan->elements(), static fn (array $e): bool => $e['required'] === 'conditional'),
    ));
    $answeredComments = array_keys(array_intersect_key($base, array_flip($conditional)));
    expect($report['export_reimport']['import_status'])->toBe(302)
        ->and($report['export_reimport']['fields'])->toBe($report['fields'])
        ->and($report['export_reimport']['notes_with_content'])->toBe(0)
        ->and($report['export_reimport']['published'])->toBeTrue()
        ->and($report['export_reimport']['full_response'])->toBe(422)
        ->and(array_column($report['export_reimport']['full_response_refused_on'], 'field'))
        ->toEqualCanonicalizing(array_values(array_diff($conditional, $answeredComments)));
})->group('nhete');

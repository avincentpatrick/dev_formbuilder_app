<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldRevision;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M240 (`builder-copy-section`, `D224`, `D225`) — copying a section, ticked questions, or one question.
|--------------------------------------------------------------------------
| Through the real routes: every link BETWEEN the copied rows points at the copies (conditions, "Required when…" rules,
| formulas, formula defaults, piped labels/hints/placeholders and their translations, the Other-specify pair, a repeat's
| `count(${section})`), every link to anything outside stays where it was, the keys count up, the copies land right after
| their sources with no "Moved" history for anyone, and every copy publishes through both gates.
|
| EVERY `${` literal is SINGLE-quoted: PHP 8.3 dropped `${var}` interpolation, so a double-quoted one silently loses it.
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

/** @return array{0: Tenant, 1: User, 2: Form} */
function copyWorkspace(string $title = 'Copy test'): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return [$tenant, $admin, app(FormService::class)->create($tenant, $admin, $title)];
}

/** @param array<string, mixed> $extra */
function copySection(FormVersion $version, string $key, int $sequence, array $extra = []): FormSection
{
    return FormSection::create(array_merge([
        'form_version_id' => $version->id, 'key' => $key, 'label' => ucfirst($key), 'sequence' => $sequence,
    ], $extra));
}

/** @param array<string, mixed> $attributes */
function copyRule(FormField $on, array $attributes): FormFieldValidation
{
    return FormFieldValidation::create(array_merge([
        'form_version_id' => $on->form_version_id, 'form_field_id' => $on->id, 'sequence' => 0,
    ], $attributes));
}

/** @return list<array{value: string, label: string}> */
function copyOptions(string ...$values): array
{
    return array_map(static fn (string $v): array => ['value' => $v, 'label' => ucfirst($v)], $values);
}

/**
 * One indicator-shaped block in section `s1`, between an outside question (`hh_name`, in `intro`) and a later section
 * (`s2`). Every kind of link a copy must carry is here, inside the block and out of it.
 *
 * @return array<string, FormField>
 */
function indicatorBlock(FormVersion $v, User $u): array
{
    copySection($v, 'intro', 0);
    $s1 = copySection($v, 's1', 1, ['label' => 'Block for ${hh_name}', 'relevant_expression' => '${hh_name} != \'\'']);
    $s2 = copySection($v, 's2', 2);
    $intro = FormSection::query()->where('form_version_id', $v->id)->where('key', 'intro')->firstOrFail();

    $f = [];
    $f['hh_name'] = addFormField($v, $u, 'hh_name', FieldType::ShortText, 0, ['form_section_id' => $intro->id]);
    $f['q1_keep'] = addFormField($v, $u, 'q1_keep', FieldType::Dropdown, 1, [
        'form_section_id' => $s1->id, 'is_required' => RequiredMode::Required,
        'config' => ['options' => copyOptions('yes', 'close', 'no', 'other'), 'other_specify_key' => 'q1_keep_other'],
    ]);
    $f['q1_keep_other'] = addFormField($v, $u, 'q1_keep_other', FieldType::ShortText, 2, [
        'form_section_id' => $s1->id, 'label' => 'Please specify', 'relevant_expression' => 'selected(${q1_keep}, \'other\')',
    ]);
    $f['q1_cut'] = addFormField($v, $u, 'q1_cut', FieldType::Dropdown, 3, [
        'form_section_id' => $s1->id, 'relevant_expression' => '${q1_keep} = \'yes\'',
        'config' => ['options' => copyOptions('change', 'keep')],
    ]);
    $f['q1_ph'] = addFormField($v, $u, 'q1_ph', FieldType::Calculated, 4, [
        'form_section_id' => $s1->id,
        'config' => ['calculated_formula' => 'if(${q1_cut} = \'change\', \'Why change?\', \'Say more\')', 'calculated_type' => 'text'],
    ]);
    $f['q1_comment'] = addFormField($v, $u, 'q1_comment', FieldType::LongText, 5, [
        'form_section_id' => $s1->id, 'is_required' => RequiredMode::Conditional,
        'label' => 'Comment on ${q1_keep}', 'label_translations' => ['fil' => 'Puna sa ${q1_keep}'],
        'hint' => 'For ${hh_name}', 'placeholder' => '${q1_ph}',
    ]);
    $f['q1_count'] = addFormField($v, $u, 'q1_count', FieldType::Integer, 6, ['form_section_id' => $s1->id]);
    $f['q1_more'] = addFormField($v, $u, 'q1_more', FieldType::Integer, 7, [
        'form_section_id' => $s1->id, 'default_value' => '${q1_count} + 1', 'default_value_is_expression' => true,
    ]);
    $f['q2_other'] = addFormField($v, $u, 'q2_other', FieldType::ShortText, 8, ['form_section_id' => $s2->id]);

    // "Required when… Any of": a copied sibling, another copied sibling, and a question outside the block, in one group.
    $group = (string) Str::uuid();
    foreach ([['q1_cut', 'change', null], ['q1_keep', 'close', 'or'], ['hh_name', 'skip', 'or']] as $n => [$related, $value, $op]) {
        copyRule($f['q1_comment'], [
            'rule_type' => 'required_if', 'operator' => 'eq', 'rule_value' => $value, 'related_form_field_id' => $f[$related]->id,
            'logic_group' => $group, 'logic_operator' => $op, 'sequence' => $n, 'error_message' => 'Tell us more.',
        ]);
    }
    copyRule($f['q1_more'], ['expression' => '. >= ${q1_count}', 'error_message' => 'Too few.']);

    return $f;
}

/** @return array<string, FormField> key ⇒ field, for the draft */
function draftFields(Form $form): array
{
    return FormField::query()->where('form_version_id', $form->draft_version_id)->get()->keyBy('key')->all();
}

it('copies a section with its questions, every link inside the copy pointing at the copies and every other link kept', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $f = indicatorBlock($form->draftVersion, $admin);
    $s1 = FormSection::query()->where('form_version_id', $form->draft_version_id)->where('key', 's1')->firstOrFail();
    // s2 moves down one to make room; its save token must not move with it (M176).
    $untouchedStamp = (string) FormSection::query()->where('form_version_id', $form->draft_version_id)->where('key', 's2')->value('updated_at');
    test()->withHeaders(builderHeaders($form));

    $reply = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/sections/{$s1->id}/duplicate")
        ->assertOk()
        ->assertJsonPath('section.key', 's3')               // s2 is taken, so the section's number moves on to 3
        ->assertJsonPath('section.label', 'Block for ${hh_name} (copy)')
        ->assertJsonPath('section.relevant_expression', '${hh_name} != \'\'') // names a question outside: kept
        ->assertJsonCount(7, 'copies');

    // Each copy beside the id of its source, in source order, with its rules naming the copied siblings by key.
    expect(array_column($reply->json('copies'), 'source_id'))->toBe(array_map(
        static fn (string $k): string => $f[$k]->id,
        ['q1_keep', 'q1_keep_other', 'q1_cut', 'q1_ph', 'q1_comment', 'q1_count', 'q1_more'],
    ));
    $comment = collect($reply->json('copies'))->firstWhere('field.key', 'q2_comment')['field'];
    expect(array_column($comment['validations'], 'related_field_key'))->toBe(['q2_cut', 'q2_keep', 'hh_name']);

    enterTenant($tenant->id, $admin->id);
    $d = draftFields($form);
    $copy = FormSection::query()->where('form_version_id', $form->draft_version_id)->where('key', 's3')->firstOrFail();

    // Keys counted up as one block; the questions keep their labels in a section copy.
    expect(collect($d)->filter(fn (FormField $x) => $x->form_section_id === $copy->id)->keys()->sort()->values()->all())
        ->toBe(['q2_comment', 'q2_count', 'q2_cut', 'q2_keep', 'q2_keep_other', 'q2_more', 'q2_ph'])
        // q2_other was already taken by s2's question, and is untouched by the copy.
        ->and($d['q2_other']->form_section_id)->not->toBe($copy->id);

    // Every link inside the copy points at the copies…
    expect($d['q2_keep']->config['other_specify_key'])->toBe('q2_keep_other')
        ->and($d['q2_keep_other']->relevant_expression)->toBe('selected(${q2_keep}, \'other\')')
        ->and($d['q2_cut']->relevant_expression)->toBe('${q2_keep} = \'yes\'')
        ->and($d['q2_ph']->config['calculated_formula'])->toBe('if(${q2_cut} = \'change\', \'Why change?\', \'Say more\')')
        ->and($d['q2_comment']->placeholder)->toBe('${q2_ph}')
        ->and($d['q2_comment']->label)->toBe('Comment on ${q2_keep}')
        ->and($d['q2_comment']->label_translations)->toBe(['fil' => 'Puna sa ${q2_keep}'])
        ->and($d['q2_more']->default_value)->toBe('${q2_count} + 1')
        // …and every link to a question outside it is kept.
        ->and($d['q2_comment']->hint)->toBe('For ${hh_name}');

    $rules = FormFieldValidation::query()->where('form_field_id', $d['q2_comment']->id)->orderBy('sequence')->get();
    expect($rules->pluck('related_form_field_id')->all())->toBe([$d['q2_cut']->id, $d['q2_keep']->id, $d['hh_name']->id])
        ->and($rules->pluck('logic_group')->unique()->count())->toBe(1)
        ->and($rules->first()->logic_group)->not->toBe(FormFieldValidation::query()->where('form_field_id', $d['q1_comment']->id)->value('logic_group'))
        ->and(FormFieldValidation::query()->where('form_field_id', $d['q2_more']->id)->value('expression'))->toBe('. >= ${q2_count}');

    // The source is untouched.
    expect(FormFieldValidation::query()->where('form_field_id', $d['q1_comment']->id)->orderBy('sequence')->pluck('related_form_field_id')->all())
        ->toBe([$d['q1_cut']->id, $d['q1_keep']->id, $d['hh_name']->id])
        ->and($d['q1_keep']->config['other_specify_key'])->toBe('q1_keep_other');

    // Right after its source, in the reply's order and in the database; nothing moved, nothing re-stamped.
    $sections = FormSection::query()->where('form_version_id', $form->draft_version_id)->orderBy('sequence')->pluck('key')->all();
    expect($sections)->toBe(['intro', 's1', 's3', 's2'])
        ->and(array_column($reply->json('order.sections'), 'id'))
        ->toBe(FormSection::query()->where('form_version_id', $form->draft_version_id)->orderBy('sequence')->pluck('id')->all())
        ->and((string) FormSection::query()->where('form_version_id', $form->draft_version_id)->where('key', 's2')->value('updated_at'))
        ->toBe($untouchedStamp)
        ->and(FormFieldRevision::query()->where('kind', 'moved')->count())->toBe(0);

    // And it publishes, through every gate.
    app(PublishService::class)->publish($form->refresh(), $admin);
    expect($form->refresh()->current_published_version_id)->not->toBeNull();
});

it('copies ticked questions where they stand, right after the last ticked one of each section, as one numbered block', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $v = $form->draftVersion;
    $s1 = copySection($v, 'one', 0);
    $s2 = copySection($v, 'two', 1);
    $a1 = addFormField($v, $admin, 'a1', FieldType::ShortText, 0, ['form_section_id' => $s1->id]);
    $a2 = addFormField($v, $admin, 'a2', FieldType::ShortText, 1, ['form_section_id' => $s1->id, 'is_required' => RequiredMode::Conditional]);
    $a3 = addFormField($v, $admin, 'a3', FieldType::ShortText, 2, ['form_section_id' => $s1->id, 'relevant_expression' => '${a2} = \'y\'']);
    $b1 = addFormField($v, $admin, 'b1', FieldType::ShortText, 3, ['form_section_id' => $s2->id, 'section_sequence' => 0]);
    $b2 = addFormField($v, $admin, 'b2', FieldType::ShortText, 4, ['form_section_id' => $s2->id, 'section_sequence' => 1]);
    copyRule($a2, ['rule_type' => 'required_if', 'operator' => 'eq', 'rule_value' => 'x', 'related_form_field_id' => $a1->id]);
    $a1Stamp = (string) $a1->refresh()->updated_at;
    test()->withHeaders(builderHeaders($form));

    // Ticked out of order: the copy follows the order on screen.
    $reply = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields/duplicate", ['field_ids' => [$b1->id, $a3->id, $a2->id]])
        ->assertOk()
        ->assertJsonPath('section', null)
        ->assertJsonCount(3, 'copies');

    // a3 is taken, so one step for the whole copy is 2: a2 → a4, a3 → a5, b1 → b3.
    expect(array_column(array_column($reply->json('copies'), 'field'), 'key'))->toBe(['a4', 'a5', 'b3'])
        ->and(array_column(array_column($reply->json('copies'), 'field'), 'label'))->toBe(['A2 (copy)', 'A3 (copy)', 'B1 (copy)']);

    enterTenant($tenant->id, $admin->id);
    $d = draftFields($form);
    $order = fn (FormSection $s) => FormField::query()->where('form_section_id', $s->id)->orderBy('sequence')->pluck('key')->all();

    expect($order($s1))->toBe(['a1', 'a2', 'a3', 'a4', 'a5'])
        ->and($order($s2))->toBe(['b1', 'b3', 'b2'])
        // a3's condition named a2, which was copied with it: the copy names the copy.
        ->and($d['a5']->relevant_expression)->toBe('${a4} = \'y\'')
        // a2's rule compared with a1, which was not: the copy still compares with a1.
        ->and(FormFieldValidation::query()->where('form_field_id', $d['a4']->id)->value('related_form_field_id'))->toBe($a1->id)
        // A move forgets an import's per-section numbers; so does a copy placed among them.
        ->and(FormField::query()->where('form_version_id', $v->id)->whereNotNull('section_sequence')->count())->toBe(0)
        ->and((string) $d['a1']->updated_at)->toBe($a1Stamp)
        ->and(FormFieldRevision::query()->where('kind', 'moved')->count())->toBe(0)
        ->and(array_column($reply->json('order.fields'), 'id'))
        ->toBe(FormField::query()->where('form_version_id', $v->id)->orderBy('sequence')->pluck('id')->all());

    app(PublishService::class)->publish($form->refresh(), $admin);
    expect($form->refresh()->current_published_version_id)->not->toBeNull();
});

it('gives one question\'s Duplicate the same rules: the number counts up and the copy lands right below', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $v = $form->draftVersion;
    $q1 = addFormField($v, $admin, 'q1', FieldType::ShortText, 0, ['label' => str_repeat('L', 500)]);
    addFormField($v, $admin, 'next', FieldType::ShortText, 1);
    test()->withHeaders(builderHeaders($form));

    $reply = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields/{$q1->id}/duplicate")
        ->assertOk()
        ->assertJsonPath('key', 'q2');

    // The label stays inside its 500-character column, " (copy)" whole at the end.
    expect($reply->json('label'))->toBe(str_repeat('L', 493).' (copy)');

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->where('form_version_id', $v->id)->orderBy('sequence')->pluck('key')->all())->toBe(['q1', 'q2', 'next']);
});

it('never gives a copy a key some published version held, though the draft no longer does', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $v = $form->draftVersion;
    addFormField($v, $admin, 'q1', FieldType::ShortText, 0);
    addFormField($v, $admin, 'q2', FieldType::ShortText, 1);
    app(PublishService::class)->publish($form->refresh(), $admin);
    $form->refresh();

    enterTenant($tenant->id, $admin->id);
    // q2 is deleted in the new draft; version 1 still holds it, so its answers are under that key.
    FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'q2')->firstOrFail()->delete();
    $q1 = FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'q1')->firstOrFail();
    test()->withHeaders(builderHeaders($form));

    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields/duplicate", ['field_ids' => [$q1->id]])
        ->assertOk()
        ->assertJsonPath('copies.0.field.key', 'q3');
});

it('re-points a repeatable section\'s own key in a copy of it, as count() names the repeat', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $v = $form->draftVersion;
    $kids = copySection($v, 'kids_1', 0, ['is_repeatable' => true, 'min_instances' => 0, 'max_instances' => 5]);
    addFormField($v, $admin, 'kid_name_1', FieldType::ShortText, 0, ['form_section_id' => $kids->id]);
    addFormField($v, $admin, 'kid_note_1', FieldType::ShortText, 1, [
        'form_section_id' => $kids->id, 'relevant_expression' => 'count(${kids_1}) > 1 and ${kid_name_1} != \'\'',
    ]);
    test()->withHeaders(builderHeaders($form));

    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/sections/{$kids->id}/duplicate")
        ->assertOk()
        ->assertJsonPath('section.key', 'kids_2')
        ->assertJsonPath('section.is_repeatable', true)
        ->assertJsonPath('section.max_instances', 5);

    enterTenant($tenant->id, $admin->id);
    expect(draftFields($form)['kid_note_2']->relevant_expression)->toBe('count(${kids_2}) > 1 and ${kid_name_2} != \'\'');
});

it('undoes a copy in one request: the section with the questions named, or the questions alone', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $f = indicatorBlock($form->draftVersion, $admin);
    $s1 = FormSection::query()->where('form_version_id', $form->draft_version_id)->where('key', 's1')->firstOrFail();
    $before = FormField::query()->where('form_version_id', $form->draft_version_id)->count();
    test()->withHeaders(builderHeaders($form));

    $made = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/sections/{$s1->id}/duplicate")->assertOk();
    $copyIds = array_column(array_column($made->json('copies'), 'field'), 'id');

    $this->actingAs($admin)
        ->deleteJson("http://acme.meridian.test/forms/{$form->id}/sections/{$made->json('section.id')}", ['field_ids' => $copyIds])
        ->assertOk()->assertJsonPath('deleted', true);

    $ticked = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields/duplicate", ['field_ids' => [$f['q1_cut']->id, $f['q1_ph']->id]])
        ->assertOk();
    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields/delete-many", [
            'field_ids' => array_column(array_column($ticked->json('copies'), 'field'), 'id'),
        ])
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->where('form_version_id', $form->draft_version_id)->count())->toBe($before)
        ->and(FormSection::query()->where('form_version_id', $form->draft_version_id)->pluck('key')->sort()->values()->all())
        ->toBe(['intro', 's1', 's2']);
});

it('refuses a copy without the draft header, from a stale page, by a viewer, and of another form\'s question', function (): void {
    [$tenant, $admin, $form] = copyWorkspace();
    $q1 = addFormField($form->draftVersion, $admin, 'q1', FieldType::ShortText, 0);
    $section = copySection($form->draftVersion, 'sec_1', 0);
    $other = app(FormService::class)->create($tenant, $admin, 'Another');
    $foreign = addFormField($other->draftVersion, $admin, 'z1', FieldType::ShortText, 0);
    $url = "http://acme.meridian.test/forms/{$form->id}";

    // No header: the builder.draft middleware's 428, before anything is read.
    $this->actingAs($admin)->postJson("{$url}/fields/duplicate", ['field_ids' => [$q1->id]])->assertStatus(428);
    $this->actingAs($admin)->postJson("{$url}/sections/{$section->id}/duplicate")->assertStatus(428);

    test()->withHeaders(builderHeaders($form));
    $this->actingAs($admin)->postJson("{$url}/fields/duplicate", ['field_ids' => []])->assertStatus(422);
    $this->actingAs($admin)->postJson("{$url}/fields/duplicate", ['field_ids' => [$foreign->id]])->assertStatus(422);
    $this->actingAs($admin)->postJson("{$url}/fields/delete-many", ['field_ids' => [$foreign->id]])->assertStatus(422);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    $this->actingAs($viewer)->postJson("{$url}/fields/duplicate", ['field_ids' => [$q1->id]])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$url}/sections/{$section->id}/duplicate")->assertForbidden();

    // Published since the page loaded: the stale page is told to reload, nothing is copied.
    enterTenant($tenant->id, $admin->id);
    $staleHeaders = builderHeaders($form);
    app(PublishService::class)->publish($form->refresh(), $admin);
    test()->withHeaders($staleHeaders);
    $this->actingAs($admin)->postJson("{$url}/fields/duplicate", ['field_ids' => [$q1->id]])
        ->assertStatus(409)->assertJsonPath('code', 'draft_superseded');

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->where('key', 'q2')->exists())->toBeFalse();
});

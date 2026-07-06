<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->serializer = new SchemaSnapshotSerializer;
});

/**
 * Build a version with the given fields (each spec = [key, type, label, seq]), inserting them in the
 * order given — so two versions with the SAME (key, seq, …) content inserted in different orders differ
 * only in row ids and DB return order.
 *
 * @param  list<array{key: string, type: FieldType, label: string, seq: int}>  $specs
 */
function versionWith(User $user, array $specs): FormVersion
{
    $version = makeDraftVersion(makeForm($user));
    foreach ($specs as $spec) {
        addFormField($version, $user, $spec['key'], $spec['type'], $spec['seq'], ['label' => $spec['label']]);
    }

    return $version->refresh();
}

it('produces an identical checksum regardless of row id or DB insertion order', function (): void {
    $specs = [
        ['key' => 'name', 'type' => FieldType::ShortText, 'label' => 'Name', 'seq' => 0],
        ['key' => 'age', 'type' => FieldType::Integer, 'label' => 'Age', 'seq' => 1],
        ['key' => 'email', 'type' => FieldType::Email, 'label' => 'Email', 'seq' => 2],
    ];

    $a = versionWith($this->user, $specs);
    $b = versionWith($this->user, array_reverse($specs)); // same (key,seq) content, inserted in reverse

    expect($this->serializer->checksum($a))
        ->toBe($this->serializer->checksum($b))
        ->and($this->serializer->checksum($a))->toMatch('/^[0-9a-f]{64}$/');
});

it('changes the checksum when any field content changes', function (): void {
    $base = [
        ['key' => 'name', 'type' => FieldType::ShortText, 'label' => 'Name', 'seq' => 0],
        ['key' => 'age', 'type' => FieldType::Integer, 'label' => 'Age', 'seq' => 1],
    ];
    $relabelled = [
        ['key' => 'name', 'type' => FieldType::ShortText, 'label' => 'Full name', 'seq' => 0],
        ['key' => 'age', 'type' => FieldType::Integer, 'label' => 'Age', 'seq' => 1],
    ];

    expect($this->serializer->checksum(versionWith($this->user, $base)))
        ->not->toBe($this->serializer->checksum(versionWith($this->user, $relabelled)));
});

it('strips ids and references sections by key in the snapshot', function (): void {
    $version = versionWith($this->user, [
        ['key' => 'name', 'type' => FieldType::ShortText, 'label' => 'Name', 'seq' => 0],
    ]);

    $snapshot = $this->serializer->snapshot($version);
    $json = json_encode($snapshot);

    expect($snapshot)->toHaveKeys(['sections', 'fields'])
        ->and($json)->not->toContain($version->id)      // no form_version_id leaks in
        ->and($json)->not->toContain($this->tenant->id) // no tenant_id leaks in
        ->and($snapshot['fields'][0])->toHaveKey('key', 'name')
        ->and($snapshot['fields'][0])->not->toHaveKey('id');
});

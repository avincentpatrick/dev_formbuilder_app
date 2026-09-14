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

/*
|--------------------------------------------------------------------------
| WHAT `form_versions.checksum` IS, AND WHAT IT IS NOT (M92).
|--------------------------------------------------------------------------
| The column was documented in three places as "SHA-256 of the canonical schema_snapshot", which is true
| of the value that was HASHED and false of the value that is STORED. `schema_snapshot` is `jsonb`, and
| Postgres does not preserve key order: it stores an object's keys sorted by LENGTH first and then by
| bytes. PHP's ksort is pure bytewise. So the array that comes back off the column is a re-ordering of
| the array that was hashed, and re-hashing it yields a different digest.
|
| ⛔ THE ROW THAT FILED THIS OFFERED A WORKED EXAMPLE THAT IS INERT, AND THE CORRECTION MATTERS BECAUSE
| SOMEONE WOULD OTHERWISE HAVE "REPRODUCED" IT AND FOUND NOTHING. It said snapshot() builds
| `['sections' => …, 'fields' => …]` and that Postgres reorders that to `fields,sections`. snapshot()
| ALREADY ksorts recursively before returning (SchemaSnapshotSerializer, and it has since the column was
| created), so the top level is `fields,sections` on BOTH sides and agrees. The divergence is one level
| down, where the two orderings genuinely part: ksort gives `config, hint, key, label` and jsonb gives
| `key`(3), `hint`(4), `label`(5), `config`(6).
|
| ⚠️ THESE TWO ARMS ARE A BOUND, NOT A COMPLAINT. The first records the live property so the documents
| cannot drift back to claiming re-derivability. The second is the one worth having: it proves the
| divergence is key ORDER and NOTHING ELSE — no numeric widening, no whitespace, no lost precision — by
| re-deriving the stored digest exactly after a recursive sort. If a numeric ever round-trips as `1`
| where `1.0` was hashed (the caveat the serializer's own docblock records as "noted, not yet
| load-bearing"), the second arm is what goes red and says so.
|
| ⚠️ THE SORT HERE IS DELIBERATELY A SECOND IMPLEMENTATION rather than a promoted private method. A test
| that re-used the production canonicaliser would prove the production canonicaliser agrees with itself.
*/

/**
 * Recursively sort an array's string keys bytewise, leaving list order alone.
 *
 * @param  array<array-key, mixed>  $value
 * @return array<array-key, mixed>
 */
function checksumSortRecursive(array $value): array
{
    foreach ($value as $k => $v) {
        if (is_array($v)) {
            $value[$k] = checksumSortRecursive($v);
        }
    }

    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

it('stores a checksum that does NOT re-derive from the jsonb column it sits beside', function (): void {
    $version = versionWith($this->user, [
        ['key' => 'name', 'type' => FieldType::ShortText, 'label' => 'Name', 'seq' => 0],
        ['key' => 'age', 'type' => FieldType::Integer, 'label' => 'Age', 'seq' => 1],
    ]);

    $snapshot = $this->serializer->snapshot($version);
    $stored = $this->serializer->checksumOf($snapshot);

    // Persist exactly as PublishService does, then read the column back through the `array` cast.
    $version->forceFill(['schema_snapshot' => $snapshot, 'checksum' => $stored])->save();
    $readBack = FormVersion::query()->whereKey($version->getKey())->firstOrFail()->schema_snapshot;

    // Floors first: an empty snapshot would satisfy every comparison below by being nothing.
    expect($readBack)->toBeArray()->not->toBeEmpty();
    expect($readBack['fields'] ?? [])->toHaveCount(2);

    expect($this->serializer->checksumOf($readBack))->not->toBe(
        $stored,
        'the stored checksum now re-derives from the stored jsonb. That is an IMPROVEMENT, not a '
        .'failure — but three documents describe this column as a version identity rather than a '
        .'verifiable integrity hash, and they must be corrected in the same commit that earns it.'
    );
});

it('diverges by key ORDER and nothing else, which is what bounds the defect', function (): void {
    $version = versionWith($this->user, [
        ['key' => 'name', 'type' => FieldType::ShortText, 'label' => 'Name', 'seq' => 0],
        ['key' => 'age', 'type' => FieldType::Integer, 'label' => 'Age', 'seq' => 1],
    ]);

    $snapshot = $this->serializer->snapshot($version);
    $stored = $this->serializer->checksumOf($snapshot);

    $version->forceFill(['schema_snapshot' => $snapshot, 'checksum' => $stored])->save();
    $readBack = FormVersion::query()->whereKey($version->getKey())->firstOrFail()->schema_snapshot;

    expect($readBack)->toBeArray()->not->toBeEmpty();

    // ⛔ THE WHOLE POINT. Re-sorting the read-back recovers the stored digest EXACTLY. Anything else
    //    that the round trip could have changed — a float rendered as an int, a lost trailing zero, a
    //    re-escaped string — would land here as a mismatch, and this is the only place it would.
    expect($this->serializer->checksumOf(checksumSortRecursive($readBack)))->toBe(
        $stored,
        'the jsonb round trip changed something OTHER than key order. Key order is recoverable and was '
        .'the known limitation; anything else means the column has lost information and the numeric '
        .'caveat in SchemaSnapshotSerializer has stopped being hypothetical.'
    );
});

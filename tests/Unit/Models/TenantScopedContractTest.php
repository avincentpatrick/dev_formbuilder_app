<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;

/*
|--------------------------------------------------------------------------
| Every model carrying `BelongsToTenant` must also `implements TenantScoped` (J3c2).
|--------------------------------------------------------------------------
| ⚠️ THIS PAIRING IS A CONTRACT THAT USED TO BE ENFORCED ONLY BY A SENTENCE IN A DOCBLOCK, AND BREAKING IT
| FAILS SILENTLY IN EXACTLY ONE DIRECTION — which is what makes it worth a gate rather than a review note.
|
| `BelongsToTenant::bootBelongsToTenant()` adds its global READ scope unconditionally, but gates the
| `creating` hook that auto-fills `tenant_id` on `$model instanceof TenantScoped`. So a model with the
| trait and without the interface looks completely normal: it scopes its reads, its relations work, its
| factory works. Only INSERTs are broken, and they are broken in a way that names the wrong culprit —
| `tenant_id` arrives null, and PostgreSQL reports **42501 "new row violates row-level security policy"**
| rather than a null-violation, so the error points at the policy and the RLS context instead of at the
| four missing characters in the class declaration.
|
| `App\Models\GoogleAuthRequest` shipped that way for one commit in J3c2 and cost a 26-minute test run to
| localise. Every other tenant-scoped model in the tree already had the pairing, so this test passes on the
| whole existing set the moment it is written — its value is entirely in the next one.
|
| Source-text-free: it reflects over the real classes, so a model that satisfies the contract by any means
| (including inheriting it) passes honestly.
*/

/**
 * Every concrete model class under app/Models.
 *
 * ⚠️ The path is derived from `__DIR__` rather than from `base_path()`, because `tests/Pest.php` binds the
 * application `->in('Feature')` only — a Unit test has no booted container and the helper does not exist.
 *
 * @return list<class-string>
 */
function modelClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Models/*.php') ?: [] as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('pairs the BelongsToTenant trait with the TenantScoped interface on every model', function (): void {
    $offenders = [];

    foreach (modelClasses() as $class) {
        $usesTrait = in_array(BelongsToTenant::class, class_uses_recursive($class), true);

        if ($usesTrait && ! is_subclass_of($class, TenantScoped::class)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These models use BelongsToTenant without implementing TenantScoped, so tenant_id is NEVER',
        'auto-filled on insert and every write is refused as an RLS violation:',
        ...$offenders,
    ]));
});

it('is actually looking at models, so the assertion above cannot pass vacuously', function (): void {
    // ⚠️ THE FLOOR MATTERS. A glob that silently matched nothing — a moved directory, a changed
    // namespace — would make the case above green while checking zero classes, which is the failure mode
    // every source-scanning guard in this repository carries and the reason each one asserts a floor.
    $classes = modelClasses();
    $tenantScoped = array_filter(
        $classes,
        fn (string $class): bool => in_array(BelongsToTenant::class, class_uses_recursive($class), true),
    );

    expect(count($classes))->toBeGreaterThan(30)
        ->and(count($tenantScoped))->toBeGreaterThan(10);
});

<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Enums\TenantStatus;
use App\Exceptions\Jobs\InvalidJobPayloadException;
use App\Jobs\MaintenanceJob;
use App\Jobs\ScanAttachmentJob;
use App\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Tests\Support\Jobs\ProbeMaintenanceJob;
use Tests\Support\Jobs\ProbeTenantJob;

/**
 * The structural contracts of the two base classes (ADR-0007 §D2/§D3/§D5/§D6/§D7).
 *
 * These are the reflection-level counterparts to the behavioural tests in this directory: they assert
 * the SHAPE of a job rather than what it does, which is what scripts/job-payload-lint.php will enforce
 * at merge time in H2b. Keeping both means a rule is exercised even before the linter exists.
 *
 * A Feature test rather than Unit: `phpunit.xml` only boots the container for Feature, and the §D2
 * assertion path reads the `tenants` table.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
});

// ── §D2: the runtime payload assertion ────────────────────────────────────────────────────────────

it('rejects an empty tenantId before touching the database', function (): void {
    expect(fn () => (new ProbeTenantJob(''))->handle())
        ->toThrow(InvalidJobPayloadException::class, 'must carry one');
});

it('rejects a tenantId that is not a uuid', function (): void {
    expect(fn () => (new ProbeTenantJob('not-a-uuid'))->handle())
        ->toThrow(InvalidJobPayloadException::class, 'not a uuid');
});

it('rejects the no-tenant sentinel uuid by name', function (): void {
    // BelongsToTenant substitutes this uuid when there is no context, so a job carrying it was
    // dispatched WITHOUT a tenant — the bug, not a valid target. It must not be reported as merely
    // "malformed", because the cause is entirely different.
    expect(fn () => (new ProbeTenantJob('00000000-0000-0000-0000-000000000000'))->handle())
        ->toThrow(InvalidJobPayloadException::class, 'no-tenant sentinel');
});

it('accepts a well-formed uuid', function (): void {
    enterTenant($this->tenant->id);

    expect(fn () => (new ProbeTenantJob($this->tenant->id))->handle())->not->toThrow(InvalidJobPayloadException::class);
});

// ── §D5/§D6/§D7: the shape every concrete job must have ───────────────────────────────────────────

/**
 * Every concrete ShouldQueue class in the application. In H2b scripts/job-payload-lint.php walks the
 * same set at merge time; until then this is the gate.
 *
 * @return list<class-string>
 */
function concreteJobClasses(): array
{
    $classes = [];

    // array_merge, NOT `+`: glob returns 0-indexed arrays, and `+` unions by key — so once the
    // subdirectory glob returns as many files as the top-level one, the top-level entries (ScanAttachmentJob,
    // the base classes) get shadowed and silently dropped. Concatenate so every job file is scanned.
    foreach (array_merge(glob(app_path('Jobs/**/*.php')), glob(app_path('Jobs/*.php'))) as $file) {
        $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path('Jobs/'))));

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        $classes[] = $class;
    }

    return $classes;
}

/** The queue a class targets via #[Queue], inherited from a parent if it declares none itself. */
function queueAttributeOf(string $class): ?string
{
    for ($r = new ReflectionClass($class); $r !== false; $r = $r->getParentClass()) {
        $attributes = $r->getAttributes(Queue::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->queue;
        }
    }

    return null;
}

/**
 * The properties a job actually serializes into its payload: its OWN public, non-static properties.
 * Excludes everything inherited from Illuminate\Bus\Queueable and the base classes, which is
 * framework/base state rather than payload.
 *
 * @return list<ReflectionProperty>
 */
function payloadPropertiesOf(string $class): array
{
    $inherited = array_merge(
        array_keys((new ReflectionClass(TenantAwareJob::class))->getDefaultProperties()),
        array_keys((new ReflectionClass(MaintenanceJob::class))->getDefaultProperties()),
    );

    $own = [];

    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->isStatic() || in_array($property->getName(), $inherited, true)) {
            continue;
        }

        $own[] = $property;
    }

    return $own;
}

it('finds the concrete jobs and payload properties it means to check', function (): void {
    // GUARDS THE GUARDS. If discovery silently matched nothing — a moved directory, a renamed
    // namespace — every reflection assertion below would pass vacuously while checking zero classes.
    expect(concreteJobClasses())->toContain(ScanAttachmentJob::class);

    // Likewise for the payload walk: it must see the job's OWN promoted constructor properties, and
    // must NOT see inherited framework/base state (which is not payload and is not scalar-typed).
    $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), payloadPropertiesOf(ScanAttachmentJob::class));

    expect($names)->toEqualCanonicalizing(['attachmentId', 'tenantId']);
});

it('gives every concrete job a queue from the five-name catalog', function (): void {
    foreach (concreteJobClasses() as $class) {
        $queue = queueAttributeOf($class);

        expect($queue)->not->toBeNull("{$class} declares no #[Queue] attribute (ADR-0007 §D6).")
            ->and(QueueName::values())->toContain($queue);
    }
});

it('never sets the $queue property on a job', function (): void {
    // Illuminate\Bus\Queueable:29 declares `public $queue;` UNTYPED. Redeclaring it with a different
    // initial value is a FATAL error, so the hazard cannot even be reached at runtime — what is
    // testable, and what matters, is that nobody assigns it a default: the #[Queue] attribute must be
    // the single source of the queue name. (Reflection attributes trait properties to the USING
    // class, so getDeclaringClass() cannot distinguish here — the default value can.)
    foreach ([...concreteJobClasses(), TenantAwareJob::class, MaintenanceJob::class] as $class) {
        expect((new ReflectionClass($class))->getDefaultProperties()['queue'] ?? null)
            ->toBeNull("{$class} sets \$queue; use #[Queue(QueueName::…)] instead (ADR-0007 §D6).");
    }
});

it('never declares $tries on a TenantAwareJob', function (): void {
    // §D7: a fairness deferral consumes an attempt, so bounding by attempts inverts §D9. retryUntil()
    // bounds these jobs, which also makes any $tries inert — i.e. documentation that lies.
    // (Queueable does NOT declare $tries, so absence of the property is the exact assertion.)
    foreach ([TenantAwareJob::class, ...concreteJobClasses()] as $class) {
        if ($class !== TenantAwareJob::class && ! is_subclass_of($class, TenantAwareJob::class)) {
            continue;
        }

        expect((new ReflectionClass($class))->hasProperty('tries'))
            ->toBeFalse("{$class} declares \$tries (ADR-0007 §D7).");
    }
});

it('types every job payload property as a scalar', function (): void {
    // §D5: SerializesModels::__unserialize runs BEFORE handle(), under a NULL GUC, so a model-typed
    // payload is a permanent failure. Enforced as a CLOSED WHITELIST rather than a model detector,
    // because "is this an Eloquent model" is undecidable from a type declaration alone — the
    // framework branches on the runtime VALUE, so mixed/untyped/object all reach restoreModel()
    // invisibly. Narrowing the language is the only decidable form of this rule.
    $allowed = ['string', 'int', 'float', 'bool', 'array'];

    foreach (concreteJobClasses() as $class) {
        foreach (payloadPropertiesOf($class) as $property) {
            $type = $property->getType();

            expect($type)->toBeInstanceOf(
                ReflectionNamedType::class,
                "{$class}::\${$property->getName()} is untyped or a union (ADR-0007 §D5).",
            );

            expect(in_array($type->getName(), $allowed, true))->toBeTrue(
                "{$class}::\${$property->getName()} is typed {$type->getName()}, which is not a scalar payload (ADR-0007 §D5).",
            );
        }
    }
});

it('seals $deleteWhenMissingModels against a subclass', function (): void {
    // §D5's named trap: flipping this to true would convert a loud RLS-visibility failure into a job
    // that silently deletes itself — silent data loss in place of an error.
    $property = (new ReflectionClass(TenantAwareJob::class))->getProperty('deleteWhenMissingModels');

    expect($property->isFinal())->toBeTrue()
        ->and($property->getDefaultValue())->toBeFalse();
});

// ── §D2/§D3: the base classes seal what must not be overridden ────────────────────────────────────

it('seals the methods that carry the tenant contract', function (): void {
    // An override of any of these silently loses the assertion, applyLocal, the transaction, or the
    // fairness limiter — with no runtime symptom on a warm worker.
    foreach (['handle', 'middleware', 'failed', 'retryUntil'] as $method) {
        expect((new ReflectionMethod(TenantAwareJob::class, $method))->isFinal())
            ->toBeTrue("TenantAwareJob::{$method}() must be final (ADR-0007 §D2).");
    }

    foreach (['handle', 'middleware', 'failed'] as $method) {
        expect((new ReflectionMethod(MaintenanceJob::class, $method))->isFinal())
            ->toBeTrue("MaintenanceJob::{$method}() must be final (ADR-0007 §D3).");
    }
});

it('applies the fairness limiter to every tenant job', function (): void {
    $middleware = (new ProbeTenantJob(Str::uuid7()->toString()))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class);
});

it('gives a MaintenanceJob a non-releasing overlap lock and no tenant', function (): void {
    $job = new ProbeMaintenanceJob;
    $middleware = $job->middleware();

    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        // §D7: the constructor defaults $releaseAfter to 0, NOT null — without dontRelease() an
        // overlapping tick calls release(0) and, with $tries = 1, fails the sweep immediately.
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($job->tries)->toBe(1)
        ->and(property_exists($job, 'tenantId'))->toBeFalse();
});

it('routes a MaintenanceJob to scheduled-maintenance by inheritance', function (): void {
    // The attribute is declared once on the base; ReadsClassAttributes walks getParentClass(), so
    // every sweep inherits it and cannot drift.
    $attributes = (new ReflectionClass(ProbeMaintenanceJob::class))->getAttributes(Queue::class);

    expect($attributes)->toBeEmpty(); // none of its own...

    expect((new ReflectionClass(MaintenanceJob::class))->getAttributes(Queue::class)[0]->newInstance()->queue)
        ->toBe(QueueName::ScheduledMaintenance->value); // ...inherited from here.
});

// ── §D3/§D13: the coupling that makes the tenant predicate real SQL ───────────────────────────────

it('keeps status among the tenant custom columns', function (): void {
    // LOAD-BEARING. Tenant::scopeActive() is only a real SQL predicate while 'status' is a custom
    // column. Remove it and stancl relocates the value into the `data` json column, at which point
    // where('status', …) matches NOTHING — every tenant silently vanishes from every fan-out, and no
    // other test in the suite would notice.
    expect(Tenant::getCustomColumns())->toContain('status');

    $suspended = Tenant::create(['name' => 'Halted', 'slug' => 'halted']);
    DB::table('tenants')->where('id', $suspended->id)->update(['status' => TenantStatus::Suspended->value]);

    expect(Tenant::query()->active()->pluck('id')->all())
        ->toContain($this->tenant->id)
        ->not->toContain($suspended->id);
});

<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Mail\TenantMail;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Notifications\Concerns\CarriesTenantBrand;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Notifications\Entitlements\QuotaOverageNotification;
use App\Notifications\ResumeLinkNotification;
use App\Notifications\Submissions\SubmissionPdfReadyNotification;
use App\Notifications\TenantInvitationNotification;
use App\Notifications\Webhooks\WebhookAutoDisabledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;

/**
 * H3 — the structural contract for queued transactional mail. Reflection-only, no database. It pins the
 * shape scripts/job-payload-lint.php enforces at merge time (so a regression is caught here too, with a
 * readable message) plus the two things the linter cannot: that the `mail` queue is threaded through the
 * compose worker + docs byte-identically, and that TenantMail is deliberately NOT ShouldQueue.
 *
 * The queued mail notifications ride the substrate WITHOUT extending TenantAwareJob (they run via the
 * framework's SendQueuedNotifications), so each must: implement ShouldQueue, name QueueName::Mail via
 * #[Queue], carry a scalar-only payload, and be listed in the linter's EXEMPT_JOBS.
 *
 * ⚠️ **THE LIST BELOW WAS THREE OF EIGHT UNTIL H23a4.** It was written in H3 when three existed, and the
 * five added since (H9b, H5b, H13b, H15a, H17) were never appended — so five classes were riding a
 * contract nothing checked. Widening it is part of this increment because H23a4 adds a member to every
 * one of them and the linter cannot see it (below).
 */
$queuedMailNotifications = [
    TenantInvitationNotification::class,
    QueuedVerifyEmail::class,
    QueuedResetPassword::class,
    ResumeLinkNotification::class,
    QuotaOverageNotification::class,
    WebhookAutoDisabledNotification::class,
    ConnectionRevokedNotification::class,
    SubmissionPdfReadyNotification::class,
];

it('implements ShouldQueue on every queued mail notification', function (string $class): void {
    expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue();
})->with($queuedMailNotifications);

it('routes every queued mail notification to QueueName::Mail via the #[Queue] attribute', function (string $class): void {
    $attributes = (new ReflectionClass($class))->getAttributes(QueueAttribute::class);

    expect($attributes)->toHaveCount(1)
        // The attribute normalizes the enum to its backing value (enum_value), so this is 'mail'.
        ->and($attributes[0]->newInstance()->queue)->toBe(QueueName::Mail->value);
})->with($queuedMailNotifications);

it('carries a scalar-only payload on every queued mail notification (no serialized model)', function (string $class): void {
    $constructor = (new ReflectionClass($class))->getConstructor();

    expect($constructor)->not->toBeNull();

    foreach ($constructor->getParameters() as $parameter) {
        $type = $parameter->getType();

        // SubmissionPdfOutcome is a backed enum, which R3 allows alongside the builtins — but it is not a
        // CONSTRUCTOR-parameter concern here because it is typed as an enum class, so allow that shape.
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            expect(enum_exists($type->getName()))->toBeTrue();

            continue;
        }

        expect($type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($type->isBuiltin())->toBeTrue()
            ->and($type->getName())->toBeIn(['string', 'int', 'float', 'bool', 'array']);
    }
})->with($queuedMailNotifications);

it('carries the brand palette as a scalar array on every queued mail notification', function (string $class): void {
    // ⚠️ `scripts/job-payload-lint.php` R3 CANNOT SEE THIS PROPERTY. Its pass reads
    // `Class_::getProperties()`, which returns only properties declared in the class BODY — a
    // trait-flattened one is invisible to it. So the gate a reader would assume guards `$brand` does not,
    // and this test is the guard.
    //
    // Targeted BY NAME rather than by iterating getProperties(IS_PUBLIC), which would be wrong twice over:
    // trait members report the USING class from getDeclaringClass(), so Illuminate\Bus\Queueable's nine
    // untyped publics ($connection, $queue, $delay, $middleware, $chained, …) would all look locally
    // declared and fail, as would QueuedResetPassword's inherited untyped $token.
    $reflection = new ReflectionClass($class);

    expect(in_array(CarriesTenantBrand::class, class_uses_recursive($class), true))->toBeTrue()
        ->and($reflection->hasProperty('brand'))->toBeTrue();

    $property = $reflection->getProperty('brand');
    $type = $property->getType();

    expect($property->isPublic())->toBeTrue()
        ->and($property->isReadOnly())->toBeFalse()   // set after construction, at the dispatch site
        ->and($type)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($type->getName())->toBe('array')
        // Defaults to empty so a dispatch site that forgets withBrand() renders the product palette
        // instead of throwing "undefined variable" on a queue worker nobody is watching.
        ->and($property->getDefaultValue())->toBe([]);
})->with($queuedMailNotifications);

it('exempts every queued mail notification from the job-payload linter R1 rule', function (string $class): void {
    // EXEMPT_JOBS lives in a standalone script (no framework boot), so assert the FQCN string is present
    // rather than reflecting the constant. If this fails, the linter would flag the class in CI.
    $linter = file_get_contents(base_path('scripts/job-payload-lint.php'));

    expect($linter)->toContain("'{$class}'");
})->with($queuedMailNotifications);

it('places the mail queue third in the worker priority order', function (): void {
    expect(QueueName::Mail->value)->toBe('mail')
        ->and(explode(',', QueueName::workerOrder()))->toBe([
            'submissions', 'webhooks', 'mail', 'exports', 'ocr-processing', 'scheduled-maintenance',
        ]);
});

it('keeps the compose worker and deployment docs byte-identical to workerOrder()', function (): void {
    // The one gate CI does NOT enforce (ADR-0007 §D6 says these must agree byte-for-byte). Guard it here.
    $order = QueueName::workerOrder();

    expect(file_get_contents(base_path('docker-compose.yml')))->toContain("--queue={$order} ")
        ->and(file_get_contents(base_path('docs/deployment-infrastructure.md')))->toContain("--queue={$order}");
});

it('keeps the TenantMail base abstract and NOT ShouldQueue', function (): void {
    $reflection = new ReflectionClass(TenantMail::class);

    // ShouldQueue on a TenantMail would route it through SendQueuedMailable, bypassing the substrate and
    // reintroducing the §D5 model-payload hazard — the base is meant to be sent synchronously from a job.
    expect($reflection->isAbstract())->toBeTrue()
        ->and(is_subclass_of(TenantMail::class, ShouldQueue::class))->toBeFalse();
});

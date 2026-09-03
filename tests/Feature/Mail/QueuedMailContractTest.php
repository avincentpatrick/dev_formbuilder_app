<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Mail\TenantMail;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Notifications\Auth\WelcomeNotification;
use App\Notifications\Concerns\CarriesTenantBrand;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Notifications\Connectors\ConnectorRulePausedNotification;
use App\Notifications\Entitlements\QuotaOverageNotification;
use App\Notifications\EventNotification;
use App\Notifications\ResumeLinkNotification;
use App\Notifications\Submissions\SubmissionPdfReadyNotification;
use App\Notifications\TenantInvitationNotification;
use App\Notifications\Webhooks\WebhookAutoDisabledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
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
 *
 * **The list is append-only in practice: adding a queued mail notification means adding it HERE and to
 * `scripts/job-payload-lint.php`'s EXEMPT_JOBS, or two separate gates fail.** I3 appended the ninth.
 *
 * ⛔ **M66 — THAT WARNING DESCRIBES A THING THAT HAD ALREADY HAPPENED, AND NOTHING WAS CHECKING IT.**
 * `ConnectorRulePausedNotification` was registered in EXEMPT_JOBS at H16a and never added here, so the
 * two hand-maintained lists disagreed by exactly one entry for its whole life. The consequence was not
 * academic: the arm below asserting {@see CarriesTenantBrand} is the gate that would have caught the class
 * shipping without the trait, which is precisely how it shipped — the only tenant-facing notification
 * rendering the framework's default theme instead of the Meridian shell. **The lists are still maintained
 * by hand and still nothing proves they agree**; that gap is filed as its own row rather than closed here,
 * because closing it means deciding where such a gate lives.
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
    EventNotification::class,
    // J3a — the tenth. See scripts/job-payload-lint.php's EXEMPT_JOBS for its twin registration; adding a
    // queued mail notification means adding it in BOTH places or two separate gates fail.
    WelcomeNotification::class,
    // M66 — the eleventh, and the one that proves the sentence above. It has been in EXEMPT_JOBS since
    // H16a and absent here ever since, which is why it reached production without CarriesTenantBrand.
    ConnectorRulePausedNotification::class,
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

it('holds EXEMPT_JOBS and the list above to each other, in BOTH directions (M69)', function () use ($queuedMailNotifications): void {
    // ⛔ THE ARM ABOVE PROVES ONLY ONE DIRECTION, AND THE DIVERGENCE THAT REACHED PRODUCTION WENT THE
    // OTHER WAY. It walks $queuedMailNotifications and asserts each is in EXEMPT_JOBS — so a class
    // present in EXEMPT_JOBS and ABSENT here is invisible to it. That is exactly what
    // ConnectorRulePausedNotification was from H16a until M66: registered with the linter, missing
    // from this list, and therefore never checked for CarriesTenantBrand by the one arm that checks
    // it. It shipped rendering the framework's default theme. This arm is the missing direction.
    //
    // ✅ PROVED BY THREE CONTROLS (M69), NOT BY BEING GREEN. Via scripts/mutate.php, sha256-restored:
    //   MU1  drop EventNotification from the list above, leave it in EXEMPT_JOBS  → CAUGHT (1 red).
    //   MU2  add a REAL non-notification job (SweepWebhookRetriesJob) to EXEMPT_JOBS → SURVIVED, and
    //        SURVIVED is the PASS here: the subset filter is supposed to ignore it.
    //   MU3  add a NON-EXISTENT class to the SAME slot                             → CAUGHT (1 red).
    // ⛔ MU2 ALONE WOULD HAVE PROVED NOTHING, and that is the transferable half. Its green is also
    // what a regex that never harvested the new entry would produce — a control passing through a
    // different branch than the one it names (M49). MU3 is what separates them: same slot, red, so
    // the slot IS read, and MU2's green is therefore the FILTER rather than blindness.
    $linter = file_get_contents(base_path('scripts/job-payload-lint.php'));

    // Parse rather than require: the linter is a standalone script with no framework boot, and
    // including it would run the whole gate. Narrow to the const block first so an FQCN in a
    // docblock elsewhere in the file cannot be harvested as an entry.
    expect($linter)->toMatch('/const EXEMPT_JOBS = \[.*?\n\];/s');
    preg_match('/const EXEMPT_JOBS = \[(.*?)\n\];/s', $linter, $block);

    // ⚠️ STRIP THE `//` COMMENTS FIRST, AND THIS IS A MEASURED STEP RATHER THAN A PRECAUTION. The
    // block is more comment than code, and one of those comments quotes `Notification::route('mail',
    // $email)` — so harvesting every quoted string picked up `'mail'` and the first run of this arm
    // failed on it. Deleting the comments is the honest fix; narrowing the pattern to strings that
    // merely LOOK like an FQCN would have hidden a genuinely malformed entry instead.
    $entries = preg_replace('#//[^\n]*#', '', $block[1]);
    preg_match_all("/'([A-Za-z0-9_\\\\]+)'/", $entries, $matches);

    $exempt = $matches[1];

    // A floor, so a regex that silently harvests nothing cannot report agreement with an empty set —
    // the M48 class of defect (an operation that succeeds on empty input).
    expect($exempt)->not->toBeEmpty()
        ->and(count($exempt))->toBeGreaterThanOrEqual(count($queuedMailNotifications));

    // ⚠️ EVERY ENTRY MUST NAME A REAL CLASS, AND THIS IS NOT TIDINESS. The subset filter below is
    // `is_subclass_of`, which answers false for a class that does not exist — so a typo or a stale
    // rename in EXEMPT_JOBS would be filtered out as "not a notification" and take this gate blind
    // in precisely the direction it was written to cover.
    // Collected rather than asserted one at a time, so a failure PRINTS the offending FQCN. A bare
    // `expect(class_exists($class))->toBeTrue()` inside the loop reports only "failed asserting that
    // false is true", which is what the first run of this arm did — true, and useless.
    $missingClasses = array_values(array_filter($exempt, fn (string $class): bool => ! class_exists($class)));

    expect($missingClasses)->toBe([]);

    // ⚠️ THE NOTIFICATION SUBSET, NOT THE WHOLE CONSTANT — and the direction of that filter is the
    // one thing the backlog row told its taker to check first. EXEMPT_JOBS is R1's exemption list and
    // may legitimately hold non-notification jobs; TODAY IT HOLDS NONE, so whole-set equality would
    // pass and be wrong in principle. Filtered on the framework base class rather than on an
    // `App\Notifications\` prefix, because a namespace is a convention and this is the actual
    // property the list above is a list of.
    $exemptNotifications = array_values(array_filter(
        $exempt,
        fn (string $class): bool => is_subclass_of($class, Notification::class),
    ));

    sort($exemptNotifications);
    $declared = $queuedMailNotifications;
    sort($declared);

    // Compared as whole sorted sets rather than with two array_diff assertions: a failure then prints
    // both lists side by side and names the class on whichever side it is missing from. (Not
    // `toContain`, whose needles are VARIADIC — M30 watched a case stay green with the bad value in
    // the array because of exactly that signature.)
    expect($exemptNotifications)->toBe($declared);
});

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

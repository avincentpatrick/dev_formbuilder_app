<?php

declare(strict_types=1);

use App\Enums\FeedbackParty;
use App\Enums\FeedbackStatus;

/*
|--------------------------------------------------------------------------
| M179 (`R-129c44c3`, `D151`) — Confirmed joins the lifecycle, and the two sides get two graphs.
|--------------------------------------------------------------------------
| A tester may now close the loop on a fix: **It works** moves a Resolved report to Confirmed, and **Still a
| problem** sends a Resolved or Confirmed one back to Ongoing. Support alone marks Resolved, and can never
| mark Confirmed — which is why one graph cannot serve both sides: the console's offered actions ARE the
| support graph (`FeedbackConsolePresenter::decorate()`), so a Confirmed edge there would put a "Mark
| Confirmed working" button in front of the support team.
|
| ⛔ The side-effect predicate is pinned separately. Reaching Resolved or Declined stamps `resolved_*` and
| tells the reporter; a tester's confirm must do neither, or it would overwrite who resolved the report with
| the tester's name and mail the reporter "your feedback was updated".
*/

it('declares five statuses, Confirmed after Resolved, and keeps every stored value', function (): void {
    expect(FeedbackStatus::values())->toBe(['new', 'reviewed', 'resolved', 'confirmed', 'wont_fix'])
        ->and(FeedbackStatus::Confirmed->label())->toBe('Confirmed working');
});

it('offers support the I7a graph, plus a way back from Confirmed, and never Confirmed itself', function (): void {
    $graph = fn (FeedbackStatus $from): array => array_map(
        static fn (FeedbackStatus $to): string => $to->value,
        $from->allowedTransitions(FeedbackParty::Support),
    );

    expect($graph(FeedbackStatus::New))->toBe(['reviewed', 'resolved', 'wont_fix'])
        ->and($graph(FeedbackStatus::Ongoing))->toBe(['resolved', 'wont_fix'])
        ->and($graph(FeedbackStatus::Resolved))->toBe(['reviewed'])
        ->and($graph(FeedbackStatus::Confirmed))->toBe(['reviewed'])
        ->and($graph(FeedbackStatus::Declined))->toBe(['reviewed']);

    foreach (FeedbackStatus::cases() as $from) {
        expect($from->canTransitionTo(FeedbackStatus::Confirmed, FeedbackParty::Support))
            ->toBeFalse("support must never reach Confirmed from {$from->value}");
    }
});

it('offers a tester only It works and Still a problem, and only after a fix', function (): void {
    $graph = fn (FeedbackStatus $from): array => array_map(
        static fn (FeedbackStatus $to): string => $to->value,
        $from->allowedTransitions(FeedbackParty::Member),
    );

    expect($graph(FeedbackStatus::Resolved))->toBe(['confirmed', 'reviewed'])
        ->and($graph(FeedbackStatus::Confirmed))->toBe(['reviewed'])
        ->and($graph(FeedbackStatus::New))->toBe([])
        ->and($graph(FeedbackStatus::Ongoing))->toBe([])
        ->and($graph(FeedbackStatus::Declined))->toBe([]);
});

it('stamps the resolution only on Resolved and Declined — never on a tester\'s Confirmed', function (): void {
    $stamping = array_values(array_filter(
        FeedbackStatus::cases(),
        static fn (FeedbackStatus $s): bool => $s->stampsResolution(),
    ));

    expect($stamping)->toBe([FeedbackStatus::Resolved, FeedbackStatus::Declined]);
});

it('counts a report as open for the testers until it is Confirmed or Declined', function (): void {
    $open = array_values(array_filter(
        FeedbackStatus::cases(),
        static fn (FeedbackStatus $s): bool => $s->isOpenForTesters(),
    ));

    expect($open)->toBe([FeedbackStatus::New, FeedbackStatus::Ongoing, FeedbackStatus::Resolved]);
});

<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Http\Controllers\Tenant\FormAnalyticsController;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Services\Forms\FormHubPresenter;

/**
 * THE tab strip for one form's pages (Increment J2b) — the destinations a given reader actually has on a
 * given form, in reading order: what the form IS, what came back, how it is built, what it says.
 *
 * ── WHY THIS IS SHARED RATHER THAN THE HUB'S PRIVATE METHOD ────────────────────────────────────────────
 * It began as `FormHubPresenter::tabs()` and moved here the moment a SECOND page needed the identical
 * strip ({@see FormAnalyticsController}). Two copies of "which of this form's
 * pages may this reader reach" is J1e's audit-export defect over again, and the failure it produces is the
 * one the whole J2 row exists to remove: a strip that offers a destination on one page and hides it on the
 * next teaches the reader that the strip cannot be trusted.
 *
 * ── EVERY TAB NAMES THE GATE ITS OWN ROUTE CARRIES, AND A REFUSED TAB IS ABSENT ────────────────────────
 * Never disabled, never present-and-inert — ADR-0011 §D9's absent-not-locked doctrine and the same rule
 * J1's search arms follow. A disabled destination is a claim about what exists; an absent one is the truth.
 * Resolving them server-side keeps the answer in one place and makes it assertable in Pest rather than only
 * in Vitest.
 *
 * ⚠️ OVERVIEW IS GATED, NOT UNCONDITIONAL, AND THE DIFFERENCE ONLY SHOWS UP AWAY FROM THE HUB.
 * {@see FormHubPresenter} used to hand back an unconditional Overview row, correctly: reaching the hub at
 * all means having passed `viewOverview`. On the builder or the analytics page it is no longer a tautology
 * — those routes gate on `update` and `view`, and {@see FormPolicy::viewOverview()} is neither. The two
 * coincide for all five shipped roles, so nothing observable changes today; the conjunct is here for the
 * same fail-closed reason `viewOverview`'s own `dashboard.form.view` conjunct is, and is labelled as such
 * in both places rather than presented as a live rule.
 */
final class FormTabSet
{
    /**
     * @return list<array{key: string, label: string, href: string, icon: string}>
     */
    public static function for(Form $form, User $user): array
    {
        // J2d: the hub's path is spelled once, in `FormHubLink`, so the strip and every crumb, tile, bar,
        // chip, audit row and search hit that names a form agree by construction rather than by grep.
        $base = FormHubLink::path($form->id);
        $tabs = [];

        if ($user->can('viewOverview', $form)) {
            $tabs[] = ['key' => 'overview', 'label' => 'Overview', 'href' => $base, 'icon' => 'forms'];
        }

        // The per-form responses list (J2c). Until that route existed this pointed at the filtered global
        // inbox, `/submissions?form_id=`, because `/forms/{form}/submissions` answered 405 — and a strip
        // whose second item did not resolve would have shipped the exact dead end this row was opened to
        // remove. It now points at the real page; nothing else had to change, because `forms/Show.vue` reads
        // the Responses TILE's href back off this array rather than rebuilding the URL.
        //
        // ⚠️ BOTH CONJUNCTS, AND THEY ARE THE ROUTE'S TWO GATES SPELLED ONCE MORE — DELIBERATELY, NOT BY
        // DUPLICATION. `viewAny,Submission` alone is what this tab used to test, and it was sound only by
        // luck: it says nothing about WHICH form, so a member holding `submissions.view` without
        // `dashboard.form.view` (or without a grant on this form) would have been OFFERED a tab that then
        // 403s. Adding `viewOverview` makes "the strip offered it" imply "the reader can reach it", which is
        // the property `FormTabSetReachabilityTest` asserts and could not otherwise guarantee.
        //
        // ⚠️ AND LIKE `viewOverview`'s OWN `dashboard.form.view` CONJUNCT, NO SHIPPED ROLE CAN OBSERVE THIS.
        // All five hold `submissions.view`, and all five satisfy `viewOverview` on a form they can reach at
        // all, so deleting either half here reddens nothing until a synthetic member exists. It is a
        // fail-closed guard, labelled as one rather than presented as a live rule — the same honesty J2b's
        // surviving mutation forced onto `FormPolicy`.
        if ($user->can('viewOverview', $form) && $user->can('viewAny', Submission::class)) {
            $tabs[] = [
                'key' => 'submissions',
                'label' => 'Responses',
                'href' => $base.'/submissions',
                'icon' => 'submissions',
            ];
        }

        if ($user->can('update', $form)) {
            $tabs[] = ['key' => 'builder', 'label' => 'Builder', 'href' => $base.'/builder', 'icon' => 'edit'];
        }

        // `can:view,form` — the analytics route's gate, deliberately UNCHANGED by J2b's widening.
        // `FormAnalyticsGateTest` pins its refusals and passes unedited.
        if ($user->can('view', $form)) {
            $tabs[] = ['key' => 'analytics', 'label' => 'Analytics', 'href' => $base.'/analytics', 'icon' => 'chart-bar'];
        }

        return $tabs;
    }
}

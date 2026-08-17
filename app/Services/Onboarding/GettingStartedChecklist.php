<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\TenantUser;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Support\Authorization\ShellAbilities;

/**
 * The dashboard's getting-started checklist (Increment J5b) — the "light gamification" half of the
 * 2026-08-09 decision of record, built here rather than in a client `computed` for the reason
 * {@see \App\Support\Navigation\CrumbTrail} and `FormTabSet` were: an href resolved in the browser sits
 * somewhere no Pest test can reach, and that gap has already shipped a `/forms` crumb that 403'd for two
 * roles.
 *
 * ── WHAT THIS IS NOT ───────────────────────────────────────────────────────────────────────────────────
 * `docs/onboarding-template-content-plan.md` §2 argues against a scripted tour in its own words — *"no
 * forced tour beyond this one choice point"* — because a wizard delays the thing that demonstrates the
 * product's value. So this is a **passive card on a page the user was going to see anyway**, never a step
 * they must click past, never a modal, and never a gate. §6 lists a multi-step product tour as deliberately
 * out of scope, and this increment does not put one back.
 *
 * It is also NOT the gamification engine. Points, badges, streaks and leaderboards are a separate row that
 * the same 2026-08-09 decision puts as the very last increment before deployment.
 *
 * ── WHEN IT IS SHOWN, AND WHY "ALL DONE ⇒ HIDDEN" IS THE LOAD-BEARING ARM ──────────────────────────────
 * Three states: dismissed (hidden), every row done (hidden), otherwise shown. The middle arm is what makes
 * the un-backfilled dismissal column correct — **every established workspace already satisfies all four
 * rows, so nobody who has been using the product for a year is handed a new card to close.** Had this
 * instead rendered a "you're all set" state, shipping it would have put a fresh, useless card on every
 * existing tenant's dashboard, and the migration would have needed a backfill to avoid it.
 *
 * The cost of that choice, stated rather than discovered: a user never sees the final row tick over — the
 * card is simply gone next render. The progress meter and the rows ticking off one at a time are where the
 * feedback lives.
 *
 * ⚠️ The page renders this only when the tenant has at least one form. That is not this class's rule to
 * enforce and it is not enforced here — it is a **duplication** rule about one screen: below one form the
 * dashboard shows onboarding §2's two-choice first-run moment, which already says *create your first form*,
 * and a checklist beside it would say the same sentence twice. `create_form` is therefore always `done` by
 * the time these rows reach a screen, which is the point — the first row is a tick, not a task.
 *
 * ── DONE-NESS COMES FROM THE NUMBERS THE PAGE IS ALREADY SHOWING ───────────────────────────────────────
 * The KPI array is passed IN rather than re-queried, so a row cannot disagree with the tile directly above
 * it. ADR-0011 §D2 names that failure one surface up (a dashboard and an inbox meaning different things by
 * "a response"); on a single screen it would be worse, because both numbers are in one glance. The one fact
 * the tiles do not carry — has anything been published — is asked of the same service, scoped the same way.
 *
 * A consequence worth naming: for a Form Editor or Reviewer these counts are **their** counts, not the
 * workspace's, exactly as their tiles are. Their checklist therefore describes their own position in the
 * product rather than the organisation's, which is the honest reading of a first-person checklist.
 *
 * ── EVERY HREF MIRRORS ITS ROUTE ───────────────────────────────────────────────────────────────────────
 * Read from {@see ShellAbilities}, the same map the sidebar, the command palette and the KPI tiles read —
 * never re-derived, because a second copy of "what may this user reach" is how the sidebar and the palette
 * come to disagree. Two degradations, and they differ on purpose:
 *
 *   • a row whose destination this reader is refused keeps its label and loses its href — `CrumbTrail`'s
 *     rule, for `CrumbTrail`'s reason: the fact is still true about their workspace even when the door is
 *     shut to them.
 *   • `invite_teammate` is **absent** for a reader who cannot invite. It is not a fact about the workspace,
 *     it is an instruction to the reader, and an instruction nobody can carry out is not a degraded link —
 *     it is a chore list item assigned to the wrong person.
 *
 * ⚠️ A DONE ROW NEVER CARRIES AN HREF, because there is nothing left to do on it. Combined with the
 * always-done `create_form` above, that makes its ability branch a **fail-closed guard no shipped surface
 * can observe** — labelled as one here rather than presented as live behaviour, the convention
 * `CrumbTrail::formSubmissions()` and `submission()` already follow.
 */
final class GettingStartedChecklist
{
    public function __construct(private readonly DashboardMetricsService $metrics) {}

    /**
     * The rows to render, or **null** when the card should not appear at all.
     *
     * Null rather than an empty array: "nothing to show" and "an empty checklist" are different claims, and
     * only one of them has a sensible rendering. The page's `v-if` reads the null.
     *
     * @param  array{forms: int, submissions: int, members: int|null}  $kpis  {@see DashboardMetricsService::forUser()}
     * @return list<array{key: string, label: string, description: string, done: bool, href?: string}>|null
     */
    public function forUser(User $user, array $kpis): ?array
    {
        if ($this->hasDismissed($user)) {
            return null;
        }

        $abilities = ShellAbilities::for($user);

        /** @var list<array{key: string, label: string, description: string, done: bool, ability: string, href: string, omit_when_refused: bool}> $rows */
        $rows = [
            [
                'key' => 'create_form',
                'label' => 'Create your first form',
                'description' => 'Start from a ready-made template, or build one from a blank canvas.',
                'done' => $kpis['forms'] > 0,
                'ability' => 'manageForms',
                'href' => '/forms',
                'omit_when_refused' => false,
            ],
            [
                'key' => 'publish_form',
                'label' => 'Publish it',
                'description' => 'A form collects nothing until it is published — drafts stay private to your workspace.',
                'done' => $this->metrics->publishedFormsCount($user) > 0,
                'ability' => 'manageForms',
                'href' => '/forms',
                'omit_when_refused' => false,
            ],
            [
                'key' => 'first_response',
                'label' => 'Collect your first response',
                // The destination is /forms rather than the inbox on purpose: an empty inbox cannot tell
                // anyone how to stop being empty, and every route to a first response — the share link, a
                // QR code, keying one in by hand — starts from the form.
                'description' => 'Share the form’s link, or key in a response yourself from the form’s page.',
                'done' => $kpis['submissions'] > 0,
                'ability' => 'manageForms',
                'href' => '/forms',
                'omit_when_refused' => false,
            ],
            [
                'key' => 'invite_teammate',
                'label' => 'Invite a teammate',
                'description' => 'Editors build forms alongside you; reviewers check what comes in.',
                // `members` is null for a reader without org-wide visibility — the same absence that omits
                // the Members tile. Such a reader does not see this row at all (they do not hold
                // `tenant.members.invite`), so the coalesce is a fail-closed guard rather than live
                // behaviour: it keeps the row honestly NOT done instead of comparing against null.
                'done' => ($kpis['members'] ?? 1) > 1,
                'ability' => 'manageMembers',
                'href' => '/members',
                'omit_when_refused' => true,
            ],
        ];

        $items = [];

        foreach ($rows as $row) {
            $permitted = $abilities[$row['ability']] ?? false;

            if ($row['omit_when_refused'] && ! $permitted) {
                continue;
            }

            $item = [
                'key' => $row['key'],
                'label' => $row['label'],
                'description' => $row['description'],
                'done' => $row['done'],
            ];

            // Omitted, never set to null — `ChecklistItem.href` is `href?: string`, so a literal null
            // renders identically in the browser and fails `vue-tsc`. The same construction, for the same
            // reason, as `CrumbTrail::push()`.
            if (! $row['done'] && $permitted) {
                $item['href'] = $row['href'];
            }

            $items[] = $item;
        }

        // Every row done ⇒ the card has finished its job. See the class docblock: this arm is what lets the
        // dismissal column ship without a backfill.
        $outstanding = array_filter($items, static fn (array $item): bool => $item['done'] === false);

        return $outstanding === [] ? null : $items;
    }

    /**
     * Has this person dismissed the card **in this workspace**?
     *
     * One row by construction — `tenant_users` carries `unique(tenant_id, user_id)`, *"at most one
     * membership record per tenant, ever"* — and RLS supplies the tenant half, which is why nothing here
     * mentions a tenant id. The same `where('user_id', …)` shape as
     * {@see \App\Services\Tenancy\TenantMembershipService} and six other callers.
     *
     * `exists()` rather than reading the timestamp back: the question this class asks is a boolean, and a
     * method that returned `?Carbon` would invite a second reader to start meaning something by *when*.
     */
    private function hasDismissed(User $user): bool
    {
        return TenantUser::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNotNull('onboarding_dismissed_at')
            ->exists();
    }
}

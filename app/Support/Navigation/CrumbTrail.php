<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Support\Forms\FormHubLink;
use App\Support\Forms\FormTabSet;

/**
 * THE breadcrumb trail for a form's or a submission's pages (Increment J2d) — every crumb resolved against
 * the gate its own route carries, server-side, so the trail cannot offer a reader a destination that then
 * refuses them.
 *
 * ── WHY THIS EXISTS: THE TAB STRIP HAD A PROPERTY THE TRAIL DID NOT ────────────────────────────────────
 * {@see FormTabSet} resolves each tab's gate on the server, and `FormTabSetReachabilityTest` issues a REAL
 * request per href — so a tab cannot be offered to someone the route refuses. Trails had no equivalent:
 * five of the six hard-coded `/forms/{id}` in a client `computed`, where no Pest test can reach them. J2c's
 * `formsCrumb()` composable fixed the FIRST crumb of that trail and, in its own words, noted the gap this
 * class closes. Two live defects were living in the gap:
 *
 *   • `submissions/Show.vue` linked to the hub and to the form's responses list from a page gated only on
 *     `can:view,submission` — whose **respondent arm** (`respondent_user_id = me`) {@see FormPolicy::viewOverview()}
 *     has no counterpart for. A keyer whose grant was revoked, or whose form was re-scoped, opened the page
 *     and got a **403 with no way back** from both middle crumbs. Tenant 403s render a bare Blade page.
 *   • the same two crumbs would 404 for a SOFT-DELETED form, rendering the em dash
 *     {@see SubmissionInboxPresenter::formTitle()} produces as a live hyperlink. ⚠️ **THIS ONE IS
 *     FAIL-CLOSED, NOT LIVE, AND AN EARLIER VERSION OF THIS NOTE CLAIMED OTHERWISE.** Checking it is what
 *     corrected it: `Form` uses `SoftDeletes`, but there is no `Route::delete` for a form and no
 *     `$form->delete()` anywhere in `app/` — `FormService::archive()` sets `status` and `archived_at` and
 *     never deletes. So the state is reachable only from a fixture today. The guard stays because the
 *     feature that adds a delete would otherwise resurrect the bug silently; it is labelled as a guard
 *     rather than presented as a shipped defect, which is the same honesty `formSubmissions()`'s second
 *     conjunct gets below.
 *
 * The FIRST is genuinely live — `DELETE /resource-grants/{resourceGrant}` exists, so grant revocation
 * reaches that state — and both are the defect `SubmissionInboxPresenter` fixed on the inbox ROW in J2c, one
 * surface over, on the page the row links to. Neither was visible to any gate we run: `MdsBreadcrumb`
 * renders an href-less crumb as text, so broken and correct are identical to vue-tsc, axe and every
 * snapshot.
 *
 * ── ONE DELIBERATE DIFFERENCE FROM `FormTabSet`, SO NOBODY "ALIGNS" THE TWO ────────────────────────────
 * A refused TAB is absent. A refused CRUMB keeps its label and loses only its href. Dropping the crumb
 * would renumber the trail and make one page render a different DEPTH per role — the reader loses the
 * hierarchy, which is the only thing a breadcrumb conveys, in exchange for hiding a link they cannot use.
 * Text is the honest degradation here; absence is the honest degradation there.
 *
 * ── THE TAIL IS NEVER A LINK, AND ONLY PEST CAN SEE THAT ───────────────────────────────────────────────
 * {@see current()} closes the trail and returns the array, so a trail structurally cannot end in a link.
 * `MdsBreadcrumb` renders its last item as text WHATEVER it carries, so an href leaking onto the tail is
 * invisible in the browser, in Vitest and in axe — it would surface only as a dead payload key that a later
 * author trusts. The type makes the rule unwriteable rather than merely tested.
 */
final class CrumbTrail
{
    /** @var list<array{label: string, href?: string}> */
    private array $items = [];

    private function __construct(private readonly User $user) {}

    /**
     * Open the trail at `Forms` — the root every trail in the product shares.
     *
     * ⚠️ `viewAny,Form` = `forms.create | forms.edit.any | forms.edit.own`, and a **Reviewer and a Viewer
     * hold none of the three** while reaching the hub, a form's responses, a submission and the encode
     * screen. That asymmetry is J2c's defect, and this is the same mirror `formsCrumb()` was: the ability
     * the route's own middleware evaluates, asked here rather than guessed at.
     */
    public static function forms(User $user): self
    {
        $trail = new self($user);

        return $trail->push('Forms', $user->can('viewAny', Form::class) ? '/forms' : null);
    }

    /**
     * The form's hub.
     *
     * ⚠️ `?Form`, AND THE NULL BRANCH IS THE SOFT-DELETE FIX. `Submission::form()` is a plain `belongsTo` to
     * a soft-deleting model, so the relation resolves to **null** for a trashed form — the same absence that
     * makes `formTitle()` render `—`. The label agrees with it deliberately: a reader seeing an em dash in
     * the inbox must see an em dash here, not a title that implies a page.
     *
     * The null check short-circuits BEFORE the gate call, because `can('viewOverview', null)` throws.
     */
    public function form(?Form $form): self
    {
        if ($form === null) {
            return $this->push('—', null);
        }

        return $this->push(
            $form->title,
            $this->user->can('viewOverview', $form) ? FormHubLink::path($form->id) : null,
        );
    }

    /**
     * That form's own responses list.
     *
     * ⚠️ BOTH CONJUNCTS — the route's two gates (`routes/tenant.php`: `can:viewOverview,form` +
     * `can:viewAny,Submission`), spelled once more rather than duplicated by accident, exactly as
     * {@see FormTabSet} does for the same destination.
     *
     * ⚠️ THE SECOND CONJUNCT IS A FAIL-CLOSED GUARD NO SHIPPED ROLE CAN OBSERVE: all five hold
     * `submissions.view`, so deleting it reddens nothing until a synthetic member exists. It is labelled as
     * one here rather than presented as a live rule — the standing `FormPolicy::viewOverview()` and
     * `FormTabSet` both carry for their own unobservable conjuncts, after J2b's surviving mutation.
     */
    public function formSubmissions(?Form $form): self
    {
        $reachable = $form !== null
            && $this->user->can('viewOverview', $form)
            && $this->user->can('viewAny', Submission::class);

        return $this->push('Responses', $reachable ? FormHubLink::path($form->id).'/submissions' : null);
    }

    /**
     * One submission's detail page — the crumb that makes this class Navigation rather than Forms.
     *
     * A form-only helper would have left exactly this crumb unguarded, and it is the one whose gate has the
     * respondent arm. `SubmissionPolicy::view()` is the real gate on `/submissions/{submission}`, asked here
     * per submission.
     *
     * ⚠️ ALSO FAIL-CLOSED IN PRACTICE: every role that reaches the answer-edit screen (the only page whose
     * trail carries this crumb) also passes `view`. Pinned with a synthetic actor, not presented as live.
     */
    public function submission(Submission $submission, string $label = 'Response'): self
    {
        return $this->push(
            $label,
            $this->user->can('view', $submission) ? '/submissions/'.$submission->id : null,
        );
    }

    /**
     * Close the trail with the page the reader is ON, and hand back the array.
     *
     * Returning the list rather than `self` is the enforcement described in the class docblock: there is no
     * spelling of a trail whose last crumb carries an href.
     *
     * @return list<array{label: string, href?: string}>
     */
    public function current(string $label): array
    {
        return $this->push($label, null)->items;
    }

    /**
     * Where a page's "Cancel" leaves TO — the crumb immediately BEFORE the tail, or null when that crumb is
     * one this reader was refused.
     *
     * ⚠️ NOT THE TAIL, and `Encode.vue` records why an earlier note saying otherwise was false in both of
     * its modes: the tail is where you ARE ("Edit answers", "New response"); Cancel is where you LEAVE TO —
     * the submission in edit mode, the form otherwise. That page spells Cancel twice (header and sticky
     * footer), so the destination lived in three places and could drift in any of them. Deriving it from the
     * trail makes the agreement structural, and `CrumbTrailReachabilityTest` asserts it rather than a
     * docblock asking future authors to remember.
     *
     * ⚠️ NULL IS A REAL OUTCOME, NOT AN ERROR: a reader refused the penultimate destination must not be
     * offered a Cancel that 403s — the whole defect class this increment closes. The template renders the
     * action only when this is non-null.
     *
     * @param  list<array{label: string, href?: string}>  $trail
     */
    public static function exitFrom(array $trail): ?string
    {
        return $trail[count($trail) - 2]['href'] ?? null;
    }

    /**
     * ⚠️ THE KEY IS OMITTED, NEVER SET TO NULL. `BreadcrumbItem.href` is `href?: string`, so a literal null
     * renders identically in the browser and fails `vue-tsc` — a gate failure whose cause is nowhere near
     * its message. Omission is also what makes `array_key_exists('href', …)` a meaningful assertion in the
     * reachability test.
     */
    private function push(string $label, ?string $href): self
    {
        $this->items[] = $href === null ? ['label' => $label] : ['label' => $label, 'href' => $href];

        return $this;
    }
}

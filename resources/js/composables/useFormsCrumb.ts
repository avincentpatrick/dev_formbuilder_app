import { usePage } from '@inertiajs/vue3';
import type { BreadcrumbItem } from '@meridian/design-system';

/**
 * The leading `Forms` breadcrumb — a LINK only for a reader who can actually open `/forms` (Increment J2c).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ `/forms` IS NOT REACHABLE BY EVERY ROLE THAT CAN REACH THE PAGES WHICH LINK TO IT.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * The route gates on `can:viewAny,Form`, which is `forms.create | forms.edit.any | forms.edit.own` — and a
 * **Reviewer and a Viewer hold none of the three**. Meanwhile the form hub, a form's responses list, a
 * submission's detail page and the encode screen are all reachable by one or both of them. So a trail that
 * opens with a hard `href: '/forms'` hands exactly those readers a **403 with no way back**: tenant 403s
 * render a bare Blade page, not an Inertia error view with the app shell around it.
 *
 * That is the dead-end defect the whole J2 row exists to remove, arriving through the component built to
 * remove it. It was found by the J2c adversarial review, not by a gate: `MdsBreadcrumb` renders a crumb
 * without an `href` as plain text, so the broken and the correct versions look identical in every snapshot,
 * every axe scan and every type-check.
 *
 * ⚠️ AND NOTE WHAT THE TAB STRIP ALREADY HAD THAT THE TRAIL DID NOT. `FormTabSet` resolves each tab's gate
 * server-side and `FormTabSetReachabilityTest` issues a REAL request per href, so a tab cannot be offered to
 * someone the route refuses. Breadcrumbs had no equivalent, and J2c was the first increment to put a
 * `/forms` crumb on pages those two roles actually live on. This composable is the trail's version of that
 * rule; it is one function rather than five copies for the reason J1e records — the copies do not disagree
 * with each other, they all agree with a page that has moved.
 *
 * `manageForms` is reused rather than coined: `ShellAbilities` has published it on every response since J1c,
 * computed as `can('viewAny', Form::class)` — the SAME ability the route's middleware evaluates, which is
 * what makes this a mirror of the gate rather than a second opinion about it.
 */
export function formsCrumb(): BreadcrumbItem {
    const page = usePage();

    return page.props.auth?.can?.manageForms ? { label: 'Forms', href: '/forms' } : { label: 'Forms' };
}

# ADR-0015: Feedback Screenshot Capture — the native Screen Capture API, with a file fallback

## Status

**Accepted — 2026-08-07.** Authored inside its own code increment (**I7a**), on the ADR-0012/H22a, ADR-0013/H25 and ADR-0014/H23a1 precedent. An ADR rather than prose because `docs/PRD.md` Feature #11 carries an explicit **"Decision (not pinned by the plan)"** deferring this exact choice to "a small technical spike" — the spike is this document, and a deferred decision that gets made in a controller comment is a decision nobody can find later.

- **Deciders:** Product owner (the fork was brought as three costed options and answered on 2026-08-07); founding engineering (architecture owner).
- **Closes:** `docs/PRD.md` Feature #11's open technique decision. That note is amended in place to point here.
- **Related ADRs:** **ADR-0002** §D3 (the elevated carve-out the support console reads through). **ADR-0008** §D4 (the `storage_bytes` quota a screenshot consumes like any other upload).
- **Related docs:** `docs/data-dictionary.md` §10 and §21; `docs/security-threat-model.md` §6; `docs/ux/design-system-reference.md` §497 (the feedback panel is a Modal in the app shell, never a route).

---

## Context

1. **The two candidates are not variants of one approach — they fail in opposite places.** The PRD names them: the browser's native Screen Capture API (`navigator.mediaDevices.getDisplayMedia`), and a DOM-snapshot library such as html2canvas. The first reproduces exactly what the reporter saw and costs a permission prompt. The second needs no permission and reproduces a *re-rendering* of the DOM, which is not the same picture.

2. **The DOM-snapshot failure is concentrated in the screens feedback is actually about.** html2canvas re-implements a subset of CSS painting; it does not read pixels from the compositor. Canvas and cross-origin image content, transforms and some filters are approximated or dropped. In this product that names two specific surfaces: the **Leaflet map picker** (ADR-0006) renders tiles into elements it fetches cross-origin, and the **signature capture field** is a `<canvas>`. Both come out blank or wrong — and a reporter filing "the map picker renders behind the toolbar" would attach a screenshot with no map in it.

3. **It would also be a new production dependency, on a gate that is watching.** html2canvas is ~1 MB in `dependencies` (not devDependencies), and CI's npm-audit stage runs `--omit=dev` — production dependencies are precisely the ones that block a merge. Its last release was 1.4.1 in 2022. `getDisplayMedia` is a browser API and adds nothing to `package.json`.

4. **The permission prompt is a real cost, and pretending otherwise is how this decision goes wrong.** Every capture opens a picker. On iOS Safari the API does not exist at all. On a non-secure context `navigator.mediaDevices` is undefined. So a design that treats capture as the primary path and has no answer for its absence would ship a feature that is missing, not degraded, for a real share of users — against Feature #11's own acceptance criterion that submitting feedback "never blocks or interrupts the task the user was in the middle of".

---

## Decision

**Capture uses `navigator.mediaDevices.getDisplayMedia()`, drawn to a canvas and encoded as PNG. A plain file input sits beside it at all times, in every browser and every state. No screenshot library is added.**

### The sub-decisions

**§D1 — Native capture, not a DOM snapshot.** Fidelity is the whole value of the artifact. A screenshot that silently omits the component being reported is worse than no screenshot, because it looks like evidence. Context 2 and 3 are the argument; the permission prompt is the price, and §D2 is what makes it affordable.

**§D2 — The file input is ALWAYS rendered — it is not a fallback that appears on failure.** Three arms have to end with a sendable report: capture accepted, capture declined, capture impossible. A conditional fallback would mean the user who declines has to discover the alternative, and a fallback nobody can find is not one. So the panel offers "Capture screen" *and* "Attach an image" together, and only the first is ever hidden. Pinned by `FeedbackButton.test.ts`.

**§D3 — A decline is not an error, and must not be reported as one.** `NotAllowedError` and `AbortError` resolve to `null`: the permission prompt exists to offer a choice, and treating the choice as a failure — a red banner, a retry nag — punishes the user for using it. A *genuine* throw is different and is handled differently: the capture affordance is withdrawn, because inviting a retry of something this browser has already proven it cannot do is worse than not offering it.

**§D4 — Feature detection is `isSecureContext && typeof …getDisplayMedia === 'function'`, both halves.** The API is absent on iOS Safari, and it is secure-context-only — on plain http the property test alone throws, because `navigator.mediaDevices` itself is undefined. Checking one and not the other produces a button that errors on click.

**§D5 — The frame is downscaled to 1600px on the longest edge before encoding.** A raw 4K frame is several megabytes of PNG. 1600px keeps UI text legible while landing typically 1–2 MB. **This is advice, not enforcement:** `config('attachments.feedback_screenshot.max_bytes')` re-checks 4 MB on the server, because a client-side cap constrains only clients that run our code.

**§D6 — Storage reuses the shared `attachments` table, with its own kind and its own allowlist.** Feature #11's acceptance criteria require this explicitly ("not a separate, ad hoc upload path"). `AttachmentKind::FeedbackScreenshot` is the ninth kind; `AttachmentStorageService::storeFeedbackScreenshot()` is a sibling of `storeBrandingLogo()` and repeats its discipline — content-sniffed MIME, server-generated key, queued scan.

**§D7 — SVG is excluded, and this is a sharper decision here than it was for the brand logo.** A brand logo is served same-origin to a tenant's respondents. A feedback screenshot is rendered back into **the platform operator's own console, on the central host** — the one viewer who is a super-admin over every tenant in the deployment. An SVG is an XML document that can carry `<script>`, so the allowlist is raster-only (png/jpeg/webp) and is enforced from the sniffed bytes, not the client's header. Because the allowlist guarantees raster, both screenshot routes serve `inline` unconditionally rather than branching the way `AttachmentController::show()` must.

**§D8 — The attachment row is `is_pii = true`, unlike the brand logo's false.** Data-dictionary §21 already says why: the pointer column is not PII but "the captured screenshot **image itself** may contain PII (whatever was on-screen)". The reporter chooses the surface, and they may well choose one showing respondent answers.

**§D9 — §D6's shared table is also a shared *route*, so the kind has to reach the gate (M29, 2026-08-26).** §D6 chose the shared `attachments` table over an ad hoc upload path, on Feature #11's own acceptance criteria, and §D8 marked the row `is_pii = true`. What neither sub-decision followed through was that the shared table has a shared **reader**: `GET /attachments/{attachment}`, whose policy was written in G6 as a flat `submissions.view` check that never touched its `$attachment` argument. That was correct for every kind G6 could produce — they were all respondent media, and `submissions.view` is the permission that reads respondent media. §D6 made it wrong, quietly, one increment later.

The consequence was live rather than latent. `viewer`, `reviewer` and `form_editor` all hold `submissions.view`; **none of them holds `feedback.view`**. So the id-addressed sibling route served the screenshot to precisely the three roles the dedicated route refuses — and `FeedbackController::screenshot()`'s own docblock says, in as many words, that it declines to route through `AttachmentController` so that a workspace revoking `submissions.view` does not silently lose its feedback screenshots. **The coupling it was built to avoid was open in the other direction the whole time.** Nothing said so, because `AttachmentPolicy` had no test of any kind and no HTTP test anywhere in the repository drove that route.

**The decision: a feedback screenshot is read under `feedback.view` on every route that serves it, or on none.** `AttachmentPolicy::view()` now reads the kind — a `match` rather than an `if`, so "which permission reads this kind" is a decision taken once per kind at the site instead of a default that silently absorbs the next one the way it absorbed the seventh. No permission key is minted; `feedback.view` has been in the role catalog since Phase 0.

⚠️ **THIS CLOSES ONE KIND, NOT THE CLASS, AND SAYING SO IS PART OF THE DECISION.** The default arm is still flat where `SubmissionPolicy::view()` is scoped: that policy requires `submissions.view` **and** org-wide visibility or per-form collaboration, this one requires only the permission. A reviewer or form_editor therefore still reads submission media, submission PDF export artifacts and archived webhook payload envelopes belonging to forms they do not collaborate on — through an id-addressed route with no per-form check at all. That is a real defect and a larger one; closing it means resolving each kind's owner through the morph map and deciding what "scope" means for a kind whose owner is a webhook delivery rather than a form. **It is filed in `docs/feature-backlog.md`, not folded in here**, because an authorization change that wide needs its own increment and its own positive controls — and a fix that shipped it as a footnote to a feedback-screenshot row is exactly how §D6 produced this one.

**What made it findable, and what would have found it sooner.** Not the backlog row, which is about a missing DENY test on the dedicated route. The row was true and its fix — one Viewer `assertForbidden`, one cross-tenant `assertNotFound` — would have left this open while reading as though the PII were gated. The census that found it enumerated *every endpoint in the repository that serves stored bytes* and asked, of each, which test asserts a refusal. Four of the ten had one. **A gate is only as narrow as the widest route that reaches the same bytes**, and nothing in this repository was asking that question per-object rather than per-route.

---

## Consequences

**Accepted:**

- A permission prompt per capture. Mitigated by `preferCurrentTab` on Chromium (this tab is first in the picker) and by §D2 for everyone else.
- No capture at all on iOS Safari. The file input covers it; the panel simply does not advertise a button that cannot work.
- The user may share a window other than ours. That is their choice and the picker is explicit about what it is sharing — but it is why the panel states what will be captured *before* opening the picker, and why §D8 flags the row as PII.
- The capture cannot include the picker dialog itself or, on some platforms, other overlay chrome. Acceptable: the reporter is documenting the app, not the browser.

**Rejected alternatives:**

- **html2canvas / dom-to-image.** Context 2 and 3.
- **Both, chosen by the user.** Two capture paths to maintain, two sets of failure modes, and a choice the reporter has no basis to make ("pixel-accurate or DOM snapshot?" is not a question a user filing a bug should be asked).
- **No screenshot at all.** Leaves an explicit PRD acceptance criterion unmet and the `screenshot_attachment_id` column unbuilt for a third increment running.

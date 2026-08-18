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

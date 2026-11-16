# BHW analytics — consistent public comparison entry

Phase 1 increment after #555 under the #525 handoff.

Question answered: do both overview comparison links open the same supported
BHW comparison experience? The primary navigation already specified the public
headcount measure, but the Places to examine more closely link used `/compare`
and selected the legacy route. Both now reuse `comparisonHref("bhw.total", [])`.

The existing public comparison validates measures and places and reads through
the catalog disclosure contract once enough places are selected. This change
adds no readers or data prerequisites, does not expose hidden measures, and does
not alter the legacy route for its existing callers. It does not establish that
legacy behavior exposed protected data; the confirmed defect is inconsistent
routing from the new overview.

Unit coverage asserts both destinations. Browser cases at 390px and 1440px
exercise both links, the public heading, headcount selection, two-place prompt,
reload and Back. These tests cover entry routing, not full comparison arithmetic,
exhaustive geography, physical devices or representative-reader acceptance.

Phase 1 cross-page state continuity, remaining contracts, boundary/license and
disclosure review, and reader acceptance remain follow-ups. Synchronized maps and
common comparison breaks remain Phase 2. Phase 1 is not declared complete.

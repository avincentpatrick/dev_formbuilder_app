# BHW analytics — public comparison search

Next Phase 1 increment after #530, under the #525 assessment and live-verification
handoff. No changed product decisions: two places initially, expandable to four;
catalog-wide withholding remains mandatory. This does not complete Phase 1 maps.

## Reader question and existing components

“How does this BHW measure differ between these places?” Search now recognizes
`Compare Cebu and Bohol accreditation` and comma-separated place requests with an
explicit supported measure. It reuses the geography search and all 66 accepted BHW
measure definitions. Accreditation requires a population choice. Every place requires
selection from named/code/level/parent-chain matches, including a single match;
no fuzzy result is automatically accepted.

The existing `/compare` route has a catalog-backed mode selected by `measure=`.
It reuses `SelectedGeoChips`, `BenchmarkBars`, public catalog readers, measure
definitions, value formatting and source dates. A request-local catalog reader
deduplicates source metadata and global disclosure checks across selected places.
No migrations, private records, hidden profiling releases or new dependencies.

## Route and state contract

- `/bhw/search?q=Compare+…` resolves each requested name independently. Supported
  separators are `and` and commas; include a recognized measure phrase. General
  conversation, forecasts, time trends and mixed-dataset comparisons remain unsupported.
- `/compare?measure=bhw.total&geos=01,02` selects the public mode. All 66 measures
  can be selected, but source/level availability is still authoritative. Unsupported
  geography is explained rather than changed. Category measures remain category lists;
  no categories or percentages are added to manufacture a scalar comparison.
- `focus=02` selects phone details while retaining all places and the measure.
  Desktop shows the place cards together. A shareable link, reload and browser history
  preserve the selected settings. Add, replace, remove, clear and measure switching
  all write the same canonical URL.
- Unknown measures, duplicate/invalid codes, repeated settings, mixed district state
  and invalid focus show an error without entering legacy readers. Mixed levels and
  unverified places retain removable selections but do not read comparison figures.
- More than four requested places are never silently truncated. Search exposes all
  requests (up to ten within the 100-character grammar) for choosing two to four.
  Over-limit shared URLs retain up to twenty valid unique codes as removable chips,
  with no figure reads until the selection is within four. Larger malformed URLs
  explain the invalid settings rather than silently dropping codes.
- Search suggestions carry geography metadata, not profile counts. Loading, failed
  reads and empty matches remain separate. Failed or withheld values are never passed
  into bars, details or the browser controls. Source dates, denominators, confidence
  intervals and partial-state notes remain attached to the respective place values.

The BHW overview and focused measure links enter the new public mode. Older `/compare`
links without `measure` preserve their existing behavior for compatibility; this PR
does **not** certify all legacy readers or remove the separate disclosure audit task.

## Verification and remaining scope

Unit coverage checks grammar, population ambiguity, excess requests, state round trips,
metadata-only suggestions, failed versus empty searches, mixed/unverified selections,
shared privacy reader identity, zero, unequal denominators, and blocked-value payloads
absent from rendered output. Browser cases exercise two-to-four-place selection,
replacement/removal, measure changes, phone focus, refresh/back, over-limit selection,
failure/retry and empty results at phone/desktop widths.

Local browser fixtures use several populated synthetic regions, zeros, missing values,
unequal denominators and withheld measures. CI additionally uses the committed
one-region development figures; neither proves nationwide coverage or physical-device
usability. Record exact results in the PR after executing checks.

Next Phase 1 work remains the useful map workspace and independent filter/classification
controls described in #525. Region VII HUC geometry reconciliation remains a data blocker;
do not invent boundary aliases. Synchronized comparison maps and deeper category/training
figures remain Phase 2, and the broader live disclosure audit remains tracked separately.

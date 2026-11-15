# BHW analytics — proportional-circle PNG size legend

Phase 1 export-composition increment after #542 under the #525 handoff.
Question: “How large a BHW count does a circle represent in the downloaded image?”

PNG exports now draw the existing full-area size-reference circles, numeric labels
and a separate hollow zero marker. Previously reference counts were text only.
The existing sizeLegend metadata supplies values and radii: filtering and missing
geometry do not change the reference population. Circle area remains proportional
to count. Zero/missing-only data produces no positive reference circles and an
explicit explanation. Choropleth exports retain their color legend.

The capture retains the ratio from CSS pixels to exported pixels, independently of
WebGL device pixel ratio, so legend radii match the resized map. The composer
allocates space before drawing and retains the existing image-height guard,
provenance, parent measure, filter, uncertainty and coverage notes. No external
fonts, scripts, data or dependencies are added.

Unit cases check squared-radius ratios, scale capture, hollow zero, missing values,
filter-independent references and choropleth isolation. Browser fixtures export
PNG at 390px and 1440px with device scale factor 2; generated synthetic images are
inspected outside git. Exact check results are in the PR. This is not physical-device
or exhaustive geographic verification.

Remaining export composition includes the locator, scale bar and linked ranked
bars. Their current omission notes remain accurate. Full cross-page state and
non-count editable domains also remain Phase 1 work; source/disclosure/boundary
and reader-evaluation prerequisites are not certified by this increment.

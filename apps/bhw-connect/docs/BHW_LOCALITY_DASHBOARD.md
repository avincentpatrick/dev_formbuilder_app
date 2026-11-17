# Same BHW dashboard for a selected locality

The `/bhw` visual dashboard now accepts an explicit `location=level:code` query. Place-only search results, autocomplete suggestions and PSGC catalog card entries open that dashboard rather than the indicator-table page. National `/bhw` remains the default; a local dashboard provides a Philippines return link.

The existing workforce, qualifications/training, education and honorarium components are reused. The page validates the selected geography before calling the existing public catalog reader for that exact scope. Captions, presentation metadata, the headcount map preview, comparison entry and methodology link follow the chosen locality. Unsupported or absent measures keep their status; no national fallback or inferred local values are used. Explicit indicator searches continue to open measure details, whose overview link now returns to that measure's locality.

The map’s Open dashboard link uses its current geographic scope. Map snapshots remain a separate return context: filters, classification and selected map features do not silently replace the dashboard's explicit locality. Detailed indicator tables remain accessible under methodology.

Validation includes exact scoped-reader assertions for regions, provinces, municipalities and barangays, rejected malformed/mismatched geography, reused chart sections/captions and scoped onward links. Browser tests cover place autocomplete → local dashboard, reload, browser back, national return, direct locality URLs and mobile width. Synthetic/seeded evidence is not a claim of complete live data or boundary coverage.

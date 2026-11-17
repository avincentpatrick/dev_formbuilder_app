# BHW card entry correction — 2026-11-17

The portal card heading and bottom BHW dashboard link opened the visual overview,
but the card action still opened the all-location availability catalog. This was
an incomplete implementation of the agreed direct-to-data entry.

All-location BHW card navigation now uses the existing `/bhw` overview. The legacy
`/catalog?mode=all&dataset=bhw` URL redirects there too. Scoped location data pages
remain in the catalog. Valid map context survives; invalid context retains its
explanation without forwarding arbitrary input. No new analysis component or
national aggregation is introduced.

Acceptance: card title and action share the overview destination; legacy URLs
open the first visual without choosing a location; mobile/desktop reloads retain
valid map-return context; scoped and unrelated catalog requests remain intact.

Local validation: lint, types, 2,003 unit tests (55 database tests skipped), migration
lint and prefixed production build passed. Five browser checks passed at 390px
and 1440px using the honest no-data mode, including invalid-context handling.
These checks verify navigation, not completeness of live data or physical-device
reader acceptance. Seeded catalog navigation is also covered by CI.

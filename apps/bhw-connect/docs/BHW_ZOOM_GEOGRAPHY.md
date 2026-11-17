# Zoom-driven BHW geography — 2026-11-17

Phone feedback identified that camera zoom kept regional values indefinitely.
BHW workspace zoom now uses the existing scoped map reader to descend into the
visible area nearest the map center: regions → provinces/HUC representations →
cities/municipalities → barangays, where the indicator and installed boundary
release support that last step. Other datasets retain their existing behavior.

Intentional zoom gestures and zoom buttons drive transitions; initialization,
resize, automatic fitting and Reset map view do not. Camera center/zoom survive
the data reload. Indicator, period, view, numeric filter and color classification
remain in the URL; selection clears when the geographic level changes. The scope
line explicitly names the level of the figures. Old totals are removed while the
next scoped response loads, and missing shapes/observations retain their existing
handling. No provincial value is inferred from a regional total.

Thresholds: enter finer scopes at zoom 6, 8 and 10; return at 5.5, 7.5 and 9.5.
The gap prevents oscillation. One level changes per completed zoom gesture.
Zoom-out parents come from authoritative navigation metadata, never PSGC slicing.
Over water, the nearest visible boundary center determines focus. This is scoped
navigation; it does not combine multiple regions' children into a national layer.
Manual area selection, drill controls, breadcrumbs and reset remain available.

Tests cover scope thresholds, unsupported barangay indicators, unknown/missing
focus, authoritative ancestors, retained analysis settings, and phone/desktop
zoom into finer data and back out. Physical-phone usability and exhaustive
nationwide boundary completeness are separate acceptance questions.

Validation: 2,007 unit tests passed (55 database cases skipped locally), migration
lint, type checks and the prefixed production build passed. Fourteen focused
browser scenarios passed across the final targeted runs, including control-wheel
zoom, both directions through all supported levels, mobile map space, classification,
missing shapes, selection and exports. CI runs the complete seeded browser suite.

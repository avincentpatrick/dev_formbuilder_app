# Mobile map space — 2026-11-17

Physical-phone feedback showed the legend and controls occupying more space than
the map. The workspace canvas was capped at 32svh above a 240px minimum.

The existing workspace now gives the mobile canvas 60svh (320px minimum). Zoom
and reset sit in a compact corner toolbar with 44px minimum touch targets. The
existing legend is expandable below the map on phones and stays visible above
it on desktop. Indicator/view selectors and the area/data panel remain available.
One map and one legend are retained; panel/legend changes do not refetch data or
reset selection. Existing missing-data explanations, exact table and exports remain.

Browser acceptance checks cover 320px, 390px and 1440px widths, canvas size and
position, opening/closing the legend, keyboard/touch controls, retained selection,
missing shapes, one map instance, PNG export and horizontal overflow. These are
emulated-device checks; physical phone acceptance remains a separate gate.

Validation: lint, types, 2,004 unit tests (55 database tests skipped locally),
migration lint and prefixed production build passed. Eight panel/symbol browser
checks passed after the final layout adjustment; four classification checks also
passed during this increment. The 390×844 preview measured a 506px canvas starting
at y=370px. A regression assertion requires more than half the first viewport to
show the map on the tested phone widths. CI runs the complete seeded browser suite.

# Map values and map-only view

Follow-up to the Phase 1 map workspace and mobile layout: tapping a circle, hollow zero marker, place label or polygon shows the selected place and published value directly over the map. Missing values remain “Not reported”. Selection still drives the existing area controls and exports. Closing the value card does not clear selection; tapping again reopens it.

“Map only” opens the existing canvas in a viewport-filling modal dialog, without page navigation, feedback widget, legend, notes or side panels. The only persistent overlay is Exit map only; a selected value can be dismissed. Pan, pinch and wheel zoom remain available, including automatic geographic detail. Exit or Escape restores the normal layout and trigger focus. Safari/browser chrome remains outside the page's control.

The dialog belongs to the workspace so it stays open while geographic zoom replaces map data. ResizeObserver resizes the same canvas when entering/exiting; the map is not recreated solely for the layout change. The normal keyboard-accessible selector/table remain available outside map-only mode.

Validation uses synthetic fixtures at phone and desktop sizes, including positive and zero values, fullscreen canvas bounds, selection dismissal/reopening, focus restoration, Escape, and one canvas/data request across layout changes. This is not a physical-iPhone or nationwide data-coverage verification.

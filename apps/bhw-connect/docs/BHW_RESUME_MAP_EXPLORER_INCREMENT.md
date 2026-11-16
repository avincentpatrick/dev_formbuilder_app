# BHW analytics — resume map explorer from the overview

Phase 1 increment after #557 under the #525 handoff.

Question answered: when a reader returns to the overview from a configured map,
does its Map explorer navigation continue that analysis? It now reuses the
validated public snapshot parser and labels that destination as previous settings.
The separate workforce link explicitly opens national headcount, keeping a clear
fresh-map route and the overview's national meaning. With no valid snapshot, the
navigation still defaults to national headcount with proportional circles.

No new readers, data prerequisites, saved account state or disclosure rules.
Invalid/hidden/duplicate snapshots cannot override navigation; existing invalid
snapshot feedback remains on the page. The national figures are not filtered.

Unit cases check exact restored settings, the independent national link, and
absent/invalid/hidden/duplicate inputs. The existing phone/desktop browser journey
now follows Map explorer, checks the exact edited URL, uses Back, and continues
through overview sections and national methodology to restore the map again.
These are bounded synthetic navigation samples, not exhaustive geography,
physical-device, live-data or representative-reader acceptance.

Other cross-page continuity, contextual contracts, boundary/license/disclosure
work and reader acceptance remain Phase 1 follow-ups. Comparison synchronization
remains Phase 2. Phase 1 is not declared complete.

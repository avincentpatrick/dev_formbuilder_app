# BHW analytics — source licenses and catalog map link accessibility

Final-review fixes under the Phase 1 handoff. The global footer previously used
the profile release's source/license for every page and fell back to CC BY 4.0
when metadata was absent. It now directs readers to each figure's source notes
without assigning a source or granting an unsupported license.

The public catalog source projection retains the license already supplied by its
existing metadata reader. Shared source notes show each source's supplied terms
or explicitly say that license metadata was not supplied. No license is invented,
no unknown source becomes permitted, and no new data read is introduced. Historical
boundary/license acceptance remains an external data prerequisite.

The automated accessibility audit also found a serious visible-label/accessibility-
name mismatch on the catalog map link. Its visible text now names the dataset,
matching the existing accessible name (for example, Open full BHW map).

Unit checks cover supplied versus missing per-source terms, removal of blanket
footer claims and the visible map-link label. Final browser/axe and performance
results belong in the consolidated acceptance record.

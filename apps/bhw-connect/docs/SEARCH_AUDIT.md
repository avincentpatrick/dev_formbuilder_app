# Portal search audit — 7 November 2026

| Portal card | Before | After |
| --- | --- | --- |
| 2025 BHW Census | Place search, including district matches | Retained |
| 2025 UUC for PHC | Geographic drill-down only | Shared place search throughout the section |
| Health facilities | Geographic drill-down and local facility list | Shared place search throughout the section |
| Legislative districts | District-name filter, region and match-quality selectors | Added district search by name or member LGU; existing filters retained |
| Health services | Geographic drill-down only | Shared place search throughout the section |
| Ways forward | Placeholder, no destination or records | No search needed until content is published |

The main portal now searches geography and legislative districts with the same endpoint as BHW. Each geographic match offers links to BHW, UUC, facilities, and health services. Parent names distinguish similarly named places. Results do not assert that a dataset has observations for a matched place; each destination reports its own availability.

All sections support region, province, city/municipality, and barangay lookup. UUC, facilities, and health services present barangay information at municipality level: validated barangay URLs redirect through their stored municipality ancestor, without guessing a parent from a geographic code. District lookup uses the existing district/member search; region browsing remains available in the district index.

Search is for places and districts, not individual people, arbitrary document contents, indicators, or facility names. Existing facility-list filtering remains available inside municipality pages. The hidden profiling-status card already has place search and was left unchanged.

Validation: production build under `/bhw-connect`, lint, type checking, unit suite, migration lint, and browser tests with synthetic search responses. Real database search ranking and municipality redirects require seeded/live data; the local no-data build cannot verify these end to end.

# BHW analytics — map return through search

Phase 1 increment after #563 under the #525 assessment and live-verification handoff.

Overview and measure-page search forms carry a validated map snapshot using the
existing GET form. Search reuses the return panel and keeps the snapshot through
repeat searches, measure results, place-only catalog results, the overview link,
and explicit comparison resolution. Failed, empty and unsupported searches keep
a valid return. Map settings do not filter search matches or choose a population.

Reuses the existing validators and destination builders. Invalid, repeated,
hidden-source and arbitrary destination settings are explained and discarded.
Search matching, ambiguity handling, maximum comparison size, data readers and
disclosure rules are unchanged.

Unit checks cover independent result location/map scope, invalid input rejection
and failed/empty/unsupported states. Synthetic phone/desktop navigation covers
overview → search → measure → repeat search → catalog → Back → comparison query
resolution, with reloads and exact map return destination assertions. This is
bounded coverage, not exhaustive geography, physical devices or reader acceptance.

Phase 1 remains in progress. Catalog-internal navigation, remaining contracts,
boundary/license/disclosure work and reader acceptance remain open. Synchronized
maps and common breaks remain Phase 2.

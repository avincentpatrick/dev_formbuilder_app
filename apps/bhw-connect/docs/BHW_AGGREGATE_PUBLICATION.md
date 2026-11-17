# Aggregate publication decision — 2026-11-17

The owner explicitly requested removal of withholding for aggregate BHW statistics,
including the honorarium-paying-level breakdown. This supersedes the catalog-wide
small-cell/complementary-suppression requirement in the Phase 0/1 handoff for these
published aggregate measures.

The catalog previously blocked an entire demographic dimension or honorarium payer
across every geography when any source row carried `is_suppressed`. The honorarium
source flag originally protects amount-distribution columns, while receipt counts
and percentages are retained. Applying that flag to receipt shares incorrectly
hid usable national and regional observations.

BHW demographic category counts and honorarium receipt counts/shares now publish
all stored numerical values, including zero and small counts. They do not run the
global suppression probe or filter on the legacy flag. Overview, measure discovery,
comparison and catalog routes reuse this contract. Other accepted-release, data
validation and geography rules remain in force.

This does not recover values previously nulled by ingestion: they are unavailable,
not zero, and no residual values are inferred from totals. Historical nulls require
an aggregate rebuild from the privately held source if those values are needed.
No individual records, names, identifiers, contacts or addresses are added to the
public projection, and no database grants or personal-record access change.

Validation covers parent/child scopes, flags on stored values, real zero alongside
source nulls, missing flags, absence of global probes, and public-field allowlists.
Physical/live verification remains distinct from synthetic automated checks.

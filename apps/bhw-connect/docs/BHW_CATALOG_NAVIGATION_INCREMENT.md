# BHW analytics — catalog navigation continuity

Phase 1 increment after #564. Fixes L04's original catalog location-form and
ancestor context loss, using the existing controls, GET forms and URL serializer.

PSGC location forms, ancestors, national/all-location links and recovery retain
the selected dataset. BHW and all-dataset views carry only a validated original
BHW map snapshot. All datasets deliberately clears the dataset selection; selecting
an unrelated dataset drops the BHW snapshot. Returning to BHW retains it when
still in the catalog journey. BHW dashboard links use the existing overview builder.

District entry explicitly starts a fresh district selection rather than sending
an invalid detail URL without a district. The original map return can remain;
subsequent district/member forms retain a chosen dataset. District membership,
partial-city semantics and public data readers are unchanged.

Unit checks cover serializer scope independence, invalid snapshots, unrelated
sources and real form/ancestor markup. Phone/desktop browser checks cover BHW
national → region → reload → ancestor → all datasets → BHW → all locations → Back.
This is bounded navigation evidence, not exhaustive geography or reader acceptance.

CI reproduction used the committed development copy in isolated PostgreSQL 15 /
PostgREST 16.4. Its catalog-wide privacy contract withholds BHW, so the browser
journey checks that explanation and returns to BHW through history. An explicit
available-card unit fixture checks the BHW link preserves the map snapshot and
an unrelated dataset drops it. Both browser widths passed against the seeded database.

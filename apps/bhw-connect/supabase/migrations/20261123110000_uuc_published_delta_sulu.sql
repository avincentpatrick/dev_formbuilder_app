-- Record the Sulu difference against the published UUC for PHC table.
--
-- 20261123100000 filed Sulu under Region IX. The 2027 Budget Cue Cards p37 (and the source list) count
-- Sulu's 87 barangays in BARMM, so BARMM now reads 87 lower and Region IX 87 higher than the published
-- table while the national total still agrees. ref_uuc_phc_published_delta stores exactly such
-- differences and was empty; this re-derives it with the same parse as 20260827170000 (its populate
-- step), so the data-quality page states the difference instead of claiming agreement.
--
-- Upsert only. A difference that closes later is removed by re-running the populate step of
-- 20260827170000, which carries the cleanup this additive file leaves out. Where the cue cards are not
-- in the corpus (a fresh development copy) the parse finds nothing and this inserts nothing.
with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2025'
),
doc as (
  select s.key, s.as_of, c.page_from, c.content
  from doc_chunk c
  join doc_source s on s.doc_id = c.doc_id
  where s.key = 'blhsd-2027-budget-cue-cards'
    and c.page_from = 37 and c.page_to = 37
),
pairs as (
  select (m)[1] as label, (m)[2]::int as n
  from doc, regexp_matches(doc.content, '([A-Z][^\n]*)\n(\d+)', 'g') as m
),
published as (
  select g.geo_code, 'region'::geo_level_enum as geo_level, p.label, p.n
  from pairs p
  join dim_geo g on g.geo_level = 'region' and g.geo_name = p.label
  where p.label <> 'TOTAL'
  union all
  select 'PH', 'national'::geo_level_enum, p.label, p.n
  from pairs p
  where p.label = 'TOTAL'
),
compared as (
  select pub.geo_code, pub.geo_level, pub.label, pub.n as n_published,
         c.n_listed, c.n_listed - pub.n as delta
  from published pub
  join agg_uuc_phc_counts c
    on c.dataset_id = (select dataset_id from ds)
   and c.geo_code = pub.geo_code and c.geo_level = pub.geo_level
)
insert into ref_uuc_phc_published_delta (
  dataset_id, geo_code, geo_level, source_label, n_published, n_listed, delta,
  source_doc_key, source_page, source_as_of
)
select (select dataset_id from ds), cmp.geo_code, cmp.geo_level, cmp.label,
       cmp.n_published, cmp.n_listed, cmp.delta,
       (select key from doc), (select page_from from doc), (select as_of from doc)
from compared cmp
where cmp.delta <> 0
on conflict (dataset_id, geo_code, geo_level) do update set
  source_label = excluded.source_label,
  n_published = excluded.n_published,
  n_listed = excluded.n_listed,
  delta = excluded.delta,
  source_doc_key = excluded.source_doc_key,
  source_page = excluded.source_page,
  source_as_of = excluded.source_as_of;

-- Sulu belongs to Region IX (Zamboanga Peninsula) in every regional view.
--
-- WHY. Sulu left BARMM in 2024. The PSA's current PSGC (3Q 2026) files the province under Region IX as
-- 0906600000. dim_geo is on the older vintage and still holds Sulu under BARMM (region 19), so every
-- regional rollup in the app counted Sulu's 87 listed barangays, its facilities, its BHWs and its
-- population under BARMM, while the 2025 UUC for PHC list and the 2027 Budget Cue Cards count them in
-- Region IX. The owner's decision: Sulu is Region IX on every card.
--
-- WHAT THIS DOES, AND WHAT IT LEAVES. Sulu keeps its geo_code ('19066…'). Re-keying it would touch every
-- fact table, and dim_psgc_crosswalk (20260826121200) already resolves the source's '09066…' codes to
-- those keys, so nothing there changes. What moves is the REGION a Sulu geography belongs to:
--
--   1. dim_geo            region_code of the province, its 19 cities/municipalities and 410 barangays
--                         goes 19 -> 09, and the province's parent_code goes 19 -> 09.
--   2. dim_legislative_district   region_code of Sulu's two districts, 19 -> 09.
--   3. agg_geo_summary    the region named in Sulu rows' parent_chain, and the search text built from it.
--   4. dataset_registry / dataset_column   the notes the AI assistant reads, which said Sulu resolves to BARMM.
--   5. agg_uuc_phc_counts / agg_uuc_phc_criteria   the region rows, recomputed from dim_geo exactly as
--                         the migrations that own those tables compute them. Only region rows change;
--                         every other row already agrees.
--
-- NOT DONE HERE: the other aggregates (BHW counts, demographics, honorarium, population, poverty,
-- facilities, workload, the UUC indicator distribution and BHW-by-UUC-status). Their region rows are
-- computed from private source data by the ingestion scripts, and several are not sums (percentiles,
-- confidence intervals, rates). They keep counting Sulu under region 19 until rebuilt; run
-- `node db/check-sulu-region.mjs` against the database to list every one that is still stale.
--
-- Additive in effect: UPDATEs and upserts only; nothing is deleted. Idempotent: a second run finds
-- nothing left to move and recomputes the same two UUC tables to the same values.

update dim_geo
   set region_code = '09'
 where (geo_code = '19066' or province_code = '19066')
   and region_code is distinct from '09';

update dim_geo
   set parent_code = '09'
 where geo_code = '19066' and geo_level = 'province'
   and parent_code is distinct from '09';

update dim_legislative_district
   set region_code = '09'
 where parent_geo_code = '19066'
   and region_code is distinct from '09';

-- The region name in the search chain, and the search text that is built from it.
update agg_geo_summary s
   set parent_chain = jsonb_set(s.parent_chain, '{region}', to_jsonb(r.geo_name))
  from dim_geo r
 where r.geo_code = '09' and r.geo_level = 'region'
   and (s.geo_code = '19066' or s.geo_code like '19066%')
   and s.parent_chain ? 'region'
   and s.parent_chain ->> 'region' is distinct from r.geo_name;

update agg_geo_summary s
   set search_text = to_tsvector('simple',
         s.geo_name || ' ' || coalesce(s.parent_chain ->> 'region', '') || ' ' ||
         coalesce(s.parent_chain ->> 'province', '') || ' ' || coalesce(s.parent_chain ->> 'citymun', ''))
 where (s.geo_code = '19066' or s.geo_code like '19066%')
   and s.parent_chain is not null;

-- UUC for PHC regional totals, recomputed for regions 09 and 19 from the corrected geography. These
-- are the populate blocks of 20260826140000 and 20260827100000, restricted to the region level.
with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2025'
),
brgy as (
  select g.region_code,
         case when f.geo_code is null then 0 else 1 end as listed
    from dim_geo g
    left join fact_uuc_phc_barangay f
      on f.geo_code = g.geo_code and f.dataset_id = (select dataset_id from ds)
   where g.geo_level = 'barangay' and g.region_code in ('09', '19')
),
rolled as (
  select region_code, sum(listed)::int as n_listed, count(*)::int as n_barangays
    from brgy group by region_code
)
insert into agg_uuc_phc_counts (dataset_id, geo_code, geo_level, n_listed, n_barangays)
select (select dataset_id from ds), r.region_code, 'region'::geo_level_enum, r.n_listed, r.n_barangays
  from rolled r
 where exists (select 1 from ds)
on conflict (dataset_id, geo_code, geo_level) do update set
  n_listed = excluded.n_listed,
  n_barangays = excluded.n_barangays;

with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2025'
),
brgy as (
  select g.region_code,
         (i.geo_code is not null)::int as listed,
         (coalesce(i.ip_pop, 0) >= 10)::int as route_ip,
         ((coalesce(i.armed_conf, 0) + coalesce(i.idp, 0) >= 10)
            or coalesce(i.elcac_brgy, false))::int as route_conflict,
         (coalesce(i.four_ps, 0) >= 50)::int as route_four_ps,
         coalesce(greatest(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref,
                           i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > 1,
                  false)::int as evaluable,
         coalesce(greatest(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref,
                           i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > 1
                    and i.health_indicators >= 4,
                  false)::int as route_health
    from dim_geo g
    left join fact_uuc_phc_indicators i
      on i.geo_code = g.geo_code and i.dataset_id = (select dataset_id from ds)
   where g.geo_level = 'barangay' and g.region_code in ('09', '19')
),
rolled as (
  select region_code, sum(listed)::int as n_listed, sum(route_ip)::int as n_route_ip,
         sum(route_conflict)::int as n_route_conflict, sum(route_four_ps)::int as n_route_four_ps,
         sum(route_health)::int as n_route_health, sum(evaluable)::int as n_health_evaluable
    from brgy group by region_code
)
insert into agg_uuc_phc_criteria (
  dataset_id, geo_code, geo_level, n_listed,
  n_route_ip, n_route_conflict, n_route_four_ps, n_route_health, n_health_evaluable
)
select (select dataset_id from ds), r.region_code, 'region'::geo_level_enum, r.n_listed,
       r.n_route_ip, r.n_route_conflict, r.n_route_four_ps, r.n_route_health, r.n_health_evaluable
  from rolled r
 where exists (select 1 from ds)
on conflict (dataset_id, geo_code, geo_level) do update set
  n_listed = excluded.n_listed,
  n_route_ip = excluded.n_route_ip,
  n_route_conflict = excluded.n_route_conflict,
  n_route_four_ps = excluded.n_route_four_ps,
  n_route_health = excluded.n_route_health,
  n_health_evaluable = excluded.n_health_evaluable;

-- The notes the assistant reads. Targeted replacements of the exact sentences the registry seeds wrote
-- (20260826090100), so a registry already edited since is left alone rather than overwritten.
update dataset_registry
   set notes_md = replace(notes_md,
     'SULU''S 177 FACILITIES: source_region_name reads "REGION IX (ZAMBOANGA PENINSULA)" on every one, but geo_code resolves all of them to BARMM (dim_geo''s placement) - honour geo_code for any rollup and name both the source''s region text and the resolved region when the discrepancy matters, never one alone.',
     'SULU''S 177 FACILITIES: source_region_name reads "REGION IX (ZAMBOANGA PENINSULA)" on every one, and geo_code now resolves all of them to Region IX as well (Sulu is filed under Region IX since 2026-11). The source''s own codes straddle two vintages (152 read 19066..., 25 read 09066...); both land on dim_geo''s Sulu. Honour geo_code for any rollup.')
 where table_name = 'fact_nhfr_facility';

update dataset_column c
   set meaning = replace(c.meaning,
     'Sulu''s 177 facilities resolve here to BARMM regardless of what source_region_name says.',
     'Sulu''s 177 facilities resolve here to Region IX, which is also what source_region_name says.')
  from dataset_registry r
 where r.registry_id = c.registry_id and r.table_name = 'fact_nhfr_facility' and c.column_name = 'geo_code';

update dataset_column c
   set meaning = replace(c.meaning,
     'this and source_region_name disagree with it for Sulu''s 177 facilities by design (see the table note).',
     'for Sulu''s 177 facilities the source''s codes straddle two vintages (see the table note).')
  from dataset_registry r
 where r.registry_id = c.registry_id and r.table_name = 'fact_nhfr_facility' and c.column_name = 'source_region_psgc';

update dataset_column c
   set meaning = replace(c.meaning,
     'Says "REGION IX (ZAMBOANGA PENINSULA)" for all 177 Sulu facilities, which geo_code files under BARMM instead - name both when the discrepancy matters.',
     'Says "REGION IX (ZAMBOANGA PENINSULA)" for all 177 Sulu facilities, which geo_code now files under Region IX too.')
  from dataset_registry r
 where r.registry_id = c.registry_id and r.table_name = 'fact_nhfr_facility' and c.column_name = 'source_region_name';

update dataset_column c
   set meaning = replace(c.meaning,
     'Differs from geo_code for Sulu 87 barangays, which the source files under Region IX and dim_geo under BARMM.',
     'Differs from geo_code for Sulu 87 barangays (the source writes 09066..., dim_geo keeps 19066...), both in Region IX.')
  from dataset_registry r
 where r.registry_id = c.registry_id and r.table_name = 'ref_uuc_phc_list' and c.column_name = 'source_geo_code';

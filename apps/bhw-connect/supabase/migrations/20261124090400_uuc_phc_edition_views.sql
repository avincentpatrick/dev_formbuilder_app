-- Views that read the UUC for PHC list by EDITION, now that there are two (2025, 2026).
--
-- New views beside the old ones, never a change to them: ref_uuc_phc_quality and
-- ref_uuc_phc_benchmark_gaps (20260827170000_uuc_phc_data_quality.sql) stay exactly as they are,
-- fixed to 2025, so nothing that reads them today changes. Each *_by_edition view below is the same
-- query with dataset_id carried through and grouped on, so the data-quality page can show the
-- edition being viewed. Their column meanings are the originals' — read that migration.
--
-- And two views for "what changed since the previous list" (/uuc-phc/changes):
--
--   ref_uuc_phc_list_changes       one row per barangay that joined or left the list between two
--                                  consecutive editions ('added' | 'removed').
--   ref_uuc_phc_indicator_changes  one row per barangay on BOTH lists whose recorded values differ,
--                                  naming which ones changed.
--
-- "Consecutive" is by as_of_date among the uuc-phc-* datasets, so a 2027 edition pairs with 2026
-- without a change here. All four are security_invoker: they read only what the caller may read,
-- and every table under them is public-read.

-- 1. ref_uuc_phc_quality, per edition.
create or replace view ref_uuc_phc_quality_by_edition
with (security_invoker = true) as
with scored as (
  select
    i.dataset_id,
    i.geo_code,
    cardinality(i.capped_indicators) as n_capped,
    i.health_indicators,
    ( coalesce(i.imr_prov_ref       <= 1000 and i.imr       > i.imr_prov_ref,       false)::int
    + coalesce(i.ufmr_prov_ref      <= 1000 and i.ufmr      > i.ufmr_prov_ref,      false)::int
    + coalesce(i.abr_prov_ref       <= 1000 and i.abr       > i.abr_prov_ref,       false)::int
    + coalesce(i.fic_prov_ref       <=  100 and i.fic       < i.fic_prov_ref,       false)::int
    + coalesce(i.pre_natal_prov_ref <=  100 and i.pre_natal < i.pre_natal_prov_ref, false)::int
    + coalesce(i.sba_prov_ref       <=  100 and i.sba       < i.sba_prov_ref,       false)::int
    + coalesce(i.water_prov_ref     <=  100 and i.water     < i.water_prov_ref,     false)::int
    ) as recomputed,
    ( coalesce(i.ip_pop, 0) >= 10
      or coalesce(i.armed_conf, 0) + coalesce(i.idp, 0) >= 10
      or coalesce(i.elcac_brgy, false)
      or coalesce(i.four_ps, 0) >= 50 ) as other_route
  from fact_uuc_phc_indicators i
  join dim_dataset d on d.dataset_id = i.dataset_id and d.slug like 'uuc-phc-%'
)
select
  dataset_id,
  count(*)::int                                              as n_listed,
  count(*) filter (where n_capped > 0)::int                  as n_barangays_capped,
  coalesce(sum(n_capped), 0)::int                            as n_values_capped,
  count(*) filter (where n_capped > 1)::int                  as n_barangays_multi_capped,
  count(*) filter (where health_indicators is not null
                     and recomputed is distinct from health_indicators)::int
                                                             as n_score_disagreement,
  count(*) filter (where health_indicators is not null
                     and recomputed < health_indicators)::int as n_score_understated,
  count(*) filter (where not other_route and recomputed < 4)::int as n_no_route_if_recomputed,
  count(*) filter (where not other_route and coalesce(health_indicators, 0) < 4)::int
                                                             as n_no_route_as_recorded
from scored
group by dataset_id;

comment on view ref_uuc_phc_quality_by_edition is
  'ref_uuc_phc_quality with one row per UUC for PHC edition (dataset_id). Same columns, same meanings: n_barangays_capped counts BARANGAYS, n_values_capped counts VALUES, and the score columns measure the gap between a recomputed criterion (d) and the source''s recorded score — never a score to report. See 20260827170000_uuc_phc_data_quality.sql.';

-- 2. ref_uuc_phc_benchmark_gaps, per edition.
create or replace view ref_uuc_phc_benchmark_gaps_by_edition
with (security_invoker = true) as
with brgy as (
  select
    i.*,
    g.province_code,
    greatest(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref,
             i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) as top_ref
  from fact_uuc_phc_indicators i
  join dim_dataset d on d.dataset_id = i.dataset_id and d.slug like 'uuc-phc-%'
  join dim_geo g on g.geo_code = i.geo_code
),
listed_per_province as (
  select dataset_id, province_code, count(*)::int as n_listed_province
  from brgy group by dataset_id, province_code
),
placeholder as (
  select
    b.dataset_id,
    b.province_code,
    case
      when count(b.top_ref) = 0 then 'no reference supplied'
      when max(b.top_ref) = 0    then 'every value zero'
      when max(b.top_ref) = 1    then 'every value exactly 1'
      else 'fractions where percentages were wanted'
    end as kind,
    count(*)::int as n_affected,
    max(b.top_ref) as witness_value
  from brgy b
  where coalesce(b.top_ref > 1, false) = false
  group by b.dataset_id, b.province_code
),
unreachable_fic as (
  select
    b.dataset_id,
    b.province_code,
    'FIC benchmark above the 100 ceiling barangay values were bounded to' as kind,
    count(*)::int as n_affected,
    max(b.fic_prov_ref) as witness_value
  from brgy b
  where b.fic_prov_ref > 100
  group by b.dataset_id, b.province_code
)
select
  gaps.dataset_id,
  gaps.province_code,
  lp.n_listed_province,
  gaps.kind,
  gaps.n_affected,
  gaps.witness_value,
  gaps.finding
from (
  select dataset_id, province_code, kind, n_affected, witness_value, 'criterion_d' as finding
  from placeholder
  union all
  select dataset_id, province_code, kind, n_affected, witness_value, 'fic_only' as finding
  from unreachable_fic
) gaps
join listed_per_province lp
  on lp.dataset_id = gaps.dataset_id and lp.province_code = gaps.province_code;

comment on view ref_uuc_phc_benchmark_gaps_by_edition is
  'ref_uuc_phc_benchmark_gaps with one set of rows per UUC for PHC edition (dataset_id). finding = criterion_d marks provinces whose whole benchmark set cannot support criterion (d); finding = fic_only marks a real FIC benchmark above the 100 ceiling barangay FIC was bounded to. n_affected is barangays. Computed from the values, never from a list of province codes. See 20260827170000_uuc_phc_data_quality.sql.';

-- 3. The editions in order, each paired with the one before it.
create or replace view ref_uuc_phc_edition_pairs
with (security_invoker = true) as
select dataset_id, slug, prev_dataset_id, prev_slug
from (
  select
    d.dataset_id,
    d.slug,
    lag(d.dataset_id) over (order by d.as_of_date, d.dataset_id) as prev_dataset_id,
    lag(d.slug)       over (order by d.as_of_date, d.dataset_id) as prev_slug
  from dim_dataset d
  where d.slug like 'uuc-phc-%'
) t
where prev_dataset_id is not null;

comment on view ref_uuc_phc_edition_pairs is
  'Each UUC for PHC edition after the first, with the edition before it by as_of_date. The pairing every change view below uses.';

-- 4. Barangays that joined or left the list between consecutive editions.
create or replace view ref_uuc_phc_list_changes
with (security_invoker = true) as
with moved as (
  select p.dataset_id, p.prev_dataset_id, 'added'::text as change, cur.geo_code,
         cur.source_province, cur.source_citymun, cur.source_barangay
  from ref_uuc_phc_edition_pairs p
  join fact_uuc_phc_barangay cur on cur.dataset_id = p.dataset_id
  where not exists (select 1 from fact_uuc_phc_barangay o
                    where o.dataset_id = p.prev_dataset_id and o.geo_code = cur.geo_code)
  union all
  select p.dataset_id, p.prev_dataset_id, 'removed'::text, old.geo_code,
         old.source_province, old.source_citymun, old.source_barangay
  from ref_uuc_phc_edition_pairs p
  join fact_uuc_phc_barangay old on old.dataset_id = p.prev_dataset_id
  where not exists (select 1 from fact_uuc_phc_barangay n
                    where n.dataset_id = p.dataset_id and n.geo_code = old.geo_code)
)
select
  m.dataset_id,
  m.prev_dataset_id,
  m.change,
  m.geo_code,
  g.geo_name,
  g.citymun_code,
  cm.geo_name as citymun_name,
  g.province_code,
  pr.geo_name as province_name,
  g.region_code,
  rg.geo_name as region_name,
  m.source_province,
  m.source_citymun,
  m.source_barangay
from moved m
join dim_geo g on g.geo_code = m.geo_code
left join dim_geo cm on cm.geo_code = g.citymun_code
left join dim_geo pr on pr.geo_code = g.province_code
left join dim_geo rg on rg.geo_code = g.region_code;

comment on view ref_uuc_phc_list_changes is
  'One row per barangay that joined (change = added) or left (change = removed) the UUC for PHC list between an edition (dataset_id) and the one before it (prev_dataset_id). Geography resolved through dim_geo as it stands today, so Sulu is under Region IX in both directions. Membership only: a barangay on both lists is never here, whatever its values did — see ref_uuc_phc_indicator_changes.';

-- 5. Barangays on both lists whose recorded values changed.
create or replace view ref_uuc_phc_indicator_changes
with (security_invoker = true) as
select
  p.dataset_id,
  p.prev_dataset_id,
  cur.geo_code,
  g.geo_name,
  g.citymun_code,
  cm.geo_name as citymun_name,
  g.province_code,
  pr.geo_name as province_name,
  g.region_code,
  rg.geo_name as region_name,
  array_remove(array[
    case when cur.physical_factor   is distinct from old.physical_factor   then 'physical_factor'   end,
    case when cur.ip_pop            is distinct from old.ip_pop            then 'ip_pop'            end,
    case when cur.armed_conf        is distinct from old.armed_conf        then 'armed_conf'        end,
    case when cur.idp               is distinct from old.idp               then 'idp'               end,
    case when cur.four_ps           is distinct from old.four_ps           then 'four_ps'           end,
    case when cur.elcac_brgy        is distinct from old.elcac_brgy        then 'elcac_brgy'        end,
    case when cur.imr               is distinct from old.imr               then 'imr'               end,
    case when cur.ufmr              is distinct from old.ufmr              then 'ufmr'              end,
    case when cur.fic               is distinct from old.fic               then 'fic'               end,
    case when cur.abr               is distinct from old.abr               then 'abr'               end,
    case when cur.pre_natal         is distinct from old.pre_natal         then 'pre_natal'         end,
    case when cur.sba               is distinct from old.sba               then 'sba'               end,
    case when cur.water             is distinct from old.water             then 'water'             end,
    case when cur.health_indicators is distinct from old.health_indicators then 'health_indicators' end
  ], null) as changed_indicators
from ref_uuc_phc_edition_pairs p
join fact_uuc_phc_indicators cur on cur.dataset_id = p.dataset_id
join fact_uuc_phc_indicators old
  on old.dataset_id = p.prev_dataset_id and old.geo_code = cur.geo_code
join dim_geo g on g.geo_code = cur.geo_code
left join dim_geo cm on cm.geo_code = g.citymun_code
left join dim_geo pr on pr.geo_code = g.province_code
left join dim_geo rg on rg.geo_code = g.region_code
where (cur.physical_factor, cur.ip_pop, cur.armed_conf, cur.idp, cur.four_ps, cur.elcac_brgy,
       cur.imr, cur.ufmr, cur.fic, cur.abr, cur.pre_natal, cur.sba, cur.water,
       cur.health_indicators)
  is distinct from
      (old.physical_factor, old.ip_pop, old.armed_conf, old.idp, old.four_ps, old.elcac_brgy,
       old.imr, old.ufmr, old.fic, old.abr, old.pre_natal, old.sba, old.water,
       old.health_indicators);

comment on view ref_uuc_phc_indicator_changes is
  'One row per barangay on both an edition (dataset_id) and the one before it (prev_dataset_id) whose recorded values differ; changed_indicators names them. Compares the values as loaded, i.e. after cleaning bounded them — a value capped to 100 in both editions reads as unchanged even if the raw figures differed. Provincial benchmarks are not compared: they are province constants, not barangay measurements.';

-- 6. ref_uuc_phc_provincial was the one UUC view with no dataset filter at all
--    (20260826150000_fact_uuc_phc_indicators.sql): it grouped every fact row by province. With a
--    second edition loaded it would count each province's barangays twice and report the larger of
--    two years' benchmarks as one. Its comment describes the 2025 list ("87" provinces, province
--    09317's "1 of 8"), so it keeps exactly that meaning: same columns, same order, now restricted
--    to 2025. The per-edition version follows it.
create or replace view ref_uuc_phc_provincial
with (security_invoker = true) as
select
  g.province_code,
  count(*)::integer as n_listed_barangays,
  count(i.water_prov_ref)::integer as n_with_reference,
  max(i.imr_prov_ref) as ref_imr,
  max(i.ufmr_prov_ref) as ref_ufmr,
  max(i.fic_prov_ref) as ref_fic,
  max(i.abr_prov_ref) as ref_abr,
  max(i.pre_natal_prov_ref) as ref_pre_natal,
  max(i.sba_prov_ref) as ref_sba,
  max(i.water_prov_ref) as ref_water
from fact_uuc_phc_indicators i
join dim_geo g on g.geo_code = i.geo_code
where i.dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2025')
group by g.province_code;

create or replace view ref_uuc_phc_provincial_by_edition
with (security_invoker = true) as
select
  i.dataset_id,
  g.province_code,
  count(*)::integer as n_listed_barangays,
  count(i.water_prov_ref)::integer as n_with_reference,
  max(i.imr_prov_ref) as ref_imr,
  max(i.ufmr_prov_ref) as ref_ufmr,
  max(i.fic_prov_ref) as ref_fic,
  max(i.abr_prov_ref) as ref_abr,
  max(i.pre_natal_prov_ref) as ref_pre_natal,
  max(i.sba_prov_ref) as ref_sba,
  max(i.water_prov_ref) as ref_water
from fact_uuc_phc_indicators i
join dim_dataset d on d.dataset_id = i.dataset_id and d.slug like 'uuc-phc-%'
join dim_geo g on g.geo_code = i.geo_code
group by i.dataset_id, g.province_code;

comment on view ref_uuc_phc_provincial_by_edition is
  'ref_uuc_phc_provincial with one set of rows per UUC for PHC edition (dataset_id): the benchmarks criterion (d) tests each edition''s barangays against. Each edition''s own seed asserts that no province carries two different values within that edition.';

grant select on ref_uuc_phc_quality_by_edition, ref_uuc_phc_benchmark_gaps_by_edition,
  ref_uuc_phc_edition_pairs, ref_uuc_phc_list_changes, ref_uuc_phc_indicator_changes,
  ref_uuc_phc_provincial, ref_uuc_phc_provincial_by_edition
  to anon, authenticated;

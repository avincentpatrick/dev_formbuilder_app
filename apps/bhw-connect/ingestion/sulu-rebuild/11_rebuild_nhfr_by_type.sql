-- Rebuild agg_nhfr_by_type
-- Extracted from supabase/migrations/20260905090300_agg_nhfr_by_type.sql (from its populate step on); creates nothing, recomputes rows.
-- Run once, after db/check-sulu-region.mjs shows the table stale. Safe to re-run.
begin;
-- Populate. Idempotent, and deletes first so a facility type that disappears from the source on a
-- re-load does not leave a stale row behind — an upsert alone cannot remove rows, and a lingering
-- "3 dialysis clinics" for a town that no longer has any is exactly the kind of quiet wrongness
-- this dataset must not publish.
delete from agg_nhfr_by_type
 where dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');

with ds as (
  select dataset_id from dim_dataset where slug = 'nhfr-2026-09'
),
fac as (
  select
    f.geo_code as citymun_code,
    g.province_code,
    g.region_code,
    f.facility_type,
    f.ownership_major
  from fact_nhfr_facility f
  join dim_geo g on g.geo_code = f.geo_code
  where f.dataset_id = (select dataset_id from ds)
),
rolled as (
  select 'PH' as geo_code, 'national'::geo_level_enum as geo_level,
         facility_type,
         count(*)::int as n_facilities,
         count(*) filter (where ownership_major = 'Government')::int as n_government,
         count(*) filter (where ownership_major = 'Private')::int as n_private
    from fac group by facility_type
  union all
  select region_code, 'region'::geo_level_enum,
         facility_type,
         count(*)::int,
         count(*) filter (where ownership_major = 'Government')::int,
         count(*) filter (where ownership_major = 'Private')::int
    from fac group by region_code, facility_type
  union all
  select province_code, 'province'::geo_level_enum,
         facility_type,
         count(*)::int,
         count(*) filter (where ownership_major = 'Government')::int,
         count(*) filter (where ownership_major = 'Private')::int
    from fac group by province_code, facility_type
  union all
  select citymun_code, 'citymun'::geo_level_enum,
         facility_type,
         count(*)::int,
         count(*) filter (where ownership_major = 'Government')::int,
         count(*) filter (where ownership_major = 'Private')::int
    from fac group by citymun_code, facility_type
)
insert into agg_nhfr_by_type (
  dataset_id, geo_code, geo_level, facility_type,
  n_facilities, n_government, n_private
)
select (select dataset_id from ds), r.*
from rolled r
on conflict (dataset_id, geo_code, geo_level, facility_type) do update set
  n_facilities = excluded.n_facilities,
  n_government = excluded.n_government,
  n_private = excluded.n_private;

-- Assert this table and agg_nhfr_counts tell the same story. They are computed from the same
-- fact table by different paths, so a disagreement means one of the two rollups is wrong — and
-- the section renders both on the same page.
do $$
declare
  v_mismatch int;
begin
  select count(*) into v_mismatch
    from agg_nhfr_counts c
    left join (
      select dataset_id, geo_code, geo_level, sum(n_facilities)::int as n
        from agg_nhfr_by_type
       where dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09')
       group by dataset_id, geo_code, geo_level
    ) t
      on t.dataset_id = c.dataset_id
     and t.geo_code = c.geo_code
     and t.geo_level = c.geo_level
   where c.dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09')
     and coalesce(t.n, 0) <> c.n_facilities;

  if v_mismatch > 0 then
    raise exception
      '% areas where agg_nhfr_by_type does not sum to agg_nhfr_counts.n_facilities', v_mismatch;
  end if;
end $$;

commit;

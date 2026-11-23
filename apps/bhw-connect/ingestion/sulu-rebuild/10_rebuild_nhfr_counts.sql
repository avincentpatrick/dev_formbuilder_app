-- Rebuild agg_nhfr_counts
-- Extracted from supabase/migrations/20260905090200_agg_nhfr_counts.sql (from its populate step on); creates nothing, recomputes rows.
-- Run once, after db/check-sulu-region.mjs shows the table stale. Safe to re-run.
begin;
-- Populate. Idempotent: recomputes and upserts every row.
with ds as (
  select dataset_id from dim_dataset where slug = 'nhfr-2026-09'
),
-- Facilities attributed to their city/municipality. geo_code is already citymun grain on the
-- fact table, so this is the leaf the rollups sum from.
fac as (
  select
    f.geo_code as citymun_code,
    f.ownership_major,
    f.facility_type,
    f.bed_capacity,
    f.barangay_geo_code
  from fact_nhfr_facility f
  where f.dataset_id = (select dataset_id from ds)
),
-- Per city/municipality: the counts, plus how many distinct barangays have at least one facility.
by_citymun as (
  select
    citymun_code,
    count(*)::int as n_facilities,
    count(*) filter (where ownership_major = 'Government')::int as n_government,
    count(*) filter (where ownership_major = 'Private')::int as n_private,
    count(*) filter (where facility_type = 'Barangay Health Station')::int as n_bhs,
    count(*) filter (where facility_type = 'Rural Health Unit')::int as n_rhu,
    count(*) filter (where facility_type = 'Hospital')::int as n_hospital,
    count(*) filter (where facility_type = 'Birthing Home')::int as n_birthing_home,
    coalesce(sum(bed_capacity), 0)::int as total_bed_capacity,
    count(distinct barangay_geo_code)::int as n_barangays_with_facility
  from fac
  group by citymun_code
),
-- Every city/municipality in dim_geo, with its own barangay count as the coverage denominator
-- and zeros where it has no facilities at all.
citymun as (
  select
    g.geo_code as citymun_code,
    g.province_code,
    g.region_code,
    coalesce(c.n_facilities, 0) as n_facilities,
    coalesce(c.n_government, 0) as n_government,
    coalesce(c.n_private, 0) as n_private,
    coalesce(c.n_bhs, 0) as n_bhs,
    coalesce(c.n_rhu, 0) as n_rhu,
    coalesce(c.n_hospital, 0) as n_hospital,
    coalesce(c.n_birthing_home, 0) as n_birthing_home,
    coalesce(c.total_bed_capacity, 0) as total_bed_capacity,
    coalesce(c.n_barangays_with_facility, 0) as n_barangays_with_facility,
    (
      select count(*)::int from dim_geo b
      where b.geo_level = 'barangay' and b.citymun_code = g.geo_code
    ) as n_barangays
  from dim_geo g
  left join by_citymun c on c.citymun_code = g.geo_code
  where g.geo_level = 'citymun'
),
rolled as (
  select
    'PH' as geo_code, 'national'::geo_level_enum as geo_level,
    sum(n_facilities)::int, sum(n_government)::int, sum(n_private)::int,
    sum(n_bhs)::int, sum(n_rhu)::int, sum(n_hospital)::int, sum(n_birthing_home)::int,
    sum(total_bed_capacity)::int, sum(n_barangays_with_facility)::int, sum(n_barangays)::int
  from citymun
  union all
  select
    region_code, 'region'::geo_level_enum,
    sum(n_facilities)::int, sum(n_government)::int, sum(n_private)::int,
    sum(n_bhs)::int, sum(n_rhu)::int, sum(n_hospital)::int, sum(n_birthing_home)::int,
    sum(total_bed_capacity)::int, sum(n_barangays_with_facility)::int, sum(n_barangays)::int
  from citymun group by region_code
  union all
  select
    province_code, 'province'::geo_level_enum,
    sum(n_facilities)::int, sum(n_government)::int, sum(n_private)::int,
    sum(n_bhs)::int, sum(n_rhu)::int, sum(n_hospital)::int, sum(n_birthing_home)::int,
    sum(total_bed_capacity)::int, sum(n_barangays_with_facility)::int, sum(n_barangays)::int
  from citymun group by province_code
  union all
  select
    citymun_code, 'citymun'::geo_level_enum,
    n_facilities, n_government, n_private,
    n_bhs, n_rhu, n_hospital, n_birthing_home,
    total_bed_capacity, n_barangays_with_facility, n_barangays
  from citymun
)
insert into agg_nhfr_counts (
  dataset_id, geo_code, geo_level,
  n_facilities, n_government, n_private,
  n_barangay_health_station, n_rural_health_unit, n_hospital, n_birthing_home,
  total_bed_capacity, n_barangays_with_facility, n_barangays
)
select (select dataset_id from ds), r.*
from rolled r
on conflict (dataset_id, geo_code, geo_level) do update set
  n_facilities = excluded.n_facilities,
  n_government = excluded.n_government,
  n_private = excluded.n_private,
  n_barangay_health_station = excluded.n_barangay_health_station,
  n_rural_health_unit = excluded.n_rural_health_unit,
  n_hospital = excluded.n_hospital,
  n_birthing_home = excluded.n_birthing_home,
  total_bed_capacity = excluded.total_bed_capacity,
  n_barangays_with_facility = excluded.n_barangays_with_facility,
  n_barangays = excluded.n_barangays;

-- Assert the rollups agree before anything reads them. A regional total that does not equal the
-- sum of its provinces is the failure mode this whole shape exists to prevent, and it is cheaper
-- to fail the migration than to publish a wrong headline number.
do $$
declare
  v_national int;
  v_regions int;
  v_provinces int;
  v_citymuns int;
  v_over int;
begin
  select n_facilities into v_national from agg_nhfr_counts
   where geo_level = 'national'
     and dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');
  select coalesce(sum(n_facilities), 0) into v_regions from agg_nhfr_counts
   where geo_level = 'region'
     and dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');
  select coalesce(sum(n_facilities), 0) into v_provinces from agg_nhfr_counts
   where geo_level = 'province'
     and dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');
  select coalesce(sum(n_facilities), 0) into v_citymuns from agg_nhfr_counts
   where geo_level = 'citymun'
     and dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');

  if v_national <> v_regions or v_national <> v_provinces or v_national <> v_citymuns then
    raise exception
      'agg_nhfr_counts rollups disagree: national %, regions %, provinces %, citymuns %',
      v_national, v_regions, v_provinces, v_citymuns;
  end if;

  select count(*) into v_over from agg_nhfr_counts
   where n_barangays_with_facility > n_barangays
     and dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');
  if v_over > 0 then
    raise exception
      '% areas report more barangays with a facility than they have barangays', v_over;
  end if;

  select count(*) into v_over from agg_nhfr_counts
   where n_government + n_private <> n_facilities
     and dataset_id = (select dataset_id from dim_dataset where slug = 'nhfr-2026-09');
  if v_over > 0 then
    raise exception
      '% areas where government + private does not equal the facility total', v_over;
  end if;
end $$;

commit;

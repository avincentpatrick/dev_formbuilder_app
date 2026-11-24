-- Filtered public inventory summary. Union presence is counted once per barangay,
-- never by summing the presence counts for overlapping facility types.
create or replace function public.nhfr_headline_summary(
  p_dataset_id bigint, p_roots text[], p_types text[] default '{}'::text[]
) returns jsonb
language sql stable security invoker
set search_path = public
as $$
  with recursive scope as (
    select geo_code, geo_level, parent_code from dim_geo where geo_code = any(p_roots)
    union
    select g.geo_code, g.geo_level, g.parent_code
    from dim_geo g join scope s on g.parent_code = s.geo_code
  ), facilities as materialized (
    select f.facility_type, f.ownership_major, f.bed_capacity,
      case when b.geo_code is not null and b.parent_code = f.geo_code
        then b.geo_code end as barangay_code
    from fact_nhfr_facility f
    left join dim_geo b on b.geo_code = f.barangay_geo_code and b.geo_level = 'barangay'
    where f.dataset_id = p_dataset_id
      and (exists (select 1 from scope s where s.geo_code = f.geo_code and s.geo_level = 'citymun')
        or exists (select 1 from scope s where s.geo_code = b.geo_code
          and s.geo_level = 'barangay' and b.parent_code = f.geo_code))
  ), selected as (
    select * from facilities
    where cardinality(p_types) = 0 or facility_type = any(p_types)
  )
  select jsonb_build_object(
    'total', (select count(*) from facilities),
    'count', count(*),
    'government', count(*) filter (where ownership_major = 'Government'),
    'private', count(*) filter (where ownership_major = 'Private'),
    'beds', coalesce(sum(bed_capacity), 0),
    'present', count(distinct barangay_code),
    'barangays', (select count(*) from scope where geo_level = 'barangay'),
    'unattributed', count(*) filter (where barangay_code is null),
    'types', coalesce((select jsonb_agg(t.facility_type order by t.facility_type)
      from (select distinct facility_type from fact_nhfr_facility
        where dataset_id = p_dataset_id) t), '[]'::jsonb)
  ) from selected;
$$;

revoke all on function public.nhfr_headline_summary(bigint, text[], text[]) from public;
grant execute on function public.nhfr_headline_summary(bigint, text[], text[]) to anon, authenticated;
comment on function public.nhfr_headline_summary(bigint, text[], text[]) is
  'Public NHFR inventory for verified geographic roots and an OR selection of facility types. Uses caller RLS; includes no personal or facility-level fields.';

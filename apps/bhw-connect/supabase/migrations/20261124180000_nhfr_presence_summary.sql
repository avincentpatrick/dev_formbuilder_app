-- Public child-area presence for an OR selection of facility types. No facility rows leave SQL.
create or replace function public.nhfr_presence_summary(
  p_dataset_id bigint, p_root text, p_types text[] default '{}'::text[]
) returns jsonb
language sql stable security invoker
set search_path = public
as $$
  with recursive children as (
    select g.geo_code, g.geo_level from dim_geo g
    where (p_root = 'PH' and g.geo_level = 'region')
       or (p_root <> 'PH' and g.parent_code = p_root and g.geo_level::text = case
         when exists(select 1 from dim_geo where geo_code = p_root and geo_level = 'region')
         then 'province' else 'citymun' end)
  ), scope as (
    select geo_code as area_code, geo_code, geo_level from children
    union
    select s.area_code, g.geo_code, g.geo_level
    from scope s join dim_geo g on g.parent_code = s.geo_code
  ), facilities as materialized (
    select s.area_code, f.facility_type,
      case when b.parent_code = f.geo_code then b.geo_code end as barangay_code
    from scope s join fact_nhfr_facility f on f.geo_code = s.geo_code
      and s.geo_level = 'citymun' and f.dataset_id = p_dataset_id
    left join dim_geo b on b.geo_code = f.barangay_geo_code and b.geo_level = 'barangay'
  ), totals as (
    select area_code, count(*) as total, count(distinct barangay_code) as all_present,
      count(distinct barangay_code) filter
        (where cardinality(p_types) = 0 or facility_type = any(p_types)) as present
    from facilities group by area_code
  )
  select jsonb_build_object(
    'types', coalesce((select jsonb_agg(t.facility_type order by t.facility_type)
      from (select distinct facility_type from fact_nhfr_facility where dataset_id = p_dataset_id) t), '[]'::jsonb),
    'rows', coalesce((select jsonb_agg(jsonb_build_object(
      'code', c.geo_code, 'total', coalesce(t.total,0), 'allPresent', coalesce(t.all_present,0),
      'present', coalesce(t.present,0),
      'barangays', (select count(*) from scope s where s.area_code = c.geo_code and s.geo_level = 'barangay')
    ) order by c.geo_code) from children c left join totals t on t.area_code = c.geo_code), '[]'::jsonb)
  );
$$;
revoke all on function public.nhfr_presence_summary(bigint, text, text[]) from public;
grant execute on function public.nhfr_presence_summary(bigint, text, text[]) to anon, authenticated;
comment on function public.nhfr_presence_summary(bigint, text, text[]) is
  'Public child-area distinct barangay presence, using caller RLS, validated barangay parentage and OR facility types. Baseline fields support reconciliation with accepted aggregates.';

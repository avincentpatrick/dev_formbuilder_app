-- Rebuild agg_uuc_phc_indicator_dist
-- Extracted from supabase/migrations/20260827160000_agg_uuc_phc_indicator_dist.sql (from its populate step on); creates nothing, recomputes rows.
-- Run once, after db/check-sulu-region.mjs shows the table stale. Safe to re-run.
begin;
-- Populate. Idempotent: recomputes and upserts every row, so re-running after a fact reload is
-- the refresh procedure. The (dataset_id, geo_code, geo_level, indicator) unique key makes every
-- row a stable target, so nothing is deleted.
--
-- A row exists for every geo and every indicator, including areas with nothing listed — U2's
-- decision, for U2's reason: NCR's zero is a result, not a gap, and the page renders it as an
-- empty state rather than as an unavailable one.
with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2025'
),
brgy as (
  -- One row per listed barangay, carrying the criterion (d) evaluability test once so all seven
  -- health indicators apply the same one. This is U7's rule verbatim: the largest of the seven
  -- benchmarks exists and is above 1. greatest() ignores nulls, so it is null only where the
  -- source supplied none of the seven, and the comparison is then null too. It selects exactly
  -- the 226 barangays in 5 provinces whose benchmarks are placeholders, zero-fills, missing or
  -- fractions — computed, never a hard-coded list of province codes, so a corrected extract makes
  -- the rule stop firing rather than makes it wrong.
  select
    i.*,
    coalesce(greatest(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref,
                      i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > 1,
             false) as evaluable
  from fact_uuc_phc_indicators i
  where i.dataset_id = (select dataset_id from ds)
),
val as (
  -- One row per (listed barangay, indicator). The lateral VALUES list is the single place the 12
  -- columns, their domains, their cap flags and criterion (d)'s direction are stated together —
  -- the alternative is twelve near-identical subqueries, which is twelve chances to pair a value
  -- with the wrong benchmark.
  select
    b.geo_code,
    v.indicator, v.val, v.capped, v.ref, v.mx, v.higher_is_worse,
    b.evaluable
  from brgy b
  cross join lateral (values
    -- Qualifying factors (AO §VI.A). No provincial benchmark: criterion (d) does not test these.
    ('physical_factor', b.physical_factor, false,                                 null::numeric, 100::numeric, null::boolean),
    ('ip_pop',          b.ip_pop,          false,                                 null,          100,          null),
    ('armed_conf',      b.armed_conf,      false,                                 null,          100,          null),
    ('idp',             b.idp,             false,                                 null,          100,          null),
    ('four_ps',         b.four_ps,         false,                                 null,          100,          null),
    -- Health indicators (criterion d). A higher rate is worse; a higher coverage is better.
    ('imr',             b.imr,             'imr'       = any(b.capped_indicators), b.imr_prov_ref,       1000, true),
    ('ufmr',            b.ufmr,            'ufmr'      = any(b.capped_indicators), b.ufmr_prov_ref,      1000, true),
    ('abr',             b.abr,             'abr'       = any(b.capped_indicators), b.abr_prov_ref,       1000, true),
    ('fic',             b.fic,             'fic'       = any(b.capped_indicators), b.fic_prov_ref,        100, false),
    ('pre_natal',       b.pre_natal,       'pre_natal' = any(b.capped_indicators), b.pre_natal_prov_ref,  100, false),
    ('sba',             b.sba,             'sba'       = any(b.capped_indicators), b.sba_prov_ref,        100, false),
    ('water',           b.water,           'water'     = any(b.capped_indicators), b.water_prov_ref,      100, false)
  ) as v(indicator, val, capped, ref, mx, higher_is_worse)
),
scored as (
  -- comparesWorse, in SQL. Kept identical to lib/db/uuc-phc-indicators.ts's function plus U7's
  -- placeholder rule, so the count on /uuc-phc/indicators and the verdict in one barangay's
  -- disclosure cannot say different things about the same barangay.
  select
    v.*,
    (v.evaluable and v.val is not null and v.ref is not null and v.ref <= v.mx) as comparable,
    (v.evaluable and v.val is not null and v.ref is not null and v.ref <= v.mx
      and case when v.higher_is_worse then v.val > v.ref else v.val < v.ref end) as worse,
    -- least(9, ...) closes the top bin inclusive; greatest(0, ...) is belt and braces — the
    -- cleaning report records zero negative values, and a future extract with one would land it
    -- in the first bin rather than outside the array.
    least(9, greatest(0, floor(v.val / (v.mx / 10.0))))::int as bin
  from val v
),
-- Each listed barangay contributes to its own four ancestor rows. Written once here rather than
-- as four near-identical group-bys, which is how a level quietly loses or double-counts one.
geo_map as (
  select g.geo_code as brgy_code, 'PH' as geo_code, 'national'::geo_level_enum as geo_level
    from dim_geo g where g.geo_level = 'barangay'
  union all
  select g.geo_code, g.region_code, 'region'::geo_level_enum
    from dim_geo g where g.geo_level = 'barangay'
  union all
  select g.geo_code, g.province_code, 'province'::geo_level_enum
    from dim_geo g where g.geo_level = 'barangay'
  union all
  select g.geo_code, g.citymun_code, 'citymun'::geo_level_enum
    from dim_geo g where g.geo_level = 'barangay'
),
binned as (
  select m.geo_code, m.geo_level, s.indicator, s.bin,
         count(*)::int as n,
         count(*) filter (where s.capped)::int as n_cap
  from scored s
  join geo_map m on m.brgy_code = s.geo_code
  where s.val is not null
  group by 1, 2, 3, 4
),
-- Every (geo, indicator, bin) that must exist, including the empty ones. Built from
-- agg_uuc_phc_counts because that is the table which already decides what "every geo" means.
scaffold as (
  select c.geo_code, c.geo_level, ind.indicator, ind.mx, s.bin
  from agg_uuc_phc_counts c
  cross join (values
    ('physical_factor', 100::numeric), ('ip_pop', 100), ('armed_conf', 100), ('idp', 100),
    ('four_ps', 100), ('imr', 1000), ('ufmr', 1000), ('abr', 1000), ('fic', 100),
    ('pre_natal', 100), ('sba', 100), ('water', 100)
  ) as ind(indicator, mx)
  cross join generate_series(0, 9) as s(bin)
  where c.dataset_id = (select dataset_id from ds)
),
arrays as (
  select s.geo_code, s.geo_level, s.indicator, s.mx,
         array_agg(coalesce(b.n, 0) order by s.bin) as bin_counts,
         array_agg(coalesce(b.n_cap, 0) order by s.bin) as bin_capped
  from scaffold s
  left join binned b
    on b.geo_code = s.geo_code and b.geo_level = s.geo_level
   and b.indicator = s.indicator and b.bin = s.bin
  group by 1, 2, 3, 4
),
scalars as (
  select m.geo_code, m.geo_level, s.indicator,
         count(*)::int as n_listed,
         count(*) filter (where s.val is null)::int as n_missing,
         count(*) filter (where s.comparable)::int as n_comparable,
         count(*) filter (where s.worse)::int as n_worse,
         -- The province's benchmark, taken from the area's own listed barangays. Unambiguous
         -- below region: every barangay of a city/municipality shares one province, and the seed
         -- migration for fact_uuc_phc_indicators asserts no province carries two different
         -- reference values. Discarded above province by the CASE in the final select.
         max(s.ref) as provincial_ref
  from scored s
  join geo_map m on m.brgy_code = s.geo_code
  group by 1, 2, 3
)
insert into agg_uuc_phc_indicator_dist (
  dataset_id, geo_code, geo_level, indicator, value_max, n_listed,
  bin_counts, bin_capped, n_missing, provincial_ref, n_comparable, n_worse
)
select
  (select dataset_id from ds),
  a.geo_code, a.geo_level, a.indicator, a.mx,
  coalesce(sc.n_listed, 0),
  a.bin_counts, a.bin_capped,
  coalesce(sc.n_missing, 0),
  -- A single reference line exists only within one province. Above that the area spans 87 of
  -- them and any one value would be an arbitrary province's standing in for the rest.
  case when a.geo_level in ('province', 'citymun') then sc.provincial_ref end,
  coalesce(sc.n_comparable, 0),
  coalesce(sc.n_worse, 0)
from arrays a
left join scalars sc
  on sc.geo_code = a.geo_code and sc.geo_level = a.geo_level and sc.indicator = a.indicator
on conflict (dataset_id, geo_code, geo_level, indicator) do update set
  value_max = excluded.value_max,
  n_listed = excluded.n_listed,
  bin_counts = excluded.bin_counts,
  bin_capped = excluded.bin_capped,
  n_missing = excluded.n_missing,
  provincial_ref = excluded.provincial_ref,
  n_comparable = excluded.n_comparable,
  n_worse = excluded.n_worse;

-- ---------------------------------------------------------------------------------------------
-- Assert, after loading, the invariants the page depends on. Each is cheap, and each has a way of
-- being violated silently by a future edit — which is the test for whether an assertion earns its
-- place. A failure aborts the migration rather than publishing a wrong histogram.
do $$
declare
  n_bad integer;
begin
  -- 1. Every geo carries all 12 indicators, and the denominator is the one agg_uuc_phc_counts
  --    publishes. A histogram drawn against a different n_listed than the coverage page prints
  --    for the same area is the drift this check exists to catch.
  select count(*) into n_bad
  from agg_uuc_phc_counts c
  left join (
    select dataset_id, geo_code, geo_level, count(*) as n_ind, min(n_listed) as lo, max(n_listed) as hi
    from agg_uuc_phc_indicator_dist group by 1, 2, 3
  ) d on d.dataset_id = c.dataset_id and d.geo_code = c.geo_code and d.geo_level = c.geo_level
  where d.geo_code is null or d.n_ind <> 12 or d.lo <> c.n_listed or d.hi <> c.n_listed;
  if n_bad > 0 then
    raise exception '% geo(s) lack all 12 indicator rows or disagree with agg_uuc_phc_counts.n_listed', n_bad;
  end if;

  -- 2. The bars account for every listed barangay. A histogram whose bins sum to less than the
  --    area's list is a histogram of an unstated subset, which is the failure mode this whole
  --    table exists to avoid.
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist
  where (select coalesce(sum(x), 0) from unnest(bin_counts) x) + n_missing <> n_listed;
  if n_bad > 0 then
    raise exception '% row(s) have bins + n_missing <> n_listed', n_bad;
  end if;

  -- 3. Capped counts are a subset of their bin, and live only in the top bin. The second half is
  --    what lets the page label the top bar "of which N were bounded" without qualifying where in
  --    the distribution the bounded values are: a bounded value equals value_max by definition.
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist d
  where (select coalesce(sum(x), 0) from unnest(d.bin_capped[1:9]) x) > 0
     or exists (
       select 1 from generate_series(1, 10) i
       where d.bin_capped[i] > d.bin_counts[i] or d.bin_capped[i] < 0 or d.bin_counts[i] < 0
     );
  if n_bad > 0 then
    raise exception '% row(s) have capped values outside the top bin or above their bin count', n_bad;
  end if;

  -- 4. The national capped totals are the fact table's own, per indicator — the figure the page
  --    prints on the top bar. docs/UUC_PHC_2025_CLEANING_REPORT.md §4 records 886 Water, 456 FIC,
  --    208 Pre-natal, 30 SBA, 2 ABR, 1 IMR, 1 UFMR; this compares against the data rather than
  --    against those numbers, so a regenerated extract moves both together.
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist d
  join (
    select unnest(capped_indicators) as indicator, count(*)::int as n
    from fact_uuc_phc_indicators
    where dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2025')
    group by 1
  ) f on f.indicator = d.indicator
  where d.geo_level = 'national'
    and (select coalesce(sum(x), 0) from unnest(d.bin_capped) x) <> f.n;
  if n_bad > 0 then
    raise exception '% national indicator(s) disagree with fact_uuc_phc_indicators.capped_indicators', n_bad;
  end if;

  -- 5. Comparison counts nest inside their denominators, and the five socio-economic indicators
  --    have no comparison at all — criterion (d) does not test them, so a non-zero count there
  --    would mean a benchmark had been paired with the wrong column.
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist
  where n_worse > n_comparable
     or n_comparable > n_listed
     or least(n_worse, n_comparable, n_missing, n_listed) < 0
     or (indicator in ('physical_factor', 'ip_pop', 'armed_conf', 'idp', 'four_ps')
         and (n_comparable > 0 or n_worse > 0 or provincial_ref is not null));
  if n_bad > 0 then
    raise exception '% row(s) have a comparison count outside its denominator, or one on an indicator criterion (d) does not test', n_bad;
  end if;

  -- 6. A reference line exists only where a single province's benchmark does.
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist
  where provincial_ref is not null and geo_level not in ('province', 'citymun');
  if n_bad > 0 then
    raise exception '% row(s) carry a provincial benchmark above province level', n_bad;
  end if;

  -- 7. n_comparable must agree with agg_uuc_phc_criteria's evaluable count wherever this
  --    indicator's own benchmark is usable everywhere in the area. The two are computed from the
  --    same rule in two files; this is what stops one of them being edited alone. FIC is exempt
  --    because 113 barangays additionally fail the benchmark-exceeds-maximum test, which is an
  --    indicator-level exclusion agg_uuc_phc_criteria has no column for.
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist d
  join agg_uuc_phc_criteria c
    on c.dataset_id = d.dataset_id and c.geo_code = d.geo_code and c.geo_level = d.geo_level
  where d.indicator in ('imr', 'ufmr', 'abr', 'pre_natal', 'sba', 'water')
    and d.n_comparable <> c.n_health_evaluable;
  if n_bad > 0 then
    raise exception '% row(s) disagree with agg_uuc_phc_criteria.n_health_evaluable', n_bad;
  end if;

  -- 8. Each level rolls up to the same national totals, per indicator. A region, province or
  --    city that lost or double-counted a barangay shows up here and nowhere else.
  select count(*) into n_bad
  from (
    select indicator, geo_level,
           sum(n_listed) as l, sum(n_missing) as m,
           sum(n_comparable) as c, sum(n_worse) as w,
           sum((select coalesce(sum(x), 0) from unnest(bin_capped) x)) as k
    from agg_uuc_phc_indicator_dist
    group by 1, 2
  ) t
  join (
    select indicator,
           n_listed as l, n_missing as m, n_comparable as c, n_worse as w,
           (select coalesce(sum(x), 0) from unnest(bin_capped) x) as k
    from agg_uuc_phc_indicator_dist where geo_level = 'national'
  ) nat on nat.indicator = t.indicator
  where t.l <> nat.l or t.m <> nat.m or t.c <> nat.c or t.w <> nat.w or t.k <> nat.k;
  if n_bad > 0 then
    raise exception '% level/indicator pair(s) do not roll up to the national totals', n_bad;
  end if;
end $$;

commit;

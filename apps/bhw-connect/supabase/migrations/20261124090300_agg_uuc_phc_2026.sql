-- The 2026 UUC for PHC list's aggregates: the same four tables the 2025 list has, computed the same
-- way, under the 2026 dataset_id. Nothing belonging to 2025 is read for writing or changed.
--
-- Each block below is the 2025 migration's own statement with only the slug changed — so the two
-- editions cannot drift apart in how a figure is derived:
--
--   1. agg_uuc_phc_counts          20260826140000_agg_uuc_phc_counts.sql
--   2. agg_uuc_phc_criteria        20260827100000_agg_uuc_phc_criteria.sql
--   3. agg_uuc_phc_indicator_dist  20260827160000_agg_uuc_phc_indicator_dist.sql
--   4. agg_bhw_by_uuc_status       20260827190000_agg_bhw_by_uuc_status.sql (and its n < 5 rule)
--
-- Read those migrations for what each column means and why. Two things differ here, both because a
-- second edition now shares the tables:
--
--   * Every insert carries `where <2026 dataset_id> is not null`. CI and the local copy run this
--     migration against empty tables (db/load-dev.mjs) where the 2026 row does not exist; the
--     national roll-up would otherwise insert a row with a NULL dataset_id there.
--   * Every assertion is scoped to the 2026 dataset_id. The 2025 migrations' roll-up checks sum a
--     whole table against "the" national row, which with two editions present would count both.
--
-- Geography is dim_geo's as it stands after 20261123100000_sulu_region_ix.sql, so Sulu's 87 listed
-- barangays roll up under Region IX: 613 there, 331 in BARMM, 6,129 nationally.
-- Idempotent: re-running recomputes every 2026 row in place.

-- 1. agg_uuc_phc_counts
with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2026'
),
brgy as (
  -- One row per barangay in the country, flagged with whether it is on the list. A LEFT JOIN,
  -- not an inner one: the zero rows are the point (see the header note).
  select
    g.citymun_code,
    g.province_code,
    g.region_code,
    case when f.geo_code is null then 0 else 1 end as listed
  from dim_geo g
  left join fact_uuc_phc_barangay f
    on f.geo_code = g.geo_code
   and f.dataset_id = (select dataset_id from ds)
  where g.geo_level = 'barangay'
),
rolled as (
  select 'PH' as geo_code, 'national'::geo_level_enum as geo_level,
         sum(listed)::int as n_listed, count(*)::int as n_barangays
    from brgy
  union all
  select region_code, 'region'::geo_level_enum, sum(listed)::int, count(*)::int
    from brgy group by region_code
  union all
  select province_code, 'province'::geo_level_enum, sum(listed)::int, count(*)::int
    from brgy group by province_code
  union all
  select citymun_code, 'citymun'::geo_level_enum, sum(listed)::int, count(*)::int
    from brgy group by citymun_code
)
insert into agg_uuc_phc_counts (dataset_id, geo_code, geo_level, n_listed, n_barangays)
select (select dataset_id from ds), r.geo_code, r.geo_level, r.n_listed, r.n_barangays
from rolled r
where (select dataset_id from ds) is not null
on conflict (dataset_id, geo_code, geo_level) do update set
  n_listed = excluded.n_listed,
  n_barangays = excluded.n_barangays;

-- 2. agg_uuc_phc_criteria
with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2026'
),
brgy as (
  -- One row per barangay in the country, carrying its route flags where it is listed and zeroes
  -- where it is not. A LEFT JOIN, not an inner one: the zero rows are the point.
  select
    g.citymun_code,
    g.province_code,
    g.region_code,
    (i.geo_code is not null)::int as listed,
    (coalesce(i.ip_pop, 0) >= 10)::int as route_ip,
    -- Criterion (b) is the union of the summed conflict/displacement test and the ELCAC
    -- designation. Nulls read as 0 in the sum, which cannot manufacture a pass: a pass needs 10.
    ((coalesce(i.armed_conf, 0) + coalesce(i.idp, 0) >= 10)
       or coalesce(i.elcac_brgy, false))::int as route_conflict,
    (coalesce(i.four_ps, 0) >= 50)::int as route_four_ps,
    -- Evaluable: the largest of this barangay's seven benchmarks exists and is above 1. greatest()
    -- ignores nulls, so it is null only when the source supplied none of the seven — and the
    -- comparison is then null too, which coalesce resolves to false. That also covers the
    -- barangays this LEFT JOIN found nothing for: not listed is not evaluable.
    coalesce(greatest(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref,
                      i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > 1,
             false)::int as evaluable,
    coalesce(greatest(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref,
                      i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > 1
               and i.health_indicators >= 4,
             false)::int as route_health
  from dim_geo g
  left join fact_uuc_phc_indicators i
    on i.geo_code = g.geo_code
   and i.dataset_id = (select dataset_id from ds)
  where g.geo_level = 'barangay'
),
rolled as (
  select 'PH' as geo_code, 'national'::geo_level_enum as geo_level,
         sum(listed)::int as n_listed,
         sum(route_ip)::int as n_route_ip,
         sum(route_conflict)::int as n_route_conflict,
         sum(route_four_ps)::int as n_route_four_ps,
         sum(route_health)::int as n_route_health,
         sum(evaluable)::int as n_health_evaluable
    from brgy
  union all
  select region_code, 'region'::geo_level_enum, sum(listed)::int, sum(route_ip)::int,
         sum(route_conflict)::int, sum(route_four_ps)::int, sum(route_health)::int,
         sum(evaluable)::int
    from brgy group by region_code
  union all
  select province_code, 'province'::geo_level_enum, sum(listed)::int, sum(route_ip)::int,
         sum(route_conflict)::int, sum(route_four_ps)::int, sum(route_health)::int,
         sum(evaluable)::int
    from brgy group by province_code
  union all
  select citymun_code, 'citymun'::geo_level_enum, sum(listed)::int, sum(route_ip)::int,
         sum(route_conflict)::int, sum(route_four_ps)::int, sum(route_health)::int,
         sum(evaluable)::int
    from brgy group by citymun_code
)
insert into agg_uuc_phc_criteria (
  dataset_id, geo_code, geo_level, n_listed,
  n_route_ip, n_route_conflict, n_route_four_ps, n_route_health, n_health_evaluable
)
select (select dataset_id from ds), r.geo_code, r.geo_level, r.n_listed,
       r.n_route_ip, r.n_route_conflict, r.n_route_four_ps, r.n_route_health, r.n_health_evaluable
from rolled r
where (select dataset_id from ds) is not null
on conflict (dataset_id, geo_code, geo_level) do update set
  n_listed = excluded.n_listed,
  n_route_ip = excluded.n_route_ip,
  n_route_conflict = excluded.n_route_conflict,
  n_route_four_ps = excluded.n_route_four_ps,
  n_route_health = excluded.n_route_health,
  n_health_evaluable = excluded.n_health_evaluable;

-- 3. agg_uuc_phc_indicator_dist
with ds as (
  select dataset_id from dim_dataset where slug = 'uuc-phc-2026'
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
where (select dataset_id from ds) is not null
on conflict (dataset_id, geo_code, geo_level, indicator) do update set
  value_max = excluded.value_max,
  n_listed = excluded.n_listed,
  bin_counts = excluded.bin_counts,
  bin_capped = excluded.bin_capped,
  n_missing = excluded.n_missing,
  provincial_ref = excluded.provincial_ref,
  n_comparable = excluded.n_comparable,
  n_worse = excluded.n_worse;

-- 4. agg_bhw_by_uuc_status
with uuc as (select dataset_id from dim_dataset where slug = 'uuc-phc-2026'),
stepzero as (select dataset_id from dim_dataset where slug = 'bhw-stepzero-2026'),
profiled as (select dataset_id from dim_dataset where slug = 'bhw-2025'),

-- One row per barangay: its ancestors, its UUC status, its StepZero figures and its profiled
-- count. `listed` is presence in the fact table — membership is presence, there is no decision
-- column (plan U1).
barangay as (
  select
    g.geo_code,
    g.region_code,
    g.province_code,
    g.citymun_code,
    exists (
      select 1 from fact_uuc_phc_barangay f
      where f.geo_code = g.geo_code and f.dataset_id = (select dataset_id from uuc)
    ) as listed,
    s.n_total_bhw,
    s.households,
    -- Mirrors getStepzeroCounts' registeredUniverse: null only when both parts are null, so a
    -- barangay reporting one and not the other is not silently dropped from the base.
    case
      when s.n_registered is null and s.n_registered_accredited is null then null
      else coalesce(s.n_registered, 0) + coalesce(s.n_registered_accredited, 0)
    end as registered_universe,
    coalesce(c.n_total, 0) as n_profiled
  from dim_geo g
  left join agg_bhw_stepzero_counts s
    on s.geo_code = g.geo_code and s.geo_level = 'barangay'
   and s.dataset_id = (select dataset_id from stepzero)
  left join agg_bhw_counts c
    on c.geo_code = g.geo_code and c.geo_level = 'barangay'
   and c.dataset_id = (select dataset_id from profiled)
  where g.geo_level = 'barangay'
),

-- Fan each barangay out to the four levels it rolls up into, exactly as build_aggregates.sql §1
-- does for the BHW census. One pass, four levels.
fanned as (
  -- Columns listed rather than `b.*`: the barangay's own geo_code would collide with the level's
  -- and make every later reference to it ambiguous.
  select
    lvl.geo_level,
    lvl.geo_code,
    b.listed,
    b.n_total_bhw,
    b.households,
    b.registered_universe,
    b.n_profiled
  from barangay b
  cross join lateral (values
    ('citymun'::geo_level_enum, b.citymun_code),
    ('province'::geo_level_enum, b.province_code),
    ('region'::geo_level_enum, b.region_code),
    ('national'::geo_level_enum, 'PH')
  ) as lvl(geo_level, geo_code)
),

split as (
  select
    f.geo_code,
    f.geo_level,
    count(*) filter (where f.listed) as n_barangays_listed,
    count(*) filter (where not f.listed) as n_barangays_other,
    count(*) filter (where f.listed and f.n_total_bhw is not null) as n_listed_with_data,
    count(*) filter (where not f.listed and f.n_total_bhw is not null) as n_other_with_data,
    count(*) filter (where f.listed and f.n_total_bhw = 0) as n_listed_no_bhw,
    count(*) filter (where not f.listed and f.n_total_bhw = 0) as n_other_no_bhw,
    coalesce(sum(f.n_total_bhw) filter (where f.listed), 0) as listed_n_bhw,
    coalesce(sum(f.n_total_bhw) filter (where not f.listed), 0) as other_n_bhw,
    coalesce(sum(f.households) filter (where f.listed), 0) as listed_households,
    coalesce(sum(f.households) filter (where not f.listed), 0) as other_households,
    coalesce(sum(f.registered_universe) filter (where f.listed), 0) as listed_registered_universe,
    coalesce(sum(f.registered_universe) filter (where not f.listed), 0) as other_registered_universe,
    coalesce(sum(f.n_profiled) filter (where f.listed), 0) as listed_n_profiled,
    coalesce(sum(f.n_profiled) filter (where not f.listed), 0) as other_n_profiled
  from fanned f
  group by f.geo_code, f.geo_level
)
insert into agg_bhw_by_uuc_status (
  dataset_id, geo_code, geo_level,
  n_barangays_listed, n_barangays_other,
  n_listed_with_data, n_other_with_data,
  n_listed_no_bhw, n_other_no_bhw,
  listed_n_bhw, other_n_bhw,
  listed_households, other_households,
  listed_registered_universe, other_registered_universe,
  listed_n_profiled, other_n_profiled,
  unallocated_n_bhw, unallocated_households
)
select
  (select dataset_id from uuc),
  s.geo_code, s.geo_level,
  s.n_barangays_listed, s.n_barangays_other,
  s.n_listed_with_data, s.n_other_with_data,
  s.n_listed_no_bhw, s.n_other_no_bhw,
  s.listed_n_bhw, s.other_n_bhw,
  s.listed_households, s.other_households,
  s.listed_registered_universe, s.other_registered_universe,
  s.listed_n_profiled, s.other_n_profiled,
  -- The area's own StepZero row minus both sides. Zero everywhere except the three regions (and
  -- their ancestors) whose barangay rows do not account for the whole area total.
  coalesce(area.n_total_bhw, s.listed_n_bhw + s.other_n_bhw) - (s.listed_n_bhw + s.other_n_bhw),
  coalesce(area.households, s.listed_households + s.other_households)
    - (s.listed_households + s.other_households)
from split s
left join agg_bhw_stepzero_counts area
  on area.geo_code = s.geo_code and area.geo_level = s.geo_level
 and area.dataset_id = (select dataset_id from stepzero)
where (select dataset_id from uuc) is not null
on conflict (dataset_id, geo_code, geo_level) do update set
  n_barangays_listed = excluded.n_barangays_listed,
  n_barangays_other = excluded.n_barangays_other,
  n_listed_with_data = excluded.n_listed_with_data,
  n_other_with_data = excluded.n_other_with_data,
  n_listed_no_bhw = excluded.n_listed_no_bhw,
  n_other_no_bhw = excluded.n_other_no_bhw,
  listed_n_bhw = excluded.listed_n_bhw,
  other_n_bhw = excluded.other_n_bhw,
  listed_households = excluded.listed_households,
  other_households = excluded.other_households,
  listed_registered_universe = excluded.listed_registered_universe,
  other_registered_universe = excluded.other_registered_universe,
  listed_n_profiled = excluded.listed_n_profiled,
  other_n_profiled = excluded.other_n_profiled,
  unallocated_n_bhw = excluded.unallocated_n_bhw,
  unallocated_households = excluded.unallocated_households,
  -- Re-running must clear a suppression the new data no longer warrants, or a corrected load
  -- would leave a permanently blank cell that reads as a rule rather than as stale state.
  listed_is_suppressed = false,
  other_is_suppressed = false;

-- 5. Assertions, all on the 2026 edition only. A failure aborts the migration.
do $$
declare
  ds bigint := (select dataset_id from dim_dataset where slug = 'uuc-phc-2026');
  n_bad integer;
begin
  if ds is null then
    return;  -- the empty-table build (see the header): nothing was written, nothing to check
  end if;

  -- 5a. The national count is the list's, and every level rolls up to it.
  select count(*) into n_bad from agg_uuc_phc_counts
  where dataset_id = ds and geo_level = 'national'
    and n_listed <> (select count(*) from fact_uuc_phc_barangay where dataset_id = ds);
  if n_bad > 0 then
    raise exception 'agg_uuc_phc_counts national n_listed disagrees with fact_uuc_phc_barangay';
  end if;
  select count(*) into n_bad from (
    select geo_level, sum(n_listed) as l, sum(n_barangays) as b
    from agg_uuc_phc_counts where dataset_id = ds group by geo_level
  ) t
  cross join (select n_listed as l, n_barangays as b from agg_uuc_phc_counts
              where dataset_id = ds and geo_level = 'national') nat
  where t.l <> nat.l or t.b <> nat.b;
  if n_bad > 0 then
    raise exception '% level(s) of agg_uuc_phc_counts do not roll up to the national totals', n_bad;
  end if;

  -- 5b. Criteria: one row per counts row, the same n_listed, every route inside its denominator,
  --     and every level rolling up to the national row.
  select count(*) into n_bad
  from agg_uuc_phc_counts n
  left join agg_uuc_phc_criteria c
    on c.dataset_id = n.dataset_id and c.geo_code = n.geo_code and c.geo_level = n.geo_level
  where n.dataset_id = ds and (c.geo_code is null or c.n_listed <> n.n_listed);
  if n_bad > 0 then
    raise exception '% geo(s) lack a criteria row or disagree with agg_uuc_phc_counts.n_listed', n_bad;
  end if;
  select count(*) into n_bad from agg_uuc_phc_criteria
  where dataset_id = ds
    and (n_route_ip > n_listed or n_route_conflict > n_listed or n_route_four_ps > n_listed
         or n_health_evaluable > n_listed or n_route_health > n_health_evaluable
         or least(n_route_ip, n_route_conflict, n_route_four_ps, n_route_health,
                  n_health_evaluable, n_listed) < 0);
  if n_bad > 0 then
    raise exception '% criteria row(s) have a route count outside its denominator', n_bad;
  end if;
  select count(*) into n_bad from (
    select geo_level, sum(n_route_ip) a, sum(n_route_conflict) b, sum(n_route_four_ps) c,
           sum(n_route_health) d, sum(n_health_evaluable) e
    from agg_uuc_phc_criteria where dataset_id = ds group by geo_level
  ) t
  cross join (select n_route_ip a, n_route_conflict b, n_route_four_ps c, n_route_health d,
                     n_health_evaluable e
              from agg_uuc_phc_criteria where dataset_id = ds and geo_level = 'national') nat
  where t.a <> nat.a or t.b <> nat.b or t.c <> nat.c or t.d <> nat.d or t.e <> nat.e;
  if n_bad > 0 then
    raise exception '% level(s) of agg_uuc_phc_criteria do not roll up to the national totals', n_bad;
  end if;

  -- 5c. Distributions: 12 indicator rows per geo, bins + missing = listed, capped values only in
  --     the top bin, and the national capped count equal to the fact table's flags.
  select count(*) into n_bad
  from agg_uuc_phc_counts c
  left join (
    select geo_code, geo_level, count(*) n_ind, min(n_listed) lo, max(n_listed) hi
    from agg_uuc_phc_indicator_dist where dataset_id = ds group by 1, 2
  ) d on d.geo_code = c.geo_code and d.geo_level = c.geo_level
  where c.dataset_id = ds
    and (d.geo_code is null or d.n_ind <> 12 or d.lo <> c.n_listed or d.hi <> c.n_listed);
  if n_bad > 0 then
    raise exception '% geo(s) lack all 12 indicator rows or disagree with n_listed', n_bad;
  end if;
  select count(*) into n_bad from agg_uuc_phc_indicator_dist d
  where d.dataset_id = ds
    and ((select coalesce(sum(x), 0) from unnest(d.bin_counts) x) + d.n_missing <> d.n_listed
         or (select coalesce(sum(x), 0) from unnest(d.bin_capped[1:9]) x) > 0
         or d.n_worse > d.n_comparable or d.n_comparable > d.n_listed);
  if n_bad > 0 then
    raise exception '% distribution row(s) fail their bin or comparison invariants', n_bad;
  end if;
  select count(*) into n_bad
  from agg_uuc_phc_indicator_dist d
  join (
    select unnest(capped_indicators) as indicator, count(*)::int as n
    from fact_uuc_phc_indicators where dataset_id = ds group by 1
  ) f on f.indicator = d.indicator
  where d.dataset_id = ds and d.geo_level = 'national'
    and (select coalesce(sum(x), 0) from unnest(d.bin_capped) x) <> f.n;
  if n_bad > 0 then
    raise exception '% national indicator(s) disagree with capped_indicators', n_bad;
  end if;

  -- 5d. BHW split: one row per counts row, listed + other = the area's barangays, and the listed
  --     side equal to agg_uuc_phc_counts.n_listed.
  select count(*) into n_bad
  from agg_uuc_phc_counts c
  left join agg_bhw_by_uuc_status a
    on a.dataset_id = c.dataset_id and a.geo_code = c.geo_code and a.geo_level = c.geo_level
  where c.dataset_id = ds
    and (a.geo_code is null or a.n_barangays_listed <> c.n_listed
         or a.n_barangays_listed + a.n_barangays_other <> c.n_barangays);
  if n_bad > 0 then
    raise exception '% geo(s) lack a BHW split row or disagree with agg_uuc_phc_counts', n_bad;
  end if;
end $$;

-- 6. The n < 5 rule on the BHW split, exactly as 2025's: a side measured in fewer than five
--    barangays publishes no figures. Recomputed rows arrive unsuppressed (the upsert resets both
--    flags), so this always runs after them.
update agg_bhw_by_uuc_status set
  listed_is_suppressed = true,
  listed_n_bhw = null,
  listed_households = null,
  listed_registered_universe = null,
  listed_n_profiled = null
where dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2026')
  and n_listed_with_data > 0 and n_listed_with_data < 5;

update agg_bhw_by_uuc_status set
  other_is_suppressed = true,
  other_n_bhw = null,
  other_households = null,
  other_registered_universe = null,
  other_n_profiled = null
where dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2026')
  and n_other_with_data > 0 and n_other_with_data < 5;

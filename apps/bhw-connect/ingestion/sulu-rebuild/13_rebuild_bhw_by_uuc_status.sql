-- Rebuild agg_bhw_by_uuc_status
-- Extracted from supabase/migrations/20260827190000_agg_bhw_by_uuc_status.sql (from its populate step on); creates nothing, recomputes rows.
-- Run once, after db/check-sulu-region.mjs shows the table stale. Safe to re-run.
begin;
-- 2. Populate, unsuppressed. Idempotent: recomputes and upserts every row, so re-running after a
-- fact reload is the refresh procedure — the same discipline as the other three UUC aggregates.
--
-- Suppression is applied as a separate pass at step 4, deliberately: the assertions at step 3 then
-- run against complete values, which is a stronger check than anything they could make of a table
-- whose small cells have already been nulled.
--
-- The two sources this table is built from. Stated outright because the generator reads
-- `create table` and `create view` bodies for provenance, and this table is populated by a
-- separate `insert ... select` — a real derivation no DDL statement expresses.
-- lineage: table:agg_bhw_by_uuc_status derived-from table:agg_bhw_stepzero_counts
-- lineage: table:agg_bhw_by_uuc_status derived-from table:fact_uuc_phc_barangay
with uuc as (select dataset_id from dim_dataset where slug = 'uuc-phc-2025'),
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

-- Drop any geo that has stopped existing between runs, so a shrinking dim_geo empties rows rather
-- than leaving a stale comparison behind (ref_uuc_phc_published_delta's rule).
delete from agg_bhw_by_uuc_status a
where a.dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2025')
  and not exists (
    select 1 from dim_geo g
    where g.geo_code = a.geo_code and g.geo_level = a.geo_level
  );

-- ---------------------------------------------------------------------------------------------
-- 3. Assertions on the complete values, before suppression nulls anything.
--
-- Every one aborts the migration rather than publish a wrong comparison. Numbered as the docs
-- refer to them.
do $$
declare
  n_bad integer;
  n_rows integer;
begin
  -- 1. Row count and coverage: one row per geo that agg_uuc_phc_counts covers, and no others.
  --    The two aggregates are built from different fact tables, so this also checks they see the
  --    same geography. The expected count is read from that aggregate rather than typed — the
  --    section's rule is that a figure is computed or it is not stated, and 1,788 in a `<>` is a
  --    figure.
  select
    (select count(*) from agg_bhw_by_uuc_status),
    (select count(*) from agg_uuc_phc_counts
      where dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2025'))
  into n_rows, n_bad;
  if n_rows <> n_bad then
    raise exception 'agg_bhw_by_uuc_status has % rows, agg_uuc_phc_counts has %', n_rows, n_bad;
  end if;

  select count(*) into n_bad
  from agg_uuc_phc_counts c
  where c.dataset_id = (select dataset_id from dim_dataset where slug = 'uuc-phc-2025')
    and not exists (
      select 1 from agg_bhw_by_uuc_status a
      where a.geo_code = c.geo_code and a.geo_level = c.geo_level
    );
  if n_bad > 0 then
    raise exception '% geo(s) in agg_uuc_phc_counts have no agg_bhw_by_uuc_status row', n_bad;
  end if;

  -- 2. The partition agrees with the section's own aggregate, barangay for barangay. Reached by a
  --    different path (dim_geo fan-out vs agg_uuc_phc_counts' own build), so a disagreement means
  --    one of the two is wrong about which barangays are listed.
  select count(*) into n_bad
  from agg_bhw_by_uuc_status a
  join agg_uuc_phc_counts c
    on c.geo_code = a.geo_code and c.geo_level = a.geo_level
   and c.dataset_id = a.dataset_id
  where a.n_barangays_listed <> c.n_listed;
  if n_bad > 0 then
    raise exception '% row(s) disagree with agg_uuc_phc_counts.n_listed', n_bad;
  end if;

  -- 3. The two sides partition the area: listed + other is every barangay in it. This is the
  --    plan's own Verify line, and the thing that makes "other" mean what the dictionary says.
  select count(*) into n_bad
  from agg_bhw_by_uuc_status a
  join agg_uuc_phc_counts c
    on c.geo_code = a.geo_code and c.geo_level = a.geo_level
   and c.dataset_id = a.dataset_id
  where a.n_barangays_listed + a.n_barangays_other <> c.n_barangays;
  if n_bad > 0 then
    raise exception '% row(s) where listed + other <> the area''s barangay count', n_bad;
  end if;

  -- 4. Recombination is exact, including the residual. The plan asks that the split reproduce the
  --    unsplit figure; StepZero's area rows exceed the sum of its barangay rows in three regions,
  --    so the equality holds only with `unallocated_*` in it — which is precisely why that column
  --    is stored rather than the discrepancy swallowed.
  select count(*) into n_bad
  from agg_bhw_by_uuc_status a
  join agg_bhw_stepzero_counts s
    on s.geo_code = a.geo_code and s.geo_level = a.geo_level
   and s.dataset_id = (select dataset_id from dim_dataset where slug = 'bhw-stepzero-2026')
  where a.listed_n_bhw + a.other_n_bhw + a.unallocated_n_bhw is distinct from s.n_total_bhw
     or a.listed_households + a.other_households + a.unallocated_households
        is distinct from s.households;
  if n_bad > 0 then
    raise exception '% row(s) do not recombine to agg_bhw_stepzero_counts', n_bad;
  end if;

  -- 4b. The residual is a shortfall in the barangay rows, never a surplus. A negative would mean
  --     the barangay rows carry BHWs the area row does not, which is a different defect entirely
  --     and must not be published as "unallocated".
  select count(*) into n_bad
  from agg_bhw_by_uuc_status
  where unallocated_n_bhw < 0 or unallocated_households < 0;
  if n_bad > 0 then
    raise exception '% row(s) carry a negative unallocated residual', n_bad;
  end if;

  -- 5. The profiled counts recombine exactly. agg_bhw_counts is fanned out from each BHW's own
  --    barangay, so unlike StepZero its levels roll up with no residual — and if that ever stops
  --    being true, the profiling-coverage caveat on the page is reading a broken denominator.
  select count(*) into n_bad
  from agg_bhw_by_uuc_status a
  join agg_bhw_counts c
    on c.geo_code = a.geo_code and c.geo_level = a.geo_level
   and c.dataset_id = (select dataset_id from dim_dataset where slug = 'bhw-2025')
  where a.listed_n_profiled + a.other_n_profiled is distinct from c.n_total;
  if n_bad > 0 then
    raise exception '% row(s) do not recombine to agg_bhw_counts.n_total', n_bad;
  end if;

  -- 6. No count exceeds the denominator it belongs to.
  select count(*) into n_bad
  from agg_bhw_by_uuc_status
  where n_listed_with_data > n_barangays_listed
     or n_other_with_data > n_barangays_other
     or n_listed_no_bhw > n_listed_with_data
     or n_other_no_bhw > n_other_with_data
     or listed_registered_universe > listed_n_bhw
     or other_registered_universe > other_n_bhw;
  if n_bad > 0 then
    raise exception '% row(s) carry a count outside its denominator', n_bad;
  end if;

  -- 7. Every level rolls up to the national totals, on all eight measures. Computed once per
  --    level from the same barangay rows, so a level that disagrees means the fan-out is wrong.
  select count(*) into n_bad
  from (
    select geo_level,
      sum(n_barangays_listed) l, sum(n_barangays_other) o,
      sum(listed_n_bhw) lb, sum(other_n_bhw) ob,
      sum(listed_households) lh, sum(other_households) oh,
      sum(listed_n_profiled) lp, sum(other_n_profiled) op
    from agg_bhw_by_uuc_status
    where geo_level <> 'national'
    group by geo_level
  ) lvl
  cross join (
    select n_barangays_listed l, n_barangays_other o,
      listed_n_bhw lb, other_n_bhw ob,
      listed_households lh, other_households oh,
      listed_n_profiled lp, other_n_profiled op
    from agg_bhw_by_uuc_status where geo_level = 'national'
  ) nat
  where lvl.l <> nat.l or lvl.o <> nat.o
     or lvl.lb <> nat.lb or lvl.ob <> nat.ob
     or lvl.lh <> nat.lh or lvl.oh <> nat.oh
     or lvl.lp <> nat.lp or lvl.op <> nat.op;
  if n_bad > 0 then
    raise exception '% level(s) do not roll up to the national totals', n_bad;
  end if;
end $$;

-- ---------------------------------------------------------------------------------------------
-- 4. Suppression.
--
-- Plan §9 U12b: "suppress any cell whose contributing barangay count is below the §4.1 threshold"
-- — n < 5, and n = 0 stays visible because a true zero reveals nothing about anybody.
--
-- **This is a presentation rule, not a disclosure control, and saying so matters.** BUILD_PLAN.md
-- §4.1's suppression exists because a small demographic cell can identify a person; but it also
-- says in as many words that "counts of totals — e.g. 'this barangay has 3 BHWs' — are not
-- suppressed", and `agg_bhw_stepzero_counts` is public at barangay grain for all 41,958. Anyone
-- can compute this split themselves. What the rule prevents is *this page* rendering one, two or
-- three barangays as a group statistic and setting it beside a group of hundreds — which is a
-- claim about a group, made from something that is not one.
--
-- Only the small side is nulled. The other side of such an area is, to within a few barangays, the
-- area's own published total, so suppressing it too would destroy a real 198-barangay figure to
-- protect a number already on the page above it. What the page must not do — and does not — is
-- draw the comparison when either side is suppressed.
update agg_bhw_by_uuc_status set
  listed_is_suppressed = true,
  listed_n_bhw = null,
  listed_households = null,
  listed_registered_universe = null,
  listed_n_profiled = null
where n_listed_with_data > 0 and n_listed_with_data < 5;

update agg_bhw_by_uuc_status set
  other_is_suppressed = true,
  other_n_bhw = null,
  other_households = null,
  other_registered_universe = null,
  other_n_profiled = null
where n_other_with_data > 0 and n_other_with_data < 5;

-- ---------------------------------------------------------------------------------------------
-- 5. Assertions on the suppression itself.
do $$
declare
  n_bad integer;
begin
  -- 8. Suppression fired exactly where the rule says and nowhere else, in both directions. The
  --    check constraints already guarantee a suppressed side is wholly null; this is the other
  --    half — that a side is suppressed if and only if 0 < contributing barangays < 5.
  select count(*) into n_bad
  from agg_bhw_by_uuc_status
  where listed_is_suppressed <> (n_listed_with_data > 0 and n_listed_with_data < 5)
     or other_is_suppressed <> (n_other_with_data > 0 and n_other_with_data < 5);
  if n_bad > 0 then
    raise exception '% row(s) where suppression does not match the 0 < n < 5 rule', n_bad;
  end if;

  -- 9. An area with nothing listed is not a suppressed area. NCR reads 0 of 1,675, which is data;
  --    if that ever started rendering as suppressed the page would say "withheld" where the
  --    correct answer is "none".
  select count(*) into n_bad
  from agg_bhw_by_uuc_status
  where n_barangays_listed = 0 and listed_is_suppressed;
  if n_bad > 0 then
    raise exception '% area(s) with nothing listed are marked suppressed', n_bad;
  end if;
end $$;

commit;

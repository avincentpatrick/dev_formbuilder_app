-- Register the UUC for PHC 2026 list as its own dataset, beside the 2025 one.
--
-- Each year's list is a separate edition (docs/UUC_PHC_2026_PLAN.md): the slug carries the year
-- because the year names an annual publication, as `uuc-phc-2025` set the precedent. The 2025 row,
-- its facts and its aggregates are not touched — the /uuc-phc section shows either year behind an
-- edition switch, newest first.
--
-- ⚠ PLACEHOLDER — fill in before merging: the 2026 list's issuance (circular number and date).
-- `source_name` below and ISSUANCE_PLACEHOLDER in lib/uuc-phc/editions.ts carry the same marker,
-- "DC No. to be confirmed"; `grep -rn "to be confirmed" apps/bhw-connect` finds both.
--
-- status 'published', never 'active' — seeding a second row 'active' blanked the site once
-- (docs/DECISIONS.md, E4.3). as_of_date '2026-01-01' follows the year-only convention of 2025's row.
--
-- Written only where dim_geo holds barangays. CI and the local copy run migrations newer than the
-- committed schema against EMPTY tables, then load their made-up seed with explicit dataset_ids
-- (db/load-dev.mjs); a row inserted here would take an id that seed then claims. On the testing
-- server the geography is loaded and the row is written.
insert into dim_dataset (
  slug, name, source_name, source_url, license, geo_join_level, as_of_date, version, status
)
select
  'uuc-phc-2026',
  'UUC for PHC 2026',
  'Department of Health (DOH) Bureau of Local Health Systems Development — 2026 list of Unserved and Underserved Communities for Primary Health Care (DC No. to be confirmed), criteria per DOH AO No. 2020-0023',
  null,
  null,
  'barangay',
  '2026-01-01',
  '1.0',
  'published'
where exists (select 1 from dim_geo where geo_level = 'barangay')
on conflict (slug) do nothing;

-- Expire the assistant's cached UUC answers. Its cache keys on uuc-phc-2025's last_updated_at
-- (lib/ai/dataset-scope.ts), and every answer cached so far called the 2025 list the current one;
-- from this migration on the assistant answers about 2026 unless asked otherwise. The same bump
-- 20261111090000_bhw_dataset_display_names.sql made, for the same reason. Side effect, accepted:
-- "Last updated" on the 2025 edition reads this migration's date.
update dim_dataset
set last_updated_at = now()
where slug = 'uuc-phc-2025'
  and exists (select 1 from dim_dataset where slug = 'uuc-phc-2026');

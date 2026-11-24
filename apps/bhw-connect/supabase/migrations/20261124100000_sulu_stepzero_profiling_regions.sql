-- Sulu in Region IX: the two BHW tables whose region rows come from the source sheet.
--
-- 20261123100000 moved Sulu's geography to Region IX and the first rebuild on the testing box fixed every
-- aggregate that is computed from dim_geo. Two tables are not: agg_bhw_stepzero_counts and
-- agg_bhw_profiling_status build their region rows from the source sheet's own region-code columns, so
-- re-running their ingestion would put Sulu back under BARMM, and their stored region rows still count Sulu
-- there (box check, 2026-11-24: region 09 stored 9,566 where its barangays add to 10,689; region 19 stored
-- 8,772 against 7,645).
--
-- THE FIX IS A MOVE, NOT A RECOMPUTE. Sulu's own province row is already correct. For each count column,
-- Region IX gains Sulu's value and BARMM loses it. Recomputing the region from barangay rows instead would
-- also drop the few barangays whose rows failed the code check at load but are deliberately inside the
-- region totals (ingest_stepzero.py says so), changing figures this change has no business touching.
--
-- GUARDED SO IT CANNOT DOUBLE-APPLY OR GUESS. A region row moves only where every count column's stored value
-- differs from the sum of its provinces (under the current geography) by exactly Sulu's own value: Region IX
-- short by it, BARMM over by it. The count columns move together or not at all. Anything else is left alone and
-- reported as a notice: a region already correct, or one that is off for another reason. population and
-- households, which may be missing, are held to the same test one at a time. Run
-- `node db/check-sulu-region.mjs` afterwards; a table it still reports needs looking at by hand.
--
-- UPDATE only; nothing is deleted. A second run finds no region in the stale state and changes nothing.
do $$
declare
  spec record;
  ds record;
  col text;
  reg text;
  v_sulu numeric;
  v_region numeric;
  v_sum numeric;
  v_ok boolean;
  v_dir integer;
begin
  for spec in
    select * from (values
      ('agg_bhw_stepzero_counts',
       array['n_registered', 'n_registered_accredited', 'n_non_registered', 'n_total_bhw'],
       array['population', 'households']),
      ('agg_bhw_profiling_status',
       array['n_registered', 'n_accredited', 'n_unregistered', 'n_total_bhw', 'n_drafted', 'n_for_validation',
             'n_back_to_encoder', 'n_validated', 'n_approved'],
       array[]::text[])
    ) as t(tbl, core, extra)
  loop
    for ds in
      execute format('select dataset_id from %I where geo_code = %L and geo_level = %L',
                     spec.tbl, '19066', 'province')
    loop
      foreach reg in array array['09', '19'] loop
        v_dir := case when reg = '09' then 1 else -1 end;

        -- The count columns move together or not at all: a row whose parts move but whose total does not
        -- (or the reverse) would contradict itself. Every one with a Sulu value must show the stale
        -- signature: Region IX short by it, BARMM over by it.
        v_ok := true;
        foreach col in array spec.core loop
          execute format('select %I from %I where dataset_id = $1 and geo_code = %L and geo_level = %L',
                         col, spec.tbl, '19066', 'province') into v_sulu using ds.dataset_id;
          continue when v_sulu is null or v_sulu = 0;
          execute format(
            'select r.%1$I,
                    (select sum(p.%1$I) from %2$I p
                       join dim_geo g on g.geo_code = p.geo_code and g.geo_level = %3$L
                      where p.dataset_id = $1 and p.geo_level = %3$L and g.region_code = $2)
               from %2$I r
              where r.dataset_id = $1 and r.geo_code = $2 and r.geo_level = %4$L',
            col, spec.tbl, 'province', 'region') into v_region, v_sum using ds.dataset_id, reg;
          if v_region is null or v_sum is null or v_region - v_sum <> -v_dir * v_sulu then
            v_ok := false;
            exit;
          end if;
        end loop;

        if not v_ok then
          raise notice 'sulu region move: % region % dataset % left alone (not in the stale state)',
            spec.tbl, reg, ds.dataset_id;
          continue;
        end if;

        foreach col in array spec.core || spec.extra loop
          execute format('select %I from %I where dataset_id = $1 and geo_code = %L and geo_level = %L',
                         col, spec.tbl, '19066', 'province') into v_sulu using ds.dataset_id;
          continue when v_sulu is null or v_sulu = 0;
          if col = any (spec.extra) then
            -- Optional columns (may be null or not carried at region level): same signature, independently.
            execute format(
              'select r.%1$I,
                      (select sum(p.%1$I) from %2$I p
                         join dim_geo g on g.geo_code = p.geo_code and g.geo_level = %3$L
                        where p.dataset_id = $1 and p.geo_level = %3$L and g.region_code = $2)
                 from %2$I r
                where r.dataset_id = $1 and r.geo_code = $2 and r.geo_level = %4$L',
              col, spec.tbl, 'province', 'region') into v_region, v_sum using ds.dataset_id, reg;
            continue when v_region is null or v_sum is null or v_region - v_sum <> -v_dir * v_sulu;
          end if;
          execute format(
            'update %1$I set %2$I = %2$I + $1 where dataset_id = $2 and geo_code = %3$L and geo_level = %4$L',
            spec.tbl, col, reg, 'region') using (v_dir * v_sulu)::integer, ds.dataset_id;
        end loop;
      end loop;
    end loop;
  end loop;
end $$;

-- The Step Zero percentage is derived from two of the columns above, so bring the two region rows' value in
-- line with them the same way the loader computes it (accredited / total, two decimals).
update agg_bhw_stepzero_counts
   set pct_registered_accredited = round(100.0 * n_registered_accredited / nullif(n_total_bhw, 0), 2)
 where geo_level = 'region' and geo_code in ('09', '19')
   and pct_registered_accredited is distinct from round(100.0 * n_registered_accredited / nullif(n_total_bhw, 0), 2);

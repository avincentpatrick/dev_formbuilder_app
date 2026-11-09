--
-- PostgreSQL database dump
--


-- Dumped from database version 15.19
-- Dumped by pg_dump version 15.19

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--



--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--



--
-- Name: admin_role_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.admin_role_enum AS ENUM (
    'admin',
    'editor'
);


--
-- Name: demographic_dimension_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.demographic_dimension_enum AS ENUM (
    'sex',
    'age_band',
    'civil_status',
    'bloodtype',
    'education',
    'ip_status'
);


--
-- Name: feedback_category_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.feedback_category_enum AS ENUM (
    'bug',
    'data_question',
    'suggestion',
    'other'
);


--
-- Name: feedback_status_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.feedback_status_enum AS ENUM (
    'open',
    'resolved',
    'dismissed'
);


--
-- Name: geo_level_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.geo_level_enum AS ENUM (
    'national',
    'region',
    'province',
    'citymun',
    'barangay'
);


--
-- Name: honorarium_frequency_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.honorarium_frequency_enum AS ENUM (
    'monthly',
    'quarterly',
    'semi_annual',
    'annual',
    'other'
);


--
-- Name: payer_level_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.payer_level_enum AS ENUM (
    'region',
    'province',
    'citymun',
    'barangay'
);


--
-- Name: quota_window_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.quota_window_enum AS ENUM (
    'minute',
    'day',
    'month'
);


--
-- Name: ai_regression_expectation_well_formed(jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ai_regression_expectation_well_formed(p jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    AS $$
  select p is not null
     and jsonb_typeof(p) = 'array'
     and not exists (
       select 1
       from jsonb_array_elements(p) as e
       where jsonb_typeof(e) <> 'object'
          -- Which recorded call this reads, and what that call must be. `call` alone would let an
          -- edited `tool_calls` shift the index and score a figure against the wrong call without
          -- saying so; `tool` is the cross-check that makes that impossible to miss.
          -- `is distinct from`, not `<>`: a missing key makes `jsonb_typeof` NULL, and `NULL <> x`
          -- is NULL, which `where` reads as "no match" — so `<>` would accept an element with no
          -- `call` at all. That is the exact defect this constraint exists to prevent.
          or jsonb_typeof(e -> 'call') is distinct from 'number'
          or jsonb_typeof(e -> 'tool') is distinct from 'string'
          -- Absent `where` means the payload root, which is how a `mode: "count"` case reaches
          -- `matchingRows`. Present, it selects exactly one row of a list.
          or (e ? 'where' and jsonb_typeof(e -> 'where') <> 'object')
          -- `from` names the list `where` selects from; absent it is `rows`, which is what every
          -- `queryDataset` case reads. Naming a list and then selecting nothing from it is not a
          -- root read — the root read is `where` absent — so it is a half-written assertion.
          or (e ? 'from' and (jsonb_typeof(e -> 'from') <> 'string' or e ->> 'from' = ''))
          or (e ? 'from' and not (e ? 'where'))
          or jsonb_typeof(e -> 'field') is distinct from 'string'
          or coalesce(jsonb_typeof(e -> 'value'), 'null') not in ('number', 'string', 'boolean')
     );
$$;


--
-- Name: FUNCTION ai_regression_expectation_well_formed(p jsonb); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.ai_regression_expectation_well_formed(p jsonb) IS 'Shape guard for ai_regression_case.expectations: an array of {call:number, tool:string, from?:string, where?:object, field:string, value:scalar}. See the migration headers for why a malformed element must be refused rather than skipped, and why `from` cannot appear without `where`.';


--
-- Name: district_dataset_gaps(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.district_dataset_gaps() RETURNS TABLE(uncovered_citymun_count bigint, unplaced_barangay_count bigint)
    LANGUAGE sql STABLE
    SET search_path TO 'public'
    AS $$
  with live as (
    select geo_code, geo_level
    from geo_district_map
    where superseded_by is null and status <> 'rejected'
  ),
  uncovered_citymun as (
    select c.geo_code
    from dim_geo c
    where c.geo_level = 'citymun'
      and not exists (select 1 from live lm where lm.geo_code = c.geo_code)
      and not exists (
        select 1
        from live lb
        join dim_geo b on b.geo_code = lb.geo_code
        where lb.geo_level = 'barangay' and b.citymun_code = c.geo_code
      )
  ),
  split_cities as (
    select distinct b.citymun_code
    from live lb
    join dim_geo b on b.geo_code = lb.geo_code
    where lb.geo_level = 'barangay'
  ),
  unplaced_barangay as (
    select b.geo_code
    from dim_geo b
    join split_cities sc on sc.citymun_code = b.citymun_code
    where b.geo_level = 'barangay'
      and not exists (select 1 from live lm where lm.geo_code = b.geo_code)
  )
  select (select count(*) from uncovered_citymun), (select count(*) from unplaced_barangay);
$$;


--
-- Name: district_gap_members(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.district_gap_members(p_district_code text) RETURNS TABLE(geo_code text, geo_name text, geo_level public.geo_level_enum)
    LANGUAGE sql STABLE
    SET search_path TO 'public'
    AS $$
  with d as (
    select district_code, parent_geo_code
    from dim_legislative_district
    where district_code = p_district_code and is_lone and parent_geo_code is not null
  ),
  live as (
    select geo_code
    from geo_district_map
    where district_code = p_district_code and superseded_by is null and status <> 'rejected'
  ),
  member_level as (
    select min(m.geo_level) as lvl
    from geo_district_map m
    join d on d.district_code = m.district_code
    where m.superseded_by is null and m.status <> 'rejected'
  ),
  expected_citymun as (
    select c.geo_code, c.geo_name, c.geo_level
    from d
    join dim_geo c on c.geo_level = 'citymun' and c.province_code = d.parent_geo_code
    join member_level ml on ml.lvl = 'citymun'
  ),
  -- Same HUC case district_index's own comment explains: a lone district over an HUC resolves at
  -- barangay grain, so its "whole scope" is that HUC's own barangay children.
  expected_barangay as (
    select c.geo_code, c.geo_name, c.geo_level
    from d
    join dim_geo hcity on hcity.geo_level = 'citymun' and hcity.province_code = d.parent_geo_code
    join dim_geo c on c.geo_level = 'barangay' and c.citymun_code = hcity.geo_code
    join member_level ml on ml.lvl = 'barangay'
  ),
  expected as (
    select * from expected_citymun
    union all
    select * from expected_barangay
  )
  select e.geo_code, e.geo_name, e.geo_level
  from expected e
  left join live lm on lm.geo_code = e.geo_code
  where lm.geo_code is null
  order by e.geo_name;
$$;


--
-- Name: district_index(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.district_index(p_dataset_id bigint) RETURNS TABLE(district_code text, district_name text, ordinal smallint, is_lone boolean, region_code text, member_count bigint, bhw_total bigint, population integer, match_quality text)
    LANGUAGE sql STABLE
    SET search_path TO 'public'
    AS $$
  with live as (
    select district_code, geo_code, geo_level, match_method
    from geo_district_map
    where superseded_by is null and status <> 'rejected'
  ),
  member_stats as (
    select
      district_code,
      count(*) as member_count,
      bool_or(match_method in ('manual_override', 'public_correction')) as has_override,
      bool_and(match_method = 'exact') as all_exact,
      -- Grain is homogeneous per district by construction (§1 of the plan: a district is either
      -- entirely citymun-grain or entirely barangay-grain), so min() just reads the one value.
      min(geo_level) as member_level
    from live
    group by district_code
  ),
  -- Only a lone district's own full scope, never a province/city shared with siblings (see above).
  lone_districts as (
    select d.district_code, d.parent_geo_code, ms.member_level
    from dim_legislative_district d
    join member_stats ms on ms.district_code = d.district_code
    where d.is_lone and d.parent_geo_code is not null
      and ms.member_level in ('citymun', 'barangay')
  ),
  lone_citymun_children as (
    select ld.district_code, c.geo_code
    from lone_districts ld
    join dim_geo c on c.geo_level = 'citymun' and c.province_code = ld.parent_geo_code
    where ld.member_level = 'citymun'
  ),
  -- A lone district over an HUC (dim_geo files the city as a province-level row -- see
  -- 20260902070000_district_independent_city.sql) resolves at barangay grain, so its "whole scope"
  -- is the barangay children of the one citymun the HUC container holds.
  lone_barangay_children as (
    select ld.district_code, c.geo_code
    from lone_districts ld
    join dim_geo hcity on hcity.geo_level = 'citymun' and hcity.province_code = ld.parent_geo_code
    join dim_geo c on c.geo_level = 'barangay' and c.citymun_code = hcity.geo_code
    where ld.member_level = 'barangay'
  ),
  expected as (
    select * from lone_citymun_children
    union all
    select * from lone_barangay_children
  ),
  gap as (
    select distinct e.district_code
    from expected e
    left join live lm on lm.district_code = e.district_code and lm.geo_code = e.geo_code
    where lm.geo_code is null
  ),
  bhw_agg as (
    select lm.district_code, sum(coalesce(a.n_total, 0)) as bhw_total
    from live lm
    join agg_bhw_counts a
      on a.geo_code = lm.geo_code and a.geo_level = lm.geo_level and a.dataset_id = p_dataset_id
    group by lm.district_code
  )
  select
    d.district_code,
    d.district_name,
    d.ordinal,
    d.is_lone,
    d.region_code,
    coalesce(ms.member_count, 0) as member_count,
    coalesce(ba.bhw_total, 0) as bhw_total,
    d.psa_population as population,
    case
      when coalesce(ms.member_count, 0) = 0 then 'no_members'
      when g.district_code is not null then 'has_unresolved'
      when ms.has_override then 'has_overrides'
      when ms.all_exact then 'all_exact'
      else 'resolved'
    end as match_quality
  from dim_legislative_district d
  left join member_stats ms on ms.district_code = d.district_code
  left join gap g on g.district_code = d.district_code
  left join bhw_agg ba on ba.district_code = d.district_code
  where d.status <> 'rejected'
  order by d.region_code nulls last, d.district_name;
$$;


--
-- Name: harvest_ask_cache_cases(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.harvest_ask_cache_cases(p_statement_timeout text DEFAULT '30s'::text) RETURNS TABLE(action text, cache_key text, question text, detail text)
    LANGUAGE plpgsql
    SET search_path TO 'public', 'extensions'
    AS $$
declare
  -- One timestamp for the whole run, so "not reproduced by the latest run" is an exact comparison
  -- rather than a window. Taken before any work, for the same reason 4.2 takes `v_swept_at` first.
  v_seen_at timestamptz := now();
  v_row record;
  v_case record;
  v_fingerprint text;
  v_conversation jsonb;
  v_note text;
begin
  perform set_config('statement_timeout', p_statement_timeout, true);

  for v_row in
    select c.cache_key,
           c.question_display,
           c.geo_code,
           c.dataset_slug,
           c.answer_md,
           c.provider,
           c.generated_at,
           coalesce(t.n_traces, 0) as n_traces,
           t.trace_text
    from ai_ask_cache c
    left join lateral (
      -- Compared as text: the count has to be over *distinct traces*, and two log rows recording
      -- the same calls in the same order must not read as a disagreement.
      select count(distinct l.tool_trace::text) as n_traces,
             min(l.tool_trace::text) as trace_text
      from ai_ask_log l
      where l.question_norm = c.question_norm
        and l.data_version = c.data_version
        and l.dataset_slug = c.dataset_slug
        and l.geo_code is not distinct from c.geo_code
        -- The condition that makes this a derivation rather than a guess: the log row matched is
        -- the one whose tool calls produced this exact text.
        and l.answer_md = c.answer_md
        -- A cache hit records no trace, because no tools ran on that turn.
        and l.served_from = 'live'
        and jsonb_typeof(l.tool_trace) = 'array'
        and jsonb_array_length(l.tool_trace) > 0
    ) t on true
    where c.status = 'approved'
    order by c.generated_at
  loop
    if v_row.n_traces = 0 then
      action := 'skipped';
      cache_key := v_row.cache_key;
      question := v_row.question_display;
      detail := 'no live ai_ask_log row records the tool calls that produced this exact answer, so the case could not be replayed';
      return next;
      continue;
    end if;

    if v_row.n_traces > 1 then
      -- Never the first, never the newest. See the header: a case built from one of several
      -- candidate traces has stopped being evidence about anything in particular.
      action := 'skipped';
      cache_key := v_row.cache_key;
      question := v_row.question_display;
      detail := format(
        '%s live log rows record different tool calls for this answer — a harvested case must name one',
        v_row.n_traces);
      return next;
      continue;
    end if;

    v_fingerprint := md5(v_row.answer_md || e'\n' || v_row.trace_text);

    -- Single-turn by construction: the answer bank keys on one question and the route only writes
    -- it for a single-turn ask, so the conversation is the pair, derived rather than invented.
    v_conversation := jsonb_build_array(
      jsonb_build_object('role', 'user', 'content', v_row.question_display),
      jsonb_build_object('role', 'assistant', 'content', v_row.answer_md));

    v_note := format(
      'Harvested from the answer bank (§10.1 route 3): ai_ask_cache row at status = approved, asked at geo %s on %s, answered %s by %s. The approval is what makes answer_given the expected answer — nothing here is authored.',
      coalesce(v_row.geo_code, 'national'),
      v_row.dataset_slug,
      to_char(v_row.generated_at, 'YYYY-MM-DD'),
      coalesce(v_row.provider, 'an unrecorded provider'));

    select case_id, harvest_fingerprint into v_case
    from ai_regression_case
    where harvest_key = v_row.cache_key;

    if not found then
      insert into ai_regression_case (
        question, conversation, answer_given, tool_calls, provider, note, source,
        harvest_key, harvest_fingerprint, harvest_last_seen_at
      ) values (
        v_row.question_display, v_conversation, v_row.answer_md, v_row.trace_text::jsonb,
        v_row.provider, v_note, 'harvested',
        v_row.cache_key, v_fingerprint, v_seen_at
      );
      action := 'harvested';
      detail := format('%s tool %s recorded',
        jsonb_array_length(v_row.trace_text::jsonb),
        case when jsonb_array_length(v_row.trace_text::jsonb) = 1 then 'call' else 'calls' end);

    elsif v_case.harvest_fingerprint is distinct from v_fingerprint then
      update ai_regression_case set
        question = v_row.question_display,
        conversation = v_conversation,
        answer_given = v_row.answer_md,
        tool_calls = v_row.trace_text::jsonb,
        provider = v_row.provider,
        note = v_note,
        -- The pins were derived from the previous answer's payloads. They are not evidence about
        -- this one, and a case that stayed green against figures nobody verified is exactly the
        -- "passes for the wrong reason" failure the expectations column is arranged against.
        expectations = '[]'::jsonb,
        harvest_fingerprint = v_fingerprint,
        harvest_last_seen_at = v_seen_at,
        updated_at = now()
      where case_id = v_case.case_id;
      action := 'refreshed';
      detail := 'the source answer or its tool calls changed; the case was rebuilt and its pinned figures cleared';

    else
      -- Nothing about the case changed, so `updated_at` must not move. Only the stamp that says
      -- the source still reproduces it.
      update ai_regression_case set harvest_last_seen_at = v_seen_at
      where case_id = v_case.case_id;
      action := 'unchanged';
      detail := null;
    end if;

    cache_key := v_row.cache_key;
    question := v_row.question_display;
    return next;
  end loop;

  -- Anything harvested before that this run did not reproduce. 4.2's rule, applied here: shown as
  -- stale rather than deleted.
  for v_case in
    select rc.harvest_key, rc.question, rc.harvest_last_seen_at
    from ai_regression_case rc
    where rc.source = 'harvested'
      and rc.harvest_last_seen_at < v_seen_at
    order by rc.harvest_last_seen_at
  loop
    action := 'stale';
    cache_key := v_case.harvest_key;
    question := v_case.question;
    detail := format(
      'the ai_ask_cache row this was harvested from no longer reproduces it (last confirmed %s) — it may have been edited, blocked or unapproved. Kept, not deleted.',
      to_char(v_case.harvest_last_seen_at, 'YYYY-MM-DD HH24:MI'));
    return next;
  end loop;
end;
$$;


--
-- Name: FUNCTION harvest_ask_cache_cases(p_statement_timeout text); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.harvest_ask_cache_cases(p_statement_timeout text) IS 'Files every ai_ask_cache row at status = approved as a replayable ai_regression_case, recovering its tool calls from the ai_ask_log row whose answer_md matches byte for byte. Idempotent through the unique harvest_key; a source row that changed rebuilds its case and clears its pins; a case the run did not reproduce is reported stale, never deleted. Pins are derived separately — see lib/ai/harvest-pins.ts and the migration header.';


--
-- Name: kb_edge_endpoints_are_approved(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.kb_edge_endpoints_are_approved() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
declare
  unapproved text;
begin
  if new.status is distinct from 'approved' then
    return new;
  end if;
  select string_agg(n.key || ' (' || n.status || ')', ', ')
    into unapproved
    from kb_node n
   where n.node_id in (new.src_node_id, new.dst_node_id)
     and n.status <> 'approved';
  if unapproved is not null then
    raise exception 'kb_edge: cannot approve an edge whose endpoints are not approved: %', unapproved
      using errcode = '23514';
  end if;
  return new;
end;
$$;


--
-- Name: kb_evidence_is_grounded(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.kb_evidence_is_grounded() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
declare
  chunk_text text;
begin
  if new.source_kind is distinct from 'chunk' then
    if new.evidence_quote is not null then
      raise exception '%: evidence_quote is for chunk-sourced rows; this row is sourced from %',
        tg_table_name, new.source_kind using errcode = '23514';
    end if;
    return new;
  end if;

  if new.evidence_quote is null or btrim(new.evidence_quote) = '' then
    raise exception '%: a chunk-sourced row must quote the text that supports it', tg_table_name
      using errcode = '23514';
  end if;

  select content into chunk_text from doc_chunk where chunk_id = new.source_chunk_id;
  if chunk_text is null then
    raise exception '%: source chunk % has no content to be supported by', tg_table_name,
      new.source_chunk_id using errcode = '23503';
  end if;

  if position(new.evidence_quote in chunk_text) = 0 then
    raise exception '%: evidence quote is not present verbatim in chunk % (%)',
      tg_table_name, new.source_chunk_id, left(new.evidence_quote, 80) using errcode = '23514';
  end if;

  return new;
end;
$$;


--
-- Name: kb_generic_terms(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.kb_generic_terms() RETURNS text[]
    LANGUAGE sql STABLE
    AS $$
  with entries as (
    select r.registry_id,
           kb_terms(r.title || ' ' || r.summary || ' ' || r.grain) as terms
    from dataset_registry r
    where r.status = 'approved'
  ),
  n as (select greatest(count(*), 1) as total from entries)
  select coalesce(array_agg(t), '{}'::text[])
  from (
    select unnest(terms) as t, count(*) as df from entries group by 1
  ) f, n
  where f.df * 2 > n.total;
$$;


--
-- Name: FUNCTION kb_generic_terms(); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.kb_generic_terms() IS 'Increment 4.2: registry terms with a document frequency above half, which therefore identify nothing. Counted rather than authored so the list stays right as the registry grows.';


--
-- Name: kb_node_keeps_its_approved_edges(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.kb_node_keeps_its_approved_edges() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
declare
  dependent int;
begin
  if new.status = 'approved' or old.status <> 'approved' then
    return new;
  end if;
  select count(*) into dependent
    from kb_edge e
   where e.status = 'approved'
     and (e.src_node_id = new.node_id or e.dst_node_id = new.node_id);
  if dependent > 0 then
    raise exception
      'kb_node: % still has % approved edge(s); reject or unapprove those first', new.key, dependent
      using errcode = '23514';
  end if;
  return new;
end;
$$;


--
-- Name: kb_terms(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.kb_terms(p_text text) RETURNS text[]
    LANGUAGE sql IMMUTABLE
    AS $_$
  select coalesce(array_agg(distinct t), '{}'::text[])
  from (
    select case when length(w) > 3 and right(w, 1) = 's' then left(w, length(w) - 1) else w end as t
    from regexp_split_to_table(lower(coalesce(p_text, '')), '[^a-z0-9]+') as g(w)
    where length(w) >= 3 and w !~ '^[0-9]+$'
  ) s;
$_$;


--
-- Name: FUNCTION kb_terms(p_text text); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.kb_terms(p_text text) IS 'Increment 4.2: the token set used to test whether a document figure and a registry entry are talking about the same subject. Same transformation on both sides, reproducible by eye.';


--
-- Name: map_psgc_to_dim_geo(text, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.map_psgc_to_dim_geo(p_code text, p_old_vintage text DEFAULT NULL::text) RETURNS text
    LANGUAGE sql STABLE
    SET search_path TO 'public'
    AS $$
  select coalesce(
    (select g.geo_code from dim_geo g where g.geo_code = p_code),
    (select x.new_code
       from dim_psgc_crosswalk x
      where x.old_code = p_code
        and (p_old_vintage is null or x.old_vintage = p_old_vintage)
      order by x.new_vintage desc
      limit 1)
  );
$$;


--
-- Name: match_ask_answer(text, text, text, text, real); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.match_ask_answer(q text, scope text, version text, dataset text, min_sim real DEFAULT 0.85) RETURNS TABLE(cache_key text, question_norm text, answer_md text, provider text, score real)
    LANGUAGE sql STABLE
    SET search_path TO 'public', 'extensions'
    AS $$
  select
    c.cache_key,
    c.question_norm,
    c.answer_md,
    c.provider,
    extensions.similarity(c.question_norm, q) as score
  from ai_ask_cache c
  where c.status = 'approved'
    and c.dataset_slug = dataset
    and c.data_version = version
    and coalesce(c.geo_code, 'national') = scope
    and extensions.similarity(c.question_norm, q) >= min_sim
  order by score desc
  limit 1;
$$;


--
-- Name: profile_dataset(text, text, text, text, text, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.profile_dataset(p_table text, p_title text DEFAULT NULL::text, p_summary text DEFAULT NULL::text, p_grain text DEFAULT NULL::text, p_dataset_slug text DEFAULT NULL::text, p_force boolean DEFAULT false) RETURNS TABLE(column_name text, data_type text, role text, is_join_key boolean, joins_to text, meaning_source text, distinct_count bigint, null_rate numeric, overlap_rate numeric)
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
declare
  v_refusal text;
  v_registry_id bigint;
  v_row_estimate bigint;
  v_col record;
  v_candidate record;
  v_is_join_key boolean;
  v_joins_to text;
  v_overlap numeric;
  v_best_overlap numeric;
  v_best_target text;
  v_meaning text;
  v_meaning_source text;
  v_unit text;
  v_role text;
  v_distinct bigint;
  v_null_rate numeric;
  v_samples text[];
  v_min text;
  v_max text;
  v_matched bigint;
  v_sampled bigint;
  v_prior jsonb;
begin
  v_refusal := profile_dataset_refusal(p_table);
  if v_refusal is not null then
    raise exception 'profile_dataset refused %: %', coalesce(p_table, '(null)'), v_refusal;
  end if;

  if not p_force and exists (
    select 1 from dataset_registry r where r.table_name = p_table and r.status = 'approved'
  ) then
    raise exception
      'profile_dataset refused %: it already has an approved registry row. Pass p_force => true to re-profile it, which returns the dictionary to the review queue.', p_table;
  end if;

  execute format('analyze %I', p_table);

  select coalesce(c.reltuples, 0)::bigint into v_row_estimate
  from pg_class c
  join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public' and c.relname = p_table;

  insert into dataset_registry as r (
    table_name, title, summary, grain, dataset_slug, exposure, row_estimate,
    source_kind, status, notes_md, created_at, updated_at
  ) values (
    p_table,
    coalesce(p_title, p_table),
    coalesce(p_summary, format('(needs review) Profiled from %I on %s. No summary has been written yet.', p_table, now()::date)),
    coalesce(p_grain, '(needs review) one row per ?'),
    p_dataset_slug,
    'internal',
    v_row_estimate,
    'profiled',
    'auto',
    format('Profiled by profile_dataset() at %s. Statistics are ANALYZE estimates.', now()),
    now(), now()
  )
  on conflict (table_name) do update set
    row_estimate = excluded.row_estimate,
    status = 'auto',
    exposure = case when r.exposure = 'public' then 'public' else 'internal' end,
    notes_md = excluded.notes_md,
    updated_at = now()
  returning r.registry_id into v_registry_id;

  select coalesce(
    jsonb_object_agg(
      dc.column_name || '|' || dc.data_type,
      jsonb_build_object('meaning', dc.meaning, 'unit', dc.unit)
    ), '{}'::jsonb)
  into v_prior
  from dataset_column dc
  where dc.registry_id = v_registry_id and dc.status = 'approved';

  delete from dataset_column dc where dc.registry_id = v_registry_id;

  for v_col in
    select c.column_name as name, c.data_type as dtype, c.ordinal_position as ord
    from information_schema.columns c
    where c.table_schema = 'public' and c.table_name = p_table
    order by c.ordinal_position
  loop
    select
      case
        when s.n_distinct is null then null
        when s.n_distinct < 0 then (-s.n_distinct * v_row_estimate)::bigint
        else s.n_distinct::bigint
      end,
      s.null_frac::numeric,
      case when s.most_common_vals is null then null
           else (select array_agg(v) from (
                   select unnest(s.most_common_vals::text::text[]) as v limit 8
                 ) t)
      end
    into v_distinct, v_null_rate, v_samples
    from pg_stats s
    where s.schemaname = 'public' and s.tablename = p_table and s.attname = v_col.name;

    select
      case when s.histogram_bounds is null then null
           else (s.histogram_bounds::text::text[])[1] end,
      case when s.histogram_bounds is null then null
           else (s.histogram_bounds::text::text[])[array_length(s.histogram_bounds::text::text[], 1)] end
    into v_min, v_max
    from pg_stats s
    where s.schemaname = 'public' and s.tablename = p_table and s.attname = v_col.name;

    v_is_join_key := false;
    v_joins_to := null;
    v_best_overlap := null;
    v_best_target := null;

    if v_col.dtype in ('text', 'character varying', 'bigint', 'integer', 'smallint') then
      for v_candidate in
        select distinct dc.joins_to as target
        from dataset_column dc
        where dc.status = 'approved' and dc.is_join_key and dc.joins_to is not null
          and (dc.column_name = v_col.name or split_part(dc.joins_to, '.', 2) = v_col.name)
      loop
        begin
          execute format(
            'select count(*), count(*) filter (where exists (select 1 from %I t where t.%I::text = s.v)) from (select distinct %I::text as v from %I where %I is not null limit 500) s',
            split_part(v_candidate.target, '.', 1),
            split_part(v_candidate.target, '.', 2),
            v_col.name, p_table, v_col.name
          ) into v_sampled, v_matched;
        exception when others then
          v_sampled := 0; v_matched := 0;
        end;

        if v_sampled > 0 then
          v_overlap := round(v_matched::numeric / v_sampled, 4);
          if v_best_overlap is null or v_overlap > v_best_overlap then
            v_best_overlap := v_overlap;
            v_best_target := v_candidate.target;
          end if;
        end if;
      end loop;

      if v_best_overlap is not null and v_best_overlap >= 0.95 then
        v_is_join_key := true;
        v_joins_to := v_best_target;
      end if;
    end if;

    v_meaning := null;
    v_unit := null;
    v_meaning_source := null;

    v_meaning := v_prior -> (v_col.name || '|' || v_col.dtype) ->> 'meaning';
    v_unit    := v_prior -> (v_col.name || '|' || v_col.dtype) ->> 'unit';
    if v_meaning is not null then
      v_meaning_source := 'kept from this table''s approved dictionary';
    end if;

    if v_meaning is null then
      select dc.meaning, dc.unit into v_meaning, v_unit
      from dataset_column dc
      join dataset_registry dr on dr.registry_id = dc.registry_id
      where dc.status = 'approved' and dr.status = 'approved'
        and dc.column_name = v_col.name and dc.data_type = v_col.dtype
      group by dc.meaning, dc.unit
      order by count(*) desc
      limit 1;
      if v_meaning is not null then
        v_meaning_source := 'borrowed from the approved dictionary';
      end if;
    end if;

    if v_meaning is null then
      v_meaning := format('(needs review) %s — no approved dictionary describes this column.', v_col.name);
      v_meaning_source := 'placeholder';
    end if;

    v_role := profile_dataset_role(v_col.dtype, v_col.name, v_is_join_key, v_distinct, v_row_estimate);

    insert into dataset_column (
      registry_id, column_name, ordinal, data_type, meaning, unit, role,
      is_join_key, joins_to, is_queryable,
      distinct_count, null_rate, min_value, max_value, sample_values, profiled_at, status
    ) values (
      v_registry_id, v_col.name, v_col.ord, v_col.dtype, v_meaning, v_unit, v_role,
      v_is_join_key, v_joins_to,
      v_col.dtype not in ('ARRAY', 'jsonb', 'json'),
      v_distinct, v_null_rate, v_min, v_max, v_samples, now(), 'auto'
    );

    column_name := v_col.name;
    data_type := v_col.dtype;
    role := v_role;
    is_join_key := v_is_join_key;
    joins_to := v_joins_to;
    meaning_source := v_meaning_source;
    distinct_count := v_distinct;
    null_rate := v_null_rate;
    overlap_rate := v_best_overlap;
    return next;
  end loop;
end;
$$;


--
-- Name: profile_dataset_refusal(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.profile_dataset_refusal(p_table text) RETURNS text
    LANGUAGE sql STABLE
    AS $$
  select case
    when p_table is null or p_table = '' then 'no table name was given'
    when p_table in ('admin_users', 'usage_events', 'feedback', 'ingestion_batches')
      then 'identity, telemetry and free-text tables are not datasets and are never profiled'
    when p_table like 'ai\_%' then 'assistant bookkeeping tables are never profiled'
    when p_table like 'dataset\_%' or p_table like 'kb\_%' or p_table like 'doc\_%'
      then 'the registry and graph tables are not themselves datasets'
    when not exists (
      select 1 from information_schema.tables
      where table_schema = 'public' and table_name = p_table
        and table_type in ('BASE TABLE', 'VIEW')
    ) then format('%L is not a table or view in the public schema', p_table)
    else null
  end;
$$;


--
-- Name: profile_dataset_role(text, text, boolean, bigint, bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.profile_dataset_role(p_data_type text, p_column_name text, p_is_join_key boolean, p_distinct bigint, p_row_estimate bigint) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$
  select case
    when p_is_join_key then 'key'
    when p_column_name in ('ingestion_batch_id', 'created_at', 'updated_at', 'profiled_at')
      then 'meta'
    when p_column_name like '%\_year' or p_data_type in ('date', 'timestamp with time zone', 'timestamp without time zone')
      then 'dimension'
    when p_data_type in ('boolean') then 'dimension'
    when p_data_type in ('smallint', 'integer', 'bigint', 'numeric', 'double precision', 'real')
      then case
        when p_distinct is not null and p_row_estimate > 0
             and p_distinct::numeric / p_row_estimate >= 0.9 then 'key'
        when p_distinct is not null and p_distinct <= 12 then 'dimension'
        else 'measure'
      end
    else 'dimension'
  end;
$$;


--
-- Name: search_district(text, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.search_district(search_query text, result_limit integer DEFAULT 8) RETURNS TABLE(district_code text, district_name text, matched_member_name text, match_rank real)
    LANGUAGE sql STABLE
    SET search_path TO 'public', 'extensions'
    AS $$
  with live as (
    select district_code, geo_code
    from geo_district_map
    where superseded_by is null and status <> 'rejected'
  ),
  by_name as (
    select
      d.district_code,
      d.district_name,
      null::text as matched_member_name,
      word_similarity(lower(search_query), lower(d.district_name)) as rank
    from dim_legislative_district d
    where d.status <> 'rejected'
      and word_similarity(lower(search_query), lower(d.district_name)) > 0.3
  ),
  by_member as (
    select
      d.district_code,
      d.district_name,
      g.geo_name as matched_member_name,
      word_similarity(lower(search_query), lower(g.geo_name)) as rank
    from live l
    join dim_legislative_district d on d.district_code = l.district_code
    join dim_geo g on g.geo_code = l.geo_code
    where d.status <> 'rejected'
      and word_similarity(lower(search_query), lower(g.geo_name)) > 0.3
  ),
  combined as (
    select * from by_name
    union all
    select * from by_member
  ),
  best_per_district as (
    select distinct on (district_code) district_code, district_name, matched_member_name, rank
    from combined
    order by district_code, rank desc
  )
  select district_code, district_name, matched_member_name, rank as match_rank
  from best_per_district
  order by match_rank desc
  limit result_limit;
$$;


--
-- Name: search_documents(text, integer, text, text, text, real); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.search_documents(p_query text, p_limit integer DEFAULT 8, p_embedding text DEFAULT NULL::text, p_model text DEFAULT NULL::text, p_doc_key text DEFAULT NULL::text, p_min_lexical real DEFAULT 0.2) RETURNS TABLE(chunk_id bigint, doc_key text, doc_title text, doc_as_of date, page_from integer, page_to integer, heading text, content text, char_start integer, char_end integer, lexical_score real, vector_distance real, matched_by text, score real)
    LANGUAGE plpgsql STABLE
    SET statement_timeout TO '5000ms'
    SET search_path TO 'public', 'extensions', 'pg_temp'
    AS $$
declare
  hard_row_cap constant int := 25;
  rrf_k constant int := 60;
  candidate_depth constant int := 40;
  effective_limit int;
begin
  if p_query is null or btrim(p_query) = '' then
    raise exception 'search_documents requires a non-empty query';
  end if;
  if p_limit is null or p_limit < 1 then
    effective_limit := 8;
  elsif p_limit > hard_row_cap then
    raise exception 'limit % exceeds the search limit of %', p_limit, hard_row_cap;
  else
    effective_limit := p_limit;
  end if;
  if p_embedding is not null and p_model is null then
    raise exception 'an embedding was supplied without naming the model that produced it';
  end if;

  return query
  with scoped as (
    select c.chunk_id, c.page_from, c.page_to, c.heading, c.content,
           c.char_start, c.char_end, s.key as doc_key, s.title as doc_title, s.as_of as doc_as_of
    from doc_chunk c
    join doc_source s on s.doc_id = c.doc_id
    where s.status = 'approved'
      and c.content <> ''
      and (p_doc_key is null or s.key = p_doc_key)
  ),
  lexical as (
    select sc.chunk_id,
           greatest(
             case when sc.content ilike '%' || p_query || '%' then 1.0::real else 0.0::real end,
             word_similarity(p_query, sc.content)
           ) as lexical_score
    from scoped sc
    where sc.content ilike '%' || p_query || '%'
       or word_similarity(p_query, sc.content) >= p_min_lexical
  ),
  lexical_ranked as (
    select l.chunk_id, l.lexical_score,
           row_number() over (order by l.lexical_score desc, l.chunk_id) as rnk
    from lexical l
    order by l.lexical_score desc, l.chunk_id
    limit candidate_depth
  ),
  vector_ranked as (
    select e.chunk_id,
           (e.embedding <=> p_embedding::vector)::real as vector_distance,
           row_number() over (order by e.embedding <=> p_embedding::vector, e.chunk_id) as rnk
    from doc_chunk_embedding e
    join scoped sc on sc.chunk_id = e.chunk_id
    where p_embedding is not null
      and e.model = p_model
    order by e.embedding <=> p_embedding::vector, e.chunk_id
    limit candidate_depth
  ),
  fused as (
    select coalesce(lr.chunk_id, vr.chunk_id) as chunk_id,
           lr.lexical_score,
           vr.vector_distance,
           case
             when lr.chunk_id is not null and vr.chunk_id is not null then 'both'
             when lr.chunk_id is not null then 'lexical'
             else 'vector'
           end as matched_by,
           (coalesce(1.0 / (rrf_k + lr.rnk), 0.0)
            + coalesce(1.0 / (rrf_k + vr.rnk), 0.0))::real as score
    from lexical_ranked lr
    full outer join vector_ranked vr on vr.chunk_id = lr.chunk_id
  )
  select sc.chunk_id, sc.doc_key, sc.doc_title, sc.doc_as_of,
         sc.page_from, sc.page_to, sc.heading, sc.content, sc.char_start, sc.char_end,
         f.lexical_score, f.vector_distance, f.matched_by, f.score
  from fused f
  join scoped sc on sc.chunk_id = f.chunk_id
  order by f.score desc, sc.chunk_id
  limit effective_limit;
end;
$$;


--
-- Name: search_geo(text, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.search_geo(search_query text, result_limit integer DEFAULT 8) RETURNS TABLE(geo_code text, geo_level public.geo_level_enum, geo_name text, n_total integer, parent_chain jsonb, match_rank real)
    LANGUAGE sql STABLE
    SET search_path TO 'public', 'extensions'
    AS $$
  with fts as (
    select
      s.geo_code,
      s.geo_level,
      s.geo_name,
      s.n_total,
      s.parent_chain,
      100 + ts_rank(s.search_text, websearch_to_tsquery('simple', search_query)) as rank
    from agg_geo_summary s
    where s.search_text @@ websearch_to_tsquery('simple', search_query)
  ),
  trgm as (
    select
      g.geo_code,
      g.geo_level,
      g.geo_name,
      s.n_total,
      s.parent_chain,
      word_similarity(lower(search_query), lower(g.geo_name)) as rank
    from dim_geo g
    join agg_geo_summary s on s.geo_code = g.geo_code
    where word_similarity(lower(search_query), lower(g.geo_name)) > 0.3
  ),
  combined as (
    select * from fts
    union all
    select * from trgm
  )
  select geo_code, geo_level, geo_name, n_total, parent_chain, max(rank) as match_rank
  from combined
  group by geo_code, geo_level, geo_name, n_total, parent_chain
  order by match_rank desc, n_total desc nulls last
  limit result_limit;
$$;


--
-- Name: sweep_contradictions(numeric, numeric, integer, numeric, integer, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sweep_contradictions(p_min_value numeric DEFAULT 1000, p_rel_tolerance numeric DEFAULT 0.10, p_min_cells integer DEFAULT 3, p_min_fit numeric DEFAULT 0.5, p_max_rows integer DEFAULT 5000, p_statement_timeout text DEFAULT '60s'::text) RETURNS TABLE(found_by text, finding text, slide integer, scope text, document_value numeric, dataset_ref text, dataset_value numeric, relative_difference numeric, stat text)
    LANGUAGE plpgsql
    SET search_path TO 'public', 'extensions'
    AS $_$
declare
  v_swept_at timestamptz := now();
  v_generic text[] := kb_generic_terms();
  v_dist record;
  v_cand record;
  v_map jsonb;
  v_rows bigint;
  v_distinct_keys bigint;
  v_covered integer;
  v_matched integer;
  v_fit numeric;
  v_best_fit numeric;
  v_best record;
  v_ties text[];
  v_cell record;
  v_data_value numeric;
  v_doc_total numeric;
  v_data_total numeric;
  v_total_rows bigint;
  v_total_keys bigint;
  v_label text;
  v_best_table text;
  v_best_column text;
  v_best_join text;
  v_best_as_of date;
  v_best_covered integer;
  v_best_matched integer;
  v_geo_count bigint;
  -- Set when some candidate agrees with this slide on every cell it lists. Read after the
  -- candidate loop, never during it: candidates are iterated `order by 1, 3`, so the corroborating
  -- one may well be probed after a disagreeing one has already been recorded as the best fit, and
  -- a guard that assumes the perfect fit arrives first would file that disagreement anyway.
  v_corroborated boolean;
begin
  perform set_config('statement_timeout', p_statement_timeout, true);
  select count(*) into v_geo_count from dim_geo;

  -- =========================================================================================
  -- Pass 1 — geographic distributions.
  -- =========================================================================================
  --
  -- `as materialized` is load-bearing, not decoration. A plpgsql FOR-over-query runs as a cursor,
  -- and a cursor is planned for a fast first row (`cursor_tuple_fraction`), which flipped the join
  -- below from a hash join over 268 label rows into a nested loop that re-derived the label view
  -- once per row of dim_geo. Same answer, 54 seconds instead of one. Materializing the label rows
  -- fixes the join order by taking the choice away.
  for v_dist in
    with labels as materialized (
      select l.chunk_id, l.doc_id, l.page_from, l.doc_as_of, l.ord, l.label, l.value
      from kb_doc_label_number l
    ),
    resolved as materialized (
      select p.chunk_id, p.doc_id, p.page_from, p.doc_as_of, p.ord,
             g.geo_code, g.geo_level::text as geo_level, p.value
      from labels p
      join dim_geo g
        on upper(regexp_replace(btrim(g.geo_name), '\s+', ' ', 'g')) = p.label
    ),
    -- A slide may repeat a geography (a two-column table). Keep its first mention only, so the
    -- comparison is against a value the slide states once.
    deduped as materialized (
      select distinct on (chunk_id, geo_level, geo_code) *
      from resolved
      order by chunk_id, geo_level, geo_code, ord
    )
    select d.chunk_id, d.doc_id, d.page_from, d.doc_as_of, d.geo_level,
           count(*)::integer as cells,
           array_agg(geo_code order by ord) as geo_codes,
           array_agg(value order by ord) as cell_values,
           sum(value) as doc_total,
           bool_and(value = trunc(value)) as all_integers,
           (select c.heading from doc_chunk c where c.chunk_id = d.chunk_id) as heading
    from deduped d
    group by d.chunk_id, d.doc_id, d.page_from, d.doc_as_of, d.geo_level
    having count(*) >= p_min_cells
    order by d.chunk_id
  loop
    v_best_fit := null;
    v_best_table := null;
    v_best_column := null;
    v_best_join := null;
    v_best_as_of := null;
    v_best_covered := 0;
    v_best_matched := 0;
    v_ties := '{}'::text[];
    -- Per distribution, not per sweep. Left set from a previous slide it would silence every
    -- slide after the first corroborated one.
    v_corroborated := false;

    -- Candidates: every approved measure column on an approved table that joins to dim_geo. The
    -- registry is the allowlist; `role = 'measure'` is the registry's own rule that this is a
    -- column a stated number may come from. When the slide's cells are all whole numbers, only
    -- integer-typed measures are considered — a percentage column is not a candidate for a table
    -- of counts, and saying so here removes most of the probes.
    for v_cand in
      select distinct r.table_name, j.column_name as join_col, m.column_name as measure_col,
             dd.as_of_date as data_as_of
      from dataset_registry r
      join dataset_column j
        on j.registry_id = r.registry_id and j.status = 'approved' and j.joins_to = 'dim_geo.geo_code'
      join dataset_column m
        on m.registry_id = r.registry_id and m.status = 'approved' and m.role = 'measure'
      left join dim_dataset dd on dd.slug = r.dataset_slug
      where r.status = 'approved'
        -- Necessary condition rather than a heuristic: a table holding at most one value per
        -- geography cannot have more rows than there are geographies. Every candidate this drops
        -- is one the uniqueness guard below would reject anyway, so nothing is lost — and it is
        -- what keeps the sweep away from agg_demographics (530,465 rows, twenty-odd per
        -- geography), whose probe alone costs 3.4 seconds. Guardrail 4 satisfied by arithmetic
        -- over the registry rather than by a blacklist of table names.
        and coalesce(r.row_estimate, 0) <= v_geo_count
        and m.data_type in ('smallint', 'integer', 'bigint', 'numeric', 'real', 'double precision')
        and (not v_dist.all_integers
             or m.data_type in ('smallint', 'integer', 'bigint'))
      order by 1, 3
    loop
      begin
        execute format(
          'select jsonb_object_agg(k, v), count(*), count(distinct k) from ('
          '  select t.%I::text as k, t.%I::numeric as v from %I t'
          '  where t.%I = any($1) and t.%I is not null limit $2'
          ') s',
          v_cand.join_col, v_cand.measure_col, v_cand.table_name,
          v_cand.join_col, v_cand.measure_col
        )
        into v_map, v_rows, v_distinct_keys
        using v_dist.geo_codes, p_max_rows;
      exception when others then
        v_map := null; v_rows := 0; v_distinct_keys := 0;
      end;

      -- More rows than geographies means this table holds several values per geography at this
      -- level (one per indicator, per category, per year). Which of them the slide is quoting is
      -- not decidable from the numbers, so the candidate is skipped rather than guessed at. This
      -- is the guard that keeps agg_demographics and agg_cohorts out without naming them.
      continue when v_map is null or v_rows = 0 or v_rows <> v_distinct_keys;

      v_covered := 0;
      v_matched := 0;
      for i in 1 .. v_dist.cells loop
        if v_map ? v_dist.geo_codes[i] then
          v_covered := v_covered + 1;
          if (v_map ->> v_dist.geo_codes[i])::numeric = v_dist.cell_values[i] then
            v_matched := v_matched + 1;
          end if;
        end if;
      end loop;

      -- The structured side must have a value for most of what the slide lists, or it is a
      -- different population rather than a different count of the same one.
      continue when v_covered * 4 < v_dist.cells * 3;

      v_fit := round(v_matched::numeric / v_dist.cells, 4);

      -- A perfect fit is corroboration, and corroboration is not what this queue is for — but it
      -- is a fact about THE SLIDE, not about this candidate. `v_fit >= 1.0` requires v_matched to
      -- equal every cell the slide lists, which the coverage guard above makes strictly stronger
      -- than "agreed wherever it had a value": the candidate carries a value for every one of the
      -- slide's geographies and agrees on all of them. That distribution is accounted for, so no
      -- weaker-fitting column may be filed against it, and there is nothing left to learn from
      -- probing the rest.
      if v_fit >= 1.0 then
        v_corroborated := true;
        exit;
      end if;

      continue when v_fit < p_min_fit;

      if v_best_fit is null or v_fit > v_best_fit then
        v_best_fit := v_fit;
        v_best_table := v_cand.table_name;
        v_best_column := v_cand.measure_col;
        v_best_join := v_cand.join_col;
        v_best_as_of := v_cand.data_as_of;
        v_best_covered := v_covered;
        v_best_matched := v_matched;
        v_ties := '{}'::text[];
      elsif v_fit = v_best_fit then
        -- Recorded rather than resolved. Several tables can legitimately carry the same measure,
        -- and a reviewer who is not told that is reviewing an arbitrary choice.
        v_ties := v_ties || (v_cand.table_name || '.' || v_cand.measure_col);
      end if;
    end loop;

    -- Read here rather than at the top of the candidate loop: this is the moment the slide would
    -- be filed, and it is the only place where "was this slide corroborated by anything?" is a
    -- settled question. Suppresses the cell rows and the level_total together — see the header for
    -- why the total does not survive its own slide's corroboration.
    continue when v_corroborated or v_best_fit is null;

    v_label := format('%s per %s: %s vs %s.%s',
                      coalesce(v_dist.heading, 'slide ' || v_dist.page_from),
                      v_dist.geo_level,
                      'cue-card slide ' || v_dist.page_from,
                      v_best_table, v_best_column);

    -- Re-read the winning candidate so each disagreeing cell is filed with its own value.
    execute format(
      'select jsonb_object_agg(k, v) from ('
      '  select t.%I::text as k, t.%I::numeric as v from %I t'
      '  where t.%I = any($1) and t.%I is not null limit $2'
      ') s',
      v_best_join, v_best_column, v_best_table, v_best_join, v_best_column
    ) into v_map using v_dist.geo_codes, p_max_rows;

    for i in 1 .. v_dist.cells loop
      continue when not (v_map ? v_dist.geo_codes[i]);
      v_data_value := (v_map ->> v_dist.geo_codes[i])::numeric;
      continue when v_data_value = v_dist.cell_values[i];

      insert into kb_contradiction as k (
        method, measure_label, doc_id, chunk_id, page_from, doc_value, doc_as_of, doc_as_of_text,
        evidence_quote, data_table, data_column, data_stat, data_value, data_as_of,
        geo_code, geo_level, abs_difference, rel_difference, evidence, last_swept_at
      )
      select
        'geo_distribution', v_label, v_dist.doc_id, v_dist.chunk_id, v_dist.page_from,
        v_dist.cell_values[i], v_dist.doc_as_of,
        (select substring(c.content from '(?i)as of[^\n]{0,60}') from doc_chunk c where c.chunk_id = v_dist.chunk_id),
        g.geo_name || E'\n' || v_dist.cell_values[i]::text,
        v_best_table, v_best_column, 'cell', v_data_value, v_best_as_of,
        v_dist.geo_codes[i], v_dist.geo_level,
        abs(v_dist.cell_values[i] - v_data_value),
        round(abs(v_dist.cell_values[i] - v_data_value) / greatest(abs(v_dist.cell_values[i]), abs(v_data_value)), 6),
        jsonb_build_object(
          'cells', v_dist.cells, 'covered', v_best_covered, 'agreed', v_best_matched,
          'fit', v_best_fit, 'tied_candidates', to_jsonb(v_ties)
        ),
        v_swept_at
      from dim_geo g where g.geo_code = v_dist.geo_codes[i]
      on conflict on constraint kb_contradiction_identity do update set
        measure_label = excluded.measure_label,
        evidence = excluded.evidence,
        last_swept_at = excluded.last_swept_at,
        -- A judged row stays judged while the two numbers it was judged on are unchanged. A
        -- changed value is a different disagreement, so it returns to the queue with the note
        -- that was written about the old one cleared rather than left standing behind it.
        status = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                      then k.status else 'auto' end,
        review_note = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                           then k.review_note else null end,
        reviewed_by = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                           then k.reviewed_by else null end,
        reviewed_at = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                           then k.reviewed_at else null end,
        doc_value = excluded.doc_value,
        data_value = excluded.data_value,
        abs_difference = excluded.abs_difference,
        rel_difference = excluded.rel_difference;

      found_by := 'geo_distribution';
      finding := v_label;
      slide := v_dist.page_from;
      select g.geo_name into scope from dim_geo g where g.geo_code = v_dist.geo_codes[i];
      document_value := v_dist.cell_values[i];
      dataset_ref := v_best_table || '.' || v_best_column;
      dataset_value := v_data_value;
      relative_difference := round(abs(v_dist.cell_values[i] - v_data_value) / greatest(abs(v_dist.cell_values[i]), abs(v_data_value)), 6);
      stat := 'cell';
      return next;
    end loop;

    -- The slide's own total against the table's. Computed from the two distributions rather than
    -- read off a "TOTAL" line, so it needs no English word and holds for a slide that states no
    -- total at all: the slide's cells sum to one number, the table's rows at that level sum to
    -- another, and a difference between them is a claim about scope, not about one geography.
    v_doc_total := v_dist.doc_total;
    begin
      execute format(
        'select sum(v)::numeric, count(*), count(distinct k) from ('
        '  select t.%I::text as k, t.%I::numeric as v from %I t'
        '  join dim_geo g on g.geo_code = t.%I where g.geo_level::text = $1 limit $2'
        ') s',
        v_best_join, v_best_column, v_best_table, v_best_join
      ) into v_data_total, v_total_rows, v_total_keys using v_dist.geo_level, p_max_rows;
    exception when others then
      v_data_total := null;
    end;

    if v_data_total is not null and v_total_rows = v_total_keys
       and v_total_rows > 0 and v_data_total <> v_doc_total then
      insert into kb_contradiction as k (
        method, measure_label, doc_id, chunk_id, page_from, doc_value, doc_as_of, doc_as_of_text,
        evidence_quote, data_table, data_column, data_stat, data_value, data_as_of,
        geo_code, geo_level, abs_difference, rel_difference, evidence, last_swept_at
      ) values (
        'geo_distribution',
        v_label || ' (total over all ' || v_dist.geo_level || 's)',
        v_dist.doc_id, v_dist.chunk_id, v_dist.page_from, v_doc_total, v_dist.doc_as_of,
        (select substring(c.content from '(?i)as of[^\n]{0,60}') from doc_chunk c where c.chunk_id = v_dist.chunk_id),
        format('The slide''s %s %s cells sum to %s.', v_dist.cells, v_dist.geo_level, v_doc_total),
        v_best_table, v_best_column, 'level_total', v_data_total, v_best_as_of,
        null, null,
        abs(v_doc_total - v_data_total),
        round(abs(v_doc_total - v_data_total) / greatest(abs(v_doc_total), abs(v_data_total)), 6),
        jsonb_build_object(
          'cells', v_dist.cells, 'covered', v_best_covered, 'agreed', v_best_matched,
          'fit', v_best_fit, 'data_rows', v_total_rows, 'tied_candidates', to_jsonb(v_ties)
        ),
        v_swept_at
      )
      on conflict on constraint kb_contradiction_identity do update set
        measure_label = excluded.measure_label,
        evidence = excluded.evidence,
        last_swept_at = excluded.last_swept_at,
        status = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                      then k.status else 'auto' end,
        review_note = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                           then k.review_note else null end,
        reviewed_by = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                           then k.reviewed_by else null end,
        reviewed_at = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                           then k.reviewed_at else null end,
        doc_value = excluded.doc_value,
        data_value = excluded.data_value,
        abs_difference = excluded.abs_difference,
        rel_difference = excluded.rel_difference;

      found_by := 'geo_distribution';
      finding := v_label;
      slide := v_dist.page_from;
      scope := '(total)';
      document_value := v_doc_total;
      dataset_ref := v_best_table || '.' || v_best_column;
      dataset_value := v_data_total;
      relative_difference := round(abs(v_doc_total - v_data_total) / greatest(abs(v_doc_total), abs(v_data_total)), 6);
      stat := 'level_total';
      return next;
    end if;
  end loop;

  -- =========================================================================================
  -- Pass 2 — standalone figures, paired on shared vocabulary and magnitude.
  -- =========================================================================================
  --
  -- No dynamic SQL and no data table: the structured side is entirely the registry's own profile
  -- statistics, which 4.1 measured. One statement, so the bound is the corpus rather than a loop.
  for v_cell in
    with doc_numbers as (
      select l.chunk_id, l.doc_id, l.page_from, l.doc_as_of, l.ord, l.line,
             m[1] as raw,
             replace(m[1], ',', '')::numeric as value
      from kb_doc_line l
      cross join lateral regexp_matches(l.line, '([0-9]{1,3}(?:,[0-9]{3})+|[0-9]{4,})', 'g') as m
    ),
    candidates as (
      select n.*,
             -- The words the figure sits among: its own line and the two most recent lines that
             -- carry any letters. On a slide that is the question and the heading above it.
             (select string_agg(x.line, ' ' order by x.ord)
              from (
                select l2.ord, l2.line from kb_doc_line l2
                where l2.chunk_id = n.chunk_id and l2.ord <= n.ord and l2.line ~ '[A-Za-z]'
                order by l2.ord desc limit 3
              ) x) as window_text
      from doc_numbers n
      where n.value >= p_min_value
        -- A bare four-digit number in this range is a year. One carrying a thousands separator is
        -- a quantity that happens to be small.
        and not (n.raw !~ ',' and n.value between 1900 and 2100)
    ),
    doc_claims as (
      select c.*, kb_terms(c.window_text) as terms
      from candidates c
    ),
    data_scalars as (
      -- BOTH RESTRICTIONS HERE WERE WRITTEN AFTER RUNNING THE FIRST VERSION, WHICH GOT THEM WRONG.
      --
      -- 1. A count of things is a count of distinct KEYS, not a count of rows. The first version
      --    also offered `dataset_registry.row_estimate`, which counts the rows of a table whose
      --    grain may be a grid (geography x indicator x year) — a number that is an artifact of
      --    the grid and that nobody publishes. `dataset_column.distinct_count` on a `key` column
      --    counts the things the key identifies. This is 4.1's role vocabulary doing load-bearing
      --    work, and it is why 4.1 retyped `bhw_id` from `measure` to `key`.
      --
      --    The consequence is stated rather than hidden: this pass can only contradict a table
      --    that has been *profiled*, because `distinct_count` is null until a profiling pass
      --    writes it. Today that is `fact_bhw_raw` and nothing else. The pass's reach grows with
      --    4.1's rather than on its own.
      --
      -- 2. The vocabulary is the entry's NAME, not its prose. The first version also drew terms
      --    from `summary`, `grain` and `meaning` — text written for a reader and therefore full of
      --    connective words. It paired "RA 11223" (a statute number) against a table's row count
      --    on four separate slides, on the strength of a shared "with", "its" or "for". A title is
      --    a name; a summary is a sentence, and a sentence shares words with everything.
      select r.table_name, dc.column_name as data_column, 'distinct_count'::text as data_stat,
             dc.distinct_count::numeric as value, dd.as_of_date as data_as_of,
             r.title || ' ' || dc.column_name as label_text,
             r.title || ' — distinct ' || dc.column_name as label
      from dataset_registry r
      join dataset_column dc on dc.registry_id = r.registry_id
      left join dim_dataset dd on dd.slug = r.dataset_slug
      where r.status = 'approved' and dc.status = 'approved'
        and dc.role = 'key' and dc.distinct_count is not null and dc.distinct_count > 0
    ),
    scored as (
      select d.chunk_id, d.doc_id, d.page_from, d.doc_as_of, d.line, d.value as doc_value,
             s.table_name, s.data_column, s.data_stat, s.value as data_value, s.data_as_of, s.label,
             array(select unnest(d.terms) intersect select unnest(kb_terms(s.label_text))
                   except select unnest(v_generic)) as shared,
             round(abs(d.value - s.value) / greatest(d.value, s.value), 6) as rel
      from doc_claims d
      join data_scalars s
        on d.value <> s.value
       and abs(d.value - s.value) <= p_rel_tolerance * greatest(d.value, s.value)
    )
    select distinct on (sc.chunk_id, sc.doc_value) sc.*
    from scored sc
    where array_length(sc.shared, 1) >= 1
    order by sc.chunk_id, sc.doc_value, sc.rel, sc.table_name, sc.data_column
  loop
    insert into kb_contradiction as k (
      method, measure_label, doc_id, chunk_id, page_from, doc_value, doc_as_of, doc_as_of_text,
      evidence_quote, data_table, data_column, data_stat, data_value, data_as_of,
      geo_code, geo_level, abs_difference, rel_difference, evidence, last_swept_at
    ) values (
      'scalar_magnitude',
      format('slide %s states %s; %s holds %s', v_cell.page_from, v_cell.doc_value,
             coalesce(v_cell.table_name || '.' || v_cell.data_column, v_cell.table_name),
             v_cell.data_value),
      v_cell.doc_id, v_cell.chunk_id, v_cell.page_from, v_cell.doc_value, v_cell.doc_as_of,
      (select substring(c.content from '(?i)as of[^\n]{0,60}') from doc_chunk c where c.chunk_id = v_cell.chunk_id),
      btrim(v_cell.line),
      v_cell.table_name, v_cell.data_column, v_cell.data_stat, v_cell.data_value, v_cell.data_as_of,
      null, null,
      abs(v_cell.doc_value - v_cell.data_value), v_cell.rel,
      jsonb_build_object('shared_terms', to_jsonb(v_cell.shared), 'registry_label', v_cell.label),
      v_swept_at
    )
    on conflict on constraint kb_contradiction_identity do update set
      measure_label = excluded.measure_label,
      evidence = excluded.evidence,
      last_swept_at = excluded.last_swept_at,
      status = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                    then k.status else 'auto' end,
      review_note = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                         then k.review_note else null end,
      reviewed_by = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                         then k.reviewed_by else null end,
      reviewed_at = case when k.doc_value = excluded.doc_value and k.data_value = excluded.data_value
                         then k.reviewed_at else null end,
      doc_value = excluded.doc_value,
      data_value = excluded.data_value,
      abs_difference = excluded.abs_difference,
      rel_difference = excluded.rel_difference;

    found_by := 'scalar_magnitude';
    finding := format('%s vs %s', v_cell.doc_value, v_cell.label);
    slide := v_cell.page_from;
    scope := null;
    document_value := v_cell.doc_value;
    dataset_ref := coalesce(v_cell.table_name || '.' || v_cell.data_column, v_cell.table_name);
    dataset_value := v_cell.data_value;
    relative_difference := v_cell.rel;
    stat := v_cell.data_stat;
    return next;
  end loop;
end;
$_$;


--
-- Name: FUNCTION sweep_contradictions(p_min_value numeric, p_rel_tolerance numeric, p_min_cells integer, p_min_fit numeric, p_max_rows integer, p_statement_timeout text); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.sweep_contradictions(p_min_value numeric, p_rel_tolerance numeric, p_min_cells integer, p_min_fit numeric, p_max_rows integer, p_statement_timeout text) IS 'Increment 4.2: computes disagreements between approved document chunks and approved registered datasets and files each as a kb_contradiction row at status = auto. Two passes — geographic distributions resolved exactly against dim_geo, and standalone figures paired on shared non-generic vocabulary plus magnitude. A candidate agreeing on every cell corroborates the slide and suppresses it entirely, rather than only itself. Reads only registry-approved tables, through %I, with a row cap and its own statement timeout. Calls no provider.';


--
-- Name: traverse_geo(text, text, integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.traverse_geo(start_code text, direction text DEFAULT 'down'::text, max_depth integer DEFAULT 3, row_cap integer DEFAULT 200) RETURNS TABLE(geo_code text, geo_level public.geo_level_enum, geo_name text, depth integer, path text[])
    LANGUAGE plpgsql STABLE
    SET statement_timeout TO '5000ms'
    SET search_path TO 'public', 'pg_temp'
    AS $$
declare
  hard_max_depth constant int := 5;
  hard_row_cap constant int := 500;
  depth_limit int := coalesce(max_depth, 3);
  cap int := least(greatest(coalesce(row_cap, 200), 1), hard_row_cap);
begin
  if direction not in ('down', 'up') then
    raise exception 'traverse_geo direction must be down or up, got %', direction
      using errcode = '22023';
  end if;
  if depth_limit > hard_max_depth then
    raise exception 'traverse_geo max_depth % exceeds the limit of %', depth_limit, hard_max_depth
      using errcode = '22023';
  end if;
  depth_limit := greatest(depth_limit, 1);

  return query
  with recursive walk as (
    select g.geo_code, g.parent_code, g.geo_level, g.geo_name, 0 as depth, array[g.geo_code] as path
    from dim_geo g
    where g.geo_code = start_code
    union all
    select c.geo_code, c.parent_code, c.geo_level, c.geo_name, w.depth + 1, w.path || c.geo_code
    from walk w
    join dim_geo c
      on (direction = 'down' and c.parent_code = w.geo_code)
      or (direction = 'up' and c.geo_code = w.parent_code)
    where w.depth < depth_limit
      and not (c.geo_code = any (w.path))
  )
  select w.geo_code, w.geo_level, w.geo_name, w.depth, w.path
  from walk w
  where w.depth > 0
  order by w.depth, w.geo_code
  limit cap;
end;
$$;


--
-- Name: traverse_kb(text, text, text[], integer, integer, date); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.traverse_kb(start_key text, direction text DEFAULT 'out'::text, relations text[] DEFAULT NULL::text[], max_depth integer DEFAULT 3, row_cap integer DEFAULT 200, as_of date DEFAULT NULL::date) RETURNS TABLE(key text, kind text, label text, depth integer, path text[], relation_path text[], source_path text[], direction_path text[], validity_path text[])
    LANGUAGE plpgsql STABLE
    SET statement_timeout TO '5000ms'
    SET search_path TO 'public', 'pg_temp'
    AS $$
declare
  hard_max_depth constant int := 6;
  both_max_depth constant int := 4;
  hard_row_cap constant int := 500;
  depth_limit int := coalesce(max_depth, 3);
  cap int := least(greatest(coalesce(row_cap, 200), 1), hard_row_cap);
  ceiling int;
begin
  if direction not in ('out', 'in', 'both') then
    raise exception 'traverse_kb direction must be out, in or both, got %', direction
      using errcode = '22023';
  end if;
  ceiling := case when direction = 'both' then both_max_depth else hard_max_depth end;
  if depth_limit > ceiling then
    raise exception 'traverse_kb max_depth % exceeds the limit of % for direction %',
      depth_limit, ceiling, direction using errcode = '22023';
  end if;
  depth_limit := greatest(depth_limit, 1);

  return query
  with recursive walk as (
    select n.node_id, n.key, n.kind, n.label, 0 as depth,
           array[n.key] as path, array[]::text[] as relation_path,
           array[]::text[] as source_path, array[]::text[] as direction_path,
           array[]::text[] as validity_path
    from kb_node n
    where n.key = start_key and n.status = 'approved'
    union all
    select m.node_id, m.key, m.kind, m.label, w.depth + 1,
           w.path || m.key, w.relation_path || e.relation, w.source_path || e.source_ref,
           w.direction_path || case when e.src_node_id = w.node_id then 'out' else 'in' end,
           w.validity_path || case
             when e.valid_from is null and e.valid_to is null then 'always'
             else coalesce(e.valid_from::text, 'open') || '..' || coalesce(e.valid_to::text, 'open')
           end
    from walk w
    join kb_edge e
      on (direction in ('out', 'both') and e.src_node_id = w.node_id)
      or (direction in ('in', 'both') and e.dst_node_id = w.node_id)
    join kb_node m
      on m.node_id = case when e.src_node_id = w.node_id then e.dst_node_id else e.src_node_id end
    where e.status = 'approved'
      and m.status = 'approved'
      and w.depth < depth_limit
      and (relations is null or e.relation = any (relations))
      and (as_of is null
           or ((e.valid_from is null or e.valid_from <= as_of)
               and (e.valid_to is null or e.valid_to > as_of)))
      and not (m.key = any (w.path))
  )
  select w.key, w.kind, w.label, w.depth, w.path, w.relation_path, w.source_path,
         w.direction_path, w.validity_path
  from walk w
  where w.depth > 0
  order by w.depth, w.key
  limit cap;
end;
$$;


--
-- Name: wilson_high(numeric, numeric); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.wilson_high(k numeric, n numeric) RETURNS numeric
    LANGUAGE sql IMMUTABLE
    AS $$
  select case when n is null or n <= 0 then null
    else least(100, round(100 * (
      (k + 1.9208) / (n + 3.8416)
      + 1.96 * (n / (n + 3.8416)) * sqrt(k * (n - k) / power(n, 3) + 0.9604 / power(n, 2))
    ), 2)) end;
$$;


--
-- Name: wilson_low(numeric, numeric); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.wilson_low(k numeric, n numeric) RETURNS numeric
    LANGUAGE sql IMMUTABLE
    AS $$
  select case when n is null or n <= 0 then null
    else greatest(0, round(100 * (
      (k + 1.9208) / (n + 3.8416)
      - 1.96 * (n / (n + 3.8416)) * sqrt(k * (n - k) / power(n, 3) + 0.9604 / power(n, 2))
    ), 2)) end;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_users (
    user_id uuid NOT NULL,
    role public.admin_role_enum NOT NULL
);


--
-- Name: agg_bhw_by_district; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_bhw_by_district (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    district_code text NOT NULL,
    n_total integer NOT NULL,
    n_accredited integer NOT NULL,
    pct_accredited numeric,
    avg_active_years numeric,
    any_honorarium_pct numeric
);


--
-- Name: TABLE agg_bhw_by_district; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_bhw_by_district IS 'BHW counts and rates per legislative district, rolled up from the LEAF grain (barangay), never from a member citymun row -- a multi-district city''s headcount would otherwise land on whichever sibling district''s citymun row it hit, or be double-counted across all of them. Same column shape as agg_bhw_counts, at the district grain instead of geo_level. Only districts with at least one matching BHW get a row, same convention as agg_bhw_counts. A district''s figure excludes any of its own gap (docs/LEGISLATIVE_DISTRICTS.md''s uncovered/unplaced lists) by construction -- those places carry no district in geo_district_map at all, so they contribute to no district''s total. See docs/LEGISLATIVE_DISTRICTS_PLAN.md §6 D3.1.';


--
-- Name: COLUMN agg_bhw_by_district.n_total; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_district.n_total IS 'Profiled BHWs (fact_bhw_raw) whose barangay resolves, directly or via its citymun, to a live geo_district_map row for this district. Summed from barangay grain -- see the table comment.';


--
-- Name: COLUMN agg_bhw_by_district.any_honorarium_pct; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_district.any_honorarium_pct IS 'Share of this district''s BHWs with at least one fact_honorarium row, same definition as agg_bhw_counts.any_honorarium_pct.';


--
-- Name: agg_bhw_by_district_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_by_district ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_bhw_by_district_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_bhw_by_uuc_status; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_bhw_by_uuc_status (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_barangays_listed integer DEFAULT 0 NOT NULL,
    n_barangays_other integer DEFAULT 0 NOT NULL,
    n_listed_with_data integer DEFAULT 0 NOT NULL,
    n_other_with_data integer DEFAULT 0 NOT NULL,
    n_listed_no_bhw integer DEFAULT 0 NOT NULL,
    n_other_no_bhw integer DEFAULT 0 NOT NULL,
    listed_n_bhw integer,
    other_n_bhw integer,
    listed_households bigint,
    other_households bigint,
    listed_registered_universe integer,
    other_registered_universe integer,
    listed_n_profiled integer,
    other_n_profiled integer,
    unallocated_n_bhw integer DEFAULT 0 NOT NULL,
    unallocated_households bigint DEFAULT 0 NOT NULL,
    listed_is_suppressed boolean DEFAULT false NOT NULL,
    other_is_suppressed boolean DEFAULT false NOT NULL,
    CONSTRAINT agg_bhw_by_uuc_status_listed_suppression_check CHECK (((listed_is_suppressed = (listed_n_bhw IS NULL)) AND (listed_is_suppressed = (listed_households IS NULL)) AND (listed_is_suppressed = (listed_registered_universe IS NULL)) AND (listed_is_suppressed = (listed_n_profiled IS NULL)))),
    CONSTRAINT agg_bhw_by_uuc_status_other_suppression_check CHECK (((other_is_suppressed = (other_n_bhw IS NULL)) AND (other_is_suppressed = (other_households IS NULL)) AND (other_is_suppressed = (other_registered_universe IS NULL)) AND (other_is_suppressed = (other_n_profiled IS NULL))))
);


--
-- Name: TABLE agg_bhw_by_uuc_status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_bhw_by_uuc_status IS 'BHW coverage in an area split by whether each barangay is on the 2025 UUC for PHC list. A CONSISTENCY CHECK, NOT A FINDING: UUC membership is defined partly on distance to a health facility (DOH AO No. 2020-0023), so a coverage gap between the two groups is partly definitional and must never be reported as a discovery in either direction. "Other" means every other barangay in the area, NOT "assessed and found adequate" — the assessed-but-unlisted barangays were never loaded. Built from agg_bhw_stepzero_counts (a headcount covering all 41,958 barangays), not from agg_bhw_counts, because profiled-BHW figures would confound BHW supply with profiling progress. Ratios are derived by the reader, never stored. See docs/UUC_PHC_2025_PLAN.md §9 U12b.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.n_barangays_listed; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.n_barangays_listed IS 'Barangays in this area on the 2025 UUC for PHC list. Equals agg_uuc_phc_counts.n_listed for the same geo, computed by a different path and asserted equal at load.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.n_barangays_other; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.n_barangays_other IS 'EVERY OTHER barangay in the area — not "assessed and found adequate". The source workbook''s 9,395 assessed-but-unlisted barangays are not loaded (plan U1), so no such group exists in this database. Plus n_barangays_listed, this is the area''s whole barangay count.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.n_listed_with_data; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.n_listed_with_data IS 'Listed barangays carrying a StepZero row with a BHW count — the denominator the listed-side figures are built from, and the count the suppression rule tests.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.n_other_with_data; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.n_other_with_data IS 'Other barangays carrying a StepZero row with a BHW count — the denominator the other-side figures are built from, and the count the suppression rule tests.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.n_listed_no_bhw; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.n_listed_no_bhw IS 'Listed barangays reporting zero BHWs. A real zero, never suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.n_other_no_bhw; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.n_other_no_bhw IS 'Other barangays reporting zero BHWs. A real zero, never suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.listed_n_bhw; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.listed_n_bhw IS 'StepZero total BHWs (registered + registered & accredited + non-registered) summed over this area''s listed barangays. NULL when the listed side is suppressed. Divide by listed_households for households per BHW — the site''s operative workload measure; never compare it across areas without its denominator.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.other_n_bhw; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.other_n_bhw IS 'StepZero total BHWs summed over this area''s other barangays. NULL when the other side is suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.listed_households; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.listed_households IS 'Households in this area''s listed barangays, from StepZero. The denominator of households per BHW. NULL when the listed side is suppressed. LISTED BARANGAYS ARE SMALLER ON AVERAGE, so most of any households-per-BHW difference between the two sides is barangay size rather than BHW deployment — divide by n_barangays_listed to see it.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.other_households; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.other_households IS 'Households in this area''s other barangays, from StepZero. NULL when the other side is suppressed. See listed_households on why barangay size, not deployment, drives most of the difference.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.listed_registered_universe; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.listed_registered_universe IS 'StepZero registered + registered & accredited BHWs on the listed side — the profiling-eligible base, and the ONLY denominator listed_n_profiled may be divided by. NULL when suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.other_registered_universe; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.other_registered_universe IS 'StepZero registered + registered & accredited BHWs on the other side — the profiling-eligible base, and the ONLY denominator other_n_profiled may be divided by. NULL when suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.listed_n_profiled; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.listed_n_profiled IS 'Individually-profiled BHWs (agg_bhw_counts.n_total) on the listed side. CONTEXT, NOT AN INDICATOR: it exists so the page can state how far profiling coverage differs between the two groups, which is why the per-person census is not what this table splits. Never report it as BHW supply. NULL when suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.other_n_profiled; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.other_n_profiled IS 'Individually-profiled BHWs on the other side. CONTEXT, NOT AN INDICATOR — see listed_n_profiled. NULL when suppressed.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.unallocated_n_bhw; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.unallocated_n_bhw IS 'BHWs in this area''s own StepZero row that are in neither side, because they sit in barangay rows StepZero carries above barangay grain only. 16 nationally, in 3 regions. listed + other + unallocated equals the area total exactly, asserted at load.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.unallocated_households; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.unallocated_households IS 'Households in this area''s own StepZero row that are in neither side. 6,061 nationally, in 3 regions. See unallocated_n_bhw.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.listed_is_suppressed; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.listed_is_suppressed IS 'True when 0 < n_listed_with_data < 5 and the listed-side measures were nulled. A PRESENTATION RULE, NOT A DISCLOSURE CONTROL: agg_bhw_stepzero_counts is public at barangay grain, so nothing here is secret. What it prevents is one, two or three barangays being rendered as a group statistic and compared against hundreds.';


--
-- Name: COLUMN agg_bhw_by_uuc_status.other_is_suppressed; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_bhw_by_uuc_status.other_is_suppressed IS 'True when 0 < n_other_with_data < 5 and the other-side measures were nulled. See listed_is_suppressed.';


--
-- Name: agg_bhw_by_uuc_status_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_by_uuc_status ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_bhw_by_uuc_status_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_bhw_counts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_bhw_counts (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_total integer,
    n_accredited integer,
    pct_accredited numeric,
    avg_active_years numeric,
    any_honorarium_pct numeric,
    ci_low numeric,
    ci_high numeric,
    adjusted_pct numeric
);


--
-- Name: agg_bhw_counts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_counts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_bhw_counts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_bhw_profiling_status; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_bhw_profiling_status (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_registered integer DEFAULT 0 NOT NULL,
    n_accredited integer DEFAULT 0 NOT NULL,
    n_unregistered integer DEFAULT 0 NOT NULL,
    n_total_bhw integer DEFAULT 0 NOT NULL,
    n_drafted integer DEFAULT 0 NOT NULL,
    n_for_validation integer DEFAULT 0 NOT NULL,
    n_back_to_encoder integer DEFAULT 0 NOT NULL,
    n_validated integer DEFAULT 0 NOT NULL,
    n_approved integer DEFAULT 0 NOT NULL
);


--
-- Name: agg_bhw_profiling_status_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_profiling_status ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_bhw_profiling_status_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_bhw_stepzero_counts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_bhw_stepzero_counts (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_registered integer,
    n_registered_accredited integer,
    n_non_registered integer,
    n_total_bhw integer,
    pct_registered_accredited numeric,
    population integer,
    households integer
);


--
-- Name: agg_bhw_stepzero_counts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_stepzero_counts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_bhw_stepzero_counts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_by_income_class; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_by_income_class (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    income_class smallint NOT NULL,
    n_bhw integer NOT NULL,
    n_citymun integer,
    pct_accredited numeric,
    any_honorarium_pct numeric,
    median_honorarium_amount numeric
);


--
-- Name: agg_by_income_class_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_by_income_class ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_by_income_class_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_certification; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_certification (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    cert_type text NOT NULL,
    n integer,
    pct numeric
);


--
-- Name: agg_certification_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_certification ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_certification_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_cohorts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_cohorts (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    kind text NOT NULL,
    cohort_year smallint NOT NULL,
    n integer NOT NULL
);


--
-- Name: agg_cohorts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_cohorts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_cohorts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_data_completeness; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_data_completeness (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    field_name text NOT NULL,
    n_missing integer,
    pct_missing numeric,
    geo_level public.geo_level_enum NOT NULL,
    geo_code text NOT NULL
);


--
-- Name: agg_data_completeness_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_data_completeness ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_data_completeness_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_demographics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_demographics (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    dimension public.demographic_dimension_enum NOT NULL,
    category text NOT NULL,
    n integer,
    pct numeric,
    is_suppressed boolean DEFAULT false NOT NULL,
    rollup_geo_code text,
    rollup_geo_level public.geo_level_enum
);


--
-- Name: agg_demographics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_demographics ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_demographics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_geo_summary; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_geo_summary (
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    geo_name text NOT NULL,
    parent_chain jsonb,
    n_total integer,
    pct_accredited numeric,
    top_training_gap text,
    any_honorarium_pct numeric,
    search_text tsvector
);


--
-- Name: agg_honorarium; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_honorarium (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    payer_level public.payer_level_enum NOT NULL,
    n_receiving integer,
    pct_receiving numeric,
    avg_monthly_amount numeric,
    modal_frequency public.honorarium_frequency_enum,
    min_amount numeric,
    p25_amount numeric,
    median_amount numeric,
    p75_amount numeric,
    max_amount numeric,
    is_suppressed boolean DEFAULT false NOT NULL,
    ci_low numeric,
    ci_high numeric
);


--
-- Name: agg_honorarium_cumulative; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_honorarium_cumulative (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    band_order smallint NOT NULL,
    band_label text NOT NULL,
    n integer,
    pct numeric,
    n_total integer NOT NULL,
    median_cumulative_monthly numeric,
    pct_below_sufficiency numeric,
    is_suppressed boolean DEFAULT false NOT NULL
);


--
-- Name: agg_honorarium_cumulative_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_honorarium_cumulative ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_honorarium_cumulative_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_honorarium_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_honorarium ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_honorarium_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_honorarium_inequality; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_honorarium_inequality (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_receiving integer NOT NULL,
    gini numeric,
    p10_amount numeric,
    p90_amount numeric,
    p90_p10_ratio numeric,
    is_suppressed boolean DEFAULT false NOT NULL
);


--
-- Name: agg_honorarium_inequality_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_honorarium_inequality ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_honorarium_inequality_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_nhfr_by_type; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_nhfr_by_type (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    facility_type text NOT NULL,
    n_facilities integer NOT NULL,
    n_government integer DEFAULT 0 NOT NULL,
    n_private integer DEFAULT 0 NOT NULL
);


--
-- Name: TABLE agg_nhfr_by_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_nhfr_by_type IS 'Facility counts by facility type per area from the NHFR September 2026 snapshot. Sparse: a row exists only where the count is non-zero — read the area total from agg_nhfr_counts. See docs/NHFR_2026_PLAN.md.';


--
-- Name: agg_nhfr_by_type_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_nhfr_by_type ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_nhfr_by_type_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_nhfr_counts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_nhfr_counts (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_facilities integer DEFAULT 0 NOT NULL,
    n_government integer DEFAULT 0 NOT NULL,
    n_private integer DEFAULT 0 NOT NULL,
    n_barangay_health_station integer DEFAULT 0 NOT NULL,
    n_rural_health_unit integer DEFAULT 0 NOT NULL,
    n_hospital integer DEFAULT 0 NOT NULL,
    n_birthing_home integer DEFAULT 0 NOT NULL,
    total_bed_capacity integer DEFAULT 0 NOT NULL,
    n_barangays_with_facility integer DEFAULT 0 NOT NULL,
    n_barangays integer DEFAULT 0 NOT NULL
);


--
-- Name: TABLE agg_nhfr_counts; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_nhfr_counts IS 'Health-facility counts per area from the NHFR September 2026 snapshot, at national/region/province/city-municipality. One row per geo including those with none. No small-cell suppression: this counts places, not people. See docs/NHFR_2026_PLAN.md.';


--
-- Name: agg_nhfr_counts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_nhfr_counts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_nhfr_counts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_peer_ranks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_peer_ranks (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    indicator text NOT NULL,
    value numeric,
    n_total integer,
    rank_position integer,
    n_siblings integer,
    percentile numeric,
    median numeric,
    mad numeric,
    is_outlier boolean DEFAULT false NOT NULL
);


--
-- Name: agg_peer_ranks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.agg_peer_ranks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: agg_peer_ranks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.agg_peer_ranks_id_seq OWNED BY public.agg_peer_ranks.id;


--
-- Name: agg_population; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_population (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    census_year smallint NOT NULL,
    population integer NOT NULL
);


--
-- Name: TABLE agg_population; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_population IS 'PSA census population (POPCEN 2024, CPH 2020) name-matched to dim_geo and rolled up from citymun leaves. Long format: one row per source × geo × census year. E4.2; see docs/EXPLORE_ENHANCEMENT_PLAN.md.';


--
-- Name: agg_population_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_population ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_population_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_poverty; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_poverty (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    sae_year smallint NOT NULL,
    poverty_incidence real NOT NULL,
    cv real,
    se real,
    ci_low real,
    ci_high real
);


--
-- Name: TABLE agg_poverty; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_poverty IS 'PSA Small Area Estimates of poverty (2018/2021/2023) at city/municipality grain, PSGC-matched to dim_geo. One row per geo × SAE year; no rollup (poverty incidence is a rate). E4.4; see docs/EXPLORE_ENHANCEMENT_PLAN.md.';


--
-- Name: agg_poverty_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_poverty ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_poverty_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_training; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_training (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    topic_slug text NOT NULL,
    topic_label text,
    n_trained integer,
    n_total integer,
    coverage_pct numeric,
    median_training_year smallint,
    ci_low numeric,
    ci_high numeric
);


--
-- Name: agg_training_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_training ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_training_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_uuc_phc_counts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_uuc_phc_counts (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_listed integer DEFAULT 0 NOT NULL,
    n_barangays integer DEFAULT 0 NOT NULL
);


--
-- Name: TABLE agg_uuc_phc_counts; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_uuc_phc_counts IS 'Per-geo counts of barangays on the 2025 UUC for PHC list (n_listed) against all barangays in the area (n_barangays, from dim_geo). Rolled up national/region/province/citymun. Share derived in the read layer. See docs/UUC_PHC_2025_PLAN.md.';


--
-- Name: agg_uuc_phc_counts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_uuc_phc_counts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_uuc_phc_counts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_uuc_phc_criteria; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_uuc_phc_criteria (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_listed integer DEFAULT 0 NOT NULL,
    n_route_ip integer DEFAULT 0 NOT NULL,
    n_route_conflict integer DEFAULT 0 NOT NULL,
    n_route_four_ps integer DEFAULT 0 NOT NULL,
    n_route_health integer DEFAULT 0 NOT NULL,
    n_health_evaluable integer DEFAULT 0 NOT NULL
);


--
-- Name: TABLE agg_uuc_phc_criteria; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_uuc_phc_criteria IS 'Per-geo counts of listed barangays qualifying by each socio-economic route of DOH AO No. 2020-0023 §VI.A. The four route counts OVERLAP and do not sum to n_listed — a barangay can qualify on several — so they must be rendered as four independent shares, never stacked or as a pie. Route (d) counts only barangays whose provincial reference can support the comparison: its denominator is n_health_evaluable, not n_listed. See docs/UUC_PHC_2025_PLAN.md §9 U7.';


--
-- Name: COLUMN agg_uuc_phc_criteria.n_route_conflict; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_criteria.n_route_conflict IS 'Criterion (b): armed_conf + idp >= 10, or the barangay is ELCAC-designated. The two are summed rather than read as the order''s "or" — that is what reproduces the source''s own Pass/Fail on every row it scored. See docs/UUC_PHC_2025_PLAN.md §1a.';


--
-- Name: COLUMN agg_uuc_phc_criteria.n_route_health; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_criteria.n_route_health IS 'Criterion (d): the source''s own health_indicators score is at least 4 of 7, counted only among barangays whose provincial reference can support the comparison. Its denominator is n_health_evaluable, never n_listed.';


--
-- Name: COLUMN agg_uuc_phc_criteria.n_health_evaluable; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_criteria.n_health_evaluable IS 'Listed barangays in this area whose provincial reference can support criterion (d). Nationally 5,761 of 5,987: 226 barangays in 5 provinces carry benchmarks that are placeholders, zeroes, missing or fractions. n_listed - n_health_evaluable is the excluded count, derived in the read layer. One further barangay — SUMISIP CENTRAL, added by the final-list alignment — is counted evaluable, because Basilan''s benchmarks are real, but carries no recorded criterion (d) score of its own, so route (d) does not count it.';


--
-- Name: agg_uuc_phc_criteria_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_uuc_phc_criteria ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_uuc_phc_criteria_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_uuc_phc_indicator_dist; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_uuc_phc_indicator_dist (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    indicator text NOT NULL,
    value_max numeric NOT NULL,
    n_listed integer DEFAULT 0 NOT NULL,
    bin_counts integer[] DEFAULT '{0,0,0,0,0,0,0,0,0,0}'::integer[] NOT NULL,
    bin_capped integer[] DEFAULT '{0,0,0,0,0,0,0,0,0,0}'::integer[] NOT NULL,
    n_missing integer DEFAULT 0 NOT NULL,
    provincial_ref numeric,
    n_comparable integer DEFAULT 0 NOT NULL,
    n_worse integer DEFAULT 0 NOT NULL,
    CONSTRAINT agg_uuc_phc_indicator_dist_bins_check CHECK (((array_length(bin_counts, 1) = 10) AND (array_length(bin_capped, 1) = 10))),
    CONSTRAINT agg_uuc_phc_indicator_dist_indicator_check CHECK ((indicator = ANY (ARRAY['physical_factor'::text, 'ip_pop'::text, 'armed_conf'::text, 'idp'::text, 'four_ps'::text, 'imr'::text, 'ufmr'::text, 'fic'::text, 'abr'::text, 'pre_natal'::text, 'sba'::text, 'water'::text])))
);


--
-- Name: TABLE agg_uuc_phc_indicator_dist; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.agg_uuc_phc_indicator_dist IS 'Per-geo, per-indicator distribution of the 2025 UUC for PHC indicator values: ten equal-width bins over [0, value_max], with the values bounded during cleaning counted separately in bin_capped. Publishes NO mean, median or any other summary statistic, and none may be derived from it — the bounded values are ceilings the source overshot, and an average absorbs them into a figure the data does not support. A distribution keeps each value at its own position, which is why this aggregate is publishable where a mean is not. See docs/UUC_PHC_2025_PLAN.md §9 U9.';


--
-- Name: COLUMN agg_uuc_phc_indicator_dist.value_max; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_indicator_dist.value_max IS 'Top of this indicator''s domain and the cap cleaning bounded it to: 100 for coverage percentages, 1,000 for rates per 1,000. Bin width is value_max/10.';


--
-- Name: COLUMN agg_uuc_phc_indicator_dist.bin_counts; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_indicator_dist.bin_counts IS 'Ten equal-width bins over [0, value_max], index 1 being [0, value_max/10). The top bin closes inclusive, so a capped value lands in it. Sums with n_missing to n_listed.';


--
-- Name: COLUMN agg_uuc_phc_indicator_dist.bin_capped; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_indicator_dist.bin_capped IS 'How many of each bin''s barangays carry a value that was BOUNDED during cleaning rather than measured — a subset of bin_counts, and zero outside the top bin by construction. Nationally 886 Water and 456 FIC. A top bin is not a statement about coverage until this count is subtracted from it or stated beside it.';


--
-- Name: COLUMN agg_uuc_phc_indicator_dist.provincial_ref; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_indicator_dist.provincial_ref IS 'The provincial benchmark criterion (d) tests this indicator against. Populated ONLY on province and citymun rows of the seven health indicators — a region or the nation spans 87 different benchmarks and has no single one. NULL for the five socio-economic indicators, which criterion (d) does not test. Do not treat it as usable without checking it against value_max (an FIC benchmark above 100 cannot be reached by a capped barangay value) and against n_comparable (a benchmark present with n_comparable 0 is a placeholder set).';


--
-- Name: COLUMN agg_uuc_phc_indicator_dist.n_comparable; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_indicator_dist.n_comparable IS 'Listed barangays here whose criterion (d) comparison can be made for this indicator: a value is recorded, the province supplied a benchmark, the benchmark does not exceed value_max, and the province''s benchmark set is not a placeholder. Nationally 5,761 of 5,987 for six health indicators and 5,648 for FIC.';


--
-- Name: COLUMN agg_uuc_phc_indicator_dist.n_worse; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.agg_uuc_phc_indicator_dist.n_worse IS 'Of n_comparable, how many perform worse than their province on this indicator — higher for the three rates, lower for the four coverage percentages. A COUNT, deliberately never a share: evaluable denominators differ between areas for data-quality reasons, so a percentage would invite comparisons the data cannot carry.';


--
-- Name: agg_uuc_phc_indicator_dist_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_uuc_phc_indicator_dist ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_uuc_phc_indicator_dist_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_workload; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agg_workload (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    n_bhw integer NOT NULL,
    p10 numeric,
    p25 numeric,
    median numeric,
    p75 numeric,
    p90 numeric,
    mean numeric,
    busiest_decile_share numeric,
    is_suppressed boolean DEFAULT false NOT NULL
);


--
-- Name: agg_workload_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.agg_workload ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.agg_workload_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ai_ask_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_ask_cache (
    cache_key text NOT NULL,
    question_norm text NOT NULL,
    question_display text NOT NULL,
    geo_code text,
    answer_md text NOT NULL,
    provider text,
    data_version text NOT NULL,
    status text DEFAULT 'auto'::text NOT NULL,
    hit_count integer DEFAULT 0 NOT NULL,
    generated_at timestamp with time zone DEFAULT now() NOT NULL,
    last_hit_at timestamp with time zone,
    dataset_slug text NOT NULL
);


--
-- Name: ai_ask_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_ask_log (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    session_id uuid NOT NULL,
    question_raw text NOT NULL,
    question_norm text NOT NULL,
    geo_code text,
    geo_level text,
    turn_index integer NOT NULL,
    answer_md text,
    outcome text NOT NULL,
    provider text,
    served_from text DEFAULT 'live'::text NOT NULL,
    data_version text,
    tool_trace jsonb,
    latency_ms integer,
    dataset_slug text
);


--
-- Name: ai_ask_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ai_ask_log ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ai_ask_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ai_narrative_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_narrative_cache (
    cache_key text NOT NULL,
    content_md text,
    provider text,
    model text,
    generated_at timestamp with time zone DEFAULT now() NOT NULL,
    data_version text
);


--
-- Name: ai_provider_quota; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_provider_quota (
    id bigint NOT NULL,
    provider text NOT NULL,
    window_type public.quota_window_enum NOT NULL,
    window_start timestamp with time zone NOT NULL,
    request_count integer DEFAULT 0 NOT NULL,
    limit_value integer NOT NULL,
    is_paused boolean DEFAULT false NOT NULL,
    paused_until timestamp with time zone
);


--
-- Name: ai_provider_quota_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ai_provider_quota ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ai_provider_quota_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ai_regression_case; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_regression_case (
    case_id bigint NOT NULL,
    question text NOT NULL,
    conversation jsonb,
    answer_given text,
    tool_calls jsonb DEFAULT '[]'::jsonb NOT NULL,
    citations jsonb DEFAULT '[]'::jsonb NOT NULL,
    provider text,
    note text,
    source text DEFAULT 'reported'::text NOT NULL,
    status text DEFAULT 'open'::text NOT NULL,
    reported_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    expectations jsonb DEFAULT '[]'::jsonb NOT NULL,
    harvest_key text,
    harvest_fingerprint text,
    harvest_last_seen_at timestamp with time zone,
    CONSTRAINT ai_regression_case_answer_pairing CHECK (((conversation IS NULL) = (answer_given IS NULL))),
    CONSTRAINT ai_regression_case_expectations_well_formed CHECK (public.ai_regression_expectation_well_formed(expectations)),
    CONSTRAINT ai_regression_case_harvest_columns CHECK ((((source = 'harvested'::text) = (harvest_key IS NOT NULL)) AND ((harvest_key IS NULL) = (harvest_fingerprint IS NULL)) AND ((harvest_key IS NULL) = (harvest_last_seen_at IS NULL)))),
    CONSTRAINT ai_regression_case_provider_needs_answer CHECK (((provider IS NULL) OR (answer_given IS NOT NULL))),
    CONSTRAINT ai_regression_case_source_check CHECK ((source = ANY (ARRAY['reported'::text, 'seeded'::text, 'harvested'::text]))),
    CONSTRAINT ai_regression_case_status_check CHECK ((status = ANY (ARRAY['open'::text, 'fixed'::text, 'invalid'::text, 'wontfix'::text])))
);


--
-- Name: COLUMN ai_regression_case.expectations; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ai_regression_case.expectations IS 'Assertions about the payloads the recorded tool calls return: [{call, tool, where?, field, value}]. Each is scored met / unmet / unresolved separately, so a replay reports which figure moved rather than that the payload differs.';


--
-- Name: COLUMN ai_regression_case.harvest_key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ai_regression_case.harvest_key IS 'The ai_ask_cache.cache_key this case was harvested from. Unique, so a second harvest cannot duplicate a case by construction rather than by a query remembering to check.';


--
-- Name: COLUMN ai_regression_case.harvest_fingerprint; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ai_regression_case.harvest_fingerprint IS 'md5 over the approved answer and its tool trace. A change means the source row was edited or re-answered; the case is rebuilt and its pins are cleared, because they described the old answer.';


--
-- Name: COLUMN ai_regression_case.harvest_last_seen_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ai_regression_case.harvest_last_seen_at IS 'When the last harvest reproduced this case from its source row. An older stamp than the latest run means the source no longer vouches for it — reported as stale, never deleted.';


--
-- Name: ai_regression_case_case_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ai_regression_case ALTER COLUMN case_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ai_regression_case_case_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ai_regression_run; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_regression_run (
    run_id bigint NOT NULL,
    started_at timestamp with time zone NOT NULL,
    finished_at timestamp with time zone NOT NULL,
    duration_ms integer NOT NULL,
    cases_open integer NOT NULL,
    cases_replayed integer NOT NULL,
    ok integer NOT NULL,
    degraded integer NOT NULL,
    broken integer NOT NULL,
    pins integer NOT NULL,
    pins_met integer NOT NULL,
    pins_unmet integer NOT NULL,
    pins_unresolved integer NOT NULL,
    outcome text NOT NULL,
    findings_digest text NOT NULL,
    cases jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT ai_regression_run_outcome_check CHECK ((outcome = ANY (ARRAY['clean'::text, 'moved'::text, 'structural'::text])))
);


--
-- Name: ai_regression_run_run_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ai_regression_run ALTER COLUMN run_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ai_regression_run_run_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: changelog_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.changelog_entries (
    id bigint NOT NULL,
    published_at timestamp with time zone DEFAULT now() NOT NULL,
    title text NOT NULL,
    body_md text NOT NULL
);


--
-- Name: changelog_entries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.changelog_entries ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.changelog_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: dataset_column; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dataset_column (
    column_id bigint NOT NULL,
    registry_id bigint NOT NULL,
    column_name text NOT NULL,
    ordinal integer NOT NULL,
    data_type text NOT NULL,
    allowed_values text[],
    meaning text NOT NULL,
    unit text,
    role text NOT NULL,
    is_join_key boolean DEFAULT false NOT NULL,
    joins_to text,
    is_queryable boolean DEFAULT true NOT NULL,
    distinct_count bigint,
    null_rate numeric,
    min_value text,
    max_value text,
    sample_values text[],
    profiled_at timestamp with time zone,
    status text DEFAULT 'auto'::text NOT NULL,
    CONSTRAINT dataset_column_null_rate_check CHECK (((null_rate IS NULL) OR ((null_rate >= (0)::numeric) AND (null_rate <= (1)::numeric)))),
    CONSTRAINT dataset_column_role_check CHECK ((role = ANY (ARRAY['key'::text, 'dimension'::text, 'measure'::text, 'meta'::text]))),
    CONSTRAINT dataset_column_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: dataset_column_column_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.dataset_column ALTER COLUMN column_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.dataset_column_column_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: dataset_registry; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dataset_registry (
    registry_id bigint NOT NULL,
    table_name text NOT NULL,
    title text NOT NULL,
    summary text NOT NULL,
    grain text NOT NULL,
    dataset_slug text,
    exposure text DEFAULT 'internal'::text NOT NULL,
    row_estimate bigint,
    source_kind text DEFAULT 'profiled'::text NOT NULL,
    status text DEFAULT 'auto'::text NOT NULL,
    notes_md text,
    doc_path text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT dataset_registry_exposure_check CHECK ((exposure = ANY (ARRAY['public'::text, 'internal'::text]))),
    CONSTRAINT dataset_registry_source_kind_check CHECK ((source_kind = ANY (ARRAY['hand_written'::text, 'profiled'::text]))),
    CONSTRAINT dataset_registry_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: dataset_registry_registry_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.dataset_registry ALTER COLUMN registry_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.dataset_registry_registry_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: dim_dataset; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dim_dataset (
    dataset_id bigint NOT NULL,
    slug text NOT NULL,
    name text NOT NULL,
    source_name text,
    source_url text,
    license text,
    methodology_md text,
    geo_join_level public.geo_level_enum,
    as_of_date date,
    version text,
    last_updated_at timestamp with time zone DEFAULT now() NOT NULL,
    status text
);


--
-- Name: dim_dataset_dataset_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.dim_dataset ALTER COLUMN dataset_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.dim_dataset_dataset_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: dim_geo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dim_geo (
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    geo_name text NOT NULL,
    parent_code text,
    region_code text,
    province_code text,
    citymun_code text,
    income_class smallint,
    psgc_vintage text,
    income_class_prior smallint
);


--
-- Name: COLUMN dim_geo.income_class; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.dim_geo.income_class IS 'LGU income class. Refreshed by E4.3 to DOF DO 074-2024 (RA 11964, 5-class, eff. 2025-01-01) where classified; a few LGUs retain the prior value (see dim_lgu_income_reclass / income_class_prior).';


--
-- Name: COLUMN dim_geo.income_class_prior; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.dim_geo.income_class_prior IS 'Pre-reclassification income class (StepZero-reported, ~DO 23-08 vintage) preserved by E4.3 before dim_geo.income_class was refreshed to DOF DO 074-2024.';


--
-- Name: dim_legislative_district; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dim_legislative_district (
    district_code text NOT NULL,
    congress_no smallint NOT NULL,
    district_name text NOT NULL,
    ordinal smallint,
    is_lone boolean DEFAULT false NOT NULL,
    parent_geo_code text,
    region_code text,
    wikidata_qid text,
    psa_population integer,
    valid_from date NOT NULL,
    valid_to date,
    source_kind text NOT NULL,
    source_ref text NOT NULL,
    retrieved_at timestamp with time zone NOT NULL,
    status text DEFAULT 'auto'::text NOT NULL,
    CONSTRAINT dim_legislative_district_source_kind_check CHECK ((source_kind = ANY (ARRAY['wikidata'::text, 'wikipedia'::text, 'psa'::text, 'manual'::text, 'public_correction'::text]))),
    CONSTRAINT dim_legislative_district_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: dim_lgu_income_reclass; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dim_lgu_income_reclass (
    reclass_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    dof_kind text NOT NULL,
    old_class_dof smallint,
    new_class smallint,
    converted boolean DEFAULT false NOT NULL,
    match_method text NOT NULL,
    match_score smallint,
    dataset_id bigint,
    CONSTRAINT dim_lgu_income_reclass_dof_kind_check CHECK ((dof_kind = ANY (ARRAY['province'::text, 'city'::text, 'municipality'::text]))),
    CONSTRAINT dim_lgu_income_reclass_match_method_check CHECK ((match_method = ANY (ARRAY['fuzzy'::text, 'province'::text, 'override'::text]))),
    CONSTRAINT dim_lgu_income_reclass_new_class_check CHECK (((new_class >= 1) AND (new_class <= 5)))
);


--
-- Name: TABLE dim_lgu_income_reclass; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dim_lgu_income_reclass IS 'DOF DO 074-2024 (RA 11964) income reclassification, one row per Annex A LGU mapped onto dim_geo. E4.3; see docs/INCOME_RECLASS.md.';


--
-- Name: dim_lgu_income_reclass_reclass_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.dim_lgu_income_reclass ALTER COLUMN reclass_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.dim_lgu_income_reclass_reclass_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: dim_psgc_crosswalk; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dim_psgc_crosswalk (
    crosswalk_id bigint NOT NULL,
    old_code text NOT NULL,
    new_code text,
    geo_level public.geo_level_enum NOT NULL,
    old_vintage text NOT NULL,
    new_vintage text NOT NULL,
    change_kind text NOT NULL,
    old_name text,
    new_name text,
    note text,
    dataset_id bigint,
    CONSTRAINT dim_psgc_crosswalk_change_kind_check CHECK ((change_kind = ANY (ARRAY['region_reassignment'::text, 'renamed'::text, 'renumbered'::text, 'created'::text, 'abolished'::text, 'merged'::text, 'split'::text, 'reclassified'::text, 'converted'::text])))
);


--
-- Name: TABLE dim_psgc_crosswalk; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dim_psgc_crosswalk IS 'Maps a source-vintage PSGC code (old_code) onto dim_geo''s current code (new_code) so external loads on other PSGC vintages join without silent loss. E4.1 infrastructure; see docs/PSGC_CROSSWALK.md.';


--
-- Name: dim_psgc_crosswalk_crosswalk_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.dim_psgc_crosswalk ALTER COLUMN crosswalk_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.dim_psgc_crosswalk_crosswalk_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: district_correction; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.district_correction (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    session_id uuid NOT NULL,
    action text NOT NULL,
    district_code text,
    to_district_code text,
    geo_code text,
    rationale text NOT NULL,
    evidence_url text,
    submitter_email text,
    status text DEFAULT 'open'::text NOT NULL,
    reviewed_at timestamp with time zone,
    reviewed_by text,
    review_note text,
    CONSTRAINT district_correction_action_check CHECK ((action = ANY (ARRAY['add'::text, 'remove'::text, 'move'::text, 'rename'::text, 'other'::text]))),
    CONSTRAINT district_correction_rationale_check CHECK ((char_length(rationale) <= 2000)),
    CONSTRAINT district_correction_status_check CHECK ((status = ANY (ARRAY['open'::text, 'accepted'::text, 'rejected'::text, 'duplicate'::text])))
);


--
-- Name: district_correction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.district_correction ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.district_correction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: district_representative; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.district_representative (
    id bigint NOT NULL,
    district_code text NOT NULL,
    congress_no smallint NOT NULL,
    full_name text NOT NULL,
    party text,
    as_of date NOT NULL,
    source_kind text NOT NULL,
    source_ref text NOT NULL,
    superseded_by bigint,
    status text DEFAULT 'auto'::text NOT NULL,
    CONSTRAINT district_representative_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: district_representative_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.district_representative ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.district_representative_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: doc_chunk; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doc_chunk (
    chunk_id bigint NOT NULL,
    doc_id bigint NOT NULL,
    chunk_index integer NOT NULL,
    page_from integer NOT NULL,
    page_to integer NOT NULL,
    char_start integer NOT NULL,
    char_end integer NOT NULL,
    content text NOT NULL,
    content_sha256 text NOT NULL,
    heading text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT doc_chunk_char_start_check CHECK ((char_start >= 0)),
    CONSTRAINT doc_chunk_chunk_index_check CHECK ((chunk_index >= 0)),
    CONSTRAINT doc_chunk_offsets_match CHECK (((char_end - char_start) = length(content))),
    CONSTRAINT doc_chunk_page_from_check CHECK ((page_from >= 1)),
    CONSTRAINT doc_chunk_page_order CHECK ((page_to >= page_from)),
    CONSTRAINT doc_chunk_page_to_check CHECK ((page_to >= 1))
);


--
-- Name: doc_chunk_chunk_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.doc_chunk ALTER COLUMN chunk_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.doc_chunk_chunk_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: doc_chunk_embedding; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doc_chunk_embedding (
    chunk_id bigint NOT NULL,
    model text NOT NULL,
    dim integer NOT NULL,
    embedding extensions.vector NOT NULL,
    embedded_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT doc_chunk_embedding_dim_matches CHECK ((extensions.vector_dims(embedding) = dim))
);


--
-- Name: doc_embedding_model; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doc_embedding_model (
    model text NOT NULL,
    provider text NOT NULL,
    dim integer NOT NULL,
    distance text DEFAULT 'cosine'::text NOT NULL,
    notes_md text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT doc_embedding_model_dim_check CHECK (((dim >= 1) AND (dim <= 16000))),
    CONSTRAINT doc_embedding_model_distance_check CHECK ((distance = ANY (ARRAY['cosine'::text, 'l2'::text, 'ip'::text])))
);


--
-- Name: doc_source; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doc_source (
    doc_id bigint NOT NULL,
    key text NOT NULL,
    title text NOT NULL,
    source_path text NOT NULL,
    source_sha256 text NOT NULL,
    media_type text DEFAULT 'application/pdf'::text NOT NULL,
    page_count integer NOT NULL,
    char_count integer NOT NULL,
    issuer text,
    as_of date,
    extractor text NOT NULL,
    exposure text DEFAULT 'internal'::text NOT NULL,
    status text DEFAULT 'auto'::text NOT NULL,
    notes_md text,
    ingested_at timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT doc_source_char_count_check CHECK ((char_count >= 0)),
    CONSTRAINT doc_source_exposure_check CHECK ((exposure = ANY (ARRAY['public'::text, 'internal'::text]))),
    CONSTRAINT doc_source_page_count_check CHECK ((page_count > 0)),
    CONSTRAINT doc_source_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: doc_source_doc_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.doc_source ALTER COLUMN doc_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.doc_source_doc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_bhw_raw; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_bhw_raw (
    bhw_id bigint NOT NULL,
    geo_code text NOT NULL,
    sex text,
    civil_status text,
    age smallint,
    bloodtype text,
    educational_attainment text,
    ip_status text,
    household smallint,
    registered_year smallint,
    accredited boolean,
    accreditation_year smallint,
    tesda_nc2 boolean,
    tesda_nc2_year smallint,
    tesda_certified boolean,
    tesda_certified_year smallint,
    ref_manual_trained boolean,
    ref_manual_year smallint,
    active_years smallint[],
    active_years_count smallint,
    first_active_year smallint,
    last_active_year smallint,
    inactive_years smallint[],
    inactive_years_count smallint,
    training jsonb,
    ingestion_batch_id bigint
);


--
-- Name: fact_bhw_raw_bhw_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_bhw_raw ALTER COLUMN bhw_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_bhw_raw_bhw_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_fhsis_indicator; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_fhsis_indicator (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    indicator_key text NOT NULL,
    breakdown text DEFAULT 'total'::text NOT NULL,
    numerator integer,
    denominator integer,
    rate_pct numeric,
    over_100 boolean DEFAULT false NOT NULL,
    source_psgc text,
    source_area_name text,
    numerator_exact numeric,
    denominator_exact numeric
);


--
-- Name: TABLE fact_fhsis_indicator; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.fact_fhsis_indicator IS 'DOH FHSIS 2025 annual release: 20 service-delivery indicators at national, region, province and city/municipality grain, with the source''s own sex and age-band breakdowns. One row per (dataset, geography, indicator, breakdown). NEVER AVERAGE rate_pct — read the published row for the area instead; every grain is loaded. A rate above 100 is stored as published with over_100 = true and is never capped. READ ref_fhsis_indicator.unit FIRST: the TB notification rates are per 100,000, not percentages. A MISSING (geo, indicator, breakdown) MEANS NOT REPORTED, NOT ZERO. Denominators are DOH projections, this dataset''s own base and nothing else''s. See docs/FHSIS_2025_PLAN.md.';


--
-- Name: COLUMN fact_fhsis_indicator.numerator_exact; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.fact_fhsis_indicator.numerator_exact IS 'Published source value, including decimals. Prefer this to legacy numerator when present.';


--
-- Name: COLUMN fact_fhsis_indicator.denominator_exact; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.fact_fhsis_indicator.denominator_exact IS 'Published denominator, including fractional projections. Prefer this to legacy denominator when present.';


--
-- Name: fact_fhsis_indicator_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_fhsis_indicator ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_fhsis_indicator_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_fhsis_workforce; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_fhsis_workforce (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    cadre text NOT NULL,
    lgu_hired integer,
    doh_hired integer,
    total integer,
    population_2025 integer,
    households_2025 integer,
    source_psgc text,
    source_area_name text,
    CONSTRAINT fact_fhsis_workforce_never_bhw CHECK ((cadre <> 'bhw'::text))
);


--
-- Name: TABLE fact_fhsis_workforce; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.fact_fhsis_workforce IS 'DOH FHSIS 2025: the public health workforce by city/municipality, province, region and nationally — 8 cadres, each split LGU-hired and DOH-hired. THE BHW CADRE DOES NOT EXIST IN THIS TABLE, AND IS NOT MERELY HIDDEN: FHSIS never supplies a BHW count for this site (owner decision). The source''s BHW column reports 1 for Las Piñas and 4,454 for all of NCR — a tally of LGU filings, not a census. This site''s BHW figure is the bhw-2025 census with the StepZero quick-count as the universe; never answer that a FHSIS BHW count is unavailable, because there is no such figure here. population_2025 and households_2025 are the SOURCE''S OWN DOH projections, this dataset''s base and nothing else''s — BHW-per-capita figures on this site read StepZero''s population (agg_bhw_stepzero_counts) with agg_population as the fallback, never these columns. See docs/FHSIS_2025_PLAN.md.';


--
-- Name: fact_fhsis_workforce_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_fhsis_workforce ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_fhsis_workforce_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_honorarium; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_honorarium (
    id bigint NOT NULL,
    bhw_id bigint NOT NULL,
    payer_level public.payer_level_enum NOT NULL,
    receives boolean NOT NULL,
    amount numeric,
    frequency public.honorarium_frequency_enum,
    normalized_monthly_amount numeric,
    source_note text
);


--
-- Name: fact_honorarium_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_honorarium ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_honorarium_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_nhfr_facility; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_nhfr_facility (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    facility_code text NOT NULL,
    facility_code_short text,
    facility_name text NOT NULL,
    facility_major_type text NOT NULL,
    facility_type text NOT NULL,
    ownership_major text NOT NULL,
    ownership_sub text,
    geo_code text NOT NULL,
    barangay_geo_code text,
    source_region_psgc text,
    source_region_name text,
    source_province_psgc text,
    source_province_name text,
    source_citymun_psgc text,
    source_citymun_name text,
    source_barangay_psgc text,
    source_barangay_name text,
    service_capability text,
    bed_capacity integer DEFAULT 0 NOT NULL,
    licensing_status text,
    license_validity_date date
);


--
-- Name: TABLE fact_nhfr_facility; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.fact_nhfr_facility IS 'The 44,799 health facilities on the DOH National Health Facility Registry as of September 2026 (public export from nhfr.doh.gov.ph, retrieved 2026-09-05). One row per facility. Contact and street-address columns are deliberately excluded — see ingestion/clean_nhfr.py. See docs/NHFR_2026_PLAN.md.';


--
-- Name: fact_nhfr_facility_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_nhfr_facility ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_nhfr_facility_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_uuc_phc_barangay; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_uuc_phc_barangay (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    source_geo_code text NOT NULL,
    source_region text,
    source_province text,
    source_citymun text,
    source_barangay text
);


--
-- Name: TABLE fact_uuc_phc_barangay; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.fact_uuc_phc_barangay IS 'The 5,987 barangays on the 2025 Unserved and Underserved Communities for Primary Health Care list (DC No. 2025-0549; criteria per DOH AO No. 2020-0023). Presence = listed. See docs/UUC_PHC_2025_PLAN.md.';


--
-- Name: fact_uuc_phc_barangay_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_uuc_phc_barangay ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_uuc_phc_barangay_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fact_uuc_phc_indicators; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_uuc_phc_indicators (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    physical_factor numeric,
    ip_pop numeric,
    armed_conf numeric,
    idp numeric,
    four_ps numeric,
    elcac_brgy boolean,
    imr numeric,
    ufmr numeric,
    fic numeric,
    abr numeric,
    pre_natal numeric,
    sba numeric,
    water numeric,
    imr_prov_ref numeric,
    ufmr_prov_ref numeric,
    fic_prov_ref numeric,
    abr_prov_ref numeric,
    pre_natal_prov_ref numeric,
    sba_prov_ref numeric,
    water_prov_ref numeric,
    capped_indicators text[] DEFAULT '{}'::text[] NOT NULL,
    health_indicators smallint
);


--
-- Name: TABLE fact_uuc_phc_indicators; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.fact_uuc_phc_indicators IS 'Per-barangay indicator values behind the 2025 UUC for PHC list, with the provincial benchmarks criterion (d) compares against. capped_indicators names the values bounded during cleaning — a bounded value is indistinguishable from a genuine one without it. See docs/UUC_PHC_2025_CLEANING_REPORT.md.';


--
-- Name: COLUMN fact_uuc_phc_indicators.health_indicators; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.fact_uuc_phc_indicators.health_indicators IS 'The source office''s own criterion (d) score, 0-7: how many of the seven health assessments this barangay failed against its province. Loaded as supplied, never recomputed — the source scored it against the values before cleaning bounded them, so recomputing from the published columns disagrees on 664 rows and leaves 98 listed barangays qualifying on no route at all. Treat it as a recorded classification, not a derived measurement.';


--
-- Name: fact_uuc_phc_indicators_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.fact_uuc_phc_indicators ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.fact_uuc_phc_indicators_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: feedback; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feedback (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    page_path text NOT NULL,
    category public.feedback_category_enum NOT NULL,
    message text NOT NULL,
    email text,
    session_id uuid NOT NULL,
    status public.feedback_status_enum DEFAULT 'open'::public.feedback_status_enum NOT NULL,
    page_url text,
    target_selector text,
    context jsonb,
    screenshot_path text,
    dataset_slug text,
    CONSTRAINT feedback_message_check CHECK ((char_length(message) <= 2000))
);


--
-- Name: COLUMN feedback.dataset_slug; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.feedback.dataset_slug IS 'dim_dataset.slug of the dataset the submitter was looking at, derived from page_path at write time (lib/feedback/dataset.ts). Null on multi-dataset or non-dataset pages, which is an answer rather than a gap. Not a foreign key: it records what was on screen and must outlive a dataset being retired.';


--
-- Name: feedback_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.feedback ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.feedback_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: geo_district_map; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geo_district_map (
    id bigint NOT NULL,
    district_code text NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    match_method text NOT NULL,
    source_kind text NOT NULL,
    source_ref text NOT NULL,
    retrieved_at timestamp with time zone NOT NULL,
    superseded_by bigint,
    status text DEFAULT 'auto'::text NOT NULL,
    reviewed_at timestamp with time zone,
    reviewed_by text,
    review_note text,
    corroboration text DEFAULT 'single_source'::text NOT NULL,
    corroborating_source_ref text,
    CONSTRAINT geo_district_map_corroboration_check CHECK ((corroboration = ANY (ARRAY['single_source'::text, 'corroborated'::text, 'conflict'::text]))),
    CONSTRAINT geo_district_map_geo_level_check CHECK ((geo_level = ANY (ARRAY['citymun'::public.geo_level_enum, 'barangay'::public.geo_level_enum]))),
    CONSTRAINT geo_district_map_match_method_check CHECK ((match_method = ANY (ARRAY['exact'::text, 'disambiguated'::text, 'crosswalk'::text, 'manual_override'::text, 'public_correction'::text, 'whole_parent'::text, 'independent_city'::text, 'whole_citymun'::text, 'psgc_identifier'::text, 'barangay_roster'::text]))),
    CONSTRAINT geo_district_map_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: COLUMN geo_district_map.match_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.geo_district_map.match_method IS 'How this row was resolved. There is deliberately no fuzzy value: guardrail 1 says an unresolved LGU is a published finding and a wrongly-matched one is an invisible lie, so the absence is enforced by this constraint rather than remembered by an implementer. psgc_identifier = the member''s own article states a PSGC code identifying it. barangay_roster = the member''s own article lists barangays matching exactly one candidate in the scoped province -- identity by contents, used where the two sources disagree on the name.';


--
-- Name: COLUMN geo_district_map.corroboration; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.geo_district_map.corroboration IS 'single_source = one source attests this row and it must not ship (guardrail 2); corroborated = two independent sources agree; conflict = they disagree and the row is a finding, not a fact.';


--
-- Name: geo_district_map_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.geo_district_map ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.geo_district_map_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ingestion_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ingestion_batches (
    batch_id bigint NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    finished_at timestamp with time zone,
    source_file text,
    row_counts jsonb,
    qa_report jsonb
);


--
-- Name: ingestion_batches_batch_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ingestion_batches ALTER COLUMN batch_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ingestion_batches_batch_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: kb_contradiction; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_contradiction (
    contradiction_id bigint NOT NULL,
    method text NOT NULL,
    measure_label text NOT NULL,
    doc_id bigint NOT NULL,
    chunk_id bigint NOT NULL,
    page_from integer NOT NULL,
    doc_value numeric NOT NULL,
    doc_as_of date,
    doc_as_of_text text,
    evidence_quote text NOT NULL,
    data_table text NOT NULL,
    data_column text,
    data_stat text NOT NULL,
    data_value numeric NOT NULL,
    data_as_of date,
    geo_code text,
    geo_level text,
    abs_difference numeric NOT NULL,
    rel_difference numeric NOT NULL,
    evidence jsonb DEFAULT '{}'::jsonb NOT NULL,
    status text DEFAULT 'auto'::text NOT NULL,
    review_note text,
    reviewed_by text,
    reviewed_at timestamp with time zone,
    first_seen_at timestamp with time zone DEFAULT now() NOT NULL,
    last_swept_at timestamp with time zone DEFAULT now() NOT NULL,
    scope_key text GENERATED ALWAYS AS (COALESCE(geo_code, '(whole)'::text)) STORED,
    data_column_key text GENERATED ALWAYS AS (COALESCE(data_column, '(table)'::text)) STORED,
    CONSTRAINT kb_contradiction_data_stat_check CHECK ((data_stat = ANY (ARRAY['cell'::text, 'level_total'::text, 'distinct_count'::text]))),
    CONSTRAINT kb_contradiction_geo_pair CHECK (((geo_code IS NULL) = (geo_level IS NULL))),
    CONSTRAINT kb_contradiction_method_check CHECK ((method = ANY (ARRAY['geo_distribution'::text, 'scalar_magnitude'::text]))),
    CONSTRAINT kb_contradiction_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text]))),
    CONSTRAINT kb_contradiction_values_differ CHECK ((doc_value <> data_value))
);


--
-- Name: TABLE kb_contradiction; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.kb_contradiction IS 'Increment 4.2: computed disagreements between a document figure and a registered dataset. Rows land at status = auto and are judged at /admin/kb-review. Per plan §12.4 rule 3 an approved row is a distinction to surface with both as-of dates, not an error to resolve.';


--
-- Name: kb_contradiction_contradiction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.kb_contradiction ALTER COLUMN contradiction_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.kb_contradiction_contradiction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: kb_doc_line; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.kb_doc_line WITH (security_invoker='true') AS
 SELECT c.chunk_id,
    c.doc_id,
    c.page_from,
    d.as_of AS doc_as_of,
    t.ord,
    t.line
   FROM ((public.doc_chunk c
     JOIN public.doc_source d ON (((d.doc_id = c.doc_id) AND (d.status = 'approved'::text))))
     CROSS JOIN LATERAL unnest(string_to_array(c.content, '
'::text)) WITH ORDINALITY t(line, ord));


--
-- Name: kb_doc_label_number; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.kb_doc_label_number WITH (security_invoker='true') AS
 WITH nonblank AS (
         SELECT l.chunk_id,
            l.doc_id,
            l.page_from,
            l.doc_as_of,
            l.ord,
            btrim(l.line) AS line
           FROM public.kb_doc_line l
          WHERE (btrim(l.line) <> ''::text)
        ), paired AS (
         SELECT n.chunk_id,
            n.doc_id,
            n.page_from,
            n.doc_as_of,
            n.ord,
            n.line,
            lead(n.line) OVER (PARTITION BY n.chunk_id ORDER BY n.ord) AS next_line
           FROM nonblank n
        )
 SELECT p.chunk_id,
    p.doc_id,
    p.page_from,
    p.doc_as_of,
    p.ord,
    p.line AS label_raw,
    upper(regexp_replace(p.line, '\s+'::text, ' '::text, 'g'::text)) AS label,
    (replace(p.next_line, ','::text, ''::text))::numeric AS value
   FROM paired p
  WHERE ((p.line ~ '[A-Za-z]'::text) AND ((p.next_line ~ '^[0-9]{1,3}(,[0-9]{3})*$'::text) OR (p.next_line ~ '^[0-9]+$'::text)));


--
-- Name: VIEW kb_doc_label_number; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.kb_doc_label_number IS 'Increment 4.2: label/number line pairs in approved document chunks — the extracted form of a slide table. Used by the geo_distribution pass, whose labels resolve against dim_geo.geo_name.';


--
-- Name: kb_edge; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_edge (
    edge_id bigint NOT NULL,
    src_node_id bigint NOT NULL,
    dst_node_id bigint NOT NULL,
    relation text NOT NULL,
    valid_from date,
    valid_to date,
    origin text DEFAULT 'extracted'::text NOT NULL,
    source_kind text NOT NULL,
    source_ref text NOT NULL,
    source_chunk_id bigint,
    note text,
    status text DEFAULT 'auto'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    evidence_quote text,
    reviewed_at timestamp with time zone,
    reviewed_by text,
    review_note text,
    CONSTRAINT kb_edge_chunk_provenance CHECK (((source_kind = 'chunk'::text) = (source_chunk_id IS NOT NULL))),
    CONSTRAINT kb_edge_no_self_loop CHECK ((src_node_id <> dst_node_id)),
    CONSTRAINT kb_edge_origin_check CHECK ((origin = ANY (ARRAY['asserted'::text, 'extracted'::text]))),
    CONSTRAINT kb_edge_relation_check CHECK ((relation = ANY (ARRAY['derived-from'::text, 'built-by'::text, 'reconciled-in'::text, 'joins-on'::text, 'has-column'::text, 'defined-by'::text, 'issued-by'::text, 'part-of'::text, 'supersedes'::text, 'amends'::text, 'implements'::text]))),
    CONSTRAINT kb_edge_source_kind_check CHECK ((source_kind = ANY (ARRAY['migration'::text, 'ingestion_script'::text, 'registry'::text, 'document'::text, 'chunk'::text]))),
    CONSTRAINT kb_edge_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text]))),
    CONSTRAINT kb_edge_validity_order CHECK (((valid_to IS NULL) OR (valid_from IS NULL) OR (valid_to >= valid_from))),
    CONSTRAINT kb_edge_validity_relations CHECK ((((valid_from IS NULL) AND (valid_to IS NULL)) OR (relation = ANY (ARRAY['supersedes'::text, 'amends'::text, 'implements'::text]))))
);


--
-- Name: COLUMN kb_edge.evidence_quote; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.kb_edge.evidence_quote IS 'The verbatim span of doc_chunk.content that supports this row. Required for source_kind = ''chunk'' and forbidden otherwise; enforced by kb_evidence_is_grounded().';


--
-- Name: kb_edge_edge_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.kb_edge ALTER COLUMN edge_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.kb_edge_edge_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: kb_node; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_node (
    node_id bigint NOT NULL,
    key text NOT NULL,
    kind text NOT NULL,
    label text NOT NULL,
    summary text,
    origin text DEFAULT 'extracted'::text NOT NULL,
    source_kind text NOT NULL,
    source_ref text NOT NULL,
    source_chunk_id bigint,
    status text DEFAULT 'auto'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    evidence_quote text,
    reviewed_at timestamp with time zone,
    reviewed_by text,
    review_note text,
    CONSTRAINT kb_node_chunk_provenance CHECK (((source_kind = 'chunk'::text) = (source_chunk_id IS NOT NULL))),
    CONSTRAINT kb_node_kind_check CHECK ((kind = ANY (ARRAY['dataset'::text, 'table'::text, 'column'::text, 'migration'::text, 'ingestion_script'::text, 'document'::text, 'geography'::text, 'issuance'::text, 'entity'::text, 'program'::text, 'organization'::text]))),
    CONSTRAINT kb_node_origin_check CHECK ((origin = ANY (ARRAY['asserted'::text, 'extracted'::text]))),
    CONSTRAINT kb_node_source_kind_check CHECK ((source_kind = ANY (ARRAY['migration'::text, 'ingestion_script'::text, 'registry'::text, 'document'::text, 'chunk'::text]))),
    CONSTRAINT kb_node_status_check CHECK ((status = ANY (ARRAY['auto'::text, 'approved'::text, 'rejected'::text])))
);


--
-- Name: COLUMN kb_node.evidence_quote; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.kb_node.evidence_quote IS 'The verbatim span of doc_chunk.content that supports this row. Required for source_kind = ''chunk'' and forbidden otherwise; enforced by kb_evidence_is_grounded().';


--
-- Name: kb_node_node_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.kb_node ALTER COLUMN node_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.kb_node_node_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ref_fhsis_indicator; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ref_fhsis_indicator (
    indicator_key text NOT NULL,
    program_area text NOT NULL,
    label text NOT NULL,
    numerator_def text NOT NULL,
    denominator_def text NOT NULL,
    unit text NOT NULL,
    source_workbook text NOT NULL,
    uuc_criterion_d boolean DEFAULT false NOT NULL
);


--
-- Name: TABLE ref_fhsis_indicator; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.ref_fhsis_indicator IS 'The 20 indicators the DOH FHSIS 2025 annual release supplies to this site, with the numerator and denominator definitions taken from each workbook''s own header. READ unit BEFORE RENDERING rate_pct: the TB notification rates are per 100,000 population and are normally in the hundreds, while every other indicator is a percentage that may exceed 100. See docs/FHSIS_2025_PLAN.md and docs/FHSIS_2025_CLEANING_REPORT.md.';


--
-- Name: ref_fhsis_reconciliation; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ref_fhsis_reconciliation WITH (security_invoker='true') AS
 WITH child_of AS (
         SELECT f.dataset_id,
            f.indicator_key,
            f.breakdown,
            f.numerator,
            (
                CASE f.geo_level
                    WHEN 'citymun'::public.geo_level_enum THEN 'province'::text
                    WHEN 'province'::public.geo_level_enum THEN 'region'::text
                    WHEN 'region'::public.geo_level_enum THEN 'national'::text
                    ELSE NULL::text
                END)::public.geo_level_enum AS parent_level,
            g.parent_code
           FROM (public.fact_fhsis_indicator f
             JOIN public.dim_geo g ON ((g.geo_code = f.geo_code)))
          WHERE ((f.geo_level <> 'national'::public.geo_level_enum) AND (f.numerator IS NOT NULL))
        )
 SELECT c.dataset_id,
    c.indicator_key,
    c.breakdown,
    c.parent_level,
    c.parent_code AS parent_geo_code,
    p.geo_name AS parent_geo_name,
    (count(*))::integer AS child_count,
    sum(c.numerator) AS child_sum,
    (parent.numerator)::bigint AS published_parent,
    (parent.numerator - sum(c.numerator)) AS residual,
        CASE
            WHEN ((parent.numerator - sum(c.numerator)) = 0) THEN 'reconciles'::text
            WHEN ((parent.numerator - sum(c.numerator)) > 0) THEN 'leaves short of published parent'::text
            ELSE 'leaves exceed published parent'::text
        END AS finding
   FROM ((child_of c
     JOIN public.dim_geo p ON ((p.geo_code = c.parent_code)))
     JOIN public.fact_fhsis_indicator parent ON (((parent.dataset_id = c.dataset_id) AND (parent.geo_code = c.parent_code) AND (parent.indicator_key = c.indicator_key) AND (parent.breakdown = c.breakdown) AND (parent.numerator IS NOT NULL))))
  GROUP BY c.dataset_id, c.indicator_key, c.breakdown, c.parent_level, c.parent_code, p.geo_name, parent.numerator;


--
-- Name: VIEW ref_fhsis_reconciliation; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.ref_fhsis_reconciliation IS 'Per FHSIS indicator and breakdown: the sum of an area''s children against the subtotal the source published for that area, and the residual between them. Computed on every read. A NON-ZERO RESIDUAL IS USUALLY NOT AN ERROR — for FIC, 78 of 83 comparable provinces reconcile exactly and the other five differ by precisely the figure of an independent component or highly urbanised city that dim_geo nests under the province while the source''s province row excludes it. NEVER DERIVE A PARENT''S FIGURE BY SUMMING ITS CHILDREN: every grain is loaded, so read the published row for the area. This view exists to explain the difference to a reader who adds them up, not to correct either side. See docs/FHSIS_2025_CLEANING_REPORT.md.';


--
-- Name: ref_fhsis_reconciliation_exact; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ref_fhsis_reconciliation_exact WITH (security_invoker='true') AS
 WITH child_of AS (
         SELECT f.dataset_id,
            f.indicator_key,
            f.breakdown,
            COALESCE(f.numerator_exact, (f.numerator)::numeric) AS numerator,
            (
                CASE f.geo_level
                    WHEN 'citymun'::public.geo_level_enum THEN 'province'::text
                    WHEN 'province'::public.geo_level_enum THEN 'region'::text
                    WHEN 'region'::public.geo_level_enum THEN 'national'::text
                    ELSE NULL::text
                END)::public.geo_level_enum AS parent_level,
            g.parent_code
           FROM (public.fact_fhsis_indicator f
             JOIN public.dim_geo g ON ((g.geo_code = f.geo_code)))
          WHERE ((f.geo_level <> 'national'::public.geo_level_enum) AND (COALESCE(f.numerator_exact, (f.numerator)::numeric) IS NOT NULL))
        )
 SELECT c.dataset_id,
    c.indicator_key,
    c.breakdown,
    c.parent_level,
    c.parent_code AS parent_geo_code,
    p.geo_name AS parent_geo_name,
    (count(*))::integer AS child_count,
    sum(c.numerator) AS child_sum,
    COALESCE(parent.numerator_exact, (parent.numerator)::numeric) AS published_parent,
    (COALESCE(parent.numerator_exact, (parent.numerator)::numeric) - sum(c.numerator)) AS residual,
        CASE
            WHEN ((COALESCE(parent.numerator_exact, (parent.numerator)::numeric) - sum(c.numerator)) = (0)::numeric) THEN 'reconciles'::text
            WHEN ((COALESCE(parent.numerator_exact, (parent.numerator)::numeric) - sum(c.numerator)) > (0)::numeric) THEN 'leaves short of published parent'::text
            ELSE 'leaves exceed published parent'::text
        END AS finding
   FROM ((child_of c
     JOIN public.dim_geo p ON ((p.geo_code = c.parent_code)))
     JOIN public.fact_fhsis_indicator parent ON (((parent.dataset_id = c.dataset_id) AND (parent.geo_code = c.parent_code) AND (parent.indicator_key = c.indicator_key) AND (parent.breakdown = c.breakdown) AND (COALESCE(parent.numerator_exact, (parent.numerator)::numeric) IS NOT NULL))))
  GROUP BY c.dataset_id, c.indicator_key, c.breakdown, c.parent_level, c.parent_code, p.geo_name, parent.numerator_exact, parent.numerator;


--
-- Name: ref_uuc_phc_benchmark_gaps; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ref_uuc_phc_benchmark_gaps WITH (security_invoker='true') AS
 WITH brgy AS (
         SELECT i.id,
            i.dataset_id,
            i.geo_code,
            i.physical_factor,
            i.ip_pop,
            i.armed_conf,
            i.idp,
            i.four_ps,
            i.elcac_brgy,
            i.imr,
            i.ufmr,
            i.fic,
            i.abr,
            i.pre_natal,
            i.sba,
            i.water,
            i.imr_prov_ref,
            i.ufmr_prov_ref,
            i.fic_prov_ref,
            i.abr_prov_ref,
            i.pre_natal_prov_ref,
            i.sba_prov_ref,
            i.water_prov_ref,
            i.capped_indicators,
            i.health_indicators,
            g.province_code,
            GREATEST(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref, i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) AS top_ref
           FROM (public.fact_uuc_phc_indicators i
             JOIN public.dim_geo g ON ((g.geo_code = i.geo_code)))
          WHERE (i.dataset_id = ( SELECT dim_dataset.dataset_id
                   FROM public.dim_dataset
                  WHERE (dim_dataset.slug = 'uuc-phc-2025'::text)))
        ), listed_per_province AS (
         SELECT brgy.province_code,
            (count(*))::integer AS n_listed_province
           FROM brgy
          GROUP BY brgy.province_code
        ), placeholder AS (
         SELECT b.province_code,
                CASE
                    WHEN (count(b.top_ref) = 0) THEN 'no reference supplied'::text
                    WHEN (max(b.top_ref) = (0)::numeric) THEN 'every value zero'::text
                    WHEN (max(b.top_ref) = (1)::numeric) THEN 'every value exactly 1'::text
                    ELSE 'fractions where percentages were wanted'::text
                END AS kind,
            (count(*))::integer AS n_affected,
            max(b.top_ref) AS witness_value
           FROM brgy b
          WHERE (COALESCE((b.top_ref > (1)::numeric), false) = false)
          GROUP BY b.province_code
        ), unreachable_fic AS (
         SELECT b.province_code,
            'FIC benchmark above the 100 ceiling barangay values were bounded to'::text AS kind,
            (count(*))::integer AS n_affected,
            max(b.fic_prov_ref) AS witness_value
           FROM brgy b
          WHERE (b.fic_prov_ref > (100)::numeric)
          GROUP BY b.province_code
        )
 SELECT gaps.province_code,
    lp.n_listed_province,
    gaps.kind,
    gaps.n_affected,
    gaps.witness_value,
    gaps.finding
   FROM (( SELECT placeholder.province_code,
            placeholder.kind,
            placeholder.n_affected,
            placeholder.witness_value,
            'criterion_d'::text AS finding
           FROM placeholder
        UNION ALL
         SELECT unreachable_fic.province_code,
            unreachable_fic.kind,
            unreachable_fic.n_affected,
            unreachable_fic.witness_value,
            'fic_only'::text AS finding
           FROM unreachable_fic) gaps
     JOIN listed_per_province lp ON ((lp.province_code = gaps.province_code)));


--
-- Name: VIEW ref_uuc_phc_benchmark_gaps; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.ref_uuc_phc_benchmark_gaps IS 'One row per province whose provincial benchmarks carry a data-quality finding, with WHICH finding it is. finding = criterion_d marks the 226 barangays in 5 provinces whose whole benchmark set cannot support criterion (d) (no reference, zeroes, a placeholder 1, or fractions); finding = fic_only marks the 113 barangays in 2 provinces whose real FIC benchmark exceeds the 100 ceiling barangay FIC was bounded to, which affects that one comparison and not the rest. n_affected is barangays, and is not always the province''s whole listed count: in province 09317 only 7 of 8 lack a reference. Computed from the values, never from a list of province codes.';


--
-- Name: ref_uuc_phc_list; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ref_uuc_phc_list WITH (security_invoker='true') AS
 SELECT b.dataset_id,
    b.geo_code,
    g.geo_name,
    g.citymun_code,
    cm.geo_name AS citymun_name,
    g.province_code,
    pr.geo_name AS province_name,
    g.region_code,
    rg.geo_name AS region_name,
    b.source_geo_code,
    b.source_region,
    b.source_province,
    b.source_citymun,
    b.source_barangay,
    (COALESCE(i.ip_pop, (0)::numeric) >= (10)::numeric) AS route_ip,
    (((COALESCE(i.armed_conf, (0)::numeric) + COALESCE(i.idp, (0)::numeric)) >= (10)::numeric) OR COALESCE(i.elcac_brgy, false)) AS route_conflict,
    (COALESCE(i.four_ps, (0)::numeric) >= (50)::numeric) AS route_four_ps,
    COALESCE((GREATEST(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref, i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > (1)::numeric), false) AS health_evaluable,
    COALESCE(((GREATEST(i.imr_prov_ref, i.ufmr_prov_ref, i.fic_prov_ref, i.abr_prov_ref, i.pre_natal_prov_ref, i.sba_prov_ref, i.water_prov_ref) > (1)::numeric) AND (i.health_indicators >= 4)), false) AS route_health,
    i.physical_factor,
    i.ip_pop,
    i.armed_conf,
    i.idp,
    i.four_ps,
    i.elcac_brgy,
    i.imr,
    i.ufmr,
    i.fic,
    i.abr,
    i.pre_natal,
    i.sba,
    i.water,
    i.imr_prov_ref,
    i.ufmr_prov_ref,
    i.fic_prov_ref,
    i.abr_prov_ref,
    i.pre_natal_prov_ref,
    i.sba_prov_ref,
    i.water_prov_ref,
    i.health_indicators,
    i.capped_indicators
   FROM (((((public.fact_uuc_phc_barangay b
     JOIN public.fact_uuc_phc_indicators i ON (((i.geo_code = b.geo_code) AND (i.dataset_id = b.dataset_id))))
     JOIN public.dim_geo g ON ((g.geo_code = b.geo_code)))
     LEFT JOIN public.dim_geo cm ON ((cm.geo_code = g.citymun_code)))
     LEFT JOIN public.dim_geo pr ON ((pr.geo_code = g.province_code)))
     LEFT JOIN public.dim_geo rg ON ((rg.geo_code = g.region_code)));


--
-- Name: VIEW ref_uuc_phc_list; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.ref_uuc_phc_list IS 'The 2025 UUC for PHC list as rows: one row per listed barangay with its geography resolved, the four qualifying routes, the 12 indicators, the 7 provincial benchmarks and capped_indicators. Joins fact_uuc_phc_barangay to fact_uuc_phc_indicators; security_invoker. capped_indicators names the values bounded during cleaning — read it before quoting any of the seven boundable indicators. See docs/UUC_PHC_2025_PLAN.md §9 U11.';


--
-- Name: COLUMN ref_uuc_phc_list.health_evaluable; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ref_uuc_phc_list.health_evaluable IS 'Whether criterion (d) can be evaluated for this barangay at all. False for 226 barangays in 5 provinces whose provincial benchmarks are placeholders, zeroes, fractions or absent — for them route_health is not a failed test, it is a test that was not asked.';


--
-- Name: COLUMN ref_uuc_phc_list.capped_indicators; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ref_uuc_phc_list.capped_indicators IS 'Which of this barangay''s indicators were bounded during cleaning, by column name. A named value is a ceiling the source overshot, not a measurement: 886 Water and 456 FIC readings now read as exactly 100% because of it. Empty array where nothing was bounded.';


--
-- Name: ref_uuc_phc_provincial; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ref_uuc_phc_provincial WITH (security_invoker='true') AS
 SELECT g.province_code,
    (count(*))::integer AS n_listed_barangays,
    (count(i.water_prov_ref))::integer AS n_with_reference,
    max(i.imr_prov_ref) AS ref_imr,
    max(i.ufmr_prov_ref) AS ref_ufmr,
    max(i.fic_prov_ref) AS ref_fic,
    max(i.abr_prov_ref) AS ref_abr,
    max(i.pre_natal_prov_ref) AS ref_pre_natal,
    max(i.sba_prov_ref) AS ref_sba,
    max(i.water_prov_ref) AS ref_water
   FROM (public.fact_uuc_phc_indicators i
     JOIN public.dim_geo g ON ((g.geo_code = i.geo_code)))
  GROUP BY g.province_code;


--
-- Name: VIEW ref_uuc_phc_provincial; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.ref_uuc_phc_provincial IS 'One row per province (87): the benchmarks criterion (d) of DOH AO No. 2020-0023 tests barangays against. Derived from fact_uuc_phc_indicators. n_with_reference vs n_listed_barangays exposes partial coverage — in province 09317 only 1 of 8 barangays carries a reference.';


--
-- Name: ref_uuc_phc_published_delta; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ref_uuc_phc_published_delta (
    id bigint NOT NULL,
    dataset_id bigint NOT NULL,
    geo_code text NOT NULL,
    geo_level public.geo_level_enum NOT NULL,
    source_label text NOT NULL,
    n_published integer NOT NULL,
    n_listed integer NOT NULL,
    delta integer NOT NULL,
    source_doc_key text NOT NULL,
    source_page integer NOT NULL,
    source_as_of date
);


--
-- Name: TABLE ref_uuc_phc_published_delta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.ref_uuc_phc_published_delta IS 'The 2025 UUC for PHC published-total reconciliation: every geography where the 2027 Budget Cue Cards p37 figure differs from this dashboard''s. AS OF THE FINAL-LIST ALIGNMENT THIS TABLE IS EMPTY, AND EMPTY IS THE ANSWER: the dashboard now publishes the source office''s final 5,987, which is what p37 reports, so all 17 regions and the national total agree to the unit. Parsed from the doc_chunk copy of p37 rather than transcribed, so any row that appears here is a real discrepancy. Matching geographies are deliberately not stored — the cue cards are an internal-exposure document and a matching row carries no reconciliation — so a caller must not read no-rows as no-data. See docs/UUC_PHC_2025_PLAN.md §3.';


--
-- Name: COLUMN ref_uuc_phc_published_delta.delta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ref_uuc_phc_published_delta.delta IS 'n_listed - n_published. Positive where this dashboard lists more barangays than the cue cards do. Never zero — matching geographies are not stored.';


--
-- Name: ref_uuc_phc_published_delta_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ref_uuc_phc_published_delta ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ref_uuc_phc_published_delta_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ref_uuc_phc_quality; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ref_uuc_phc_quality WITH (security_invoker='true') AS
 WITH scored AS (
         SELECT i.geo_code,
            cardinality(i.capped_indicators) AS n_capped,
            i.health_indicators,
            (((((((COALESCE(((i.imr_prov_ref <= (1000)::numeric) AND (i.imr > i.imr_prov_ref)), false))::integer + (COALESCE(((i.ufmr_prov_ref <= (1000)::numeric) AND (i.ufmr > i.ufmr_prov_ref)), false))::integer) + (COALESCE(((i.abr_prov_ref <= (1000)::numeric) AND (i.abr > i.abr_prov_ref)), false))::integer) + (COALESCE(((i.fic_prov_ref <= (100)::numeric) AND (i.fic < i.fic_prov_ref)), false))::integer) + (COALESCE(((i.pre_natal_prov_ref <= (100)::numeric) AND (i.pre_natal < i.pre_natal_prov_ref)), false))::integer) + (COALESCE(((i.sba_prov_ref <= (100)::numeric) AND (i.sba < i.sba_prov_ref)), false))::integer) + (COALESCE(((i.water_prov_ref <= (100)::numeric) AND (i.water < i.water_prov_ref)), false))::integer) AS recomputed,
            ((COALESCE(i.ip_pop, (0)::numeric) >= (10)::numeric) OR ((COALESCE(i.armed_conf, (0)::numeric) + COALESCE(i.idp, (0)::numeric)) >= (10)::numeric) OR COALESCE(i.elcac_brgy, false) OR (COALESCE(i.four_ps, (0)::numeric) >= (50)::numeric)) AS other_route
           FROM public.fact_uuc_phc_indicators i
          WHERE (i.dataset_id = ( SELECT dim_dataset.dataset_id
                   FROM public.dim_dataset
                  WHERE (dim_dataset.slug = 'uuc-phc-2025'::text)))
        )
 SELECT (count(*))::integer AS n_listed,
    (count(*) FILTER (WHERE (scored.n_capped > 0)))::integer AS n_barangays_capped,
    (COALESCE(sum(scored.n_capped), (0)::bigint))::integer AS n_values_capped,
    (count(*) FILTER (WHERE (scored.n_capped > 1)))::integer AS n_barangays_multi_capped,
    (count(*) FILTER (WHERE ((scored.health_indicators IS NOT NULL) AND (scored.recomputed IS DISTINCT FROM scored.health_indicators))))::integer AS n_score_disagreement,
    (count(*) FILTER (WHERE ((scored.health_indicators IS NOT NULL) AND (scored.recomputed < scored.health_indicators))))::integer AS n_score_understated,
    (count(*) FILTER (WHERE ((NOT scored.other_route) AND (scored.recomputed < 4))))::integer AS n_no_route_if_recomputed,
    (count(*) FILTER (WHERE ((NOT scored.other_route) AND (COALESCE((scored.health_indicators)::integer, 0) < 4))))::integer AS n_no_route_as_recorded
   FROM scored;


--
-- Name: VIEW ref_uuc_phc_quality; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.ref_uuc_phc_quality IS 'One row: the national data-quality facts behind /uuc-phc/data-quality that no per-indicator aggregate can express. n_barangays_capped counts BARANGAYS (1,397), not values (1,584) — 167 barangays carry more than one bounded value, so the two must never be swapped. The score columns recompute criterion (d) from the published columns solely to measure how far that derivation lands from the source''s own recorded score; they are a measurement of the gap, never a score to report, and they count only the barangays that have a recorded score to compare against. See docs/UUC_PHC_2025_PLAN.md §9 U10.';


--
-- Name: usage_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_events (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    session_id uuid NOT NULL,
    event_type text NOT NULL,
    page_path text,
    geo_code text,
    meta jsonb,
    ip_hash text
);


--
-- Name: usage_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.usage_events ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.usage_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: agg_peer_ranks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_peer_ranks ALTER COLUMN id SET DEFAULT nextval('public.agg_peer_ranks_id_seq'::regclass);


--
-- Name: admin_users admin_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_pkey PRIMARY KEY (user_id);


--
-- Name: agg_bhw_by_district agg_bhw_by_district_dataset_id_district_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_district
    ADD CONSTRAINT agg_bhw_by_district_dataset_id_district_code_key UNIQUE (dataset_id, district_code);


--
-- Name: agg_bhw_by_district agg_bhw_by_district_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_district
    ADD CONSTRAINT agg_bhw_by_district_pkey PRIMARY KEY (id);


--
-- Name: agg_bhw_by_uuc_status agg_bhw_by_uuc_status_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_uuc_status
    ADD CONSTRAINT agg_bhw_by_uuc_status_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_bhw_by_uuc_status agg_bhw_by_uuc_status_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_uuc_status
    ADD CONSTRAINT agg_bhw_by_uuc_status_pkey PRIMARY KEY (id);


--
-- Name: agg_bhw_counts agg_bhw_counts_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_counts
    ADD CONSTRAINT agg_bhw_counts_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_bhw_counts agg_bhw_counts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_counts
    ADD CONSTRAINT agg_bhw_counts_pkey PRIMARY KEY (id);


--
-- Name: agg_bhw_profiling_status agg_bhw_profiling_status_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_profiling_status
    ADD CONSTRAINT agg_bhw_profiling_status_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_bhw_profiling_status agg_bhw_profiling_status_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_profiling_status
    ADD CONSTRAINT agg_bhw_profiling_status_pkey PRIMARY KEY (id);


--
-- Name: agg_bhw_stepzero_counts agg_bhw_stepzero_counts_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_stepzero_counts
    ADD CONSTRAINT agg_bhw_stepzero_counts_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_bhw_stepzero_counts agg_bhw_stepzero_counts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_stepzero_counts
    ADD CONSTRAINT agg_bhw_stepzero_counts_pkey PRIMARY KEY (id);


--
-- Name: agg_by_income_class agg_by_income_class_dataset_id_income_class_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_by_income_class
    ADD CONSTRAINT agg_by_income_class_dataset_id_income_class_key UNIQUE (dataset_id, income_class);


--
-- Name: agg_by_income_class agg_by_income_class_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_by_income_class
    ADD CONSTRAINT agg_by_income_class_pkey PRIMARY KEY (id);


--
-- Name: agg_certification agg_certification_dataset_id_geo_code_geo_level_cert_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_certification
    ADD CONSTRAINT agg_certification_dataset_id_geo_code_geo_level_cert_type_key UNIQUE (dataset_id, geo_code, geo_level, cert_type);


--
-- Name: agg_certification agg_certification_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_certification
    ADD CONSTRAINT agg_certification_pkey PRIMARY KEY (id);


--
-- Name: agg_cohorts agg_cohorts_dataset_id_geo_code_geo_level_kind_cohort_year_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_cohorts
    ADD CONSTRAINT agg_cohorts_dataset_id_geo_code_geo_level_kind_cohort_year_key UNIQUE (dataset_id, geo_code, geo_level, kind, cohort_year);


--
-- Name: agg_cohorts agg_cohorts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_cohorts
    ADD CONSTRAINT agg_cohorts_pkey PRIMARY KEY (id);


--
-- Name: agg_data_completeness agg_data_completeness_dataset_geo_field_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_data_completeness
    ADD CONSTRAINT agg_data_completeness_dataset_geo_field_key UNIQUE (dataset_id, geo_level, geo_code, field_name);


--
-- Name: agg_data_completeness agg_data_completeness_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_data_completeness
    ADD CONSTRAINT agg_data_completeness_pkey PRIMARY KEY (id);


--
-- Name: agg_demographics agg_demographics_dataset_id_geo_code_geo_level_dimension_ca_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_demographics
    ADD CONSTRAINT agg_demographics_dataset_id_geo_code_geo_level_dimension_ca_key UNIQUE (dataset_id, geo_code, geo_level, dimension, category);


--
-- Name: agg_demographics agg_demographics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_demographics
    ADD CONSTRAINT agg_demographics_pkey PRIMARY KEY (id);


--
-- Name: agg_geo_summary agg_geo_summary_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_geo_summary
    ADD CONSTRAINT agg_geo_summary_pkey PRIMARY KEY (dataset_id, geo_code);


--
-- Name: agg_honorarium_cumulative agg_honorarium_cumulative_dataset_id_geo_code_geo_level_ban_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_cumulative
    ADD CONSTRAINT agg_honorarium_cumulative_dataset_id_geo_code_geo_level_ban_key UNIQUE (dataset_id, geo_code, geo_level, band_order);


--
-- Name: agg_honorarium_cumulative agg_honorarium_cumulative_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_cumulative
    ADD CONSTRAINT agg_honorarium_cumulative_pkey PRIMARY KEY (id);


--
-- Name: agg_honorarium agg_honorarium_dataset_id_geo_code_geo_level_payer_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium
    ADD CONSTRAINT agg_honorarium_dataset_id_geo_code_geo_level_payer_level_key UNIQUE (dataset_id, geo_code, geo_level, payer_level);


--
-- Name: agg_honorarium_inequality agg_honorarium_inequality_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_inequality
    ADD CONSTRAINT agg_honorarium_inequality_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_honorarium_inequality agg_honorarium_inequality_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_inequality
    ADD CONSTRAINT agg_honorarium_inequality_pkey PRIMARY KEY (id);


--
-- Name: agg_honorarium agg_honorarium_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium
    ADD CONSTRAINT agg_honorarium_pkey PRIMARY KEY (id);


--
-- Name: agg_nhfr_by_type agg_nhfr_by_type_dataset_id_geo_code_geo_level_facility_typ_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_by_type
    ADD CONSTRAINT agg_nhfr_by_type_dataset_id_geo_code_geo_level_facility_typ_key UNIQUE (dataset_id, geo_code, geo_level, facility_type);


--
-- Name: agg_nhfr_by_type agg_nhfr_by_type_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_by_type
    ADD CONSTRAINT agg_nhfr_by_type_pkey PRIMARY KEY (id);


--
-- Name: agg_nhfr_counts agg_nhfr_counts_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_counts
    ADD CONSTRAINT agg_nhfr_counts_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_nhfr_counts agg_nhfr_counts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_counts
    ADD CONSTRAINT agg_nhfr_counts_pkey PRIMARY KEY (id);


--
-- Name: agg_peer_ranks agg_peer_ranks_dataset_id_geo_code_geo_level_indicator_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_peer_ranks
    ADD CONSTRAINT agg_peer_ranks_dataset_id_geo_code_geo_level_indicator_key UNIQUE (dataset_id, geo_code, geo_level, indicator);


--
-- Name: agg_peer_ranks agg_peer_ranks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_peer_ranks
    ADD CONSTRAINT agg_peer_ranks_pkey PRIMARY KEY (id);


--
-- Name: agg_population agg_population_dataset_id_geo_code_geo_level_census_year_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_population
    ADD CONSTRAINT agg_population_dataset_id_geo_code_geo_level_census_year_key UNIQUE (dataset_id, geo_code, geo_level, census_year);


--
-- Name: agg_population agg_population_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_population
    ADD CONSTRAINT agg_population_pkey PRIMARY KEY (id);


--
-- Name: agg_poverty agg_poverty_dataset_id_geo_code_sae_year_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_poverty
    ADD CONSTRAINT agg_poverty_dataset_id_geo_code_sae_year_key UNIQUE (dataset_id, geo_code, sae_year);


--
-- Name: agg_poverty agg_poverty_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_poverty
    ADD CONSTRAINT agg_poverty_pkey PRIMARY KEY (id);


--
-- Name: agg_training agg_training_dataset_id_geo_code_geo_level_topic_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_training
    ADD CONSTRAINT agg_training_dataset_id_geo_code_geo_level_topic_slug_key UNIQUE (dataset_id, geo_code, geo_level, topic_slug);


--
-- Name: agg_training agg_training_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_training
    ADD CONSTRAINT agg_training_pkey PRIMARY KEY (id);


--
-- Name: agg_uuc_phc_counts agg_uuc_phc_counts_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_counts
    ADD CONSTRAINT agg_uuc_phc_counts_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_uuc_phc_counts agg_uuc_phc_counts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_counts
    ADD CONSTRAINT agg_uuc_phc_counts_pkey PRIMARY KEY (id);


--
-- Name: agg_uuc_phc_criteria agg_uuc_phc_criteria_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_criteria
    ADD CONSTRAINT agg_uuc_phc_criteria_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_uuc_phc_criteria agg_uuc_phc_criteria_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_criteria
    ADD CONSTRAINT agg_uuc_phc_criteria_pkey PRIMARY KEY (id);


--
-- Name: agg_uuc_phc_indicator_dist agg_uuc_phc_indicator_dist_dataset_id_geo_code_geo_level_in_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_indicator_dist
    ADD CONSTRAINT agg_uuc_phc_indicator_dist_dataset_id_geo_code_geo_level_in_key UNIQUE (dataset_id, geo_code, geo_level, indicator);


--
-- Name: agg_uuc_phc_indicator_dist agg_uuc_phc_indicator_dist_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_indicator_dist
    ADD CONSTRAINT agg_uuc_phc_indicator_dist_pkey PRIMARY KEY (id);


--
-- Name: agg_workload agg_workload_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_workload
    ADD CONSTRAINT agg_workload_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: agg_workload agg_workload_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_workload
    ADD CONSTRAINT agg_workload_pkey PRIMARY KEY (id);


--
-- Name: ai_ask_cache ai_ask_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_ask_cache
    ADD CONSTRAINT ai_ask_cache_pkey PRIMARY KEY (cache_key);


--
-- Name: ai_ask_log ai_ask_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_ask_log
    ADD CONSTRAINT ai_ask_log_pkey PRIMARY KEY (id);


--
-- Name: ai_narrative_cache ai_narrative_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_narrative_cache
    ADD CONSTRAINT ai_narrative_cache_pkey PRIMARY KEY (cache_key);


--
-- Name: ai_provider_quota ai_provider_quota_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_provider_quota
    ADD CONSTRAINT ai_provider_quota_pkey PRIMARY KEY (id);


--
-- Name: ai_provider_quota ai_provider_quota_provider_window_type_window_start_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_provider_quota
    ADD CONSTRAINT ai_provider_quota_provider_window_type_window_start_key UNIQUE (provider, window_type, window_start);


--
-- Name: ai_regression_case ai_regression_case_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_regression_case
    ADD CONSTRAINT ai_regression_case_pkey PRIMARY KEY (case_id);


--
-- Name: ai_regression_run ai_regression_run_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_regression_run
    ADD CONSTRAINT ai_regression_run_pkey PRIMARY KEY (run_id);


--
-- Name: changelog_entries changelog_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.changelog_entries
    ADD CONSTRAINT changelog_entries_pkey PRIMARY KEY (id);


--
-- Name: dataset_column dataset_column_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dataset_column
    ADD CONSTRAINT dataset_column_pkey PRIMARY KEY (column_id);


--
-- Name: dataset_column dataset_column_registry_id_column_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dataset_column
    ADD CONSTRAINT dataset_column_registry_id_column_name_key UNIQUE (registry_id, column_name);


--
-- Name: dataset_registry dataset_registry_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dataset_registry
    ADD CONSTRAINT dataset_registry_pkey PRIMARY KEY (registry_id);


--
-- Name: dataset_registry dataset_registry_table_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dataset_registry
    ADD CONSTRAINT dataset_registry_table_name_key UNIQUE (table_name);


--
-- Name: dim_dataset dim_dataset_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_dataset
    ADD CONSTRAINT dim_dataset_pkey PRIMARY KEY (dataset_id);


--
-- Name: dim_dataset dim_dataset_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_dataset
    ADD CONSTRAINT dim_dataset_slug_key UNIQUE (slug);


--
-- Name: dim_geo dim_geo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_geo
    ADD CONSTRAINT dim_geo_pkey PRIMARY KEY (geo_code);


--
-- Name: dim_legislative_district dim_legislative_district_congress_no_district_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_legislative_district
    ADD CONSTRAINT dim_legislative_district_congress_no_district_name_key UNIQUE (congress_no, district_name);


--
-- Name: dim_legislative_district dim_legislative_district_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_legislative_district
    ADD CONSTRAINT dim_legislative_district_pkey PRIMARY KEY (district_code);


--
-- Name: dim_lgu_income_reclass dim_lgu_income_reclass_geo_code_dataset_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_lgu_income_reclass
    ADD CONSTRAINT dim_lgu_income_reclass_geo_code_dataset_id_key UNIQUE (geo_code, dataset_id);


--
-- Name: dim_lgu_income_reclass dim_lgu_income_reclass_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_lgu_income_reclass
    ADD CONSTRAINT dim_lgu_income_reclass_pkey PRIMARY KEY (reclass_id);


--
-- Name: dim_psgc_crosswalk dim_psgc_crosswalk_old_code_old_vintage_new_vintage_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_psgc_crosswalk
    ADD CONSTRAINT dim_psgc_crosswalk_old_code_old_vintage_new_vintage_key UNIQUE (old_code, old_vintage, new_vintage);


--
-- Name: dim_psgc_crosswalk dim_psgc_crosswalk_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_psgc_crosswalk
    ADD CONSTRAINT dim_psgc_crosswalk_pkey PRIMARY KEY (crosswalk_id);


--
-- Name: district_correction district_correction_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_correction
    ADD CONSTRAINT district_correction_pkey PRIMARY KEY (id);


--
-- Name: district_representative district_representative_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_representative
    ADD CONSTRAINT district_representative_pkey PRIMARY KEY (id);


--
-- Name: doc_chunk doc_chunk_doc_id_chunk_index_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_chunk
    ADD CONSTRAINT doc_chunk_doc_id_chunk_index_key UNIQUE (doc_id, chunk_index);


--
-- Name: doc_chunk_embedding doc_chunk_embedding_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_chunk_embedding
    ADD CONSTRAINT doc_chunk_embedding_pkey PRIMARY KEY (chunk_id, model);


--
-- Name: doc_chunk doc_chunk_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_chunk
    ADD CONSTRAINT doc_chunk_pkey PRIMARY KEY (chunk_id);


--
-- Name: doc_embedding_model doc_embedding_model_model_dim_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_embedding_model
    ADD CONSTRAINT doc_embedding_model_model_dim_key UNIQUE (model, dim);


--
-- Name: doc_embedding_model doc_embedding_model_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_embedding_model
    ADD CONSTRAINT doc_embedding_model_pkey PRIMARY KEY (model);


--
-- Name: doc_source doc_source_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_source
    ADD CONSTRAINT doc_source_key_key UNIQUE (key);


--
-- Name: doc_source doc_source_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_source
    ADD CONSTRAINT doc_source_pkey PRIMARY KEY (doc_id);


--
-- Name: fact_bhw_raw fact_bhw_raw_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_bhw_raw
    ADD CONSTRAINT fact_bhw_raw_pkey PRIMARY KEY (bhw_id);


--
-- Name: fact_fhsis_indicator fact_fhsis_indicator_dataset_id_geo_code_indicator_key_brea_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_indicator
    ADD CONSTRAINT fact_fhsis_indicator_dataset_id_geo_code_indicator_key_brea_key UNIQUE (dataset_id, geo_code, indicator_key, breakdown);


--
-- Name: fact_fhsis_indicator fact_fhsis_indicator_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_indicator
    ADD CONSTRAINT fact_fhsis_indicator_pkey PRIMARY KEY (id);


--
-- Name: fact_fhsis_workforce fact_fhsis_workforce_dataset_id_geo_code_cadre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_workforce
    ADD CONSTRAINT fact_fhsis_workforce_dataset_id_geo_code_cadre_key UNIQUE (dataset_id, geo_code, cadre);


--
-- Name: fact_fhsis_workforce fact_fhsis_workforce_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_workforce
    ADD CONSTRAINT fact_fhsis_workforce_pkey PRIMARY KEY (id);


--
-- Name: fact_honorarium fact_honorarium_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_honorarium
    ADD CONSTRAINT fact_honorarium_pkey PRIMARY KEY (id);


--
-- Name: fact_nhfr_facility fact_nhfr_facility_dataset_id_facility_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_nhfr_facility
    ADD CONSTRAINT fact_nhfr_facility_dataset_id_facility_code_key UNIQUE (dataset_id, facility_code);


--
-- Name: fact_nhfr_facility fact_nhfr_facility_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_nhfr_facility
    ADD CONSTRAINT fact_nhfr_facility_pkey PRIMARY KEY (id);


--
-- Name: fact_uuc_phc_barangay fact_uuc_phc_barangay_dataset_id_geo_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_barangay
    ADD CONSTRAINT fact_uuc_phc_barangay_dataset_id_geo_code_key UNIQUE (dataset_id, geo_code);


--
-- Name: fact_uuc_phc_barangay fact_uuc_phc_barangay_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_barangay
    ADD CONSTRAINT fact_uuc_phc_barangay_pkey PRIMARY KEY (id);


--
-- Name: fact_uuc_phc_indicators fact_uuc_phc_indicators_dataset_id_geo_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_indicators
    ADD CONSTRAINT fact_uuc_phc_indicators_dataset_id_geo_code_key UNIQUE (dataset_id, geo_code);


--
-- Name: fact_uuc_phc_indicators fact_uuc_phc_indicators_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_indicators
    ADD CONSTRAINT fact_uuc_phc_indicators_pkey PRIMARY KEY (id);


--
-- Name: feedback feedback_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feedback
    ADD CONSTRAINT feedback_pkey PRIMARY KEY (id);


--
-- Name: geo_district_map geo_district_map_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_district_map
    ADD CONSTRAINT geo_district_map_pkey PRIMARY KEY (id);


--
-- Name: ingestion_batches ingestion_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ingestion_batches
    ADD CONSTRAINT ingestion_batches_pkey PRIMARY KEY (batch_id);


--
-- Name: kb_contradiction kb_contradiction_identity; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_contradiction
    ADD CONSTRAINT kb_contradiction_identity UNIQUE (method, chunk_id, scope_key, data_table, data_column_key);


--
-- Name: kb_contradiction kb_contradiction_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_contradiction
    ADD CONSTRAINT kb_contradiction_pkey PRIMARY KEY (contradiction_id);


--
-- Name: kb_edge kb_edge_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_edge
    ADD CONSTRAINT kb_edge_pkey PRIMARY KEY (edge_id);


--
-- Name: kb_edge kb_edge_src_node_id_relation_dst_node_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_edge
    ADD CONSTRAINT kb_edge_src_node_id_relation_dst_node_id_key UNIQUE (src_node_id, relation, dst_node_id);


--
-- Name: kb_node kb_node_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_node
    ADD CONSTRAINT kb_node_key_key UNIQUE (key);


--
-- Name: kb_node kb_node_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_node
    ADD CONSTRAINT kb_node_pkey PRIMARY KEY (node_id);


--
-- Name: ref_fhsis_indicator ref_fhsis_indicator_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ref_fhsis_indicator
    ADD CONSTRAINT ref_fhsis_indicator_pkey PRIMARY KEY (indicator_key);


--
-- Name: ref_uuc_phc_published_delta ref_uuc_phc_published_delta_dataset_id_geo_code_geo_level_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ref_uuc_phc_published_delta
    ADD CONSTRAINT ref_uuc_phc_published_delta_dataset_id_geo_code_geo_level_key UNIQUE (dataset_id, geo_code, geo_level);


--
-- Name: ref_uuc_phc_published_delta ref_uuc_phc_published_delta_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ref_uuc_phc_published_delta
    ADD CONSTRAINT ref_uuc_phc_published_delta_pkey PRIMARY KEY (id);


--
-- Name: usage_events usage_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_events
    ADD CONSTRAINT usage_events_pkey PRIMARY KEY (id);


--
-- Name: agg_bhw_by_uuc_status_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_bhw_by_uuc_status_geo_idx ON public.agg_bhw_by_uuc_status USING btree (dataset_id, geo_code, geo_level);


--
-- Name: agg_bhw_profiling_status_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_bhw_profiling_status_geo_idx ON public.agg_bhw_profiling_status USING btree (geo_code, geo_level);


--
-- Name: agg_bhw_profiling_status_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_bhw_profiling_status_level_idx ON public.agg_bhw_profiling_status USING btree (dataset_id, geo_level);


--
-- Name: agg_data_completeness_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_data_completeness_geo_idx ON public.agg_data_completeness USING btree (geo_code, geo_level);


--
-- Name: agg_geo_summary_search_text_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_geo_summary_search_text_idx ON public.agg_geo_summary USING gin (search_text);


--
-- Name: agg_nhfr_by_type_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_nhfr_by_type_geo_idx ON public.agg_nhfr_by_type USING btree (geo_code, geo_level);


--
-- Name: agg_nhfr_by_type_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_nhfr_by_type_type_idx ON public.agg_nhfr_by_type USING btree (dataset_id, geo_level, facility_type);


--
-- Name: agg_nhfr_counts_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_nhfr_counts_geo_idx ON public.agg_nhfr_counts USING btree (geo_code, geo_level);


--
-- Name: agg_nhfr_counts_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_nhfr_counts_level_idx ON public.agg_nhfr_counts USING btree (dataset_id, geo_level);


--
-- Name: agg_population_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_population_geo_idx ON public.agg_population USING btree (geo_code, census_year);


--
-- Name: agg_poverty_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_poverty_geo_idx ON public.agg_poverty USING btree (geo_code, sae_year);


--
-- Name: agg_uuc_phc_counts_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_uuc_phc_counts_geo_idx ON public.agg_uuc_phc_counts USING btree (geo_code, geo_level);


--
-- Name: agg_uuc_phc_counts_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_uuc_phc_counts_level_idx ON public.agg_uuc_phc_counts USING btree (dataset_id, geo_level);


--
-- Name: agg_uuc_phc_criteria_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_uuc_phc_criteria_geo_idx ON public.agg_uuc_phc_criteria USING btree (geo_code, geo_level);


--
-- Name: agg_uuc_phc_criteria_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_uuc_phc_criteria_level_idx ON public.agg_uuc_phc_criteria USING btree (dataset_id, geo_level);


--
-- Name: agg_uuc_phc_indicator_dist_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agg_uuc_phc_indicator_dist_geo_idx ON public.agg_uuc_phc_indicator_dist USING btree (dataset_id, geo_code, geo_level);


--
-- Name: ai_ask_cache_dataset_slug_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_ask_cache_dataset_slug_idx ON public.ai_ask_cache USING btree (dataset_slug);


--
-- Name: ai_ask_cache_norm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_ask_cache_norm_idx ON public.ai_ask_cache USING btree (question_norm);


--
-- Name: ai_ask_cache_question_norm_trgm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_ask_cache_question_norm_trgm_idx ON public.ai_ask_cache USING gin (question_norm extensions.gin_trgm_ops);


--
-- Name: ai_ask_log_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_ask_log_created_at_idx ON public.ai_ask_log USING btree (created_at);


--
-- Name: ai_ask_log_question_norm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_ask_log_question_norm_idx ON public.ai_ask_log USING btree (question_norm);


--
-- Name: ai_regression_case_harvest_key_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ai_regression_case_harvest_key_idx ON public.ai_regression_case USING btree (harvest_key) WHERE (harvest_key IS NOT NULL);


--
-- Name: ai_regression_case_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_regression_case_status_idx ON public.ai_regression_case USING btree (status, created_at DESC);


--
-- Name: ai_regression_run_started_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_regression_run_started_idx ON public.ai_regression_run USING btree (started_at DESC);


--
-- Name: dataset_column_join_key_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dataset_column_join_key_idx ON public.dataset_column USING btree (joins_to) WHERE is_join_key;


--
-- Name: dataset_column_registry_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dataset_column_registry_idx ON public.dataset_column USING btree (registry_id, ordinal);


--
-- Name: dataset_registry_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dataset_registry_status_idx ON public.dataset_registry USING btree (status, exposure);


--
-- Name: dim_geo_citymun_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_geo_citymun_code_idx ON public.dim_geo USING btree (citymun_code);


--
-- Name: dim_geo_geo_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_geo_geo_level_idx ON public.dim_geo USING btree (geo_level);


--
-- Name: dim_geo_geo_name_lower_trgm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_geo_geo_name_lower_trgm_idx ON public.dim_geo USING gin (lower(geo_name) extensions.gin_trgm_ops);


--
-- Name: dim_geo_geo_name_trgm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_geo_geo_name_trgm_idx ON public.dim_geo USING gin (geo_name extensions.gin_trgm_ops);


--
-- Name: dim_geo_parent_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_geo_parent_code_idx ON public.dim_geo USING btree (parent_code);


--
-- Name: dim_geo_province_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_geo_province_code_idx ON public.dim_geo USING btree (province_code);


--
-- Name: dim_legislative_district_name_lower_trgm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_legislative_district_name_lower_trgm_idx ON public.dim_legislative_district USING gin (lower(district_name) extensions.gin_trgm_ops);


--
-- Name: dim_lgu_income_reclass_new_class_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_lgu_income_reclass_new_class_idx ON public.dim_lgu_income_reclass USING btree (new_class);


--
-- Name: dim_psgc_crosswalk_new_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_psgc_crosswalk_new_code_idx ON public.dim_psgc_crosswalk USING btree (new_code);


--
-- Name: dim_psgc_crosswalk_old_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dim_psgc_crosswalk_old_code_idx ON public.dim_psgc_crosswalk USING btree (old_code);


--
-- Name: district_correction_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX district_correction_status_idx ON public.district_correction USING btree (status, created_at DESC);


--
-- Name: district_representative_district_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX district_representative_district_code_idx ON public.district_representative USING btree (district_code);


--
-- Name: doc_chunk_content_trgm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doc_chunk_content_trgm_idx ON public.doc_chunk USING gin (content extensions.gin_trgm_ops);


--
-- Name: doc_chunk_doc_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doc_chunk_doc_idx ON public.doc_chunk USING btree (doc_id, chunk_index);


--
-- Name: doc_chunk_embedding_model_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doc_chunk_embedding_model_idx ON public.doc_chunk_embedding USING btree (model);


--
-- Name: doc_chunk_page_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doc_chunk_page_idx ON public.doc_chunk USING btree (doc_id, page_from);


--
-- Name: fact_bhw_raw_geo_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_bhw_raw_geo_code_idx ON public.fact_bhw_raw USING btree (geo_code);


--
-- Name: fact_fhsis_indicator_dataset_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_fhsis_indicator_dataset_idx ON public.fact_fhsis_indicator USING btree (dataset_id);


--
-- Name: fact_fhsis_indicator_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_fhsis_indicator_geo_idx ON public.fact_fhsis_indicator USING btree (geo_code);


--
-- Name: fact_fhsis_indicator_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_fhsis_indicator_lookup_idx ON public.fact_fhsis_indicator USING btree (dataset_id, indicator_key, geo_level, breakdown);


--
-- Name: fact_fhsis_workforce_cadre_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_fhsis_workforce_cadre_idx ON public.fact_fhsis_workforce USING btree (dataset_id, cadre, geo_level);


--
-- Name: fact_fhsis_workforce_dataset_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_fhsis_workforce_dataset_idx ON public.fact_fhsis_workforce USING btree (dataset_id);


--
-- Name: fact_fhsis_workforce_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_fhsis_workforce_geo_idx ON public.fact_fhsis_workforce USING btree (geo_code);


--
-- Name: fact_honorarium_bhw_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_honorarium_bhw_id_idx ON public.fact_honorarium USING btree (bhw_id);


--
-- Name: fact_nhfr_facility_barangay_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_nhfr_facility_barangay_idx ON public.fact_nhfr_facility USING btree (barangay_geo_code);


--
-- Name: fact_nhfr_facility_dataset_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_nhfr_facility_dataset_idx ON public.fact_nhfr_facility USING btree (dataset_id);


--
-- Name: fact_nhfr_facility_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_nhfr_facility_geo_idx ON public.fact_nhfr_facility USING btree (geo_code);


--
-- Name: fact_nhfr_facility_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_nhfr_facility_type_idx ON public.fact_nhfr_facility USING btree (dataset_id, facility_type);


--
-- Name: fact_uuc_phc_barangay_dataset_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_uuc_phc_barangay_dataset_idx ON public.fact_uuc_phc_barangay USING btree (dataset_id);


--
-- Name: fact_uuc_phc_barangay_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_uuc_phc_barangay_geo_idx ON public.fact_uuc_phc_barangay USING btree (geo_code);


--
-- Name: fact_uuc_phc_indicators_geo_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fact_uuc_phc_indicators_geo_idx ON public.fact_uuc_phc_indicators USING btree (geo_code);


--
-- Name: feedback_dataset_slug_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feedback_dataset_slug_idx ON public.feedback USING btree (dataset_slug, created_at DESC) WHERE (dataset_slug IS NOT NULL);


--
-- Name: geo_district_map_district_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geo_district_map_district_code_idx ON public.geo_district_map USING btree (district_code);


--
-- Name: geo_district_map_geo_code_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geo_district_map_geo_code_idx ON public.geo_district_map USING btree (geo_code);


--
-- Name: geo_district_map_live_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX geo_district_map_live_idx ON public.geo_district_map USING btree (district_code, geo_code) WHERE (superseded_by IS NULL);


--
-- Name: kb_contradiction_chunk_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_contradiction_chunk_idx ON public.kb_contradiction USING btree (chunk_id);


--
-- Name: kb_contradiction_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_contradiction_status_idx ON public.kb_contradiction USING btree (status, rel_difference DESC);


--
-- Name: kb_edge_dst_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_edge_dst_idx ON public.kb_edge USING btree (dst_node_id, relation) WHERE (status = 'approved'::text);


--
-- Name: kb_edge_review_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_edge_review_idx ON public.kb_edge USING btree (status, origin) WHERE (status = 'auto'::text);


--
-- Name: kb_edge_src_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_edge_src_idx ON public.kb_edge USING btree (src_node_id, relation) WHERE (status = 'approved'::text);


--
-- Name: kb_node_kind_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_node_kind_idx ON public.kb_node USING btree (kind, status);


--
-- Name: kb_node_review_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kb_node_review_idx ON public.kb_node USING btree (status, origin) WHERE (status = 'auto'::text);


--
-- Name: usage_events_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX usage_events_created_at_idx ON public.usage_events USING btree (created_at);


--
-- Name: kb_edge kb_edge_endpoints_approved; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER kb_edge_endpoints_approved BEFORE INSERT OR UPDATE ON public.kb_edge FOR EACH ROW EXECUTE FUNCTION public.kb_edge_endpoints_are_approved();


--
-- Name: kb_edge kb_edge_evidence_grounded; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER kb_edge_evidence_grounded BEFORE INSERT OR UPDATE ON public.kb_edge FOR EACH ROW EXECUTE FUNCTION public.kb_evidence_is_grounded();


--
-- Name: kb_node kb_node_approved_edges; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER kb_node_approved_edges BEFORE UPDATE ON public.kb_node FOR EACH ROW EXECUTE FUNCTION public.kb_node_keeps_its_approved_edges();


--
-- Name: kb_node kb_node_evidence_grounded; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER kb_node_evidence_grounded BEFORE INSERT OR UPDATE ON public.kb_node FOR EACH ROW EXECUTE FUNCTION public.kb_evidence_is_grounded();


--
-- Name: agg_bhw_by_district agg_bhw_by_district_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_district
    ADD CONSTRAINT agg_bhw_by_district_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_bhw_by_district agg_bhw_by_district_district_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_district
    ADD CONSTRAINT agg_bhw_by_district_district_code_fkey FOREIGN KEY (district_code) REFERENCES public.dim_legislative_district(district_code) ON DELETE CASCADE;


--
-- Name: agg_bhw_by_uuc_status agg_bhw_by_uuc_status_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_uuc_status
    ADD CONSTRAINT agg_bhw_by_uuc_status_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_bhw_by_uuc_status agg_bhw_by_uuc_status_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_by_uuc_status
    ADD CONSTRAINT agg_bhw_by_uuc_status_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_bhw_counts agg_bhw_counts_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_counts
    ADD CONSTRAINT agg_bhw_counts_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_bhw_counts agg_bhw_counts_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_counts
    ADD CONSTRAINT agg_bhw_counts_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_bhw_profiling_status agg_bhw_profiling_status_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_profiling_status
    ADD CONSTRAINT agg_bhw_profiling_status_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_bhw_profiling_status agg_bhw_profiling_status_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_profiling_status
    ADD CONSTRAINT agg_bhw_profiling_status_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_bhw_stepzero_counts agg_bhw_stepzero_counts_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_stepzero_counts
    ADD CONSTRAINT agg_bhw_stepzero_counts_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_bhw_stepzero_counts agg_bhw_stepzero_counts_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_bhw_stepzero_counts
    ADD CONSTRAINT agg_bhw_stepzero_counts_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_by_income_class agg_by_income_class_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_by_income_class
    ADD CONSTRAINT agg_by_income_class_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_certification agg_certification_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_certification
    ADD CONSTRAINT agg_certification_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_certification agg_certification_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_certification
    ADD CONSTRAINT agg_certification_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_cohorts agg_cohorts_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_cohorts
    ADD CONSTRAINT agg_cohorts_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_cohorts agg_cohorts_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_cohorts
    ADD CONSTRAINT agg_cohorts_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_data_completeness agg_data_completeness_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_data_completeness
    ADD CONSTRAINT agg_data_completeness_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_demographics agg_demographics_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_demographics
    ADD CONSTRAINT agg_demographics_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_demographics agg_demographics_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_demographics
    ADD CONSTRAINT agg_demographics_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_demographics agg_demographics_rollup_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_demographics
    ADD CONSTRAINT agg_demographics_rollup_geo_code_fkey FOREIGN KEY (rollup_geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_geo_summary agg_geo_summary_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_geo_summary
    ADD CONSTRAINT agg_geo_summary_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_geo_summary agg_geo_summary_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_geo_summary
    ADD CONSTRAINT agg_geo_summary_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_honorarium_cumulative agg_honorarium_cumulative_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_cumulative
    ADD CONSTRAINT agg_honorarium_cumulative_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_honorarium_cumulative agg_honorarium_cumulative_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_cumulative
    ADD CONSTRAINT agg_honorarium_cumulative_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_honorarium agg_honorarium_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium
    ADD CONSTRAINT agg_honorarium_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_honorarium agg_honorarium_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium
    ADD CONSTRAINT agg_honorarium_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_honorarium_inequality agg_honorarium_inequality_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_inequality
    ADD CONSTRAINT agg_honorarium_inequality_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_honorarium_inequality agg_honorarium_inequality_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_honorarium_inequality
    ADD CONSTRAINT agg_honorarium_inequality_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_nhfr_by_type agg_nhfr_by_type_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_by_type
    ADD CONSTRAINT agg_nhfr_by_type_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_nhfr_by_type agg_nhfr_by_type_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_by_type
    ADD CONSTRAINT agg_nhfr_by_type_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_nhfr_counts agg_nhfr_counts_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_counts
    ADD CONSTRAINT agg_nhfr_counts_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_nhfr_counts agg_nhfr_counts_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_nhfr_counts
    ADD CONSTRAINT agg_nhfr_counts_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_peer_ranks agg_peer_ranks_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_peer_ranks
    ADD CONSTRAINT agg_peer_ranks_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_peer_ranks agg_peer_ranks_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_peer_ranks
    ADD CONSTRAINT agg_peer_ranks_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_population agg_population_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_population
    ADD CONSTRAINT agg_population_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_population agg_population_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_population
    ADD CONSTRAINT agg_population_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_poverty agg_poverty_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_poverty
    ADD CONSTRAINT agg_poverty_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_poverty agg_poverty_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_poverty
    ADD CONSTRAINT agg_poverty_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_training agg_training_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_training
    ADD CONSTRAINT agg_training_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_training agg_training_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_training
    ADD CONSTRAINT agg_training_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_uuc_phc_counts agg_uuc_phc_counts_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_counts
    ADD CONSTRAINT agg_uuc_phc_counts_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_uuc_phc_counts agg_uuc_phc_counts_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_counts
    ADD CONSTRAINT agg_uuc_phc_counts_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_uuc_phc_criteria agg_uuc_phc_criteria_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_criteria
    ADD CONSTRAINT agg_uuc_phc_criteria_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_uuc_phc_criteria agg_uuc_phc_criteria_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_criteria
    ADD CONSTRAINT agg_uuc_phc_criteria_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_uuc_phc_indicator_dist agg_uuc_phc_indicator_dist_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_indicator_dist
    ADD CONSTRAINT agg_uuc_phc_indicator_dist_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_uuc_phc_indicator_dist agg_uuc_phc_indicator_dist_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_uuc_phc_indicator_dist
    ADD CONSTRAINT agg_uuc_phc_indicator_dist_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: agg_workload agg_workload_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_workload
    ADD CONSTRAINT agg_workload_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: agg_workload agg_workload_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agg_workload
    ADD CONSTRAINT agg_workload_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: dataset_column dataset_column_registry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dataset_column
    ADD CONSTRAINT dataset_column_registry_id_fkey FOREIGN KEY (registry_id) REFERENCES public.dataset_registry(registry_id) ON DELETE CASCADE;


--
-- Name: dim_geo dim_geo_parent_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_geo
    ADD CONSTRAINT dim_geo_parent_code_fkey FOREIGN KEY (parent_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: dim_legislative_district dim_legislative_district_parent_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_legislative_district
    ADD CONSTRAINT dim_legislative_district_parent_geo_code_fkey FOREIGN KEY (parent_geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: dim_lgu_income_reclass dim_lgu_income_reclass_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_lgu_income_reclass
    ADD CONSTRAINT dim_lgu_income_reclass_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: dim_lgu_income_reclass dim_lgu_income_reclass_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_lgu_income_reclass
    ADD CONSTRAINT dim_lgu_income_reclass_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: dim_psgc_crosswalk dim_psgc_crosswalk_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_psgc_crosswalk
    ADD CONSTRAINT dim_psgc_crosswalk_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: dim_psgc_crosswalk dim_psgc_crosswalk_new_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dim_psgc_crosswalk
    ADD CONSTRAINT dim_psgc_crosswalk_new_code_fkey FOREIGN KEY (new_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: district_correction district_correction_district_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_correction
    ADD CONSTRAINT district_correction_district_code_fkey FOREIGN KEY (district_code) REFERENCES public.dim_legislative_district(district_code);


--
-- Name: district_correction district_correction_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_correction
    ADD CONSTRAINT district_correction_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: district_representative district_representative_district_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_representative
    ADD CONSTRAINT district_representative_district_code_fkey FOREIGN KEY (district_code) REFERENCES public.dim_legislative_district(district_code) ON DELETE CASCADE;


--
-- Name: district_representative district_representative_superseded_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.district_representative
    ADD CONSTRAINT district_representative_superseded_by_fkey FOREIGN KEY (superseded_by) REFERENCES public.district_representative(id);


--
-- Name: doc_chunk doc_chunk_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_chunk
    ADD CONSTRAINT doc_chunk_doc_id_fkey FOREIGN KEY (doc_id) REFERENCES public.doc_source(doc_id) ON DELETE CASCADE;


--
-- Name: doc_chunk_embedding doc_chunk_embedding_chunk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_chunk_embedding
    ADD CONSTRAINT doc_chunk_embedding_chunk_id_fkey FOREIGN KEY (chunk_id) REFERENCES public.doc_chunk(chunk_id) ON DELETE CASCADE;


--
-- Name: doc_chunk_embedding doc_chunk_embedding_model_dim_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doc_chunk_embedding
    ADD CONSTRAINT doc_chunk_embedding_model_dim_fkey FOREIGN KEY (model, dim) REFERENCES public.doc_embedding_model(model, dim) ON UPDATE CASCADE;


--
-- Name: fact_bhw_raw fact_bhw_raw_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_bhw_raw
    ADD CONSTRAINT fact_bhw_raw_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: fact_bhw_raw fact_bhw_raw_ingestion_batch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_bhw_raw
    ADD CONSTRAINT fact_bhw_raw_ingestion_batch_id_fkey FOREIGN KEY (ingestion_batch_id) REFERENCES public.ingestion_batches(batch_id);


--
-- Name: fact_fhsis_indicator fact_fhsis_indicator_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_indicator
    ADD CONSTRAINT fact_fhsis_indicator_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: fact_fhsis_indicator fact_fhsis_indicator_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_indicator
    ADD CONSTRAINT fact_fhsis_indicator_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: fact_fhsis_indicator fact_fhsis_indicator_indicator_key_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_indicator
    ADD CONSTRAINT fact_fhsis_indicator_indicator_key_fkey FOREIGN KEY (indicator_key) REFERENCES public.ref_fhsis_indicator(indicator_key);


--
-- Name: fact_fhsis_workforce fact_fhsis_workforce_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_workforce
    ADD CONSTRAINT fact_fhsis_workforce_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: fact_fhsis_workforce fact_fhsis_workforce_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_fhsis_workforce
    ADD CONSTRAINT fact_fhsis_workforce_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: fact_honorarium fact_honorarium_bhw_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_honorarium
    ADD CONSTRAINT fact_honorarium_bhw_id_fkey FOREIGN KEY (bhw_id) REFERENCES public.fact_bhw_raw(bhw_id);


--
-- Name: fact_nhfr_facility fact_nhfr_facility_barangay_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_nhfr_facility
    ADD CONSTRAINT fact_nhfr_facility_barangay_geo_code_fkey FOREIGN KEY (barangay_geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: fact_nhfr_facility fact_nhfr_facility_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_nhfr_facility
    ADD CONSTRAINT fact_nhfr_facility_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: fact_nhfr_facility fact_nhfr_facility_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_nhfr_facility
    ADD CONSTRAINT fact_nhfr_facility_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: fact_uuc_phc_barangay fact_uuc_phc_barangay_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_barangay
    ADD CONSTRAINT fact_uuc_phc_barangay_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: fact_uuc_phc_barangay fact_uuc_phc_barangay_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_barangay
    ADD CONSTRAINT fact_uuc_phc_barangay_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: fact_uuc_phc_indicators fact_uuc_phc_indicators_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_indicators
    ADD CONSTRAINT fact_uuc_phc_indicators_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: fact_uuc_phc_indicators fact_uuc_phc_indicators_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_uuc_phc_indicators
    ADD CONSTRAINT fact_uuc_phc_indicators_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: geo_district_map geo_district_map_district_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_district_map
    ADD CONSTRAINT geo_district_map_district_code_fkey FOREIGN KEY (district_code) REFERENCES public.dim_legislative_district(district_code) ON DELETE CASCADE;


--
-- Name: geo_district_map geo_district_map_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_district_map
    ADD CONSTRAINT geo_district_map_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: geo_district_map geo_district_map_superseded_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_district_map
    ADD CONSTRAINT geo_district_map_superseded_by_fkey FOREIGN KEY (superseded_by) REFERENCES public.geo_district_map(id);


--
-- Name: kb_contradiction kb_contradiction_chunk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_contradiction
    ADD CONSTRAINT kb_contradiction_chunk_id_fkey FOREIGN KEY (chunk_id) REFERENCES public.doc_chunk(chunk_id) ON DELETE CASCADE;


--
-- Name: kb_contradiction kb_contradiction_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_contradiction
    ADD CONSTRAINT kb_contradiction_doc_id_fkey FOREIGN KEY (doc_id) REFERENCES public.doc_source(doc_id) ON DELETE CASCADE;


--
-- Name: kb_edge kb_edge_dst_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_edge
    ADD CONSTRAINT kb_edge_dst_node_id_fkey FOREIGN KEY (dst_node_id) REFERENCES public.kb_node(node_id) ON DELETE CASCADE;


--
-- Name: kb_edge kb_edge_source_chunk_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_edge
    ADD CONSTRAINT kb_edge_source_chunk_fk FOREIGN KEY (source_chunk_id) REFERENCES public.doc_chunk(chunk_id) ON DELETE RESTRICT;


--
-- Name: kb_edge kb_edge_src_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_edge
    ADD CONSTRAINT kb_edge_src_node_id_fkey FOREIGN KEY (src_node_id) REFERENCES public.kb_node(node_id) ON DELETE CASCADE;


--
-- Name: kb_node kb_node_source_chunk_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_node
    ADD CONSTRAINT kb_node_source_chunk_fk FOREIGN KEY (source_chunk_id) REFERENCES public.doc_chunk(chunk_id) ON DELETE RESTRICT;


--
-- Name: ref_uuc_phc_published_delta ref_uuc_phc_published_delta_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ref_uuc_phc_published_delta
    ADD CONSTRAINT ref_uuc_phc_published_delta_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.dim_dataset(dataset_id);


--
-- Name: ref_uuc_phc_published_delta ref_uuc_phc_published_delta_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ref_uuc_phc_published_delta
    ADD CONSTRAINT ref_uuc_phc_published_delta_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: usage_events usage_events_geo_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_events
    ADD CONSTRAINT usage_events_geo_code_fkey FOREIGN KEY (geo_code) REFERENCES public.dim_geo(geo_code);


--
-- Name: admin_users; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.admin_users ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_bhw_by_district; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_by_district ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_bhw_by_district agg_bhw_by_district public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_bhw_by_district public read" ON public.agg_bhw_by_district FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_bhw_by_uuc_status; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_by_uuc_status ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_bhw_by_uuc_status agg_bhw_by_uuc_status public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_bhw_by_uuc_status public read" ON public.agg_bhw_by_uuc_status FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_bhw_counts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_counts ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_bhw_counts agg_bhw_counts public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_bhw_counts public read" ON public.agg_bhw_counts FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_bhw_profiling_status; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_profiling_status ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_bhw_profiling_status agg_bhw_profiling_status public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_bhw_profiling_status public read" ON public.agg_bhw_profiling_status FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_bhw_stepzero_counts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_bhw_stepzero_counts ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_bhw_stepzero_counts agg_bhw_stepzero_counts public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_bhw_stepzero_counts public read" ON public.agg_bhw_stepzero_counts FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_by_income_class; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_by_income_class ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_by_income_class agg_by_income_class public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_by_income_class public read" ON public.agg_by_income_class FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_certification; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_certification ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_certification agg_certification public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_certification public read" ON public.agg_certification FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_cohorts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_cohorts ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_cohorts agg_cohorts public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_cohorts public read" ON public.agg_cohorts FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_data_completeness; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_data_completeness ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_data_completeness agg_data_completeness public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_data_completeness public read" ON public.agg_data_completeness FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_demographics; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_demographics ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_demographics agg_demographics public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_demographics public read" ON public.agg_demographics FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_geo_summary; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_geo_summary ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_geo_summary agg_geo_summary public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_geo_summary public read" ON public.agg_geo_summary FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_honorarium; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_honorarium ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_honorarium agg_honorarium public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_honorarium public read" ON public.agg_honorarium FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_honorarium_cumulative; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_honorarium_cumulative ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_honorarium_cumulative agg_honorarium_cumulative public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_honorarium_cumulative public read" ON public.agg_honorarium_cumulative FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_honorarium_inequality; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_honorarium_inequality ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_honorarium_inequality agg_honorarium_inequality public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_honorarium_inequality public read" ON public.agg_honorarium_inequality FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_nhfr_by_type; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_nhfr_by_type ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_nhfr_by_type agg_nhfr_by_type public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_nhfr_by_type public read" ON public.agg_nhfr_by_type FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_nhfr_counts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_nhfr_counts ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_nhfr_counts agg_nhfr_counts public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_nhfr_counts public read" ON public.agg_nhfr_counts FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_peer_ranks; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_peer_ranks ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_peer_ranks agg_peer_ranks public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_peer_ranks public read" ON public.agg_peer_ranks FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_population; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_population ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_population agg_population public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_population public read" ON public.agg_population FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_poverty; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_poverty ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_poverty agg_poverty public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_poverty public read" ON public.agg_poverty FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_training; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_training ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_training agg_training public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_training public read" ON public.agg_training FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_uuc_phc_counts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_uuc_phc_counts ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_uuc_phc_counts agg_uuc_phc_counts public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_uuc_phc_counts public read" ON public.agg_uuc_phc_counts FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_uuc_phc_criteria; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_uuc_phc_criteria ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_uuc_phc_criteria agg_uuc_phc_criteria public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_uuc_phc_criteria public read" ON public.agg_uuc_phc_criteria FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_uuc_phc_indicator_dist; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_uuc_phc_indicator_dist ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_uuc_phc_indicator_dist agg_uuc_phc_indicator_dist public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_uuc_phc_indicator_dist public read" ON public.agg_uuc_phc_indicator_dist FOR SELECT TO anon, authenticated USING (true);


--
-- Name: agg_workload; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agg_workload ENABLE ROW LEVEL SECURITY;

--
-- Name: agg_workload agg_workload public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "agg_workload public read" ON public.agg_workload FOR SELECT TO anon, authenticated USING (true);


--
-- Name: ai_ask_cache; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_ask_cache ENABLE ROW LEVEL SECURITY;

--
-- Name: ai_ask_log; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_ask_log ENABLE ROW LEVEL SECURITY;

--
-- Name: ai_narrative_cache; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_narrative_cache ENABLE ROW LEVEL SECURITY;

--
-- Name: ai_provider_quota; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_provider_quota ENABLE ROW LEVEL SECURITY;

--
-- Name: ai_regression_case; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_regression_case ENABLE ROW LEVEL SECURITY;

--
-- Name: ai_regression_run; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_regression_run ENABLE ROW LEVEL SECURITY;

--
-- Name: changelog_entries; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.changelog_entries ENABLE ROW LEVEL SECURITY;

--
-- Name: changelog_entries changelog_entries public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "changelog_entries public read" ON public.changelog_entries FOR SELECT TO anon, authenticated USING (true);


--
-- Name: dataset_column; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dataset_column ENABLE ROW LEVEL SECURITY;

--
-- Name: dataset_registry; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dataset_registry ENABLE ROW LEVEL SECURITY;

--
-- Name: dim_dataset; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dim_dataset ENABLE ROW LEVEL SECURITY;

--
-- Name: dim_dataset dim_dataset public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "dim_dataset public read" ON public.dim_dataset FOR SELECT TO anon, authenticated USING (true);


--
-- Name: dim_geo; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dim_geo ENABLE ROW LEVEL SECURITY;

--
-- Name: dim_geo dim_geo public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "dim_geo public read" ON public.dim_geo FOR SELECT TO anon, authenticated USING (true);


--
-- Name: dim_legislative_district; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dim_legislative_district ENABLE ROW LEVEL SECURITY;

--
-- Name: dim_legislative_district dim_legislative_district public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "dim_legislative_district public read" ON public.dim_legislative_district FOR SELECT TO anon, authenticated USING ((status <> 'rejected'::text));


--
-- Name: dim_lgu_income_reclass; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dim_lgu_income_reclass ENABLE ROW LEVEL SECURITY;

--
-- Name: dim_lgu_income_reclass dim_lgu_income_reclass public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "dim_lgu_income_reclass public read" ON public.dim_lgu_income_reclass FOR SELECT TO anon, authenticated USING (true);


--
-- Name: dim_psgc_crosswalk; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.dim_psgc_crosswalk ENABLE ROW LEVEL SECURITY;

--
-- Name: dim_psgc_crosswalk dim_psgc_crosswalk public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "dim_psgc_crosswalk public read" ON public.dim_psgc_crosswalk FOR SELECT TO anon, authenticated USING (true);


--
-- Name: district_correction; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.district_correction ENABLE ROW LEVEL SECURITY;

--
-- Name: district_correction district_correction public insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "district_correction public insert" ON public.district_correction FOR INSERT TO anon, authenticated WITH CHECK (true);


--
-- Name: district_representative; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.district_representative ENABLE ROW LEVEL SECURITY;

--
-- Name: district_representative district_representative public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "district_representative public read" ON public.district_representative FOR SELECT TO anon, authenticated USING ((status <> 'rejected'::text));


--
-- Name: doc_chunk; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.doc_chunk ENABLE ROW LEVEL SECURITY;

--
-- Name: doc_chunk_embedding; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.doc_chunk_embedding ENABLE ROW LEVEL SECURITY;

--
-- Name: doc_embedding_model; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.doc_embedding_model ENABLE ROW LEVEL SECURITY;

--
-- Name: doc_source; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.doc_source ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_bhw_raw; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_bhw_raw ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_fhsis_indicator; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_fhsis_indicator ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_fhsis_indicator fact_fhsis_indicator public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "fact_fhsis_indicator public read" ON public.fact_fhsis_indicator FOR SELECT TO anon, authenticated USING (true);


--
-- Name: fact_fhsis_workforce; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_fhsis_workforce ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_fhsis_workforce fact_fhsis_workforce public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "fact_fhsis_workforce public read" ON public.fact_fhsis_workforce FOR SELECT TO anon, authenticated USING (true);


--
-- Name: fact_honorarium; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_honorarium ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_nhfr_facility; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_nhfr_facility ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_nhfr_facility fact_nhfr_facility public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "fact_nhfr_facility public read" ON public.fact_nhfr_facility FOR SELECT TO anon, authenticated USING (true);


--
-- Name: fact_uuc_phc_barangay; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_uuc_phc_barangay ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_uuc_phc_barangay fact_uuc_phc_barangay public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "fact_uuc_phc_barangay public read" ON public.fact_uuc_phc_barangay FOR SELECT TO anon, authenticated USING (true);


--
-- Name: fact_uuc_phc_indicators; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fact_uuc_phc_indicators ENABLE ROW LEVEL SECURITY;

--
-- Name: fact_uuc_phc_indicators fact_uuc_phc_indicators public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "fact_uuc_phc_indicators public read" ON public.fact_uuc_phc_indicators FOR SELECT TO anon, authenticated USING (true);


--
-- Name: feedback; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.feedback ENABLE ROW LEVEL SECURITY;

--
-- Name: feedback feedback public insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "feedback public insert" ON public.feedback FOR INSERT TO anon, authenticated WITH CHECK (true);


--
-- Name: geo_district_map; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.geo_district_map ENABLE ROW LEVEL SECURITY;

--
-- Name: geo_district_map geo_district_map public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "geo_district_map public read" ON public.geo_district_map FOR SELECT TO anon, authenticated USING ((status <> 'rejected'::text));


--
-- Name: ingestion_batches; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ingestion_batches ENABLE ROW LEVEL SECURITY;

--
-- Name: kb_contradiction; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.kb_contradiction ENABLE ROW LEVEL SECURITY;

--
-- Name: kb_edge; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.kb_edge ENABLE ROW LEVEL SECURITY;

--
-- Name: kb_node; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.kb_node ENABLE ROW LEVEL SECURITY;

--
-- Name: ref_fhsis_indicator; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ref_fhsis_indicator ENABLE ROW LEVEL SECURITY;

--
-- Name: ref_fhsis_indicator ref_fhsis_indicator public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "ref_fhsis_indicator public read" ON public.ref_fhsis_indicator FOR SELECT TO anon, authenticated USING (true);


--
-- Name: ref_uuc_phc_published_delta; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ref_uuc_phc_published_delta ENABLE ROW LEVEL SECURITY;

--
-- Name: ref_uuc_phc_published_delta ref_uuc_phc_published_delta public read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "ref_uuc_phc_published_delta public read" ON public.ref_uuc_phc_published_delta FOR SELECT TO anon, authenticated USING (true);


--
-- Name: usage_events; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.usage_events ENABLE ROW LEVEL SECURITY;

--
-- Name: usage_events usage_events public insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY "usage_events public insert" ON public.usage_events FOR INSERT TO anon, authenticated WITH CHECK (true);


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: -
--

GRANT USAGE ON SCHEMA public TO anon;
GRANT USAGE ON SCHEMA public TO authenticated;
GRANT USAGE ON SCHEMA public TO service_role;


--
-- Name: FUNCTION ai_regression_expectation_well_formed(p jsonb); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.ai_regression_expectation_well_formed(p jsonb) TO anon;
GRANT ALL ON FUNCTION public.ai_regression_expectation_well_formed(p jsonb) TO authenticated;
GRANT ALL ON FUNCTION public.ai_regression_expectation_well_formed(p jsonb) TO service_role;


--
-- Name: FUNCTION district_dataset_gaps(); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.district_dataset_gaps() TO anon;
GRANT ALL ON FUNCTION public.district_dataset_gaps() TO authenticated;
GRANT ALL ON FUNCTION public.district_dataset_gaps() TO service_role;


--
-- Name: FUNCTION district_gap_members(p_district_code text); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.district_gap_members(p_district_code text) TO anon;
GRANT ALL ON FUNCTION public.district_gap_members(p_district_code text) TO authenticated;
GRANT ALL ON FUNCTION public.district_gap_members(p_district_code text) TO service_role;


--
-- Name: FUNCTION district_index(p_dataset_id bigint); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.district_index(p_dataset_id bigint) TO anon;
GRANT ALL ON FUNCTION public.district_index(p_dataset_id bigint) TO authenticated;
GRANT ALL ON FUNCTION public.district_index(p_dataset_id bigint) TO service_role;


--
-- Name: FUNCTION harvest_ask_cache_cases(p_statement_timeout text); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.harvest_ask_cache_cases(p_statement_timeout text) TO anon;
GRANT ALL ON FUNCTION public.harvest_ask_cache_cases(p_statement_timeout text) TO authenticated;
GRANT ALL ON FUNCTION public.harvest_ask_cache_cases(p_statement_timeout text) TO service_role;


--
-- Name: FUNCTION kb_edge_endpoints_are_approved(); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.kb_edge_endpoints_are_approved() TO anon;
GRANT ALL ON FUNCTION public.kb_edge_endpoints_are_approved() TO authenticated;
GRANT ALL ON FUNCTION public.kb_edge_endpoints_are_approved() TO service_role;


--
-- Name: FUNCTION kb_evidence_is_grounded(); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.kb_evidence_is_grounded() TO anon;
GRANT ALL ON FUNCTION public.kb_evidence_is_grounded() TO authenticated;
GRANT ALL ON FUNCTION public.kb_evidence_is_grounded() TO service_role;


--
-- Name: FUNCTION kb_generic_terms(); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.kb_generic_terms() TO anon;
GRANT ALL ON FUNCTION public.kb_generic_terms() TO authenticated;
GRANT ALL ON FUNCTION public.kb_generic_terms() TO service_role;


--
-- Name: FUNCTION kb_node_keeps_its_approved_edges(); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.kb_node_keeps_its_approved_edges() TO anon;
GRANT ALL ON FUNCTION public.kb_node_keeps_its_approved_edges() TO authenticated;
GRANT ALL ON FUNCTION public.kb_node_keeps_its_approved_edges() TO service_role;


--
-- Name: FUNCTION kb_terms(p_text text); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.kb_terms(p_text text) TO anon;
GRANT ALL ON FUNCTION public.kb_terms(p_text text) TO authenticated;
GRANT ALL ON FUNCTION public.kb_terms(p_text text) TO service_role;


--
-- Name: FUNCTION map_psgc_to_dim_geo(p_code text, p_old_vintage text); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.map_psgc_to_dim_geo(p_code text, p_old_vintage text) TO anon;
GRANT ALL ON FUNCTION public.map_psgc_to_dim_geo(p_code text, p_old_vintage text) TO authenticated;
GRANT ALL ON FUNCTION public.map_psgc_to_dim_geo(p_code text, p_old_vintage text) TO service_role;


--
-- Name: FUNCTION match_ask_answer(q text, scope text, version text, dataset text, min_sim real); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.match_ask_answer(q text, scope text, version text, dataset text, min_sim real) TO anon;
GRANT ALL ON FUNCTION public.match_ask_answer(q text, scope text, version text, dataset text, min_sim real) TO authenticated;
GRANT ALL ON FUNCTION public.match_ask_answer(q text, scope text, version text, dataset text, min_sim real) TO service_role;


--
-- Name: FUNCTION profile_dataset(p_table text, p_title text, p_summary text, p_grain text, p_dataset_slug text, p_force boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.profile_dataset(p_table text, p_title text, p_summary text, p_grain text, p_dataset_slug text, p_force boolean) TO anon;
GRANT ALL ON FUNCTION public.profile_dataset(p_table text, p_title text, p_summary text, p_grain text, p_dataset_slug text, p_force boolean) TO authenticated;
GRANT ALL ON FUNCTION public.profile_dataset(p_table text, p_title text, p_summary text, p_grain text, p_dataset_slug text, p_force boolean) TO service_role;


--
-- Name: FUNCTION profile_dataset_refusal(p_table text); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.profile_dataset_refusal(p_table text) TO anon;
GRANT ALL ON FUNCTION public.profile_dataset_refusal(p_table text) TO authenticated;
GRANT ALL ON FUNCTION public.profile_dataset_refusal(p_table text) TO service_role;


--
-- Name: FUNCTION profile_dataset_role(p_data_type text, p_column_name text, p_is_join_key boolean, p_distinct bigint, p_row_estimate bigint); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.profile_dataset_role(p_data_type text, p_column_name text, p_is_join_key boolean, p_distinct bigint, p_row_estimate bigint) TO anon;
GRANT ALL ON FUNCTION public.profile_dataset_role(p_data_type text, p_column_name text, p_is_join_key boolean, p_distinct bigint, p_row_estimate bigint) TO authenticated;
GRANT ALL ON FUNCTION public.profile_dataset_role(p_data_type text, p_column_name text, p_is_join_key boolean, p_distinct bigint, p_row_estimate bigint) TO service_role;


--
-- Name: FUNCTION search_district(search_query text, result_limit integer); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.search_district(search_query text, result_limit integer) TO anon;
GRANT ALL ON FUNCTION public.search_district(search_query text, result_limit integer) TO authenticated;
GRANT ALL ON FUNCTION public.search_district(search_query text, result_limit integer) TO service_role;


--
-- Name: FUNCTION search_documents(p_query text, p_limit integer, p_embedding text, p_model text, p_doc_key text, p_min_lexical real); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.search_documents(p_query text, p_limit integer, p_embedding text, p_model text, p_doc_key text, p_min_lexical real) TO anon;
GRANT ALL ON FUNCTION public.search_documents(p_query text, p_limit integer, p_embedding text, p_model text, p_doc_key text, p_min_lexical real) TO authenticated;
GRANT ALL ON FUNCTION public.search_documents(p_query text, p_limit integer, p_embedding text, p_model text, p_doc_key text, p_min_lexical real) TO service_role;


--
-- Name: FUNCTION search_geo(search_query text, result_limit integer); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.search_geo(search_query text, result_limit integer) TO anon;
GRANT ALL ON FUNCTION public.search_geo(search_query text, result_limit integer) TO authenticated;
GRANT ALL ON FUNCTION public.search_geo(search_query text, result_limit integer) TO service_role;


--
-- Name: FUNCTION sweep_contradictions(p_min_value numeric, p_rel_tolerance numeric, p_min_cells integer, p_min_fit numeric, p_max_rows integer, p_statement_timeout text); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.sweep_contradictions(p_min_value numeric, p_rel_tolerance numeric, p_min_cells integer, p_min_fit numeric, p_max_rows integer, p_statement_timeout text) TO anon;
GRANT ALL ON FUNCTION public.sweep_contradictions(p_min_value numeric, p_rel_tolerance numeric, p_min_cells integer, p_min_fit numeric, p_max_rows integer, p_statement_timeout text) TO authenticated;
GRANT ALL ON FUNCTION public.sweep_contradictions(p_min_value numeric, p_rel_tolerance numeric, p_min_cells integer, p_min_fit numeric, p_max_rows integer, p_statement_timeout text) TO service_role;


--
-- Name: FUNCTION traverse_geo(start_code text, direction text, max_depth integer, row_cap integer); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.traverse_geo(start_code text, direction text, max_depth integer, row_cap integer) TO anon;
GRANT ALL ON FUNCTION public.traverse_geo(start_code text, direction text, max_depth integer, row_cap integer) TO authenticated;
GRANT ALL ON FUNCTION public.traverse_geo(start_code text, direction text, max_depth integer, row_cap integer) TO service_role;


--
-- Name: FUNCTION traverse_kb(start_key text, direction text, relations text[], max_depth integer, row_cap integer, as_of date); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.traverse_kb(start_key text, direction text, relations text[], max_depth integer, row_cap integer, as_of date) TO anon;
GRANT ALL ON FUNCTION public.traverse_kb(start_key text, direction text, relations text[], max_depth integer, row_cap integer, as_of date) TO authenticated;
GRANT ALL ON FUNCTION public.traverse_kb(start_key text, direction text, relations text[], max_depth integer, row_cap integer, as_of date) TO service_role;


--
-- Name: FUNCTION wilson_high(k numeric, n numeric); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.wilson_high(k numeric, n numeric) TO anon;
GRANT ALL ON FUNCTION public.wilson_high(k numeric, n numeric) TO authenticated;
GRANT ALL ON FUNCTION public.wilson_high(k numeric, n numeric) TO service_role;


--
-- Name: FUNCTION wilson_low(k numeric, n numeric); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.wilson_low(k numeric, n numeric) TO anon;
GRANT ALL ON FUNCTION public.wilson_low(k numeric, n numeric) TO authenticated;
GRANT ALL ON FUNCTION public.wilson_low(k numeric, n numeric) TO service_role;


--
-- Name: TABLE admin_users; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.admin_users TO anon;
GRANT ALL ON TABLE public.admin_users TO authenticated;
GRANT ALL ON TABLE public.admin_users TO service_role;


--
-- Name: TABLE agg_bhw_by_district; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_bhw_by_district TO anon;
GRANT ALL ON TABLE public.agg_bhw_by_district TO authenticated;
GRANT ALL ON TABLE public.agg_bhw_by_district TO service_role;


--
-- Name: SEQUENCE agg_bhw_by_district_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_bhw_by_district_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_bhw_by_district_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_bhw_by_district_id_seq TO service_role;


--
-- Name: TABLE agg_bhw_by_uuc_status; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_bhw_by_uuc_status TO anon;
GRANT ALL ON TABLE public.agg_bhw_by_uuc_status TO authenticated;
GRANT ALL ON TABLE public.agg_bhw_by_uuc_status TO service_role;


--
-- Name: SEQUENCE agg_bhw_by_uuc_status_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_bhw_by_uuc_status_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_bhw_by_uuc_status_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_bhw_by_uuc_status_id_seq TO service_role;


--
-- Name: TABLE agg_bhw_counts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_bhw_counts TO anon;
GRANT ALL ON TABLE public.agg_bhw_counts TO authenticated;
GRANT ALL ON TABLE public.agg_bhw_counts TO service_role;


--
-- Name: SEQUENCE agg_bhw_counts_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_bhw_counts_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_bhw_counts_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_bhw_counts_id_seq TO service_role;


--
-- Name: TABLE agg_bhw_profiling_status; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_bhw_profiling_status TO anon;
GRANT ALL ON TABLE public.agg_bhw_profiling_status TO authenticated;
GRANT ALL ON TABLE public.agg_bhw_profiling_status TO service_role;


--
-- Name: SEQUENCE agg_bhw_profiling_status_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_bhw_profiling_status_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_bhw_profiling_status_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_bhw_profiling_status_id_seq TO service_role;


--
-- Name: TABLE agg_bhw_stepzero_counts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_bhw_stepzero_counts TO anon;
GRANT ALL ON TABLE public.agg_bhw_stepzero_counts TO authenticated;
GRANT ALL ON TABLE public.agg_bhw_stepzero_counts TO service_role;


--
-- Name: SEQUENCE agg_bhw_stepzero_counts_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_bhw_stepzero_counts_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_bhw_stepzero_counts_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_bhw_stepzero_counts_id_seq TO service_role;


--
-- Name: TABLE agg_by_income_class; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_by_income_class TO anon;
GRANT ALL ON TABLE public.agg_by_income_class TO authenticated;
GRANT ALL ON TABLE public.agg_by_income_class TO service_role;


--
-- Name: SEQUENCE agg_by_income_class_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_by_income_class_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_by_income_class_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_by_income_class_id_seq TO service_role;


--
-- Name: TABLE agg_certification; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_certification TO anon;
GRANT ALL ON TABLE public.agg_certification TO authenticated;
GRANT ALL ON TABLE public.agg_certification TO service_role;


--
-- Name: SEQUENCE agg_certification_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_certification_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_certification_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_certification_id_seq TO service_role;


--
-- Name: TABLE agg_cohorts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_cohorts TO anon;
GRANT ALL ON TABLE public.agg_cohorts TO authenticated;
GRANT ALL ON TABLE public.agg_cohorts TO service_role;


--
-- Name: SEQUENCE agg_cohorts_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_cohorts_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_cohorts_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_cohorts_id_seq TO service_role;


--
-- Name: TABLE agg_data_completeness; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_data_completeness TO anon;
GRANT ALL ON TABLE public.agg_data_completeness TO authenticated;
GRANT ALL ON TABLE public.agg_data_completeness TO service_role;


--
-- Name: SEQUENCE agg_data_completeness_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_data_completeness_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_data_completeness_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_data_completeness_id_seq TO service_role;


--
-- Name: TABLE agg_demographics; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_demographics TO anon;
GRANT ALL ON TABLE public.agg_demographics TO authenticated;
GRANT ALL ON TABLE public.agg_demographics TO service_role;


--
-- Name: SEQUENCE agg_demographics_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_demographics_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_demographics_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_demographics_id_seq TO service_role;


--
-- Name: TABLE agg_geo_summary; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_geo_summary TO anon;
GRANT ALL ON TABLE public.agg_geo_summary TO authenticated;
GRANT ALL ON TABLE public.agg_geo_summary TO service_role;


--
-- Name: TABLE agg_honorarium; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_honorarium TO anon;
GRANT ALL ON TABLE public.agg_honorarium TO authenticated;
GRANT ALL ON TABLE public.agg_honorarium TO service_role;


--
-- Name: TABLE agg_honorarium_cumulative; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_honorarium_cumulative TO anon;
GRANT ALL ON TABLE public.agg_honorarium_cumulative TO authenticated;
GRANT ALL ON TABLE public.agg_honorarium_cumulative TO service_role;


--
-- Name: SEQUENCE agg_honorarium_cumulative_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_honorarium_cumulative_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_honorarium_cumulative_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_honorarium_cumulative_id_seq TO service_role;


--
-- Name: SEQUENCE agg_honorarium_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_honorarium_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_honorarium_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_honorarium_id_seq TO service_role;


--
-- Name: TABLE agg_honorarium_inequality; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_honorarium_inequality TO anon;
GRANT ALL ON TABLE public.agg_honorarium_inequality TO authenticated;
GRANT ALL ON TABLE public.agg_honorarium_inequality TO service_role;


--
-- Name: SEQUENCE agg_honorarium_inequality_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_honorarium_inequality_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_honorarium_inequality_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_honorarium_inequality_id_seq TO service_role;


--
-- Name: TABLE agg_nhfr_by_type; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_nhfr_by_type TO anon;
GRANT ALL ON TABLE public.agg_nhfr_by_type TO authenticated;
GRANT ALL ON TABLE public.agg_nhfr_by_type TO service_role;


--
-- Name: SEQUENCE agg_nhfr_by_type_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_nhfr_by_type_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_nhfr_by_type_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_nhfr_by_type_id_seq TO service_role;


--
-- Name: TABLE agg_nhfr_counts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_nhfr_counts TO anon;
GRANT ALL ON TABLE public.agg_nhfr_counts TO authenticated;
GRANT ALL ON TABLE public.agg_nhfr_counts TO service_role;


--
-- Name: SEQUENCE agg_nhfr_counts_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_nhfr_counts_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_nhfr_counts_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_nhfr_counts_id_seq TO service_role;


--
-- Name: TABLE agg_peer_ranks; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_peer_ranks TO anon;
GRANT ALL ON TABLE public.agg_peer_ranks TO authenticated;
GRANT ALL ON TABLE public.agg_peer_ranks TO service_role;


--
-- Name: SEQUENCE agg_peer_ranks_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_peer_ranks_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_peer_ranks_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_peer_ranks_id_seq TO service_role;


--
-- Name: TABLE agg_population; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_population TO anon;
GRANT ALL ON TABLE public.agg_population TO authenticated;
GRANT ALL ON TABLE public.agg_population TO service_role;


--
-- Name: SEQUENCE agg_population_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_population_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_population_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_population_id_seq TO service_role;


--
-- Name: TABLE agg_poverty; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_poverty TO anon;
GRANT ALL ON TABLE public.agg_poverty TO authenticated;
GRANT ALL ON TABLE public.agg_poverty TO service_role;


--
-- Name: SEQUENCE agg_poverty_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_poverty_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_poverty_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_poverty_id_seq TO service_role;


--
-- Name: TABLE agg_training; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_training TO anon;
GRANT ALL ON TABLE public.agg_training TO authenticated;
GRANT ALL ON TABLE public.agg_training TO service_role;


--
-- Name: SEQUENCE agg_training_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_training_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_training_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_training_id_seq TO service_role;


--
-- Name: TABLE agg_uuc_phc_counts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_uuc_phc_counts TO anon;
GRANT ALL ON TABLE public.agg_uuc_phc_counts TO authenticated;
GRANT ALL ON TABLE public.agg_uuc_phc_counts TO service_role;


--
-- Name: SEQUENCE agg_uuc_phc_counts_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_uuc_phc_counts_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_uuc_phc_counts_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_uuc_phc_counts_id_seq TO service_role;


--
-- Name: TABLE agg_uuc_phc_criteria; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_uuc_phc_criteria TO anon;
GRANT ALL ON TABLE public.agg_uuc_phc_criteria TO authenticated;
GRANT ALL ON TABLE public.agg_uuc_phc_criteria TO service_role;


--
-- Name: SEQUENCE agg_uuc_phc_criteria_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_uuc_phc_criteria_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_uuc_phc_criteria_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_uuc_phc_criteria_id_seq TO service_role;


--
-- Name: TABLE agg_uuc_phc_indicator_dist; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_uuc_phc_indicator_dist TO anon;
GRANT ALL ON TABLE public.agg_uuc_phc_indicator_dist TO authenticated;
GRANT ALL ON TABLE public.agg_uuc_phc_indicator_dist TO service_role;


--
-- Name: SEQUENCE agg_uuc_phc_indicator_dist_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_uuc_phc_indicator_dist_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_uuc_phc_indicator_dist_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_uuc_phc_indicator_dist_id_seq TO service_role;


--
-- Name: TABLE agg_workload; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.agg_workload TO anon;
GRANT ALL ON TABLE public.agg_workload TO authenticated;
GRANT ALL ON TABLE public.agg_workload TO service_role;


--
-- Name: SEQUENCE agg_workload_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.agg_workload_id_seq TO anon;
GRANT ALL ON SEQUENCE public.agg_workload_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.agg_workload_id_seq TO service_role;


--
-- Name: TABLE ai_ask_cache; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ai_ask_cache TO anon;
GRANT ALL ON TABLE public.ai_ask_cache TO authenticated;
GRANT ALL ON TABLE public.ai_ask_cache TO service_role;


--
-- Name: TABLE ai_ask_log; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ai_ask_log TO anon;
GRANT ALL ON TABLE public.ai_ask_log TO authenticated;
GRANT ALL ON TABLE public.ai_ask_log TO service_role;


--
-- Name: SEQUENCE ai_ask_log_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.ai_ask_log_id_seq TO anon;
GRANT ALL ON SEQUENCE public.ai_ask_log_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.ai_ask_log_id_seq TO service_role;


--
-- Name: TABLE ai_narrative_cache; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ai_narrative_cache TO anon;
GRANT ALL ON TABLE public.ai_narrative_cache TO authenticated;
GRANT ALL ON TABLE public.ai_narrative_cache TO service_role;


--
-- Name: TABLE ai_provider_quota; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ai_provider_quota TO anon;
GRANT ALL ON TABLE public.ai_provider_quota TO authenticated;
GRANT ALL ON TABLE public.ai_provider_quota TO service_role;


--
-- Name: SEQUENCE ai_provider_quota_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.ai_provider_quota_id_seq TO anon;
GRANT ALL ON SEQUENCE public.ai_provider_quota_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.ai_provider_quota_id_seq TO service_role;


--
-- Name: TABLE ai_regression_case; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ai_regression_case TO anon;
GRANT ALL ON TABLE public.ai_regression_case TO authenticated;
GRANT ALL ON TABLE public.ai_regression_case TO service_role;


--
-- Name: SEQUENCE ai_regression_case_case_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.ai_regression_case_case_id_seq TO anon;
GRANT ALL ON SEQUENCE public.ai_regression_case_case_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.ai_regression_case_case_id_seq TO service_role;


--
-- Name: TABLE ai_regression_run; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ai_regression_run TO anon;
GRANT ALL ON TABLE public.ai_regression_run TO authenticated;
GRANT ALL ON TABLE public.ai_regression_run TO service_role;


--
-- Name: SEQUENCE ai_regression_run_run_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.ai_regression_run_run_id_seq TO anon;
GRANT ALL ON SEQUENCE public.ai_regression_run_run_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.ai_regression_run_run_id_seq TO service_role;


--
-- Name: TABLE changelog_entries; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.changelog_entries TO anon;
GRANT ALL ON TABLE public.changelog_entries TO authenticated;
GRANT ALL ON TABLE public.changelog_entries TO service_role;


--
-- Name: SEQUENCE changelog_entries_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.changelog_entries_id_seq TO anon;
GRANT ALL ON SEQUENCE public.changelog_entries_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.changelog_entries_id_seq TO service_role;


--
-- Name: TABLE dataset_column; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dataset_column TO anon;
GRANT ALL ON TABLE public.dataset_column TO authenticated;
GRANT ALL ON TABLE public.dataset_column TO service_role;


--
-- Name: SEQUENCE dataset_column_column_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.dataset_column_column_id_seq TO anon;
GRANT ALL ON SEQUENCE public.dataset_column_column_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.dataset_column_column_id_seq TO service_role;


--
-- Name: TABLE dataset_registry; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dataset_registry TO anon;
GRANT ALL ON TABLE public.dataset_registry TO authenticated;
GRANT ALL ON TABLE public.dataset_registry TO service_role;


--
-- Name: SEQUENCE dataset_registry_registry_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.dataset_registry_registry_id_seq TO anon;
GRANT ALL ON SEQUENCE public.dataset_registry_registry_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.dataset_registry_registry_id_seq TO service_role;


--
-- Name: TABLE dim_dataset; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dim_dataset TO anon;
GRANT ALL ON TABLE public.dim_dataset TO authenticated;
GRANT ALL ON TABLE public.dim_dataset TO service_role;


--
-- Name: SEQUENCE dim_dataset_dataset_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.dim_dataset_dataset_id_seq TO anon;
GRANT ALL ON SEQUENCE public.dim_dataset_dataset_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.dim_dataset_dataset_id_seq TO service_role;


--
-- Name: TABLE dim_geo; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dim_geo TO anon;
GRANT ALL ON TABLE public.dim_geo TO authenticated;
GRANT ALL ON TABLE public.dim_geo TO service_role;


--
-- Name: TABLE dim_legislative_district; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dim_legislative_district TO anon;
GRANT ALL ON TABLE public.dim_legislative_district TO authenticated;
GRANT ALL ON TABLE public.dim_legislative_district TO service_role;


--
-- Name: TABLE dim_lgu_income_reclass; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dim_lgu_income_reclass TO anon;
GRANT ALL ON TABLE public.dim_lgu_income_reclass TO authenticated;
GRANT ALL ON TABLE public.dim_lgu_income_reclass TO service_role;


--
-- Name: SEQUENCE dim_lgu_income_reclass_reclass_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.dim_lgu_income_reclass_reclass_id_seq TO anon;
GRANT ALL ON SEQUENCE public.dim_lgu_income_reclass_reclass_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.dim_lgu_income_reclass_reclass_id_seq TO service_role;


--
-- Name: TABLE dim_psgc_crosswalk; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.dim_psgc_crosswalk TO anon;
GRANT ALL ON TABLE public.dim_psgc_crosswalk TO authenticated;
GRANT ALL ON TABLE public.dim_psgc_crosswalk TO service_role;


--
-- Name: SEQUENCE dim_psgc_crosswalk_crosswalk_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.dim_psgc_crosswalk_crosswalk_id_seq TO anon;
GRANT ALL ON SEQUENCE public.dim_psgc_crosswalk_crosswalk_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.dim_psgc_crosswalk_crosswalk_id_seq TO service_role;


--
-- Name: TABLE district_correction; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.district_correction TO anon;
GRANT ALL ON TABLE public.district_correction TO authenticated;
GRANT ALL ON TABLE public.district_correction TO service_role;


--
-- Name: SEQUENCE district_correction_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.district_correction_id_seq TO anon;
GRANT ALL ON SEQUENCE public.district_correction_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.district_correction_id_seq TO service_role;


--
-- Name: TABLE district_representative; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.district_representative TO anon;
GRANT ALL ON TABLE public.district_representative TO authenticated;
GRANT ALL ON TABLE public.district_representative TO service_role;


--
-- Name: SEQUENCE district_representative_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.district_representative_id_seq TO anon;
GRANT ALL ON SEQUENCE public.district_representative_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.district_representative_id_seq TO service_role;


--
-- Name: TABLE doc_chunk; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.doc_chunk TO anon;
GRANT ALL ON TABLE public.doc_chunk TO authenticated;
GRANT ALL ON TABLE public.doc_chunk TO service_role;


--
-- Name: SEQUENCE doc_chunk_chunk_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.doc_chunk_chunk_id_seq TO anon;
GRANT ALL ON SEQUENCE public.doc_chunk_chunk_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.doc_chunk_chunk_id_seq TO service_role;


--
-- Name: TABLE doc_chunk_embedding; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.doc_chunk_embedding TO anon;
GRANT ALL ON TABLE public.doc_chunk_embedding TO authenticated;
GRANT ALL ON TABLE public.doc_chunk_embedding TO service_role;


--
-- Name: TABLE doc_embedding_model; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.doc_embedding_model TO anon;
GRANT ALL ON TABLE public.doc_embedding_model TO authenticated;
GRANT ALL ON TABLE public.doc_embedding_model TO service_role;


--
-- Name: TABLE doc_source; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.doc_source TO anon;
GRANT ALL ON TABLE public.doc_source TO authenticated;
GRANT ALL ON TABLE public.doc_source TO service_role;


--
-- Name: SEQUENCE doc_source_doc_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.doc_source_doc_id_seq TO anon;
GRANT ALL ON SEQUENCE public.doc_source_doc_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.doc_source_doc_id_seq TO service_role;


--
-- Name: TABLE fact_bhw_raw; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_bhw_raw TO anon;
GRANT ALL ON TABLE public.fact_bhw_raw TO authenticated;
GRANT ALL ON TABLE public.fact_bhw_raw TO service_role;


--
-- Name: SEQUENCE fact_bhw_raw_bhw_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_bhw_raw_bhw_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_bhw_raw_bhw_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_bhw_raw_bhw_id_seq TO service_role;


--
-- Name: TABLE fact_fhsis_indicator; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_fhsis_indicator TO anon;
GRANT ALL ON TABLE public.fact_fhsis_indicator TO authenticated;
GRANT ALL ON TABLE public.fact_fhsis_indicator TO service_role;


--
-- Name: SEQUENCE fact_fhsis_indicator_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_fhsis_indicator_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_fhsis_indicator_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_fhsis_indicator_id_seq TO service_role;


--
-- Name: TABLE fact_fhsis_workforce; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_fhsis_workforce TO anon;
GRANT ALL ON TABLE public.fact_fhsis_workforce TO authenticated;
GRANT ALL ON TABLE public.fact_fhsis_workforce TO service_role;


--
-- Name: SEQUENCE fact_fhsis_workforce_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_fhsis_workforce_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_fhsis_workforce_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_fhsis_workforce_id_seq TO service_role;


--
-- Name: TABLE fact_honorarium; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_honorarium TO anon;
GRANT ALL ON TABLE public.fact_honorarium TO authenticated;
GRANT ALL ON TABLE public.fact_honorarium TO service_role;


--
-- Name: SEQUENCE fact_honorarium_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_honorarium_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_honorarium_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_honorarium_id_seq TO service_role;


--
-- Name: TABLE fact_nhfr_facility; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_nhfr_facility TO anon;
GRANT ALL ON TABLE public.fact_nhfr_facility TO authenticated;
GRANT ALL ON TABLE public.fact_nhfr_facility TO service_role;


--
-- Name: SEQUENCE fact_nhfr_facility_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_nhfr_facility_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_nhfr_facility_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_nhfr_facility_id_seq TO service_role;


--
-- Name: TABLE fact_uuc_phc_barangay; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_uuc_phc_barangay TO anon;
GRANT ALL ON TABLE public.fact_uuc_phc_barangay TO authenticated;
GRANT ALL ON TABLE public.fact_uuc_phc_barangay TO service_role;


--
-- Name: SEQUENCE fact_uuc_phc_barangay_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_uuc_phc_barangay_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_uuc_phc_barangay_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_uuc_phc_barangay_id_seq TO service_role;


--
-- Name: TABLE fact_uuc_phc_indicators; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.fact_uuc_phc_indicators TO anon;
GRANT ALL ON TABLE public.fact_uuc_phc_indicators TO authenticated;
GRANT ALL ON TABLE public.fact_uuc_phc_indicators TO service_role;


--
-- Name: SEQUENCE fact_uuc_phc_indicators_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.fact_uuc_phc_indicators_id_seq TO anon;
GRANT ALL ON SEQUENCE public.fact_uuc_phc_indicators_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.fact_uuc_phc_indicators_id_seq TO service_role;


--
-- Name: TABLE feedback; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.feedback TO anon;
GRANT ALL ON TABLE public.feedback TO authenticated;
GRANT ALL ON TABLE public.feedback TO service_role;


--
-- Name: SEQUENCE feedback_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.feedback_id_seq TO anon;
GRANT ALL ON SEQUENCE public.feedback_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.feedback_id_seq TO service_role;


--
-- Name: TABLE geo_district_map; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.geo_district_map TO anon;
GRANT ALL ON TABLE public.geo_district_map TO authenticated;
GRANT ALL ON TABLE public.geo_district_map TO service_role;


--
-- Name: SEQUENCE geo_district_map_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.geo_district_map_id_seq TO anon;
GRANT ALL ON SEQUENCE public.geo_district_map_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.geo_district_map_id_seq TO service_role;


--
-- Name: TABLE ingestion_batches; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ingestion_batches TO anon;
GRANT ALL ON TABLE public.ingestion_batches TO authenticated;
GRANT ALL ON TABLE public.ingestion_batches TO service_role;


--
-- Name: SEQUENCE ingestion_batches_batch_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.ingestion_batches_batch_id_seq TO anon;
GRANT ALL ON SEQUENCE public.ingestion_batches_batch_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.ingestion_batches_batch_id_seq TO service_role;


--
-- Name: TABLE kb_contradiction; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.kb_contradiction TO anon;
GRANT ALL ON TABLE public.kb_contradiction TO authenticated;
GRANT ALL ON TABLE public.kb_contradiction TO service_role;


--
-- Name: SEQUENCE kb_contradiction_contradiction_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.kb_contradiction_contradiction_id_seq TO anon;
GRANT ALL ON SEQUENCE public.kb_contradiction_contradiction_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.kb_contradiction_contradiction_id_seq TO service_role;


--
-- Name: TABLE kb_doc_line; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.kb_doc_line TO anon;
GRANT ALL ON TABLE public.kb_doc_line TO authenticated;
GRANT ALL ON TABLE public.kb_doc_line TO service_role;


--
-- Name: TABLE kb_doc_label_number; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.kb_doc_label_number TO anon;
GRANT ALL ON TABLE public.kb_doc_label_number TO authenticated;
GRANT ALL ON TABLE public.kb_doc_label_number TO service_role;


--
-- Name: TABLE kb_edge; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.kb_edge TO anon;
GRANT ALL ON TABLE public.kb_edge TO authenticated;
GRANT ALL ON TABLE public.kb_edge TO service_role;


--
-- Name: SEQUENCE kb_edge_edge_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.kb_edge_edge_id_seq TO anon;
GRANT ALL ON SEQUENCE public.kb_edge_edge_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.kb_edge_edge_id_seq TO service_role;


--
-- Name: TABLE kb_node; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.kb_node TO anon;
GRANT ALL ON TABLE public.kb_node TO authenticated;
GRANT ALL ON TABLE public.kb_node TO service_role;


--
-- Name: SEQUENCE kb_node_node_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.kb_node_node_id_seq TO anon;
GRANT ALL ON SEQUENCE public.kb_node_node_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.kb_node_node_id_seq TO service_role;


--
-- Name: TABLE ref_fhsis_indicator; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_fhsis_indicator TO anon;
GRANT ALL ON TABLE public.ref_fhsis_indicator TO authenticated;
GRANT ALL ON TABLE public.ref_fhsis_indicator TO service_role;


--
-- Name: TABLE ref_fhsis_reconciliation; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_fhsis_reconciliation TO anon;
GRANT ALL ON TABLE public.ref_fhsis_reconciliation TO authenticated;
GRANT ALL ON TABLE public.ref_fhsis_reconciliation TO service_role;


--
-- Name: TABLE ref_fhsis_reconciliation_exact; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_fhsis_reconciliation_exact TO anon;
GRANT ALL ON TABLE public.ref_fhsis_reconciliation_exact TO authenticated;
GRANT ALL ON TABLE public.ref_fhsis_reconciliation_exact TO service_role;


--
-- Name: TABLE ref_uuc_phc_benchmark_gaps; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_uuc_phc_benchmark_gaps TO anon;
GRANT ALL ON TABLE public.ref_uuc_phc_benchmark_gaps TO authenticated;
GRANT ALL ON TABLE public.ref_uuc_phc_benchmark_gaps TO service_role;


--
-- Name: TABLE ref_uuc_phc_list; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_uuc_phc_list TO anon;
GRANT ALL ON TABLE public.ref_uuc_phc_list TO authenticated;
GRANT ALL ON TABLE public.ref_uuc_phc_list TO service_role;


--
-- Name: TABLE ref_uuc_phc_provincial; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_uuc_phc_provincial TO anon;
GRANT ALL ON TABLE public.ref_uuc_phc_provincial TO authenticated;
GRANT ALL ON TABLE public.ref_uuc_phc_provincial TO service_role;


--
-- Name: TABLE ref_uuc_phc_published_delta; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_uuc_phc_published_delta TO anon;
GRANT ALL ON TABLE public.ref_uuc_phc_published_delta TO authenticated;
GRANT ALL ON TABLE public.ref_uuc_phc_published_delta TO service_role;


--
-- Name: SEQUENCE ref_uuc_phc_published_delta_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.ref_uuc_phc_published_delta_id_seq TO anon;
GRANT ALL ON SEQUENCE public.ref_uuc_phc_published_delta_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.ref_uuc_phc_published_delta_id_seq TO service_role;


--
-- Name: TABLE ref_uuc_phc_quality; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.ref_uuc_phc_quality TO anon;
GRANT ALL ON TABLE public.ref_uuc_phc_quality TO authenticated;
GRANT ALL ON TABLE public.ref_uuc_phc_quality TO service_role;


--
-- Name: TABLE usage_events; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.usage_events TO anon;
GRANT ALL ON TABLE public.usage_events TO authenticated;
GRANT ALL ON TABLE public.usage_events TO service_role;


--
-- Name: SEQUENCE usage_events_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.usage_events_id_seq TO anon;
GRANT ALL ON SEQUENCE public.usage_events_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.usage_events_id_seq TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: public; Owner: -
--

ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON SEQUENCES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON SEQUENCES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON SEQUENCES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: public; Owner: -
--

ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON FUNCTIONS  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON FUNCTIONS  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON FUNCTIONS  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: -
--

ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON TABLES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON TABLES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public GRANT ALL ON TABLES  TO service_role;


--
-- PostgreSQL database dump complete
--



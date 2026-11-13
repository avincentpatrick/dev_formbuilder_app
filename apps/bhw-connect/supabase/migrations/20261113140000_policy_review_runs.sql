-- Private owner-scoped full-policy reviews. Coverage is computed by the server, never by a model.
create table policy_review_run (
 id uuid primary key default gen_random_uuid(), actor_id uuid not null, request_key uuid not null,
 question text not null check(length(question) between 1 and 4000), settings jsonb not null,
 dataset_snapshot jsonb not null, status text not null default 'queued' check(status in ('queued','running','partial','completed','cancelled')),
 budget integer not null check(budget between 1 and 10000), used integer not null default 0,
 reason text, created_at timestamptz not null default now(), updated_at timestamptz not null default now(),
 unique(actor_id,request_key)
);
create table policy_review_document (
 id uuid primary key default gen_random_uuid(), run_id uuid not null references policy_review_run(id),
 version_id uuid references document_version(id), extraction_id uuid references document_extraction(id),
 identity_key text not null, metadata jsonb not null, exclusion text, warnings jsonb not null default '[]',
 unique(run_id,identity_key)
);
create table policy_review_section (
 id bigint generated always as identity primary key, run_id uuid not null references policy_review_run(id),
 document_id uuid not null references policy_review_document(id), page integer not null,
 char_start integer not null, char_end integer not null, content_sha256 text not null,
 status text not null default 'pending' check(status in ('pending','running','examined','no_finding','unreadable','failed')),
 attempts integer not null default 0, cycle_attempts integer not null default 0,
 lease uuid, lease_until timestamptz, retry_at timestamptz, safe_error text,
 result jsonb, evidence jsonb, attempts_log jsonb not null default '[]', updated_at timestamptz not null default now(),
 check(char_start>=0 and char_end>=char_start), unique(document_id,page,char_start)
);
create index policy_review_section_claim on policy_review_section(status,retry_at,lease_until,run_id,id);
create index policy_review_runs_owner on policy_review_run(actor_id,created_at desc);
alter table policy_review_run enable row level security;
alter table policy_review_document enable row level security;
alter table policy_review_section enable row level security;
revoke all on policy_review_run,policy_review_document,policy_review_section from anon,authenticated;
grant all on policy_review_run,policy_review_document,policy_review_section to service_role;
grant usage,select on sequence policy_review_section_id_seq to service_role;

create function policy_review_api(p_actor uuid,p_operation text,p_payload jsonb default '{}') returns jsonb
language plpgsql set search_path=public,extensions,pg_temp as $$
declare rid uuid; run policy_review_run%rowtype; v record; e document_extraction%rowtype;
 did uuid; vid uuid; eid uuid; why text; ids uuid[]; revisions uuid[]; page jsonb; pos integer; stop_at integer;
 result jsonb; inventory jsonb; sections jsonb; totals jsonb; groups jsonb; topic text; expected_pos integer; expected_page integer; document_count integer:=0; section_count integer:=0;
begin
 if not exists(select 1 from admin_users where user_id=p_actor and role='admin') or
    not exists(select 1 from analysis_users where user_id=p_actor and approved) then
   raise exception 'Approved admin required' using errcode='42501'; end if;
 if p_operation='list' then
  select coalesce(jsonb_agg(t),'[]') into result from(select id,question,status,reason,used,budget,created_at
   from policy_review_run where actor_id=p_actor order by created_at desc limit 30)t;return result;
 end if;
 if p_operation='create' then
  select * into run from policy_review_run where actor_id=p_actor and request_key=(p_payload->>'requestKey')::uuid;
  if found then return jsonb_build_object('id',run.id); end if;
  if coalesce((p_payload#>>'{sources,online}')::boolean,false) then raise exception 'Online research unavailable'; end if;
  insert into policy_review_run(actor_id,request_key,question,settings,dataset_snapshot,budget)
   values(p_actor,(p_payload->>'requestKey')::uuid,p_payload->>'question',p_payload->'sources',p_payload->'datasets',
    least(greatest(coalesce((p_payload->>'budget')::integer,200),1),1000)) on conflict(actor_id,request_key) do nothing returning id into rid;
  if rid is null then select id into rid from policy_review_run where actor_id=p_actor and request_key=(p_payload->>'requestKey')::uuid;return jsonb_build_object('id',rid);end if;
  ids:=array(select jsonb_array_elements_text(coalesce(p_payload#>'{sources,versions}','[]'))::uuid);
  revisions:=array(select jsonb_array_elements_text(coalesce(p_payload#>'{sources,revisions}','[]'))::uuid);
  for v in select distinct dv.*,c.title,c.issuer,c.document_type,c.collection_key from document_version dv
   join document_catalog c on c.id=dv.document_id where
   (p_payload#>>'{sources,mode}'='active' and (nullif(p_payload#>>'{sources,collection}','') is null or c.collection_key=p_payload#>>'{sources,collection}'))
   or (p_payload#>>'{sources,mode}'='selected' and (dv.id=any(ids) or dv.id in(select version_id from document_extraction where id=any(revisions))))
   order by dv.uploaded_at,dv.id loop
   -- Active scope includes excluded archives in the frozen inventory, not in processing.
   for eid in select x.id from document_extraction x where x.version_id=v.id and
     (x.id=v.current_extraction_id and (p_payload#>>'{sources,mode}'='active' or v.id=any(ids)) or x.id=any(revisions))
     union all select null::uuid where not exists(select 1 from document_extraction x where x.version_id=v.id and
       (x.id=v.current_extraction_id and (p_payload#>>'{sources,mode}'='active' or v.id=any(ids)) or x.id=any(revisions))) loop
    document_count:=document_count+1; if document_count>1000 then raise exception 'Snapshot exceeds 1000 versions; narrow collection';end if;
    why:=null;select * into e from document_extraction where id=eid;
    if eid is null then
      why:=case when exists(select 1 from document_job where version_id=v.id and kind='extract' and stage in ('queued','extracting','ocr','indexing')) then 'processing' else 'failed_or_no_extracted_text' end;
    elsif v.archived and not coalesce((p_payload#>>'{sources,historical}')::boolean,false) then why:='archived';
    elsif eid<>v.current_extraction_id and not coalesce((p_payload#>>'{sources,historical}')::boolean,false) then why:='older_extraction_not_selected_for_history';
    elsif not length(trim(e.canonical_text))>0 then why:='empty_extraction';
    end if;
    insert into policy_review_document(run_id,version_id,extraction_id,identity_key,metadata,exclusion,warnings)
     values(rid,v.id,eid,coalesce(eid,v.id)::text,jsonb_build_object('title',v.title,'issuer',v.issuer,'version_id',v.id,
      'extraction_id',eid,'revision',e.revision,'document','upload:'||eid,'publication_date',v.publication_date,
      'effective_date',v.effective_date,'supersedes_id',v.supersedes_id,'archived_at_start',v.archived,
      'uploaded_at',v.uploaded_at),why,coalesce(e.warnings,'[]')) returning id into did;
    if why is null then
     if jsonb_array_length(e.page_map)<>e.page_count then
      update policy_review_document set exclusion='invalid_page_map' where id=did;
     else
      expected_pos:=0;expected_page:=1;
      for page in select * from jsonb_array_elements(e.page_map) loop
       pos:=(page->>'char_start')::integer;stop_at:=(page->>'char_end')::integer;
       if pos<>expected_pos or (page->>'page')::integer<>expected_page or stop_at<pos or stop_at>length(e.canonical_text) or
          (expected_page<e.page_count and substring(e.canonical_text from stop_at+1 for 1)<>E'\f') then
        update policy_review_document set exclusion='invalid_page_map' where id=did;exit;
       end if;
       expected_pos:=stop_at+1;expected_page:=expected_page+1;
       if pos=stop_at then
        section_count:=section_count+1; if section_count>5000 then raise exception 'Snapshot exceeds 5000 sections; narrow collection';end if;
        insert into policy_review_section(run_id,document_id,page,char_start,char_end,content_sha256,status,safe_error)
         values(rid,did,(page->>'page')::integer,pos,pos,encode(extensions.digest('','sha256'),'hex'),'unreadable','Blank or unreadable physical page');
       end if;
       while pos<stop_at loop
        section_count:=section_count+1; if section_count>5000 then raise exception 'Snapshot exceeds 5000 sections; narrow collection';end if;
        insert into policy_review_section(run_id,document_id,page,char_start,char_end,content_sha256,status,safe_error)
         values(rid,did,(page->>'page')::integer,pos,least(pos+2400,stop_at),
          encode(extensions.digest(substring(e.canonical_text from pos+1 for least(2400,stop_at-pos)),'sha256'),'hex'),
          case when length(trim(substring(e.canonical_text from pos+1 for least(2400,stop_at-pos))))=0 then 'unreadable' else 'pending' end,
          case when length(trim(substring(e.canonical_text from pos+1 for least(2400,stop_at-pos))))=0 then 'No readable extracted text in section' else null end);
        pos:=pos+2400;
       end loop;
      end loop;
      if expected_pos<>length(e.canonical_text)+1 or expected_page<>e.page_count+1 then update policy_review_document set exclusion='invalid_page_map' where id=did;end if;
      if exists(select 1 from policy_review_document where id=did and exclusion is not null) then
       update policy_review_section set status='unreadable',safe_error='Page map does not cover the canonical source text' where document_id=did;
      end if;
     end if;
    end if;
   end loop;
  end loop;
  -- Explicit IDs that could not be resolved are still visible as gaps.
  for vid in select unnest(ids) except select version_id from policy_review_document where run_id=rid loop
   insert into policy_review_document(run_id,identity_key,metadata,exclusion) values(rid,vid::text,jsonb_build_object('title','Unavailable selected version','version_id',vid),'missing_selected_version');
  end loop;
  for eid in select unnest(revisions) except select extraction_id from policy_review_document where run_id=rid loop
   insert into policy_review_document(run_id,identity_key,metadata,exclusion) values(rid,eid::text,jsonb_build_object('title','Unavailable selected extraction','extraction_id',eid),'missing_selected_extraction');
  end loop;
  if (select count(*) from policy_review_document where run_id=rid)>1000 or
     (select count(*) from policy_review_section where run_id=rid)>5000 then raise exception 'Snapshot exceeds 1000 versions or 5000 sections; narrow the collection'; end if;
  if not exists(select 1 from policy_review_document where run_id=rid) then raise exception 'No policies in selected scope'; end if;
  if not exists(select 1 from policy_review_section where run_id=rid and status='pending') then
   update policy_review_run set status='partial',reason='No readable pending sections; inspect excluded material' where id=rid;
  end if;
  return jsonb_build_object('id',rid);
 end if;
 rid:=(p_payload->>'run')::uuid;
 select * into run from policy_review_run where id=rid and actor_id=p_actor for update;
 if not found then raise exception 'Review not available' using errcode='42501'; end if;
 if p_operation='cancel' then
  if run.status='completed' then return '{}';end if;
  update policy_review_run set status='cancelled',reason='Cancelled by requesting admin; unfinished sections were not examined',updated_at=now() where id=rid;
  update policy_review_section set status='pending',lease=null,lease_until=null,
   attempts_log=attempts_log||jsonb_build_array(jsonb_build_object('attempt',attempts,'outcome','cancelled','at',now())) where run_id=rid and status='running';
  return '{}';
 elsif p_operation='resume' then
  if run.status not in ('partial','cancelled') then return '{}';end if;
  update policy_review_section set status='pending',lease=null,lease_until=null,
   attempts_log=attempts_log||jsonb_build_array(jsonb_build_object('attempt',attempts,'outcome','interrupted_for_resume','at',now()))
   where run_id=rid and status='running';
  if coalesce((p_payload->>'retryFailed')::boolean,false) then
   update policy_review_section set status='pending',cycle_attempts=0,retry_at=null where run_id=rid and status='failed';
  end if;
  update policy_review_run set budget=least(10000,greatest(budget,used)+least(greatest(coalesce((p_payload->>'extraBudget')::integer,200),1),1000)),
   status='queued',reason=null,updated_at=now() where id=rid;
  return '{}';
 elsif p_operation='section' then
  select to_jsonb(t) into result from(select s.*,d.metadata,d.extraction_id,
    substring(ex.canonical_text from s.char_start+1 for s.char_end-s.char_start) as source_text,
    encode(extensions.digest(substring(ex.canonical_text from s.char_start+1 for s.char_end-s.char_start),'sha256'),'hex')=s.content_sha256 as integrity_ok
   from policy_review_section s join policy_review_document d on d.id=s.document_id join document_extraction ex on ex.id=d.extraction_id
   where s.run_id=rid and s.id=(p_payload->>'section')::bigint)t;
  if result is null then raise exception 'Section not found'; end if;return result;
 elsif p_operation<>'get' then raise exception 'Unknown review operation'; end if;
 select jsonb_build_object('total',count(*),'examined',count(*) filter(where status='examined'),
   'no_finding',count(*) filter(where status='no_finding'),'unreadable',count(*) filter(where status='unreadable'),
   'failed',count(*) filter(where status='failed'),'pending',count(*) filter(where status='pending'),
   'running',count(*) filter(where status='running')) into totals from policy_review_section where run_id=rid;
 select coalesce(jsonb_agg(t),'[]') into inventory from(select d.*,count(s.id) as sections,
   count(s.id) filter(where s.status in ('examined','no_finding')) as examined_sections
   from policy_review_document d left join policy_review_section s on s.document_id=d.id where d.run_id=rid group by d.id order by d.identity_key)t;
 topic:=nullif(p_payload->>'topic','');
 select coalesce(jsonb_agg(t),'[]') into sections from(select s.*,d.metadata from policy_review_section s join policy_review_document d on d.id=s.document_id
   where s.run_id=rid and (topic is null or exists(select 1 from jsonb_array_elements(coalesce(s.result->'findings','[]')) f where lower(trim(f->>'topic'))=topic))
   order by s.id limit 50 offset greatest(coalesce((p_payload->>'offset')::integer,0),0))t;
 -- Consolidate every accepted finding by topic; no top-hit or model-authored completeness claim.
 select coalesce(jsonb_agg(t),'[]') into groups from(select lower(trim(f->>'topic')) as topic,
   count(*) as findings,count(distinct d.version_id) as versions,
   (count(distinct d.version_id)>1 and count(distinct f->>'comparisonValue') filter(where f->>'kind'='policy_requirement' and nullif(f->>'comparisonValue','') is not null)>1) as comparison_needed
   from policy_review_section s join policy_review_document d on d.id=s.document_id cross join lateral jsonb_array_elements(coalesce(s.result->'findings','[]')) f
   where s.run_id=rid and s.status='examined' group by lower(trim(f->>'topic')) order by lower(trim(f->>'topic')))t;
 return jsonb_build_object('run',to_jsonb(run),'documents',inventory,'coverage',totals,'sections',sections,'groups',groups,
   'complete',run.status='completed','dataset_rows_frozen',false);
end;
$$;
revoke all on function policy_review_api(uuid,text,jsonb) from public,anon,authenticated;
grant execute on function policy_review_api(uuid,text,jsonb) to service_role;

create function policy_review_worker(p_operation text,p_section bigint default null,p_lease uuid default null,p_run uuid default null,p_payload jsonb default '{}') returns jsonb
language plpgsql set search_path=public,extensions,pg_temp as $$
declare r policy_review_run%rowtype; s policy_review_section%rowtype; d policy_review_document%rowtype;
 text_value text; token uuid; complete boolean; why text;
begin
 if p_operation='claim' then
  -- Revocation pauses work; no new evidence is sent to a provider.
  update policy_review_run x set status='partial',reason='Requesting admin is no longer authorized' where x.status in ('queued','running') and
   (not exists(select 1 from admin_users where user_id=x.actor_id and role='admin') or not exists(select 1 from analysis_users where user_id=x.actor_id and approved));
  for r in select * from policy_review_run where status in ('queued','running') and (p_run is null or id=p_run) order by created_at for update skip locked loop
  update policy_review_section set status=case when cycle_attempts>=3 then 'failed' else 'pending' end,
   lease=null,lease_until=null,safe_error='Previous worker stopped; recovered expired lease',
   attempts_log=attempts_log||jsonb_build_array(jsonb_build_object('attempt',attempts,'outcome','abandoned','at',now()))
   where run_id=r.id and status='running' and lease_until<now();

   if r.used>=r.budget then
    if exists(select 1 from policy_review_section where run_id=r.id and status='running') then continue;end if;
    update policy_review_run set status='partial',reason='Section-attempt budget exhausted; unfinished sections were not examined',updated_at=now() where id=r.id;continue;
   end if;
   select * into s from policy_review_section where run_id=r.id and status='pending' and cycle_attempts<3
    and (retry_at is null or retry_at<=now()) order by id for update skip locked limit 1;
   if found then
    token:=gen_random_uuid();update policy_review_section set status='running',attempts=attempts+1,cycle_attempts=cycle_attempts+1,
     lease=token,lease_until=now()+interval '60 seconds',updated_at=now() where id=s.id returning * into s;
    update policy_review_run set status='running',used=used+1,updated_at=now() where id=r.id returning * into r;
    select * into d from policy_review_document where id=s.document_id;
    select substring(canonical_text from s.char_start+1 for s.char_end-s.char_start) into text_value from document_extraction where id=d.extraction_id;
    return jsonb_build_object('run',to_jsonb(r),'section',to_jsonb(s),'document',to_jsonb(d),'text',text_value,
      'integrity_ok',encode(extensions.digest(text_value,'sha256'),'hex')=s.content_sha256);
   end if;
   if exists(select 1 from policy_review_section where run_id=r.id and status in ('pending','running')) then continue; end if;
   complete:=not exists(select 1 from policy_review_section where run_id=r.id and status not in ('examined','no_finding'))
      and not exists(select 1 from policy_review_document where run_id=r.id and (exclusion is not null or jsonb_array_length(warnings)>0));
   update policy_review_run set status=case when complete then 'completed' else 'partial' end,
    reason=case when complete then 'Every frozen extracted section was examined; this is not legal verification'
      else 'All remaining readable work finished; excluded, unreadable, failed or quality-limited material prevents completeness' end,updated_at=now() where id=r.id;
  end loop;
  return null;
 end if;
 select * into r from policy_review_run where id=(select run_id from policy_review_section where id=p_section) for update;
 if not found then return jsonb_build_object('active',false);end if;
 select * into s from policy_review_section where id=p_section for update;
 if s.status<>'running' or s.lease is distinct from p_lease or s.lease_until<now() or r.status<>'running' or
   not exists(select 1 from admin_users where user_id=r.actor_id and role='admin') or not exists(select 1 from analysis_users where user_id=r.actor_id and approved) then
   return jsonb_build_object('active',false);
 end if;
 if p_operation='heartbeat' then
  update policy_review_section set lease_until=now()+interval '60 seconds',updated_at=now() where id=s.id;return jsonb_build_object('active',true);
 elsif p_operation='finish' then
  if p_payload->>'outcome' not in ('examined','no_finding') then raise exception 'Invalid review outcome';end if;
  update policy_review_section set status=p_payload->>'outcome',result=p_payload->'result',evidence=p_payload->'evidence',lease=null,lease_until=null,
   safe_error=null,updated_at=now(),attempts_log=attempts_log||jsonb_build_array(jsonb_build_object('attempt',attempts,'outcome',p_payload->>'outcome','at',now(),'usage',p_payload#>'{evidence,usage}')) where id=s.id;
 elsif p_operation='fail' then
  update policy_review_section set status=case when cycle_attempts>=3 then 'failed' else 'pending' end,
   retry_at=now()+interval '1 minute',lease=null,lease_until=null,safe_error='Section review unavailable or failed evidence validation; retry is bounded',updated_at=now(),
   attempts_log=attempts_log||jsonb_build_array(jsonb_build_object('attempt',attempts,'outcome','failed','at',now(),'usage',p_payload->'usage')) where id=s.id;
 else raise exception 'Unknown worker operation';end if;
 if not exists(select 1 from policy_review_section where run_id=r.id and status in ('pending','running')) then
  complete:=not exists(select 1 from policy_review_section where run_id=r.id and status not in ('examined','no_finding'))
   and not exists(select 1 from policy_review_document where run_id=r.id and (exclusion is not null or jsonb_array_length(warnings)>0));
  update policy_review_run set status=case when complete then 'completed' else 'partial' end,
   reason=case when complete then 'Every frozen extracted section was examined; this is not legal verification'
    else 'Excluded, unreadable, failed or quality-limited material prevents completeness' end,updated_at=now() where id=r.id;
 elsif r.used>=r.budget and not exists(select 1 from policy_review_section where run_id=r.id and status='running') then
  update policy_review_run set status='partial',reason='Section-attempt budget exhausted; unfinished sections were not examined',updated_at=now() where id=r.id;
 end if;
 return jsonb_build_object('active',true);
end;
$$;
revoke all on function policy_review_worker(text,bigint,uuid,uuid,jsonb) from public,anon,authenticated;
grant execute on function policy_review_worker(text,bigint,uuid,uuid,jsonb) to service_role;

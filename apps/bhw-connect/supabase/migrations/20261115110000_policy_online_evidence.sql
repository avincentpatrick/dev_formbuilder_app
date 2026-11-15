-- Private, bounded, request-scoped evidence; never insert online sources into the library.
create table policy_research_evidence (
 id uuid primary key, actor_id uuid not null, run_id uuid unique references policy_review_run(id),
 settings jsonb not null, status text not null default 'pending' check(status in ('pending','running','ready')),
 snapshot jsonb, lease uuid, lease_until timestamptz, attempts integer not null default 0,
 created_at timestamptz not null default now(), check(snapshot is null or octet_length(snapshot::text)<=200000)
);
create index policy_research_evidence_owner on policy_research_evidence(actor_id,created_at desc);
alter table policy_research_evidence enable row level security;
revoke all on policy_research_evidence from anon,authenticated;
grant all on policy_research_evidence to service_role;
create function policy_research_api(p_actor uuid,p_operation text,p_payload jsonb default '{}') returns jsonb
language plpgsql set search_path=public,pg_temp as $$
declare r policy_research_evidence%rowtype; rid uuid; result jsonb; token uuid; key uuid;
begin
 if not exists(select 1 from admin_users where user_id=p_actor and role='admin') or
    not exists(select 1 from analysis_users where user_id=p_actor and approved) then
  raise exception 'Approved admin required' using errcode='42501';end if;
 if p_operation='create_run' then
  -- One immutable opt-in/configuration per idempotent run creation, serialized before the old facade.
  perform pg_advisory_xact_lock(hashtextextended(p_actor::text||(p_payload->>'requestKey'),0));
  select id into rid from policy_review_run where actor_id=p_actor and request_key=(p_payload->>'requestKey')::uuid;
  if found then return jsonb_build_object('id',rid);end if;
  result:=policy_review_api(p_actor,'create',jsonb_set(p_payload,'{sources,online}','false'));
  rid:=(result->>'id')::uuid;
  if coalesce((p_payload#>>'{sources,online}')::boolean,false) then
   update policy_review_run set settings=p_payload->'sources' where id=rid and actor_id=p_actor;
   insert into policy_research_evidence(id,actor_id,run_id,settings) values(rid,p_actor,rid,p_payload#>'{sources,research}');
  end if;
  return result;
 end if;
 key:=(p_payload->>'id')::uuid;
 if p_operation='begin' and not coalesce((p_payload->>'run')::boolean,false) then
  insert into policy_research_evidence(id,actor_id,settings) values(key,p_actor,coalesce(p_payload->'settings','{}')) on conflict(id) do nothing;
 end if;
 -- Lock the parent first, as the section worker does. A cancelled run cannot publish web evidence.
 if exists(select 1 from policy_research_evidence where id=key and actor_id=p_actor and run_id is not null) then
  perform 1 from policy_review_run where id=key and actor_id=p_actor and status in ('queued','running') for update;
  if not found and p_operation<>'get' then raise exception 'Run is inactive';end if;
 end if;
 select * into r from policy_research_evidence where id=key and actor_id=p_actor for update;
 if not found then raise exception 'Evidence unavailable' using errcode='42501';end if;
 if p_operation='get' then return jsonb_build_object('id',r.id,'status',r.status,'snapshot',r.snapshot);end if;
 if p_operation='begin' then
  if r.status='ready' then return jsonb_build_object('snapshot',r.snapshot);end if;
  if r.status='running' and r.lease_until>now() then return jsonb_build_object('busy',true);end if;
  if r.attempts>=3 then
   result:=jsonb_build_object('id',r.id,'available',false,'results','[]'::jsonb,'gaps',jsonb_build_array('Online research was interrupted repeatedly; internal evidence remains available.'));
   update policy_research_evidence set status='ready',snapshot=result,lease=null,lease_until=null where id=key;
   return jsonb_build_object('snapshot',result);
  end if;
  token:=gen_random_uuid();update policy_research_evidence set status='running',attempts=attempts+1,lease=token,lease_until=now()+interval '90 seconds' where id=key;
  return jsonb_build_object('lease',token,'settings',r.settings);
 elsif p_operation='save' then
  if r.status<>'running' or r.lease is distinct from (p_payload->>'lease')::uuid or r.lease_until<now() then raise exception 'Lease expired';end if;
  update policy_research_evidence set snapshot=p_payload->'snapshot',status='ready',lease=null,lease_until=null where id=key;
  return jsonb_build_object('snapshot',p_payload->'snapshot');
 end if;
 raise exception 'Unknown research operation';
end;
$$;
revoke all on function policy_research_api(uuid,text,jsonb) from public,anon,authenticated;
grant execute on function policy_research_api(uuid,text,jsonb) to service_role;

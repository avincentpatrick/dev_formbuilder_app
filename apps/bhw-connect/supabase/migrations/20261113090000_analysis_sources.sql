create table public.analysis_sources (
 id uuid primary key, project_id uuid not null references public.analysis_projects(id), actor_id uuid not null,
 kind text not null check(kind in ('file','web')), name text not null, sha256 text not null check(sha256 ~ '^[0-9a-f]{64}$'),
 bytes bigint not null check(bytes>=0), url text, provenance jsonb not null default '{}', metadata jsonb not null default '{}', content jsonb,
 status text not null default 'uploading' check(status in ('uploading','pending','processing','ready','blocked','failed','deleted')),
 error text, lease_id uuid, lease_until timestamptz, attempt integer not null default 0,
 created_at timestamptz not null default now(), deleted_at timestamptz, cleanup_at timestamptz,
 unique(project_id,sha256,kind)
);
alter table public.analysis_sources enable row level security;
revoke all on public.analysis_sources from anon,authenticated;
grant select,insert,update,delete on public.analysis_sources to service_role;
create index analysis_sources_queue on public.analysis_sources(status,created_at);

create function public.analysis_source_api(p_actor uuid,p_project uuid,p_operation text,p_payload jsonb default '{}') returns jsonb
language plpgsql set search_path=public,pg_temp as $$
declare p analysis_projects; s analysis_sources; access_role text; result_json jsonb;
begin
 if not exists(select 1 from analysis_users where user_id=p_actor and approved) then raise exception 'Approval required' using errcode='42501'; end if;
 select * into p from analysis_projects where id=p_project and deleted_at is null for update;
 if not found then raise exception 'Project unavailable' using errcode='42501'; end if;
 if p.owner_id=p_actor then access_role:='owner'; else select role into access_role from analysis_members where project_id=p.id and user_id=p_actor; end if;
 if access_role is null then raise exception 'Project unavailable' using errcode='42501'; end if;
 if p_operation='access' then return jsonb_build_object('role',access_role); end if;
 if p_operation='list' then
  select coalesce(jsonb_agg(to_jsonb(t) order by t.created_at),'[]') into result_json from (
   select id,name,kind,status,error,sha256,bytes,url,provenance,metadata,created_at,content->'profile' as profile,content->'preparations' as preparations
   from analysis_sources where project_id=p.id and deleted_at is null
  )t; return result_json;
 end if;
 if p_operation='get' then
  select * into s from analysis_sources where id=(p_payload->>'sourceId')::uuid and project_id=p.id and deleted_at is null;
  if not found then raise exception 'Source unavailable' using errcode='42501'; end if;
  return to_jsonb(s)-'lease_id'-'lease_until';
 end if;
 if access_role='viewer' then raise exception 'Editing required' using errcode='42501'; end if;
 if p_operation='create' then
  insert into analysis_sources(id,project_id,actor_id,kind,name,sha256,bytes,url,provenance,metadata)
   values((p_payload->>'sourceId')::uuid,p.id,p_actor,p_payload->>'kind',left(p_payload->>'name',200),p_payload->>'sha256',(p_payload->>'bytes')::bigint,p_payload->>'url',coalesce(p_payload->'provenance','{}'),coalesce(p_payload->'metadata','{}'))
   on conflict(project_id,sha256,kind) do update set sha256=excluded.sha256 returning * into s;
  if s.deleted_at is not null then raise exception 'This source version was deleted; create a new project or upload a revised file' using errcode='22023'; end if;
  return to_jsonb(s)-'content'-'lease_id'-'lease_until';
 end if;
 select * into s from analysis_sources where id=(p_payload->>'sourceId')::uuid and project_id=p.id and deleted_at is null for update;
 if not found then raise exception 'Source unavailable' using errcode='42501'; end if;
 if p_operation='ready' and s.status in ('uploading','failed','blocked') then update analysis_sources set status='pending',attempt=0,error=null,actor_id=p_actor where id=s.id;
 elsif p_operation='metadata' then
  update analysis_sources set content=case when (metadata->>'crs') is distinct from (p_payload->'metadata'->>'crs') then null else content end, metadata=p_payload->'metadata',status=case when (metadata->>'crs') is distinct from (p_payload->'metadata'->>'crs') then 'pending' else status end,attempt=0,actor_id=p_actor where id=s.id;
 elsif p_operation='retry' and s.status in ('failed','blocked','ready') then update analysis_sources set status='pending',attempt=0,error=null,actor_id=p_actor where id=s.id;
 elsif p_operation='delete' then
  update analysis_sources set status='deleted',deleted_at=now(),content=null,metadata='{}',name='Deleted',url=null,provenance='{}',error=null,lease_id=null,lease_until=null where id=s.id;
  -- Invalidate dependent saved evidence and fence work already using the deleted source.
  update analysis_jobs set result=null,status='cancelled',lease_id=null,lease_until=null,progress='Source removed',error='A source used by this analysis was deleted' where project_id=p.id and (status in ('queued','running') or result::text like '%'||s.id::text||'%');
 else raise exception 'Source state cannot be changed' using errcode='22023'; end if;
 return '{}'::jsonb;
end $$;
revoke all on function public.analysis_source_api(uuid,uuid,text,jsonb) from public,anon,authenticated;
grant execute on function public.analysis_source_api(uuid,uuid,text,jsonb) to service_role;

create function public.analysis_source_worker_api(p_operation text,p_source uuid default null,p_lease uuid default null,p_payload jsonb default '{}') returns jsonb
language plpgsql set search_path=public,pg_temp as $$
declare s analysis_sources; p analysis_projects; candidate uuid; result_json jsonb;
begin
 if p_operation='cleanup' then
  select coalesce(jsonb_agg(to_jsonb(t)),'[]') into result_json from (
   select id as project_id,null::uuid as source_id from analysis_projects where deleted_at is not null order by cleanup_at nulls first limit 5
  )t;
  return result_json || (select coalesce(jsonb_agg(to_jsonb(t)),'[]') from (
   select project_id,id as source_id from analysis_sources where deleted_at is not null order by cleanup_at nulls first limit 10
  )t);
 end if;
 if p_operation='cleaned' then
  if p_source is not null then update analysis_sources set cleanup_at=now() where id=p_source and deleted_at is not null;
  else update analysis_projects set cleanup_at=now() where id=(p_payload->>'projectId')::uuid and deleted_at is not null; end if;
  return '{}'::jsonb;
 end if;
 if p_operation='claim' then
  select id into candidate from analysis_sources where (p_source is null or id=p_source) and (status='pending' or (status='processing' and lease_until<now())) order by created_at limit 1;
  if not found then return 'null'::jsonb; end if;
  select pr.* into p from analysis_projects pr join analysis_sources a on a.project_id=pr.id where a.id=candidate for update of pr skip locked;
  if not found then return 'null'::jsonb; end if;
  select * into s from analysis_sources where id=candidate and (status='pending' or (status='processing' and lease_until<now())) for update skip locked;
  if not found then return 'null'::jsonb; end if;
 else
  select pr.* into p from analysis_projects pr join analysis_sources a on a.project_id=pr.id where a.id=p_source for update of pr;
  if not found then return jsonb_build_object('active',false); end if;
  select * into s from analysis_sources where id=p_source for update;
  if s.lease_id is distinct from p_lease or s.status<>'processing' or s.lease_until<now() then return jsonb_build_object('active',false); end if;
 end if;
 if p.deleted_at is not null or s.deleted_at is not null or not exists(select 1 from analysis_users where user_id=s.actor_id and approved)
  or not (p.owner_id=s.actor_id or exists(select 1 from analysis_members where project_id=p.id and user_id=s.actor_id and role='editor')) then
  update analysis_sources set status='blocked',error='Source processing access was revoked',lease_id=null,lease_until=null where id=s.id and deleted_at is null;
  return case when p_operation='claim' then 'null'::jsonb else jsonb_build_object('active',false) end;
 end if;
 if p_operation='claim' then
  if s.attempt>=3 then update analysis_sources set status='failed',error='Extraction retry limit reached' where id=s.id; return 'null'::jsonb; end if;
  update analysis_sources set status='processing',lease_id=gen_random_uuid(),lease_until=now()+interval '60 seconds',attempt=attempt+1 where id=s.id returning * into s;
  return to_jsonb(s)-'content';
 elsif p_operation='heartbeat' then update analysis_sources set lease_until=now()+interval '60 seconds' where id=s.id;
 elsif p_operation='finish' then update analysis_sources set status=case when p_payload->'content'->>'status'='blocked' then 'blocked' else 'ready' end,content=p_payload->'content',error=p_payload->'content'->>'error',lease_id=null,lease_until=null where id=s.id;
 elsif p_operation='fail' then update analysis_sources set status='failed',error=left(p_payload->>'error',500),lease_id=null,lease_until=null where id=s.id;
 else raise exception 'Unknown operation' using errcode='22023'; end if;
 return jsonb_build_object('active',true);
end $$;
revoke all on function public.analysis_source_worker_api(text,uuid,uuid,jsonb) from public,anon,authenticated;
grant execute on function public.analysis_source_worker_api(text,uuid,uuid,jsonb) to service_role;

create function public.analysis_sources_on_project_delete() returns trigger language plpgsql set search_path=public,pg_temp as $$
begin
 if new.deleted_at is not null then
  update analysis_sources set deleted_at=now(),status='deleted',name='Deleted',url=null,provenance='{}',metadata='{}',content=null,error=null,lease_id=null,lease_until=null where project_id=new.id and deleted_at is null;
 end if;
 return new;
end $$;
revoke all on function public.analysis_sources_on_project_delete() from public,anon,authenticated;
create trigger analysis_sources_project_delete after update of deleted_at on public.analysis_projects for each row execute function public.analysis_sources_on_project_delete();

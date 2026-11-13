-- Durable project state. RPCs are service-role-only; the web layer supplies the authenticated actor.
create table public.analysis_projects (
  id uuid primary key default gen_random_uuid(), owner_id uuid not null, creation_key uuid not null default gen_random_uuid(), unique(owner_id,creation_key),
  title text not null check (length(title) between 1 and 200),
  revision integer not null default 0, state jsonb not null default '{}',
  created_at timestamptz not null default now(), updated_at timestamptz not null default now(),
  deleted_at timestamptz, cleanup_at timestamptz
);
alter table public.analysis_projects enable row level security;
create table public.analysis_members (
  project_id uuid references public.analysis_projects(id) on delete cascade,
  user_id uuid not null, role text not null check(role in ('viewer','editor')),
  primary key(project_id,user_id)
);
alter table public.analysis_members enable row level security;
create table public.analysis_jobs (
  id uuid primary key default gen_random_uuid(), project_id uuid not null references public.analysis_projects(id),
  actor_id uuid not null, request_id uuid not null, question text not null check(length(question) between 1 and 4000),
  status text not null default 'queued' check(status in ('queued','running','completed','failed','cancelled')),
  attempt integer not null default 0, lease_id uuid, lease_until timestamptz,
  progress text not null default 'Waiting for a worker', context jsonb not null default '{}',
  result jsonb, usage jsonb, error text, created_at timestamptz not null default now(), finished_at timestamptz,
  unique(project_id,actor_id,request_id)
);
alter table public.analysis_jobs enable row level security;
create table public.analysis_job_usage (
  lease_id uuid primary key, job_id uuid not null references public.analysis_jobs(id), attempt integer not null,
  usage jsonb not null default '{"requests":0,"estimatedCostUsd":null,"note":"Attempt started; final usage is unavailable if this worker was interrupted."}'
);
alter table public.analysis_job_usage enable row level security;
create index analysis_jobs_queue on public.analysis_jobs(status,created_at);
create index analysis_jobs_project on public.analysis_jobs(project_id,created_at);
revoke all on public.analysis_projects, public.analysis_members, public.analysis_jobs, public.analysis_job_usage from anon, authenticated;
grant select, insert, update, delete on public.analysis_projects, public.analysis_members, public.analysis_jobs, public.analysis_job_usage to service_role;

create function public.analysis_project_api(p_actor uuid, p_operation text, p_project uuid default null, p_payload jsonb default '{}')
returns jsonb language plpgsql set search_path = public, pg_temp as $$
declare p analysis_projects; j analysis_jobs; access_role text; target uuid; v_result jsonb;
begin
  if not exists(select 1 from analysis_users where user_id=p_actor and approved) then
    raise exception 'Analysis approval required' using errcode='42501';
  end if;
  if p_operation='list' then
    select coalesce(jsonb_agg(to_jsonb(t) order by t.updated_at desc),'[]') into v_result from (
      select a.id,a.title,a.updated_at,a.revision,
        case when a.owner_id=p_actor then 'owner' else m.role end as role
      from analysis_projects a left join analysis_members m on m.project_id=a.id and m.user_id=p_actor
      where a.deleted_at is null and (a.owner_id=p_actor or m.user_id is not null)
    ) t;
    return v_result;
  end if;
  if p_operation='create' then
    insert into analysis_projects(owner_id,creation_key,title,state) values(p_actor,(p_payload->>'requestId')::uuid,coalesce(nullif(p_payload->>'title',''),'New analysis'),coalesce(p_payload->'context','{}')) on conflict(owner_id,creation_key) do update set creation_key=excluded.creation_key returning * into p;
    return jsonb_build_object('id',p.id);
  end if;
  select * into p from analysis_projects where id=p_project and deleted_at is null for update;
  if not found then raise exception 'Project unavailable' using errcode='42501'; end if;
  if p.owner_id=p_actor then access_role:='owner'; else
    select role into access_role from analysis_members where project_id=p.id and user_id=p_actor;
  end if;
  if access_role is null then raise exception 'Project unavailable' using errcode='42501'; end if;
  if p_operation='get' then
    return to_jsonb(p)-'deleted_at'-'cleanup_at' || jsonb_build_object('role',access_role,
      'members',case when access_role='owner' then (select coalesce(jsonb_agg(to_jsonb(m)),'[]') from analysis_members m where m.project_id=p.id) else '[]'::jsonb end,
      'jobs',(select coalesce(jsonb_agg(to_jsonb(t) order by t.created_at),'[]') from (
        select id,question,status,progress,attempt,error,result,usage,created_at from analysis_jobs where project_id=p.id and (p_payload->>'before' is null or created_at<(p_payload->>'before')::timestamptz) order by created_at desc limit 200
      )t));
  end if;
  if p_operation='artifact' then
    select * into j from analysis_jobs where id=(p_payload->>'jobId')::uuid and project_id=p.id and status='completed';
    if not found then raise exception 'Result unavailable' using errcode='42501'; end if;
    return jsonb_build_object('jobId',j.id,'projectId',p.id,'question',j.question,'context',j.context,'createdAt',j.created_at,'finishedAt',j.finished_at,'actorId',j.actor_id,'result',j.result,'usage',j.usage,'usageHistory',(select coalesce(jsonb_agg(to_jsonb(u)),'[]') from analysis_job_usage u where u.job_id=j.id));
  end if;
  if p_operation in ('share','unshare','delete') and access_role <> 'owner' then
    raise exception 'Owner access required' using errcode='42501';
  end if;
  if p_operation='share' then
    target:=(p_payload->>'userId')::uuid;
    if target=p.owner_id or not exists(select 1 from analysis_users where user_id=target and approved) then
      raise exception 'Choose another approved user' using errcode='22023';
    end if;
    insert into analysis_members(project_id,user_id,role) values(p.id,target,p_payload->>'role')
      on conflict(project_id,user_id) do update set role=excluded.role;
    return '{}'::jsonb;
  elsif p_operation='unshare' then
    delete from analysis_members where project_id=p.id and user_id=(p_payload->>'userId')::uuid;
    return '{}'::jsonb;
  elsif p_operation='delete' then
    delete from analysis_job_usage where job_id in (select id from analysis_jobs where project_id=p.id);
    update analysis_projects set deleted_at=now(),state='{}',title='Deleted project',revision=revision+1 where id=p.id;
    update analysis_jobs set status='cancelled',question='Deleted',context='{}',result=null,usage=null,error=null,progress='Deleted',lease_id=null,lease_until=null where project_id=p.id;
    delete from analysis_members where project_id=p.id;
    return '{}'::jsonb;
  end if;
  if access_role='viewer' then raise exception 'Editing access required' using errcode='42501'; end if;
  if p_operation='enqueue' then
    -- A replay returns the same job even when the project revision has since advanced.
    select * into j from analysis_jobs where project_id=p.id and actor_id=p_actor and request_id=(p_payload->>'requestId')::uuid;
    if found then
      if j.question<>p_payload->>'question' then raise exception 'Request ID already used' using errcode='22023'; end if;
      return jsonb_build_object('id',j.id);
    end if;
  end if;
  if p_operation in ('rename','enqueue') and (p_payload->>'revision')::integer is distinct from p.revision then
    raise exception 'Project changed; refresh and try again' using errcode='40001';
  end if;
  if p_operation='rename' then
    update analysis_projects set title=p_payload->>'title',revision=revision+1,updated_at=now() where id=p.id;
  elsif p_operation='enqueue' then
    if exists(select 1 from analysis_jobs where project_id=p.id and status in ('queued','running')) then
      raise exception 'An analysis is already active in this project' using errcode='40001';
    end if;
    insert into analysis_jobs(project_id,actor_id,request_id,question,context)
      values(p.id,p_actor,(p_payload->>'requestId')::uuid,p_payload->>'question',p.state || coalesce(p_payload->'context','{}')) returning * into j;
    update analysis_projects set revision=revision+1,updated_at=now(),title=case when title='New analysis' then left(j.question,100) else title end where id=p.id;
    return jsonb_build_object('id',j.id);
  elsif p_operation in ('cancel','retry') then
    select * into j from analysis_jobs where id=(p_payload->>'jobId')::uuid and project_id=p.id for update;
    if not found then raise exception 'Job unavailable' using errcode='42501'; end if;
    if p_operation='cancel' and j.status in ('queued','running') then
      update analysis_jobs set status='cancelled',progress='Cancelled',lease_id=null,lease_until=null,finished_at=now() where id=j.id;
    elsif p_operation='retry' and j.status in ('failed','cancelled') then
      if exists(select 1 from analysis_jobs where project_id=p.id and status in ('queued','running')) then raise exception 'Another analysis is active' using errcode='40001'; end if;
      -- Retry executes for the requesting editor, not a possibly revoked original actor.
      update analysis_jobs set status='queued',actor_id=p_actor,attempt=0,error=null,result=null,progress='Waiting for a worker',finished_at=null where id=j.id;
    else raise exception 'Job cannot be changed in this state' using errcode='22023'; end if;
  else raise exception 'Unknown operation' using errcode='22023'; end if;
  return '{}'::jsonb;
end $$;
revoke all on function public.analysis_project_api(uuid,text,uuid,jsonb) from public,anon,authenticated;
grant execute on function public.analysis_project_api(uuid,text,uuid,jsonb) to service_role;

create function public.analysis_worker_api(p_operation text, p_job uuid default null, p_lease uuid default null, p_payload jsonb default '{}')
returns jsonb language plpgsql set search_path = public, pg_temp as $$
declare j analysis_jobs; p analysis_projects; history jsonb; active boolean; candidate uuid;
begin
  if p_operation='claim' then
    -- Expired leases are recoverable; a bounded number of automatic retries avoids crash loops.

    select a.id into candidate from analysis_jobs a where (a.status='queued' or (a.status='running' and a.lease_until<now() )) order by a.created_at limit 1;
    if not found then return 'null'::jsonb; end if;
    select pr.* into p from analysis_projects pr join analysis_jobs a on a.project_id=pr.id where a.id=candidate for update of pr skip locked;
    if not found then return 'null'::jsonb; end if;
    select * into j from analysis_jobs where id=candidate and (status='queued' or (status='running' and lease_until<now() )) for update skip locked;
    if not found then return 'null'::jsonb; end if;
    if j.attempt>=3 then
      update analysis_jobs set status='failed',error='Worker retry limit reached',progress='Failed',lease_id=null,lease_until=null where id=j.id;
      return 'null'::jsonb;
    end if;
    active:=p.deleted_at is null and exists(select 1 from analysis_users where user_id=j.actor_id and approved)
      and (p.owner_id=j.actor_id or exists(select 1 from analysis_members where project_id=p.id and user_id=j.actor_id and role='editor'));
    if not active then
      update analysis_jobs set status='cancelled',progress='Access revoked',lease_id=null,lease_until=null where id=j.id;
      return 'null'::jsonb;
    end if;
    update analysis_jobs set status='running',attempt=attempt+1,lease_id=gen_random_uuid(),lease_until=now()+interval '60 seconds',progress='Preparing analysis' where id=j.id returning * into j;
    insert into analysis_job_usage(lease_id,job_id,attempt) values(j.lease_id,j.id,j.attempt);
    select coalesce(jsonb_agg(t.message order by t.created_at,t.seq),'[]') into history from (
      select a.created_at,v.seq,v.message from (select * from analysis_jobs where project_id=p.id and status='completed' and created_at<j.created_at order by created_at desc limit 14) a
      cross join lateral (values(1,jsonb_build_object('role','user','content',a.question)),(2,jsonb_build_object('role','assistant','content',left(coalesce(a.result->>'answer',''),4000))))v(seq,message)
    )t where length(t.message->>'content')>0;
    return to_jsonb(j) || jsonb_build_object('input',j.context || jsonb_build_object('messages',history || jsonb_build_array(jsonb_build_object('role','user','content',j.question))));
  end if;
  select pr.* into p from analysis_projects pr join analysis_jobs a on a.project_id=pr.id where a.id=p_job for update of pr;
  if not found then return jsonb_build_object('active',false); end if;
  select * into j from analysis_jobs where id=p_job for update;
  if j.id is not null and p.deleted_at is null and p_operation in ('finish','fail') then
    update analysis_job_usage set usage=coalesce(p_payload->'usage',usage) where lease_id=p_lease and job_id=j.id;
  end if;
  if j.id is null or j.lease_id is distinct from p_lease or j.status<>'running' or j.lease_until<now() then return jsonb_build_object('active',false); end if;
  active:=p.deleted_at is null and exists(select 1 from analysis_users where user_id=j.actor_id and approved)
    and (p.owner_id=j.actor_id or exists(select 1 from analysis_members where project_id=p.id and user_id=j.actor_id and role='editor'));
  if not active then
    update analysis_jobs set status='cancelled',progress='Access revoked',lease_id=null,lease_until=null where id=j.id;
    return jsonb_build_object('active',false);
  end if;
  if p_operation='heartbeat' then
    update analysis_jobs set lease_until=now()+interval '60 seconds',progress=left(coalesce(p_payload->>'progress','Working'),200) where id=j.id;
  elsif p_operation='finish' then
    update analysis_jobs set status='completed',progress='Complete',result=p_payload->'result',usage=p_payload->'usage',finished_at=now(),lease_id=null,lease_until=null where id=j.id;
    update analysis_projects set state=state || coalesce(p_payload->'context','{}'),revision=revision+1,updated_at=now() where id=p.id;
  elsif p_operation='fail' then
    update analysis_jobs set status='failed',progress='Failed',error=left(coalesce(p_payload->>'error','Analysis failed'),500),usage=p_payload->'usage',finished_at=now(),lease_id=null,lease_until=null where id=j.id;
  else raise exception 'Unknown worker operation' using errcode='22023'; end if;
  return jsonb_build_object('active',true);
end $$;
revoke all on function public.analysis_worker_api(text,uuid,uuid,jsonb) from public,anon,authenticated;
grant execute on function public.analysis_worker_api(text,uuid,uuid,jsonb) to service_role;

-- One completed extraction per job, even after a lease/restart race.
alter table document_extraction add column job_id uuid unique references document_job(id);
alter table document_version add column current_extraction_id uuid references document_extraction(id);
alter table document_job add column kind text not null default 'extract' check (kind in ('extract','embed'));
alter table document_job add column extraction_id uuid references document_extraction(id);
create unique index document_job_one_active_version on document_job(version_id)
  where kind='extract' and stage in ('queued','extracting','ocr','indexing');

create unique index document_job_one_active_embedding on document_job(extraction_id)
  where kind='embed' and stage in ('queued','extracting','ocr','indexing');
alter table document_job add constraint embedding_job_has_extraction check (kind='extract' or extraction_id is not null);

-- Submission is trusted-server-only and independently checks strict BHW role.
-- Does not grant browser access or alter the inferred KB review workflow.
create function enqueue_document_job(p_version uuid, p_actor uuid) returns uuid
language plpgsql set search_path = public, pg_temp as $$
declare job uuid;
begin
  if not exists (select 1 from admin_users where user_id = p_actor and role = 'admin') then
    raise exception 'Admin role required' using errcode = '42501';
  end if;
  perform 1 from document_version where id = p_version for update;
  if not found then raise exception 'Version not found'; end if;
  select id into job from document_job where version_id = p_version and kind='extract'
    and stage in ('queued','extracting','ocr','indexing');
  if job is null then
    insert into document_job(version_id,requested_by) values(p_version,p_actor) returning id into job;
  end if;
  return job;
end;
$$;
revoke all on function enqueue_document_job(uuid,uuid) from public, anon, authenticated;
grant execute on function enqueue_document_job(uuid,uuid) to service_role;
